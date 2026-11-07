<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\Acte;
use App\Models\CalendrierAcademique;
use App\Models\EvenementCalendrier;
use App\Models\ModeleActe;
use App\Models\User;
use App\Support\CandidatureObjectStorage;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Actes produits à partir du calendrier (spec lot 12b ; D18, D19) : génération par modèle,
 * échéances qui suivent les événements, cycle à produire → brouillon → soumis → signé → diffusé.
 */
final class Actes
{
    /**
     * Un acte par événement (hors phases et annulés) et par modèle actif de son type ; rejouable sans doublon.
     * Les actes ne se génèrent que sur un calendrier signé : les dates d'un brouillon ne font pas foi.
     */
    public function generer(CalendrierAcademique $c, ?User $auteur = null): int
    {
        if ($auteur !== null) {
            abort_unless($auteur->can(PermissionsLmd::ACTES_PRODUIRE), 403);
        }
        if (! $c->estSigne()) {
            throw new InvalidArgumentException('Les actes se génèrent sur un calendrier signé.');
        }
        $modeles = ModeleActe::query()->where('actif', true)->get()->groupBy('type_evenement');
        if ($modeles->isEmpty()) {
            return 0;
        }
        $evenements = $c->evenements()->whereIn('type', $modeles->keys())->where('statut', '<>', 'annule')->with('actes:id,evenement_id,modele_id')->get();
        $crees = 0;
        DB::transaction(function () use ($evenements, $modeles, &$crees): void {
            foreach ($evenements as $e) {
                $existants = $e->actes->pluck('modele_id')->all();
                foreach ($modeles->get($e->type, collect()) as $m) {
                    if (in_array($m->id, $existants, true)) {
                        continue;
                    }
                    $a = new Acte;
                    $a->forceFill([
                        'modele_id' => $m->id, 'evenement_id' => $e->id,
                        'objet' => mb_substr($m->libelle.' — '.$e->libelle, 0, 500),
                        'echeance' => $this->echeance($e, $m)->toDateString(),
                    ])->save();
                    $crees++;
                }
            }
        });

        return $crees;
    }

    /**
     * Signature d'une nouvelle version (D21) : les actes de la version remplacée passent sur l'événement
     * correspondant (même type, libellé, niveau, session et promotion), sans doublon. Un acte signé garde
     * son échéance ; un acte non signé suit la nouvelle date. Les actes non signés d'un événement disparu
     * sont annulés ; les actes annulés restent sur l'ancienne version.
     */
    public function reporterVersion(CalendrierAcademique $ancien, CalendrierAcademique $nouveau): void
    {
        $cle = fn (EvenementCalendrier $e): string => implode('|', [$e->type, $e->libelle, $e->niveau, $e->session, $e->promotion]);
        $cibles = $nouveau->evenements()->where('type', '<>', 'phase')->with('actes:id,evenement_id,modele_id')->get()->keyBy($cle);
        $actes = Acte::query()->where('statut', '<>', Acte::ANNULE)->whereHas('evenement', fn ($q) => $q->where('calendrier_id', $ancien->id))
            ->with(['evenement', 'modele'])->orderBy('id')->get();
        foreach ($actes as $a) {
            $cible = $cibles->get($cle($a->evenement));
            if ($cible !== null && $cible->statut !== 'annule' && ! $cible->actes->contains('modele_id', $a->modele_id)) {
                $a->forceFill(['evenement_id' => $cible->id, 'echeance' => $a->estEnCours() ? $this->echeance($cible, $a->modele)->toDateString() : $a->echeance->toDateString()])->save();
                $cible->actes->push($a);
            } elseif ($a->estEnCours()) {
                $a->forceFill(['statut' => Acte::ANNULE, 'motif' => 'Événement absent de la version '.$nouveau->version.' du calendrier'])->save();
            }
        }
    }

    /** L'échéance des actes non signés suit les dates de leur événement (réajustement, D20). */
    public function suivreEvenement(EvenementCalendrier $e): void
    {
        foreach ($e->actes()->whereIn('statut', Acte::EN_COURS)->with('modele')->get() as $a) {
            $a->forceFill(['echeance' => $this->echeance($e, $a->modele)->toDateString()])->save();
        }
    }

    /** Changement du délai d'un modèle : les actes non signés suivent. */
    public function suivreModele(ModeleActe $m): void
    {
        foreach ($m->actes()->whereIn('statut', Acte::EN_COURS)->with('evenement')->get() as $a) {
            $a->forceFill(['echeance' => $this->echeance($a->evenement, $m)->toDateString()])->save();
        }
    }

    /** Un événement annulé annule ses actes non signés. */
    public function annulerPourEvenement(EvenementCalendrier $e): void
    {
        $e->actes()->whereIn('statut', Acte::EN_COURS)->update(['statut' => Acte::ANNULE, 'motif' => 'Événement annulé', 'updated_at' => now()]);
    }

    /** Texte du gabarit, champs remplacés. */
    public function texteInitial(Acte $a): string
    {
        $e = $a->evenement;
        $m = $a->modele;
        $valeurs = [
            'objet' => $a->objet,
            'evenement' => $e->libelle,
            'promotion' => $e->promotion !== null ? $e->promotion.'e promotion' : 'promotion concernée',
            'niveau' => $e->niveau ?? 'M1 et M2',
            'debut' => $e->debut_prevu->format('d/m/Y'),
            'fin' => $e->fin_prevue->format('d/m/Y'),
            'date_seance' => $e->debut_prevu->format('d/m/Y'),
            'annee' => $e->calendrier->annee_academique,
            'signataire' => $m->signataire,
        ];

        return strtr($m->gabarit, array_combine(array_map(fn (string $k): string => '{'.$k.'}', array_keys($valeurs)), array_values($valeurs)));
    }

    public function rediger(Acte $a, string $contenu, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::ACTES_PRODUIRE), 403);
        if (! in_array($a->statut, [Acte::A_PRODUIRE, Acte::BROUILLON], true)) {
            throw new InvalidArgumentException('Seul un acte à produire ou en brouillon se rédige.');
        }
        if (mb_strlen(trim($contenu)) < 10) {
            throw new InvalidArgumentException('Le texte de l\'acte est trop court.');
        }
        $a->forceFill(['statut' => Acte::BROUILLON, 'contenu' => mb_substr(trim($contenu), 0, 20000), 'redige_par' => $auteur->id, 'redige_at' => now(), 'motif' => null])->save();
    }

    public function soumettre(Acte $a, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::ACTES_PRODUIRE), 403);
        if ($a->statut !== Acte::BROUILLON) {
            throw new InvalidArgumentException('Seul un acte rédigé (brouillon) se soumet à la signature.');
        }
        $a->forceFill(['statut' => Acte::SOUMIS, 'soumis_par' => $auteur->id, 'soumis_at' => now()])->save();
    }

    public function renvoyer(Acte $a, string $motif, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::ACTES_SIGNER), 403);
        if ($a->statut !== Acte::SOUMIS || mb_strlen(trim($motif)) < 5) {
            throw new InvalidArgumentException('Seul un acte soumis se renvoie, avec un motif.');
        }
        $a->forceFill(['statut' => Acte::BROUILLON, 'motif' => mb_substr(trim($motif), 0, 500)])->save();
    }

    /** Signature (D18) : numéro et date attribués par l'autorité, signataire, acte signé numérisé. */
    public function signer(Acte $a, string $numero, string $date, string $signataire, ?UploadedFile $piece, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::ACTES_SIGNER), 403);
        if ($a->statut !== Acte::SOUMIS) {
            throw new InvalidArgumentException('Seul un acte soumis reçoit sa signature.');
        }
        $numero = trim($numero);
        if ($numero === '' || trim($signataire) === '' || trim($date) === '') {
            throw new InvalidArgumentException('Numéro, date de signature et signataire sont obligatoires.');
        }
        if (CarbonImmutable::parse($date)->gt(now('Africa/Douala')->endOfDay())) {
            throw new InvalidArgumentException('La date de signature ne peut pas être dans le futur.');
        }
        if (Acte::query()->where('numero', $numero)->where('id', '<>', $a->id)->exists()) {
            throw new InvalidArgumentException('Le numéro '.$numero.' est déjà attribué à un autre acte.');
        }
        if ($piece === null || ! $piece->isValid() || $piece->getMimeType() !== 'application/pdf') {
            throw new InvalidArgumentException('Joignez l\'acte signé numérisé (PDF).');
        }
        $contenu = (string) file_get_contents($piece->getRealPath());
        $chemin = Acte::DOSSIER.'/'.Str::ulid().'.pdf';
        if (! Storage::disk(CandidatureObjectStorage::DISK)->put($chemin, $contenu)) {
            throw new InvalidArgumentException('La pièce n\'a pas pu être enregistrée : réessayez dans quelques minutes.');
        }
        $a->forceFill([
            'statut' => Acte::SIGNE, 'numero' => mb_substr($numero, 0, 120), 'date_signature' => $date, 'signataire' => mb_substr(trim($signataire), 0, 255),
            'piece_signee_path' => $chemin, 'piece_signee_sha256' => hash('sha256', $contenu), 'signature_par' => $auteur->id, 'signature_at' => now(), 'motif' => null,
        ])->save();
    }

    public function diffuser(Acte $a, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::ACTES_SIGNER), 403);
        if ($a->statut !== Acte::SIGNE) {
            throw new InvalidArgumentException('Seul un acte signé se diffuse.');
        }
        $a->forceFill(['statut' => Acte::DIFFUSE, 'diffuse_par' => $auteur->id, 'diffuse_at' => now()])->save();
    }

    public function annuler(Acte $a, string $motif, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::ACTES_PRODUIRE), 403);
        if (! $a->estEnCours() || mb_strlen(trim($motif)) < 5) {
            throw new InvalidArgumentException('Seul un acte non signé s\'annule, avec un motif.');
        }
        $a->forceFill(['statut' => Acte::ANNULE, 'motif' => mb_substr(trim($motif), 0, 500)])->save();
    }

    /** Projet d'acte en PDF, à soumettre à la signature. */
    public function projetPdf(Acte $a): string
    {
        $a->loadMissing(['modele', 'evenement.calendrier']);

        return Pdf::loadView('pdf.lmd.projet-acte', [
            'acte' => $a,
            'contenu' => $a->contenu ?? $this->texteInitial($a),
            'edite_le' => now('Africa/Douala'),
        ])->setPaper('a4', 'portrait')->output();
    }

    /** Acte signé numérisé, vérifié par son empreinte ; null si indisponible. */
    public function piece(Acte $a): ?string
    {
        if ($a->piece_signee_path === null) {
            return null;
        }
        $contenu = CandidatureObjectStorage::read($a->piece_signee_path);

        return $contenu !== null && hash('sha256', $contenu) === $a->piece_signee_sha256 ? $contenu : null;
    }

    private function echeance(EvenementCalendrier $e, ModeleActe $m): CarbonImmutable
    {
        return $e->debut_prevu->addDays($m->delai_jours);
    }
}
