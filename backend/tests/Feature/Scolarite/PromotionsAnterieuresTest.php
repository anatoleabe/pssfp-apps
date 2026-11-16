<?php

declare(strict_types=1);

use App\Filament\Resources\PromotionResource\Pages\ListPromotions;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\Role;
use App\Models\User;
use App\Services\AjoutRetenu\AjoutRetenu;
use App\Services\CandidatureService;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\AnneeEnCours;
use App\Services\Scolarite\PromotionsAnterieures;
use App\Support\AppSettings;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('scolarite');

/* Lot 15j (spec de la réinscription des anciens, §5, §6.1, §8.1) : année en cours, promotions antérieures, garde-fous. */

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('scolarite.annee_en_cours', '2026-2027');
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->p14 = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14, 'slug' => 'p14-2026', 'prefix_numero' => 'P14026-']);
    $this->p13 = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2025-2026'], 'promotion_numero' => 13, 'slug' => 'p13-2025', 'prefix_numero' => 'P13025-']);
    $this->responsable = ($this->compte)('scolarite_responsable');
});

it('lit l\'année en cours dans le réglage, sinon dans la configuration, et en déduit les promotions', function (): void {
    expect(AnneeEnCours::annee())->toBe('2026-2027')
        ->and(AnneeEnCours::promotionCourante()?->is($this->p14))->toBeTrue()
        ->and(AnneeEnCours::promotionAccueil('2026-2027', 'M1')?->is($this->p14))->toBeTrue()
        ->and(AnneeEnCours::promotionAccueil('2026-2027', 'M2')?->is($this->p13))->toBeTrue()
        ->and(AnneeEnCours::estCourante($this->p13))->toBeFalse();

    AppSettings::set(AnneeEnCours::REGLAGE, '2027-2028');
    expect(AnneeEnCours::annee())->toBe('2027-2028')->and(AnneeEnCours::promotionCourante())->toBeNull();
    AppSettings::set(AnneeEnCours::REGLAGE, null);

    config()->set('scolarite.annee_en_cours', 'n\'importe quoi');
    expect(AnneeEnCours::annee())->toBeNull()->and(AnneeEnCours::estCourante($this->p13))->toBeTrue();
});

it('crée une promotion antérieure close, invisible sur le portail, et la journalise', function (): void {
    $p12 = app(PromotionsAnterieures::class)->creer(12, PromotionsAnterieures::anneeProposee(12), 'Réinscription d\'un ancien de la P12', $this->responsable);

    expect($p12->anneeAcademique())->toBe('2024-2025')
        ->and($p12->status)->toBe('closed')
        ->and($p12->promotion_numero)->toBe(12)
        ->and($p12->prefix_numero)->toBe('P12024-')
        ->and($p12->compte_scolarite)->toBe('710160')
        ->and($p12->communique_resultats_path)->toBeNull()
        ->and(AnneeEnCours::promotionDeLAnnee('2024-2025')?->is($p12))->toBeTrue();
    $portail = app(CandidatureService::class);
    expect($portail->currentCampagne()?->id)->not->toBe($p12->id)
        ->and($portail->derniereCampagneAvecResultats()?->id)->not->toBe($p12->id);
    $journal = Activity::query()->where('event', PromotionsAnterieures::EVENEMENT)->sole();
    expect($journal->log_name)->toBe('scolarite')->and($journal->properties['promotion'])->toBe(12);
});

it('refuse un numéro pris ou non antérieur, une année mal formée, déjà portée ou postérieure, et un motif court', function (int $numero, string $annee, string $motif, string $message): void {
    expect(fn () => app(PromotionsAnterieures::class)->creer($numero, $annee, $motif, $this->responsable))
        ->toThrow(InvalidArgumentException::class, $message);
    expect(CampagneCandidature::query()->count())->toBe(2);
})->with([
    'numéro pris' => [13, '2025-2026', 'Motif suffisamment long', 'existe déjà'],
    'numéro de la courante' => [14, '2026-2027', 'Motif suffisamment long', 'de 1 à 13'],
    'numéro futur' => [15, '2027-2028', 'Motif suffisamment long', 'de 1 à 13'],
    'année mal formée' => [11, '2023-2025', 'Motif suffisamment long', 'AAAA-AAAA'],
    'année déjà portée' => [11, '2025-2026', 'Motif suffisamment long', 'déjà celle de la 13e'],
    'année postérieure' => [11, '2026-2027', 'Motif suffisamment long', 'précède'],
    'motif court' => [11, '2023-2024', 'court', 'motif'],
]);

it('réserve la création au responsable et à l\'administration', function (): void {
    expect(fn () => app(PromotionsAnterieures::class)->creer(12, '2024-2025', 'Motif suffisamment long', ($this->compte)('scolarite_agent')))
        ->toThrow(HttpException::class);

    $this->actingAs(($this->compte)('scolarite_agent'));
    expect(auth()->user()->can(PromotionsAnterieures::PERMISSION))->toBeFalse();

    $this->actingAs($this->responsable);
    $this->livewire(ListPromotions::class)
        ->assertActionVisible('creerAnterieure')
        ->callAction('creerAnterieure', ['numero' => 11, 'annee' => '2023-2024', 'motif' => 'Rattachement d\'un ancien de la P11'])
        ->assertHasNoActionErrors();
    $p11 = CampagneCandidature::query()->where('promotion_numero', 11)->sole();
    $this->livewire(ListPromotions::class)->assertCanSeeTableRecords([$p11]);
});

it('n\'ajoute un retenu et n\'attribue de matricules que dans la promotion en cours', function (): void {
    $admin = ($this->compte)('admin');
    $donnees = [
        'campagne_id' => $this->p13->id, 'phone_e164' => '+237699000123', 'motif' => 'Retenu hors portail',
        'civilite' => 'M.', 'genre' => 'M', 'nom' => 'ESSAI', 'prenom' => 'Fictif', 'date_naissance' => '1990-01-01',
        'lieu_naissance' => 'Ville fictive', 'nationalite' => 'CM', 'specialite' => 'Tronc commun',
    ];
    expect(fn () => app(AjoutRetenu::class)->ajouter($donnees, $admin))->toThrow(InvalidArgumentException::class, 'promotion en cours');
    expect(Candidature::query()->where('phone_e164', '+237699000123')->exists())->toBeFalse();

    Candidature::factory()->forCampagne($this->p13)->create(['statut' => Candidature::STATUT_ACCEPTE]);
    expect(fn () => app(AttributionMatricules::class)->attribuer($this->p13, '25', $admin))->toThrow(InvalidArgumentException::class, 'promotion en cours');
    expect(Candidature::query()->where('campagne_id', $this->p13->id)->whereNotNull('matricule')->exists())->toBeFalse();
});

it('ne fige pas l\'année des matricules sur un matricule repris d\'ACMA', function (): void {
    Candidature::factory()->forCampagne($this->p14)->create(['statut' => Candidature::STATUT_ACCEPTE])
        ->forceFill(['matricule' => '99EFP123', 'rang_retenu' => null, 'matricule_attribue_at' => now()])->save();

    expect(AttributionMatricules::anneeFigee($this->p14))->toBeNull();
    Candidature::factory()->forCampagne($this->p14)->create(['statut' => Candidature::STATUT_ACCEPTE]);
    $r = app(AttributionMatricules::class)->attribuer($this->p14, '26', ($this->compte)('admin'));
    expect($r['attribues'])->toBe(1)->and(AttributionMatricules::anneeFigee($this->p14))->toBe('26');
});

it('retire l\'import « Full data » : la commande refuse', function (): void {
    $this->artisan('scolarite:importer-auditeurs', ['--auteur' => $this->responsable->email, '--appliquer' => true])
        ->expectsOutputToContain('Commande retirée')->assertFailed();
});

it('accorde le droit par migration additive, et se défait', function (): void {
    $migration = require database_path('migrations/2026_12_10_100100_grant_promotion_anterieure_permission.php');
    Permission::query()->where('name', PromotionsAnterieures::PERMISSION)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $migration->up();
    $migration->up();
    $a = fn (string $role): bool => Role::findByName($role, 'web')->hasPermissionTo(PromotionsAnterieures::PERMISSION);
    expect($a('scolarite_responsable'))->toBeTrue()->and($a('admin'))->toBeTrue()->and($a('super_admin'))->toBeTrue()
        ->and($a('scolarite_agent'))->toBeFalse()->and($a('service_financier'))->toBeFalse();

    $migration->down();
    expect(Permission::query()->where('name', PromotionsAnterieures::PERMISSION)->exists())->toBeFalse();
});
