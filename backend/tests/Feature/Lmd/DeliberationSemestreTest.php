<?php

declare(strict_types=1);

use App\Models\AcquisUe;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\Deliberation;
use App\Models\DocumentDelivre;
use App\Models\EtapeSaisie;
use App\Models\InscriptionAnnuelle;
use App\Models\InscriptionPedagogique;
use App\Models\Jury;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\MembreJury;
use App\Models\MesureJury;
use App\Models\NoteEcue;
use App\Models\ResultatAnnuel;
use App\Models\ResultatScenarioUe;
use App\Models\User;
use App\Services\Lmd\Deliberations;
use App\Services\Lmd\DocumentsOfficiels;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\InscriptionsPedagogiques;
use App\Services\Lmd\Memoires;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\PvDeliberation;
use App\Services\Lmd\ResultatsAuditeur;
use App\Services\Lmd\SaisieNotes;
use App\Services\Supervision\Controles\NotApresArret;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses()->group('lmd');

/*
 * Lot 17a : délibération de semestre. La maquette M1 de 2026-2027 enregistre ses semestres 7 et 8
 * (rangs 1 et 2), trois UE et 30 crédits chacun.
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
    $this->president = $compte('membre_jury');
    $this->rapporteur = $compte('membre_jury');
    $this->deliberations = app(Deliberations::class);

    $this->jury = Jury::query()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'nature' => 'deliberation',
        'objet' => 'Délibération M1', 'acte_reference' => 'NS 26/001', 'acte_date' => '2026-11-01', 'acte_signataire' => 'Le Doyen']);
    foreach ([['president', $this->president], ['rapporteur', $this->rapporteur]] as [$role, $u]) {
        MembreJury::query()->create(['jury_id' => $this->jury->id, 'role' => $role, 'nom' => 'Membre '.$role, 'user_id' => $u->id]);
    }

    // Deux auditeurs de M1 notés 14 partout ; le second a 11,80 à sa première UE du semestre 1.
    $bareme = BaremeScolarite::query()->firstOrCreate(['annee_academique' => '2026-2027', 'niveau' => 'M1'],
        ['montant_inscription' => 185000, 'montant_tranche1' => 500000, 'montant_tranche2' => 500000]);
    $creer = function (?string $noteFaible) use ($bareme): InscriptionAnnuelle {
        $inscription = InscriptionAnnuelle::factory()->create(['auditeur_id' => Auditeur::factory()->campagneCommune()->create()->id, 'bareme_id' => $bareme->id]);
        app(InscriptionsPedagogiques::class)->inscrire($inscription);
        $ips = $inscription->inscriptionsPedagogiques()->with('maquetteUe.ecues')->get()->sortBy(fn ($ip): array => [$ip->maquetteUe->semestre, $ip->maquetteUe->ordre])->values();
        foreach ($ips as $rang => $ip) {
            $note = $rang === 0 && $noteFaible !== null ? $noteFaible : '14.00';
            foreach ($ip->maquetteUe->ecues as $ecue) {
                (new NoteEcue(['inscription_pedagogique_id' => $ip->id, 'maquette_ecue_id' => $ecue->id, 'session' => 'normale']))
                    ->forceFill(['cc' => $note, 'assiduite' => $note, 'examen' => $note, 'presence_examen' => 'present'])->save();
            }
        }

        return $inscription;
    };
    $this->fort = $creer(null);
    $this->limite = $creer('11.80');
    foreach (MaquetteEcue::query()->pluck('id') as $id) {
        foreach (['cc', 'assiduite', 'examen'] as $etape) {
            EtapeSaisie::query()->updateOrCreate(['maquette_ecue_id' => $id, 'session' => 'normale', 'etape' => $etape], [])
                ->forceFill(['statut' => EtapeSaisie::VERROUILLEE, 'verrouillee_at' => now()])->save();
        }
    }

    $this->creer = fn (?int $semestre, string $session = 'normale'): Deliberation => $this->deliberations->creer(
        ['annee_academique' => '2026-2027', 'niveau' => 'M1', 'session' => $session, 'jury_id' => $this->jury->id, 'semestre' => $semestre], $this->agent);
    /** Mène une délibération jusqu'à l'arrêt, avec un relèvement des notes de 11,50 à 11,99 si demandé. */
    $this->arreter = function (Deliberation $d, bool $relever = false): Deliberation {
        $s = $d->scenarios()->first();
        if ($relever) {
            $this->deliberations->ajouterMesure($s, MesureJury::RELEVEMENT, ['plage_min' => 11.5, 'plage_max' => 11.99, 'cible' => 12], 'Harmonisation des notes proches du seuil', $this->agent);
        }
        $this->deliberations->calculer($s, $this->agent);
        $presences = $this->jury->membres()->get()->mapWithKeys(fn (MembreJury $m): array => [$m->id => ['present' => true, 'motif_absence' => null]])->all();
        $this->deliberations->ouvrirSeance($d, $presences, $this->agent);
        $this->deliberations->retenir($s->refresh(), $this->agent);
        $this->deliberations->arreter($d->refresh(), $this->responsable);

        return $d->refresh();
    };
    $this->publier = function (Deliberation $d): void {
        $this->deliberations->signer($d->refresh(), $this->president);
        $this->deliberations->signer($d->refresh(), $this->rapporteur);
        $this->deliberations->publier($d->refresh(), $this->responsable);
    };
    $this->resultat = fn (Deliberation $d, InscriptionAnnuelle $i): ResultatAnnuel => ResultatAnnuel::query()
        ->where('deliberation_id', $d->id)->where('inscription_annuelle_id', $i->id)->sole();
});

it('ne délibère que les UE de son semestre et décide « validé » ou « non validé »', function (): void {
    $d = ($this->arreter)(($this->creer)(1));

    expect($d->libelle())->toContain('Semestre 1')
        ->and(ResultatScenarioUe::query()->where('scenario_id', $d->scenarioRetenu->id)->where('inscription_annuelle_id', $this->fort->id)->count())->toBe(3)
        ->and(($this->resultat)($d, $this->fort)->decision)->toBe('AD')
        ->and(($this->resultat)($d, $this->fort)->credits_acquis)->toBe(30)
        ->and(($this->resultat)($d, $this->limite)->decision)->toBe('AJ')
        ->and(($this->resultat)($d, $this->limite)->credits_acquis)->toBeLessThan(30)
        ->and($d->decisions()['AD'])->toBe('Semestre validé');
});

it('refuse un semestre hors du niveau et une seconde délibération arrêtée de même portée', function (): void {
    expect(fn () => ($this->creer)(3))->toThrow(InvalidArgumentException::class, 'Master 2');

    ($this->arreter)(($this->creer)(1));
    expect(fn () => ($this->creer)(1))->toThrow(InvalidArgumentException::class, 'ce semestre');
    // L'autre semestre et l'année restent ouverts.
    expect(($this->creer)(2)->semestre)->toBe(2)
        ->and(($this->creer)(null)->semestre)->toBeNull();
});

it('garde acquise à la délibération annuelle l\'UE relevée par le jury au semestre', function (): void {
    $s1 = ($this->arreter)(($this->creer)(1), relever: true);
    expect(($this->resultat)($s1, $this->limite)->decision)->toBe('AD');

    // La délibération annuelle n'a aucune mesure : sans la reprise, l'UE à 11,80 ferait ajourner.
    $annuelle = ($this->arreter)(($this->creer)(null));
    $r = ($this->resultat)($annuelle, $this->limite);
    $ue = collect(app(ResultatsAuditeur::class)->ues($r))->first();

    expect($r->decision)->toBe('AD')
        ->and($r->credits_acquis)->toBe(60)
        ->and($ue['note'])->toBe(12.0)
        ->and($ue['mode'])->toBe('deliberation')
        ->and($r->decision_calculee)->toBe('AJ')
        ->and($r->mesures)->toContain($s1->numero_pv)
        ->and(AcquisUe::query()->where('auditeur_id', $this->limite->auditeur_id)->count())->toBe(6);
});

it('ne gèle que les notes des UE du semestre délibéré', function (): void {
    ($this->arreter)(($this->creer)(1));
    $ecue = fn (int $semestre): MaquetteEcue => MaquetteEcue::query()->whereHas('maquetteUe', fn ($q) => $q->where('semestre', $semestre)
        ->whereIn('id', InscriptionPedagogique::query()->where('inscription_annuelle_id', $this->fort->id)->select('maquette_ue_id')))->firstOrFail();
    $saisie = app(SaisieNotes::class);

    expect(fn () => $saisie->rouvrir($saisie->etape($ecue(7), 'normale', 'cc'), $this->responsable, 'Erreur de report signalée par l\'enseignant'))
        ->toThrow(InvalidArgumentException::class, 'Notes gelées');
    $saisie->rouvrir($saisie->etape($ecue(8), 'normale', 'cc'), $this->responsable, 'Erreur de report signalée par l\'enseignant');
    expect($saisie->etape($ecue(8), 'normale', 'cc')->statut)->toBe(EtapeSaisie::OUVERTE);
});

it('refuse de rectifier un semestre une fois la délibération annuelle arrêtée', function (): void {
    $s1 = ($this->arreter)(($this->creer)(1));
    $annuelle = ($this->arreter)(($this->creer)(null));

    expect(fn () => $this->deliberations->rectifier($s1->refresh(), 'Requête fondée sur une note', 'NS 26/014', $this->responsable))
        ->toThrow(InvalidArgumentException::class, $annuelle->numero_pv);
    expect($this->deliberations->rectifier($annuelle->refresh(), 'Requête fondée sur une note', 'NS 26/015', $this->responsable)->semestre)->toBeNull();
});

it('lève le gel du semestre pendant la rectification de l\'année, et le marquage « à rectifier » à son arrêt', function (): void {
    $s1 = ($this->arreter)(($this->creer)(1));
    $annuelle = ($this->arreter)(($this->creer)(null));
    $ecue = MaquetteEcue::query()->whereHas('maquetteUe', fn ($q) => $q->where('semestre', 7)
        ->whereIn('id', InscriptionPedagogique::query()->where('inscription_annuelle_id', $this->fort->id)->select('maquette_ue_id')))->firstOrFail();
    $saisie = app(SaisieNotes::class);
    $s1->forceFill(['a_rectifier_at' => now(), 'a_rectifier_motif' => 'Requête appliquée après l\'arrêt'])->save();

    $rectification = $this->deliberations->rectifier($annuelle->refresh(), 'Requête fondée sur une note', 'NS 26/015', $this->responsable);
    $saisie->rouvrir($saisie->etape($ecue, 'normale', 'cc'), $this->responsable, 'Erreur de report signalée par l\'enseignant');
    expect($saisie->etape($ecue, 'normale', 'cc')->statut)->toBe(EtapeSaisie::OUVERTE);

    $saisie->etape($ecue, 'normale', 'cc')->forceFill(['statut' => EtapeSaisie::VERROUILLEE])->save();
    ($this->arreter)($rectification);
    expect($s1->refresh()->a_rectifier_at)->toBeNull()
        ->and(app(NotApresArret::class)->cas())->toBe([]);
});

it('ne reprend pas en session normale une UE acquise au rattrapage, et refuse de rectifier la session normale ensuite', function (): void {
    $normale = ($this->arreter)(($this->creer)(null));
    expect(($this->resultat)($normale, $this->limite)->decision)->toBe('AJ');

    // Rattrapage : l'auditeur à 11,80 obtient 13 à l'examen de rattrapage de sa première UE.
    $ip = $this->limite->inscriptionsPedagogiques()->with('maquetteUe.ecues')->get()->sortBy(fn ($ip): array => [$ip->maquetteUe->semestre, $ip->maquetteUe->ordre])->first();
    foreach ($ip->maquetteUe->ecues as $ecue) {
        (new NoteEcue(['inscription_pedagogique_id' => $ip->id, 'maquette_ecue_id' => $ecue->id, 'session' => 'rattrapage']))
            ->forceFill(['examen' => '13.00', 'presence_examen' => 'present'])->save();
    }
    foreach (MaquetteEcue::query()->pluck('id') as $id) {
        EtapeSaisie::query()->updateOrCreate(['maquette_ecue_id' => $id, 'session' => 'rattrapage', 'etape' => 'examen'], [])
            ->forceFill(['statut' => EtapeSaisie::VERROUILLEE, 'verrouillee_at' => now()])->save();
    }
    $rattrapage = ($this->arreter)(($this->creer)(null, 'rattrapage'));
    expect(($this->resultat)($rattrapage, $this->limite)->decision)->toBe('AD');

    expect(fn () => $this->deliberations->rectifier($normale->refresh(), 'Requête fondée sur une note', 'NS 26/016', $this->responsable))
        ->toThrow(InvalidArgumentException::class, $rattrapage->numero_pv);
    // La session normale ne s'appuie sur aucune délibération de rattrapage.
    expect($normale->appuis()->count())->toBe(0)
        ->and($rattrapage->appuis()->pluck('id')->all())->toBe([$normale->id]);
});

it('exige un nouveau calcul de l\'année quand un semestre est arrêté après le calcul', function (): void {
    $annuelle = ($this->creer)(null);
    $s = $annuelle->scenarios()->first();
    $this->deliberations->calculer($s, $this->agent);
    $presences = $this->jury->membres()->get()->mapWithKeys(fn (MembreJury $m): array => [$m->id => ['present' => true, 'motif_absence' => null]])->all();
    $this->deliberations->ouvrirSeance($annuelle, $presences, $this->agent);
    $this->deliberations->retenir($s->refresh(), $this->agent);

    $this->travel(5)->seconds();
    ($this->arreter)(($this->creer)(1), relever: true);

    expect(fn () => $this->deliberations->arreter($annuelle->refresh(), $this->responsable))
        ->toThrow(InvalidArgumentException::class, 'recalculez-le');
});

it('reporte le semestre sur la rectification d\'une délibération de semestre', function (): void {
    $s1 = ($this->arreter)(($this->creer)(1));

    expect($this->deliberations->rectifier($s1->refresh(), 'Requête fondée sur une note', 'NS 26/014', $this->responsable)->semestre)->toBe(1);
});

it('délivre le relevé du semestre, pas l\'attestation, et n\'ouvre pas les mémoires', function (): void {
    $d = ($this->arreter)(($this->creer)(1));
    ($this->publier)($d);
    $documents = app(DocumentsOfficiels::class);

    $releve = $documents->obtenir(DocumentDelivre::RELEVE, $this->fort->auditeur, $d->refresh());
    expect($releve->deliberation_id)->toBe($d->id)
        ->and(str_starts_with($documents->contenu($releve), '%PDF'))->toBeTrue()
        ->and($documents->indisponibilite(DocumentDelivre::ATTESTATION, $this->fort->auditeur, $d))->toContain('pas sur ceux d\'un semestre')
        ->and(str_starts_with(app(PvDeliberation::class)->pv($d), '%PDF'))->toBeTrue()
        ->and(str_starts_with(app(PvDeliberation::class)->affichage($d), '%PDF'))->toBeTrue();
    expect(fn () => app(Memoires::class)->ouvrirPourDeliberation($d, User::factory()->create()->givePermissionTo(PermissionsLmd::MEMOIRES_SUIVRE)))
        ->toThrow(InvalidArgumentException::class, 'délibération annuelle');
});

it('expose le semestre dans les résultats de l\'espace auditeur', function (): void {
    $d = ($this->arreter)(($this->creer)(1));
    ($this->publier)($d);
    $this->fort->auditeur->candidature->forceFill(['user_id' => User::factory()->create()->id])->save();
    $jeton = $this->fort->auditeur->candidature->refresh()->user->createToken('candidat', ['profile:read', 'application:read'])->plainTextToken;

    $this->withToken($jeton)->getJson('/v1/applications/me/resultats')->assertOk()
        ->assertJsonCount(1, 'data.resultats')
        ->assertJsonPath('data.resultats.0.semestre', 1)
        ->assertJsonPath('data.resultats.0.decision', 'AD')
        ->assertJsonPath('data.resultats.0.credits', 30)
        ->assertJsonCount(3, 'data.resultats.0.ues');
});
