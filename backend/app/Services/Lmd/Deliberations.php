<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\AcquisUe;
use App\Models\Deliberation;
use App\Models\EtapeSaisie;
use App\Models\InscriptionAnnuelle;
use App\Models\InscriptionPedagogique;
use App\Models\Jury;
use App\Models\MaquetteEcue;
use App\Models\MembreJury;
use App\Models\MesureJury;
use App\Models\NoteEcue;
use App\Models\ParticipationDeliberation;
use App\Models\RegleEvaluation;
use App\Models\ResultatAnnuel;
use App\Models\ResultatScenario;
use App\Models\ResultatScenarioUe;
use App\Models\Scenario;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cycle d'une délibération (spec lot 6 ; D35, D37) : préparation, préalables, scénarios et
 * mesures, séance et quorum, scénario retenu, arrêt (résultats figés, acquis, PV numéroté et
 * scellé), signatures, publication, rectification.
 */
final class Deliberations
{
    public const SCENARIO_BASE = 'Application stricte des règles';

    private const RANG_DECISION = ['AJ' => 0, 'AS' => 1, 'AD' => 2];

    public function __construct(private readonly CalculScenario $calcul) {}

    /** @param  array{annee_academique: string, niveau: string, parcours_id?: ?int, session: string, jury_id?: ?int, date_deliberation?: ?string}  $data */
    public function creer(array $data, User $auteur): Deliberation
    {
        abort_unless($auteur->can(PermissionsLmd::DELIBERATION_PREPARER), 403);
        $existe = Deliberation::query()->where('annee_academique', $data['annee_academique'])->where('niveau', $data['niveau'])
            ->where('session', $data['session'])->where('parcours_id', $data['parcours_id'] ?? null)
            ->where('statut', Deliberation::ARRETEE)->exists();
        if ($existe) {
            throw new InvalidArgumentException('Une délibération arrêtée existe déjà pour cette année, ce niveau et cette session : rectifiez-la.');
        }

        return DB::transaction(function () use ($data, $auteur): Deliberation {
            $d = Deliberation::query()->create($data);
            $d->forceFill(['creee_par' => $auteur->id])->save();
            Scenario::query()->create(['deliberation_id' => $d->id, 'libelle' => self::SCENARIO_BASE]);
            $this->journal($d, $auteur, 'deliberation_creee', 'Délibération créée');

            return $d;
        });
    }

    /** @return list<Anomalie> */
    public function prealables(Deliberation $d): array
    {
        $anomalies = [];
        $jury = $d->jury;
        if ($jury === null) {
            $anomalies[] = new Anomalie(Anomalie::BLOQUANT, 'jury_non_designe', 'Aucun jury n\'est rattaché à la délibération.');
        } else {
            $roles = $jury->membres()->pluck('role');
            if (! $roles->contains('president') || ! $roles->contains('rapporteur')) {
                $anomalies[] = new Anomalie(Anomalie::BLOQUANT, 'jury_incomplet', 'Le jury n\'a pas de président ou pas de rapporteur.');
            }
            if ($jury->acte_piece_path === null) {
                $anomalies[] = new Anomalie(Anomalie::AVERTISSEMENT, 'acte_non_numerise', 'La note de service du jury n\'est pas numérisée.');
            }
        }

        $population = CalculScenario::population($d);
        if (! (clone $population)->exists()) {
            $anomalies[] = new Anomalie(Anomalie::BLOQUANT, 'population_vide', 'Aucun auditeur inscrit à des UE pour cette année et ce niveau.');

            return $anomalies;
        }
        $sansUe = InscriptionAnnuelle::query()->where('annee_academique', $d->annee_academique)->where('niveau', $d->niveau)
            ->when($d->parcours_id !== null, fn ($q) => $q->where('parcours_id', $d->parcours_id))
            ->whereDoesntHave('inscriptionsPedagogiques')->count();
        if ($sansUe > 0 && $d->parcours_id === null) {
            $anomalies[] = new Anomalie(Anomalie::AVERTISSEMENT, 'sans_inscription_pedagogique', "{$sansUe} inscription(s) de l'année sans UE : hors délibération.");
        }

        $ecues = MaquetteEcue::query()->whereIn('maquette_ue_id',
            InscriptionPedagogique::query()->whereIn('inscription_annuelle_id', (clone $population)->select('id'))->select('maquette_ue_id'))
            ->get(['id', 'code']);
        $etapesAttendues = $d->session === EtapeSaisie::RATTRAPAGE ? [EtapeSaisie::EXAMEN] : EtapeSaisie::etapesDe(EtapeSaisie::NORMALE);
        $verrouillees = EtapeSaisie::query()->whereIn('maquette_ecue_id', $ecues->pluck('id'))->where('session', $d->session)
            ->where('statut', EtapeSaisie::VERROUILLEE)->get()->groupBy('maquette_ecue_id');
        $nonVerrouilles = $ecues->filter(fn ($e): bool => count($verrouillees->get($e->id, collect())) < count($etapesAttendues))->pluck('code');
        if ($nonVerrouilles->isNotEmpty()) {
            $anomalies[] = new Anomalie(Anomalie::BLOQUANT, 'etapes_non_verrouillees', sprintf('%d ECUE dont les notes ne sont pas verrouillées : %s.',
                $nonVerrouilles->count(), $nonVerrouilles->take(12)->implode(', ').($nonVerrouilles->count() > 12 ? '…' : '')));
        }

        return $anomalies;
    }

    public function dupliquer(Scenario $source, string $libelle, User $auteur): Scenario
    {
        $this->modifiable(Deliberation::query()->findOrFail($source->deliberation_id), $auteur);

        return DB::transaction(function () use ($source, $libelle): Scenario {
            $copie = Scenario::query()->create(['deliberation_id' => $source->deliberation_id, 'libelle' => mb_substr(trim($libelle) ?: 'Variante', 0, 120)]);
            foreach ($source->mesures as $m) {
                MesureJury::query()->create(['scenario_id' => $copie->id, 'type' => $m->type, 'parametres' => $m->parametres, 'motif' => $m->motif, 'ordre' => $m->ordre]);
            }

            return $copie;
        });
    }

    /**
     * Mesure du jury, dans les limites des règles de l'année (D37, V-05 à V-07).
     *
     * @param  array<string, mixed>  $parametres
     */
    public function ajouterMesure(Scenario $scenario, string $type, array $parametres, string $motif, User $auteur): MesureJury
    {
        $d = Deliberation::query()->findOrFail($scenario->deliberation_id);
        $this->modifiable($d, $auteur);
        $regle = RegleEvaluation::pourAnnee($d->annee_academique);
        $seuil = (float) $regle->seuil_validation;
        $motif = trim($motif);
        if (mb_strlen($motif) < ($type === MesureJury::CAS_PARTICULIER ? 30 : 10)) {
            throw new InvalidArgumentException($type === MesureJury::CAS_PARTICULIER
                ? 'Un cas particulier exige un motif détaillé (30 caractères au moins).' : 'Indiquez le motif de la mesure (10 caractères au moins).');
        }

        $parametres = match ($type) {
            MesureJury::RELEVEMENT => $this->parametresRelevement($parametres, $seuil, (float) ($regle->jury['relevement_ecart_max'] ?? 0.5)),
            MesureJury::REPECHAGE => $this->parametresRepechage($parametres, (float) ($regle->jury['repechage_ecart_max'] ?? 1)),
            MesureJury::CAS_PARTICULIER => $this->parametresCasParticulier($parametres, $d),
            default => throw new InvalidArgumentException('Type de mesure inconnu.'),
        };

        $mesure = MesureJury::query()->create([
            'scenario_id' => $scenario->id, 'type' => $type, 'parametres' => $parametres, 'motif' => mb_substr($motif, 0, 500),
            'ordre' => (int) $scenario->mesures()->max('ordre') + 1,
        ]);
        $scenario->forceFill(['calcule_at' => null])->save();

        return $mesure;
    }

    public function retirerMesure(MesureJury $mesure, User $auteur): void
    {
        $this->modifiable(Deliberation::query()->findOrFail($mesure->scenario->deliberation_id), $auteur);
        $mesure->scenario->forceFill(['calcule_at' => null])->save();
        DB::transaction(function () use ($mesure): void {
            ResultatScenarioUe::query()->where('mesure_id', $mesure->id)->update(['mesure_id' => null]);
            $mesure->delete();
        });
    }

    /** @return array{effectif: int, admis: int, autorises: int, ajournes: int} */
    public function calculer(Scenario $scenario, User $auteur): array
    {
        $this->modifiable(Deliberation::query()->findOrFail($scenario->deliberation_id), $auteur);

        return $this->calcul->calculer($scenario->refresh());
    }

    /**
     * Ouverture de la séance : présence de chaque membre, motif de chaque absence ; quorum (D35, D37).
     *
     * @param  array<int, array{present: bool, motif_absence?: ?string}>  $presences  par id de membre
     */
    public function ouvrirSeance(Deliberation $d, array $presences, User $auteur): bool
    {
        $this->modifiable($d, $auteur);
        $jury = $d->jury ?? throw new InvalidArgumentException('Aucun jury n\'est rattaché à la délibération.');
        $membres = $jury->membres()->get();
        foreach ($membres as $m) {
            $p = $presences[$m->id] ?? null;
            if ($p === null) {
                throw new InvalidArgumentException("Présence non indiquée pour {$m->nom}.");
            }
            if (! $p['present'] && trim((string) ($p['motif_absence'] ?? '')) === '') {
                throw new InvalidArgumentException("Indiquez le motif de l'absence de {$m->nom}.");
            }
        }
        $regle = RegleEvaluation::pourAnnee($d->annee_academique);
        $presents = $membres->filter(fn (MembreJury $m): bool => (bool) $presences[$m->id]['present']);
        $president = $presents->contains(fn (MembreJury $m): bool => $m->role === 'president');
        $quorum = ($regle->jury['quorum_mode'] ?? 'pourcentage') === 'tous_designes'
            ? $presents->count() === $membres->count()
            : $president && $presents->count() * 100 >= (int) ($regle->jury['quorum_pct'] ?? 50) * $membres->count();

        DB::transaction(function () use ($d, $membres, $presences, $quorum, $auteur, $presents, $jury): void {
            ParticipationDeliberation::query()->where('deliberation_id', $d->id)->delete();
            foreach ($membres as $m) {
                ParticipationDeliberation::query()->create([
                    'deliberation_id' => $d->id, 'membre_jury_id' => $m->id, 'present' => (bool) $presences[$m->id]['present'],
                    'motif_absence' => $presences[$m->id]['present'] ? null : mb_substr(trim((string) $presences[$m->id]['motif_absence']), 0, 255),
                ]);
            }
            $d->forceFill(['seance_ouverte_at' => now(), 'quorum_atteint' => $quorum])->save();
            $jury->forceFill(['seance_le' => $jury->seance_le ?? now()])->save();
            $this->journal($d, $auteur, 'seance_ouverte', 'Séance de délibération ouverte', ['presents' => $presents->count(), 'membres' => $membres->count(), 'quorum' => $quorum]);
        });

        return $quorum;
    }

    public function retenir(Scenario $scenario, User $auteur): void
    {
        $scenario->refresh();
        $d = Deliberation::query()->findOrFail($scenario->deliberation_id);
        $this->modifiable($d, $auteur);
        if ($d->seance_ouverte_at === null || ! $d->quorum_atteint) {
            throw new InvalidArgumentException('Séance non ouverte ou quorum non atteint.');
        }
        if ($scenario->calcule_at === null) {
            throw new InvalidArgumentException('Calculez le scénario avant de le retenir.');
        }
        DB::transaction(function () use ($scenario, $d, $auteur): void {
            Scenario::query()->where('deliberation_id', $d->id)->update(['retenu' => false]);
            $scenario->forceFill(['retenu' => true])->save();
            $d->forceFill(['decision_jury' => $scenario->mesures()->exists() ? 'deliberation' : 'sans_deliberation'])->save();
            $this->journal($d, $auteur, 'scenario_retenu', 'Scénario retenu par le jury', ['scenario' => $scenario->libelle]);
        });
    }

    /** Arrêt (D35) : résultats figés, acquis écrits, PV numéroté et scellé. */
    public function arreter(Deliberation $d, User $auteur): string
    {
        abort_unless($auteur->can(PermissionsLmd::DELIBERATION_ARRETER), 403);

        return DB::transaction(function () use ($d, $auteur): string {
            $d = Deliberation::query()->lockForUpdate()->with(['jury', 'rectifiee'])->findOrFail($d->id);
            if ($d->statut !== Deliberation::SIMULATION) {
                throw new InvalidArgumentException('Délibération déjà arrêtée.');
            }
            if ($d->seance_ouverte_at === null || ! $d->quorum_atteint) {
                throw new InvalidArgumentException('Séance non ouverte ou quorum non atteint.');
            }
            $scenario = $d->scenarioRetenu()->first() ?? throw new InvalidArgumentException('Aucun scénario retenu par le jury.');
            $bloquants = array_filter($this->prealables($d), fn (Anomalie $a): bool => $a->estBloquante());
            if ($bloquants !== []) {
                throw new InvalidArgumentException('Préalables bloquants : '.implode(' ', array_map(fn (Anomalie $a): string => $a->message, $bloquants)));
            }
            $derniereNote = NoteEcue::query()->whereIn('inscription_pedagogique_id',
                InscriptionPedagogique::query()->whereIn('inscription_annuelle_id', CalculScenario::population($d)->select('id'))->select('id'))
                ->max('updated_at');
            if ($scenario->calcule_at === null || ($derniereNote !== null && CarbonImmutable::parse($derniereNote)->gt($scenario->calcule_at))) {
                throw new InvalidArgumentException('Des notes ont changé après le calcul du scénario retenu : recalculez-le.');
            }

            $resultats = ResultatScenario::query()->where('scenario_id', $scenario->id)->with('inscriptionAnnuelle.auditeur.candidature')->get();
            if ($d->rectifie_id !== null) {
                $this->controlerRectification($d, $resultats);
            }
            foreach ($resultats as $r) {
                ResultatAnnuel::query()->create([
                    'deliberation_id' => $d->id, 'inscription_annuelle_id' => $r->inscription_annuelle_id,
                    'credits_acquis' => $r->credits_acquis, 'credits_annee' => $r->credits_annee, 'moyenne' => $r->moyenne, 'mgp' => $r->mgp,
                    'decision_calculee' => $r->decision_calculee, 'decision' => $r->decision, 'rang' => $r->rang, 'mention' => $r->mention,
                    'felicitations_proposees' => $r->felicitations_proposees, 'mesures' => $r->mesures,
                ]);
            }

            if ($d->rectifie_id !== null) {
                AcquisUe::query()->where('deliberation_id', $d->rectifie_id)->delete();
                Deliberation::query()->whereKey($d->rectifie_id)->update(['statut' => Deliberation::RECTIFIEE]);
            }
            $lignes = $scenario->resultatsUe()->where('acquise', true)->where('capitalisee', false)
                ->with(['maquetteUe', 'mesure'])->get();
            $auditeurs = $resultats->mapWithKeys(fn ($r): array => [$r->inscription_annuelle_id => $r->inscriptionAnnuelle->auditeur_id]);
            foreach ($lignes as $l) {
                AcquisUe::query()->insertOrIgnore([[
                    'auditeur_id' => $auditeurs[$l->inscription_annuelle_id], 'unite_enseignement_id' => $l->maquetteUe->unite_enseignement_id,
                    'maquette_ue_id' => $l->maquette_ue_id, 'niveau' => $d->niveau, 'credits' => $l->credits, 'note_retenue' => $l->note_jury,
                    'annee_academique' => $d->annee_academique, 'session' => $d->session, 'origine' => $l->mesure_id === null ? 'evaluation' : 'deliberation',
                    'deliberation_id' => $d->id, 'created_at' => now(), 'updated_at' => now(),
                ]]);
            }

            DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('numero_pv_'.$d->annee_academique)]);
            $rang = Deliberation::query()->where('annee_academique', $d->annee_academique)->whereNotNull('numero_pv')->count() + 1;
            $numero = sprintf('PVD-%s-%04d', $d->annee_academique, $rang);
            $sceau = hash('sha256', $resultats->map(fn ($r): string => implode('|', [
                (string) $r->inscriptionAnnuelle->auditeur?->candidature?->matricule, $r->credits_acquis, $r->moyenne, $r->mgp, $r->decision, $r->decision_calculee, $r->mesures,
            ]))->sort()->implode("\n"));
            $d->forceFill(['statut' => Deliberation::ARRETEE, 'arretee_par' => $auteur->id, 'arretee_at' => now(), 'numero_pv' => $numero, 'pv_sha256' => $sceau])->save();
            $this->journal($d, $auteur, 'deliberation_arretee', 'Délibération arrêtée', ['numero_pv' => $numero, 'resultats' => $resultats->count(), 'sceau' => $sceau]);

            return $numero;
        });
    }

    /** @return list<string> noms des signataires attendus qui n'ont pas signé */
    public function signaturesManquantes(Deliberation $d): array
    {
        $regle = RegleEvaluation::pourAnnee($d->annee_academique);
        $exigees = ($regle->jury['signataires'] ?? 'tous_presents') === 'president_rapporteur' ? ['president', 'rapporteur'] : null;

        return $d->participations()->with('membre')->where('present', true)->whereNull('signe_at')->get()
            ->filter(fn (ParticipationDeliberation $p): bool => $exigees === null || in_array($p->membre->role, $exigees, true))
            ->map(fn (ParticipationDeliberation $p): string => $p->membre->libelle())->values()->all();
    }

    public function signer(Deliberation $d, User $membre): void
    {
        if ($d->statut !== Deliberation::ARRETEE) {
            throw new InvalidArgumentException('Seul un PV arrêté se signe.');
        }
        $participation = $d->participations()->whereHas('membre', fn ($q) => $q->where('user_id', $membre->id))->with('membre')->first();
        if ($participation === null) {
            throw new InvalidArgumentException('Ce compte n\'est pas membre du jury.');
        }
        if (! $participation->present) {
            throw new InvalidArgumentException('Un membre absent à la séance ne signe pas le PV.');
        }
        if ($participation->signe_at !== null) {
            throw new InvalidArgumentException('PV déjà signé par ce membre.');
        }
        $participation->forceFill(['signe_at' => now()])->save();
        $this->journal($d, $membre, 'pv_signe', 'PV de délibération signé', ['membre' => $participation->membre->libelle()]);
    }

    public function publier(Deliberation $d, User $auteur): CarbonImmutable
    {
        abort_unless($auteur->can(PermissionsLmd::DELIBERATION_PUBLIER), 403);

        return DB::transaction(function () use ($d, $auteur): CarbonImmutable {
            $d = Deliberation::query()->lockForUpdate()->findOrFail($d->id);
            if ($d->statut !== Deliberation::ARRETEE) {
                throw new InvalidArgumentException('Seule une délibération arrêtée se publie.');
            }
            if ($d->publiee_at !== null) {
                throw new InvalidArgumentException('Déjà publiée le '.$d->publiee_at->timezone('Africa/Douala')->format('d/m/Y').'.');
            }
            $manquantes = $this->signaturesManquantes($d);
            if ($manquantes !== []) {
                throw new InvalidArgumentException('PV non signé par : '.implode(', ', $manquantes).'.');
            }
            $delai = (int) (RegleEvaluation::pourAnnee($d->annee_academique)->jury['delai_requetes_jours'] ?? 10);
            $limite = CarbonImmutable::now('Africa/Douala')->startOfDay()->addDays($delai);
            $d->forceFill(['publiee_at' => now(), 'publiee_par' => $auteur->id, 'requetes_jusqu_au' => $limite->toDateString()])->save();
            $this->journal($d, $auteur, 'deliberation_publiee', 'Résultats publiés', ['requetes_jusqu_au' => $limite->toDateString()]);

            return $limite;
        });
    }

    /** Rectification (D35, D37) : nouvelle délibération liée, même jury, mesures du scénario retenu. */
    public function rectifier(Deliberation $d, string $motif, string $acte, User $auteur): Deliberation
    {
        abort_unless($auteur->can(PermissionsLmd::DELIBERATION_ARRETER), 403);
        if (mb_strlen(trim($motif)) < 10 || trim($acte) === '') {
            throw new InvalidArgumentException('Motif (10 caractères au moins) et référence de l\'acte rectificatif obligatoires.');
        }
        if ($d->statut !== Deliberation::ARRETEE) {
            throw new InvalidArgumentException('Seule une délibération arrêtée se rectifie.');
        }
        if (Deliberation::query()->where('rectifie_id', $d->id)->exists()) {
            throw new InvalidArgumentException('Une rectification de cette délibération est déjà en cours.');
        }

        return DB::transaction(function () use ($d, $motif, $acte, $auteur): Deliberation {
            $nouvelle = Deliberation::query()->create($d->only(['annee_academique', 'niveau', 'parcours_id', 'session', 'jury_id']));
            $nouvelle->forceFill(['rectifie_id' => $d->id, 'motif_rectification' => mb_substr(trim($motif), 0, 500),
                'acte_rectificatif' => mb_substr(trim($acte), 0, 120), 'creee_par' => $auteur->id])->save();
            $scenario = Scenario::query()->create(['deliberation_id' => $nouvelle->id, 'libelle' => 'Rectification de '.$d->numero_pv]);
            foreach ($d->scenarioRetenu?->mesures ?? [] as $m) {
                MesureJury::query()->create(['scenario_id' => $scenario->id, 'type' => $m->type, 'parametres' => $m->parametres, 'motif' => $m->motif, 'ordre' => $m->ordre]);
            }
            $this->journal($nouvelle, $auteur, 'rectification_ouverte', 'Rectification de délibération ouverte', ['pv' => $d->numero_pv, 'motif' => trim($motif)]);

            return $nouvelle;
        });
    }

    /**
     * Écarts entre la délibération rectifiée et la nouvelle : décision défavorable soumise
     * à la règle de l'année (V-08, V-09).
     *
     * @return array{favorables: int, defavorables: int}
     */
    public function ecartsRectification(Deliberation $d): array
    {
        $anciens = ResultatAnnuel::query()->where('deliberation_id', $d->rectifie_id)->pluck('decision', 'inscription_annuelle_id');
        $nouveaux = $d->statut === Deliberation::SIMULATION
            ? ResultatScenario::query()->whereIn('scenario_id', $d->scenarios()->where('retenu', true)->select('id'))->pluck('decision', 'inscription_annuelle_id')
            : ResultatAnnuel::query()->where('deliberation_id', $d->id)->pluck('decision', 'inscription_annuelle_id');
        $ecarts = ['favorables' => 0, 'defavorables' => 0];
        foreach ($nouveaux as $id => $decision) {
            $avant = $anciens[$id] ?? null;
            if ($avant === null || $avant === $decision) {
                continue;
            }
            self::RANG_DECISION[$decision] > self::RANG_DECISION[$avant] ? $ecarts['favorables']++ : $ecarts['defavorables']++;
        }

        return $ecarts;
    }

    /** @param  Collection<int, ResultatScenario>  $resultats */
    private function controlerRectification(Deliberation $d, $resultats): void
    {
        $ecarts = $this->ecartsRectification($d);
        if ($ecarts['defavorables'] === 0) {
            return;
        }
        $regle = RegleEvaluation::pourAnnee($d->annee_academique);
        $mode = $regle->jury['rectification_defavorable'] ?? 'dans_delai';
        $publiee = $d->rectifiee?->publiee_at;
        $delai = (int) ($regle->jury['rectification_delai_jours'] ?? 120);
        if ($mode === 'interdite' || ($mode === 'dans_delai' && $publiee !== null && now()->gt($publiee->copy()->addDays($delai)))) {
            throw new InvalidArgumentException(sprintf('La rectification abaisse %d décision(s) : %s.', $ecarts['defavorables'],
                $mode === 'interdite' ? 'interdit par la règle de l\'année' : "hors du délai de {$delai} jours après la publication"));
        }
    }

    private function modifiable(Deliberation $d, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::DELIBERATION_PREPARER), 403);
        if (! $d->estModifiable()) {
            throw new InvalidArgumentException('Délibération arrêtée : scénarios, mesures et séance ne se modifient plus.');
        }
    }

    /** @return array<string, mixed> */
    private function parametresRelevement(array $p, float $seuil, float $ecartMax): array
    {
        $min = (float) ($p['plage_min'] ?? -1);
        $max = (float) ($p['plage_max'] ?? -1);
        $cible = (float) ($p['cible'] ?? $seuil);
        if ($min < 0 || $max > 20 || $min > $max || $cible < $max || $cible > 20) {
            throw new InvalidArgumentException('Plage de relèvement invalide.');
        }
        if ($cible - $min > $ecartMax + 0.0001) {
            throw new InvalidArgumentException(sprintf('Le relèvement dépasse %s point (règle de l\'année).', number_format($ecartMax, 2, ',', ' ')));
        }

        return ['plage_min' => $min, 'plage_max' => $max, 'cible' => $cible];
    }

    /** @return array<string, mixed> */
    private function parametresRepechage(array $p, float $ecartMax): array
    {
        $ecart = (float) ($p['ecart_max'] ?? 0);
        if ($ecart <= 0 || $ecart > $ecartMax + 0.0001) {
            throw new InvalidArgumentException(sprintf('L\'écart du repêchage doit être positif et ne pas dépasser %s point.', number_format($ecartMax, 2, ',', ' ')));
        }

        return ['credits_min' => max(0, (int) ($p['credits_min'] ?? 0)), 'ecart_max' => $ecart, 'ue_max' => max(1, (int) ($p['ue_max'] ?? 1))];
    }

    /** @return array<string, mixed> */
    private function parametresCasParticulier(array $p, Deliberation $d): array
    {
        $inscription = CalculScenario::population($d)->whereKey((int) ($p['inscription_annuelle_id'] ?? 0))->first();
        $ue = $inscription?->inscriptionsPedagogiques()->where('maquette_ue_id', (int) ($p['maquette_ue_id'] ?? 0))->exists();
        if ($inscription === null || ! $ue) {
            throw new InvalidArgumentException('Auditeur ou UE hors de la délibération.');
        }
        $note = isset($p['note']) && $p['note'] !== '' && $p['note'] !== null ? (float) $p['note'] : null;
        if ($note !== null && ($note < 0 || $note > 20)) {
            throw new InvalidArgumentException('La note retenue doit être entre 0 et 20.');
        }

        return ['inscription_annuelle_id' => $inscription->id, 'maquette_ue_id' => (int) $p['maquette_ue_id'], 'note' => $note];
    }

    /** @param  array<string, mixed>  $extra */
    private function journal(Deliberation $d, User $auteur, string $evenement, string $libelle, array $extra = []): void
    {
        activity('lmd')->causedBy($auteur)->performedOn($d)->event($evenement)
            ->withProperties(['deliberation' => $d->libelle(), ...$extra])->log($libelle);
    }
}
