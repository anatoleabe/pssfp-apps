<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Models\AnomalieSysteme;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\DerogationRattrapage;
use App\Models\InscriptionAnnuelle;
use App\Models\Maquette;
use App\Models\Parcours;
use App\Models\User;
use App\Services\Lmd\ChangementSpecialite;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\InscriptionsPedagogiques;
use App\Services\Lmd\ValidationMaquette;
use App\Services\Supervision\Supervision;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('lmd', 'filament');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->admin = ($this->compte)('admin');
    foreach (['FFCP', 'GTFP', 'TC'] as $code) {
        app(ValidationMaquette::class)->valider(Maquette::query()->whereHas('parcours', fn ($q) => $q->where('code', $code))->firstOrFail(), $this->admin);
    }
    $this->service = app(ChangementSpecialite::class);
    $this->ffcp = 'Fiscalité, Finance et Comptabilité Publique';
    $this->gtfp = 'Gouvernance Territoriale et Finances Publiques locales';
    $this->acfp = 'Audit et contrôle des Finances Publiques';
});

/** Auditeur inscrit en 2026-2027 au niveau donné, spécialité du dossier donnée, inscription pédagogique faite. */
function auditeurSpecialiteInscrit(string $niveau, ?string $specialite): Auditeur
{
    $bareme = BaremeScolarite::query()->firstOrCreate(['annee_academique' => '2026-2027', 'niveau' => $niveau],
        ['montant_inscription' => 135000, 'montant_tranche1' => 500000, 'montant_tranche2' => 500000]);
    $auditeur = Auditeur::factory()->campagneCommune()->create();
    $auditeur->candidature->forceFill(['specialite' => $specialite])->save();
    $inscription = InscriptionAnnuelle::factory()->create(['auditeur_id' => $auditeur->id, 'bareme_id' => $bareme->id]);
    app(InscriptionsPedagogiques::class)->inscrire($inscription);

    return $auditeur->refresh();
}

it('change la spécialité d\'un auditeur de M2 : dossier, parcours et UE suivent, le journal garde l\'ancienne', function (): void {
    $auditeur = auditeurSpecialiteInscrit('M2', $this->ffcp);
    expect($auditeur->inscriptionCourante->parcours->code)->toBe('FFCP');

    $r = $this->service->changer($auditeur, $this->gtfp, 'Erreur de saisie à l\'inscription', $this->admin);

    $inscription = $auditeur->inscriptionCourante()->first();
    expect($r)->toMatchArray(['specialite' => $this->gtfp, 'parcours' => 'GTFP', 'issue' => InscriptionsPedagogiques::INSCRITE])
        ->and($auditeur->candidature->refresh()->specialite)->toBe($this->gtfp)
        ->and($inscription->parcours->code)->toBe('GTFP')
        ->and($inscription->inscriptionsPedagogiques()->count())->toBe($r['ues'])
        ->and($r['ues'])->toBeGreaterThan(0);
    $journal = Activity::query()->where('event', ChangementSpecialite::EVENEMENT)->sole();
    expect($journal->subject_id)->toBe($auditeur->id)
        ->and($journal->properties->only(['ancienne_specialite', 'ancien_parcours', 'nouveau_parcours'])->all())
        ->toBe(['ancienne_specialite' => $this->ffcp, 'ancien_parcours' => 'FFCP', 'nouveau_parcours' => 'GTFP']);
});

it('aligne le parcours quand seul lui diverge du dossier, et refuse un changement qui ne change rien', function (): void {
    $auditeur = auditeurSpecialiteInscrit('M2', $this->ffcp);
    $auditeur->candidature->forceFill(['specialite' => $this->gtfp])->save();

    $r = $this->service->changer($auditeur->refresh(), $this->gtfp, 'Parcours aligné sur le dossier', $this->admin);

    expect($r['parcours'])->toBe('GTFP');
    expect(fn () => $this->service->changer($auditeur->refresh(), $this->gtfp, 'Deuxième fois pour rien', $this->admin))
        ->toThrow(InvalidArgumentException::class, 'déjà');
});

it('ne change que le dossier en M1, le tronc commun reste', function (): void {
    $auditeur = auditeurSpecialiteInscrit('M1', $this->ffcp);
    $ues = $auditeur->inscriptionCourante->inscriptionsPedagogiques()->count();

    $r = $this->service->changer($auditeur, $this->acfp, 'Choix d\'option rectifié', $this->admin);

    expect($r['issue'])->toBeNull()
        ->and($auditeur->candidature->refresh()->specialite)->toBe($this->acfp)
        ->and($auditeur->inscriptionCourante()->first()->parcours->code)->toBe(Parcours::TRONC_COMMUN)
        ->and($auditeur->inscriptionCourante()->first()->inscriptionsPedagogiques()->count())->toBe($ues);
});

it('pose le parcours sans inscrire d\'UE quand la maquette de la nouvelle option n\'est pas validée', function (): void {
    $auditeur = auditeurSpecialiteInscrit('M2', $this->ffcp);

    $r = $this->service->changer($auditeur, $this->acfp, 'Demande écrite de l\'auditeur', $this->admin);

    expect($r)->toMatchArray(['parcours' => 'ACFP', 'issue' => InscriptionsPedagogiques::SANS_MAQUETTE, 'ues' => 0])
        ->and($auditeur->inscriptionCourante()->first()->inscriptionsPedagogiques()->count())->toBe(0);
});

it('refuse quand des travaux sont saisis sur les UE du parcours quitté, sans rien modifier', function (): void {
    $auditeur = auditeurSpecialiteInscrit('M2', $this->ffcp);
    DerogationRattrapage::query()->create([
        'inscription_pedagogique_id' => $auditeur->inscriptionCourante->inscriptionsPedagogiques()->value('id'),
        'accordee_par' => 'Le Chef de la structure', 'reference_acte' => 'N°1', 'date_acte' => now()->toDateString(),
        'motif' => 'Motif de test suffisant', 'saisie_par' => $this->admin->id,
    ]);

    expect(fn () => $this->service->changer($auditeur, $this->gtfp, 'Erreur de saisie à l\'inscription', $this->admin))
        ->toThrow(InvalidArgumentException::class, 'notes');
    expect($auditeur->candidature->refresh()->specialite)->toBe($this->ffcp)
        ->and($auditeur->inscriptionCourante()->first()->parcours->code)->toBe('FFCP');
});

it('refuse un motif trop court et une spécialité hors de la liste officielle', function (): void {
    $auditeur = auditeurSpecialiteInscrit('M2', $this->ffcp);

    expect(fn () => $this->service->changer($auditeur, $this->gtfp, 'court', $this->admin))->toThrow(InvalidArgumentException::class, 'motif')
        ->and(fn () => $this->service->changer($auditeur, 'Spécialité inventée', 'Erreur de saisie à l\'inscription', $this->admin))
        ->toThrow(InvalidArgumentException::class, 'officielles');
});

it('réserve le changement à l\'administrateur', function (string $role): void {
    $auditeur = auditeurSpecialiteInscrit('M2', $this->ffcp);
    $compte = ($this->compte)($role);

    expect(fn () => $this->service->changer($auditeur, $this->gtfp, 'Erreur de saisie à l\'inscription', $compte))->toThrow(HttpException::class);
    $this->actingAs($compte)->livewire(ViewAuditeur::class, ['record' => $auditeur->getRouteKey()])
        ->assertInfolistActionHidden('candidature.specialite', 'changerSpecialite');
})->with(['scolarite_responsable', 'scolarite_agent', 'service_financier']);

it('change la spécialité depuis l\'onglet Identité de la fiche', function (): void {
    $auditeur = auditeurSpecialiteInscrit('M2', $this->ffcp);

    $this->actingAs($this->admin)->livewire(ViewAuditeur::class, ['record' => $auditeur->getRouteKey()])
        ->assertInfolistActionVisible('candidature.specialite', 'changerSpecialite')
        ->callInfolistAction('candidature.specialite', 'changerSpecialite', data: ['specialite' => $this->gtfp, 'motif' => 'Erreur de saisie à l\'inscription'])
        ->assertHasNoInfolistActionErrors()
        ->assertNotified('Spécialité changée');

    expect($auditeur->candidature->refresh()->specialite)->toBe($this->gtfp)
        ->and($auditeur->inscriptionCourante()->first()->parcours->code)->toBe('GTFP');
});

it('signale au contrôle interne un parcours de M2 qui diverge du dossier, et referme l\'anomalie une fois corrigé', function (): void {
    $auditeur = auditeurSpecialiteInscrit('M2', $this->ffcp);
    auditeurSpecialiteInscrit('M2', $this->gtfp);
    $auditeur->candidature->forceFill(['specialite' => $this->acfp])->save();

    app(Supervision::class)->executer(['LMD_SPECIALITE']);
    expect(AnomalieSysteme::query()->where('controle_code', 'LMD_SPECIALITE')->where('statut', AnomalieSysteme::OUVERTE)->pluck('cle')->all())
        ->toBe([(string) $auditeur->uuid]);

    $this->service->changer($auditeur->refresh(), $this->ffcp, 'Dossier aligné sur le parcours', $this->admin);
    app(Supervision::class)->executer(['LMD_SPECIALITE']);
    expect(AnomalieSysteme::query()->where('controle_code', 'LMD_SPECIALITE')->where('statut', AnomalieSysteme::OUVERTE)->count())->toBe(0);
});
