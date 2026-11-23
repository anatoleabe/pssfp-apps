<?php

declare(strict_types=1);

use App\Models\AcquisUe;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\Deliberation;
use App\Models\DossierMemoire;
use App\Models\EtapeSaisie;
use App\Models\InscriptionAnnuelle;
use App\Models\Jury;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\MaquetteUe;
use App\Models\MembreJury;
use App\Models\NoteEcue;
use App\Models\Parcours;
use App\Models\ResultatAnnuel;
use App\Models\ResultatScenarioUe;
use App\Models\User;
use App\Services\Lmd\Deliberations;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\InscriptionsPedagogiques;
use App\Services\Lmd\Memoires;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses()->group('lmd');

/*
 * Lot 24c : jury d'admission du Master 2. Option FFCP de 2026-2027 : 44 crédits écrits et l'UE de
 * soutenance de 16 crédits. Trois auditeurs : deux notés 14 aux écrits, un ajourné (8 à une UE).
 */
beforeEach(function (): void {
    Storage::fake(CandidatureObjectStorage::DISK);
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    Maquette::query()->update(['statut' => Maquette::VALIDEE, 'validee_at' => now()]);
    $compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->responsable = $compte('scolarite_responsable');
    $this->agent = $compte('scolarite_agent');
    $this->deliberations = app(Deliberations::class);
    $this->memoires = app(Memoires::class);

    $this->jury = Jury::query()->create(['annee_academique' => '2026-2027', 'niveau' => 'M2', 'nature' => 'deliberation',
        'objet' => 'Délibération M2', 'acte_reference' => 'NS 26/002', 'acte_date' => '2026-11-01', 'acte_signataire' => 'Le Doyen']);
    foreach (['president', 'rapporteur'] as $role) {
        MembreJury::query()->create(['jury_id' => $this->jury->id, 'role' => $role, 'nom' => 'Membre '.$role, 'user_id' => $compte('membre_jury')->id]);
    }

    $bareme = BaremeScolarite::query()->firstOrCreate(['annee_academique' => '2026-2027', 'niveau' => 'M2'],
        ['montant_inscription' => 135000, 'montant_tranche1' => 500000, 'montant_tranche2' => 500000]);
    $specialite = (string) Parcours::query()->where('code', 'FFCP')->value('specialite');
    $creer = function (?string $noteFaible) use ($bareme, $specialite): InscriptionAnnuelle {
        $auditeur = Auditeur::factory()->campagneCommune()->create();
        $auditeur->candidature->forceFill(['specialite' => $specialite])->save();
        $inscription = InscriptionAnnuelle::factory()->create(['auditeur_id' => $auditeur->id, 'bareme_id' => $bareme->id]);
        app(InscriptionsPedagogiques::class)->inscrire($inscription);
        $ecrites = $inscription->inscriptionsPedagogiques()->with('maquetteUe.ecues')->get()
            ->filter(fn ($ip): bool => $ip->maquetteUe->partie !== MaquetteUe::SOUTENANCE)->sortBy('maquetteUe.ordre')->values();
        foreach ($ecrites as $rang => $ip) {
            $note = $rang === 0 && $noteFaible !== null ? $noteFaible : '14.00';
            foreach ($ip->maquetteUe->ecues as $ecue) {
                (new NoteEcue(['inscription_pedagogique_id' => $ip->id, 'maquette_ecue_id' => $ecue->id, 'session' => 'normale']))
                    ->forceFill(['cc' => $note, 'assiduite' => $note, 'examen' => $note, 'presence_examen' => 'present'])->save();
            }
        }

        return $inscription->refresh();
    };
    $this->soutenu = $creer(null);
    $this->enAttente = $creer(null);
    $this->ajourne = $creer('8.00');
    $ecrits = MaquetteEcue::query()->whereHas('maquetteUe', fn ($q) => $q->where('partie', '<>', MaquetteUe::SOUTENANCE))->pluck('id');
    foreach ($ecrits as $id) {
        foreach (['cc', 'assiduite', 'examen'] as $etape) {
            EtapeSaisie::query()->updateOrCreate(['maquette_ecue_id' => $id, 'session' => 'normale', 'etape' => $etape], [])
                ->forceFill(['statut' => EtapeSaisie::VERROUILLEE, 'verrouillee_at' => now()])->save();
        }
    }

    $this->creer = fn (string $session, string $niveau = 'M2', ?int $semestre = null): Deliberation => $this->deliberations->creer(
        ['annee_academique' => '2026-2027', 'niveau' => $niveau, 'session' => $session, 'jury_id' => $this->jury->id, 'semestre' => $semestre], $this->agent);
    $this->arreter = function (Deliberation $d): Deliberation {
        $s = $d->scenarios()->first();
        $this->deliberations->calculer($s, $this->agent);
        $presences = $this->jury->membres()->get()->mapWithKeys(fn (MembreJury $m): array => [$m->id => ['present' => true, 'motif_absence' => null]])->all();
        $this->deliberations->ouvrirSeance($d, $presences, $this->agent);
        $this->deliberations->retenir($s->refresh(), $this->agent);
        $this->deliberations->arreter($d->refresh(), $this->responsable);

        return $d->refresh();
    };
    /** Enregistre le résultat de soutenance d'une inscription, dossier ouvert au besoin. */
    $this->soutenir = function (InscriptionAnnuelle $i, string $note): DossierMemoire {
        $dossier = $this->memoires->ouvrir($i);
        $dossier->forceFill(['theme' => 'La fiscalité des collectivités territoriales décentralisées', 'date_soutenance' => '2027-07-10',
            'jury_president' => 'Pr Président', 'jury_rapporteur' => 'Pr Rapporteur'])->save();
        $this->memoires->enregistrerSoutenance($dossier->refresh(), $note, null, $this->agent);

        return $dossier->refresh();
    };
    $this->resultat = fn (Deliberation $d, InscriptionAnnuelle $i): ?ResultatAnnuel => ResultatAnnuel::query()
        ->where('deliberation_id', $d->id)->where('inscription_annuelle_id', $i->id)->first();
});

it('refuse le jury d\'admission en Master 1 et pour un semestre', function (): void {
    expect(fn () => ($this->creer)(Deliberation::ADMISSION, 'M1'))->toThrow(InvalidArgumentException::class, 'Master 2')
        ->and(fn () => ($this->creer)(Deliberation::ADMISSION, 'M2', 3))->toThrow(InvalidArgumentException::class, 'année entière')
        ->and(($this->creer)(Deliberation::ADMISSION)->libelle())->toContain('Jury d\'admission');
});

it('bloque le jury d\'admission tant que la délibération des écrits n\'est pas arrêtée', function (): void {
    $admission = ($this->creer)(Deliberation::ADMISSION);

    $codes = collect($this->deliberations->prealables($admission))->filter->estBloquante()->pluck('code')->all();

    expect($codes)->toContain('population_vide');
});

it('prononce « admis » à 60 crédits avec la note du jury de soutenance, sans toucher aux UE écrites', function (): void {
    $ecrits = ($this->arreter)(($this->creer)('normale'));
    expect(($this->resultat)($ecrits, $this->soutenu)->decision)->toBe('AS')
        ->and(($this->resultat)($ecrits, $this->soutenu)->credits_acquis)->toBe(44)
        ->and(($this->resultat)($ecrits, $this->ajourne)->decision)->toBe('AJ');
    ($this->soutenir)($this->soutenu, '17,5');

    $admission = ($this->creer)(Deliberation::ADMISSION);
    $avertissements = collect($this->deliberations->prealables($admission))->pluck('code')->all();
    $admission = ($this->arreter)($admission);

    $admis = ($this->resultat)($admission, $this->soutenu);
    $lignes = ResultatScenarioUe::query()->where('scenario_id', $admission->scenarioRetenu->id)->where('inscription_annuelle_id', $this->soutenu->id)->with('maquetteUe')->get();
    $soutenance = $lignes->first(fn ($l): bool => $l->maquetteUe->partie === MaquetteUe::SOUTENANCE);

    expect($avertissements)->toContain('soutenances_manquantes')
        ->and($admis->decision)->toBe('AD')
        ->and($admis->credits_acquis)->toBe(60)
        ->and((float) $soutenance->note_jury)->toBe(17.5)
        ->and($lignes->where('maquetteUe.partie', '<>', MaquetteUe::SOUTENANCE)->every(fn ($l): bool => $l->acquise && (float) $l->note_jury === 14.0))->toBeTrue()
        // (44 × 14 + 16 × 17,5) / 60
        ->and(round((float) $admis->moyenne, 2))->toBe(14.93)
        ->and(AcquisUe::query()->where('deliberation_id', $admission->id)->where('session', Deliberation::ADMISSION)->count())->toBe(1)
        // Sans résultat de soutenance : reste autorisé à soutenir. L'ajourné ne figure pas.
        ->and(($this->resultat)($admission, $this->enAttente)->decision)->toBe('AS')
        ->and(($this->resultat)($admission, $this->ajourne))->toBeNull();
});

it('laisse « autorisé à soutenir » une soutenance notée sous le seuil de validation', function (): void {
    ($this->arreter)(($this->creer)('normale'));
    ($this->soutenir)($this->soutenu, '10');

    $admission = ($this->arreter)(($this->creer)(Deliberation::ADMISSION));

    expect(($this->resultat)($admission, $this->soutenu)->decision)->toBe('AS')
        ->and(($this->resultat)($admission, $this->soutenu)->credits_acquis)->toBe(44);
});

it('fige le résultat de soutenance et la délibération des écrits une fois le jury d\'admission arrêté', function (): void {
    $ecrits = ($this->arreter)(($this->creer)('normale'));
    $dossier = ($this->soutenir)($this->soutenu, '17,5');
    $admission = ($this->arreter)(($this->creer)(Deliberation::ADMISSION));

    expect(fn () => $this->memoires->enregistrerSoutenance($dossier, '18', null, $this->agent))->toThrow(InvalidArgumentException::class, 'figé par le jury d\'admission')
        ->and(fn () => $this->deliberations->rectifier($ecrits, 'Erreur de report sur une UE écrite', 'NS 27/010', $this->responsable))->toThrow(InvalidArgumentException::class, $admission->numero_pv)
        ->and(fn () => $this->memoires->ouvrirPourDeliberation($admission, $this->agent))->toThrow(InvalidArgumentException::class, 'après les soutenances');

    // La rectification du jury d'admission rouvre la correction du résultat de soutenance.
    $this->deliberations->rectifier($admission, 'Note de soutenance mal reportée', 'NS 27/011', $this->responsable);
    $this->memoires->enregistrerSoutenance($dossier->refresh(), '18', null, $this->agent);
    expect((float) $dossier->refresh()->etapes->firstWhere('etape', 'soutenance')->note)->toBe(18.0);
});
