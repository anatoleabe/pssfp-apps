<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource;
use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Services\Ecampus\EchecEcampus;
use App\Services\Ecampus\MotsDePasseEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
 * Compte eCampus réel d'un auditeur (19/11/2026) : vérification, réinitialisation du mot de passe à sa demande
 * (format simple, posé sur eCampus avant d'être gardé par le portail) et ouverture de sa fiche sur eCampus.
 */

uses()->group('filament', 'ecampus');

/**
 * Faux eCampus : comptes présents et réponse au changement de mot de passe. Note chaque appel dans $this->appels.
 *
 * @param  list<array<string, mixed>>  $comptes
 * @param  list<array<string, mixed>>  $avertissements
 */
function fauxEcampusComptes(object $test, array $comptes, array $avertissements = []): void
{
    $test->appels = [];
    // Les comptes restent modifiables par le test : un second Http::fake ne remplacerait pas le premier.
    $test->comptesEcampus = $comptes;
    Http::fake(['ecampus.test/*' => function (Request $requete) use ($test, $avertissements) {
        $donnees = $requete->data();
        $test->appels[] = $donnees;

        return match ($donnees['wsfunction'] ?? '') {
            'core_user_get_users_by_field' => Http::response(array_values(array_filter(
                $test->comptesEcampus,
                fn (array $c): bool => in_array($c[$donnees['field']] ?? null, $donnees['values'], true),
            ))),
            'core_user_update_users' => Http::response(['warnings' => $avertissements]),
            default => Http::response(['exception' => 'moodle_exception', 'errorcode' => 'inconnu', 'message' => 'x']),
        };
    }]);
}

/** @return list<array<string, mixed>> */
function changementsDeMotDePasse(object $test): array
{
    return array_values(array_filter($test->appels, fn (array $a): bool => ($a['wsfunction'] ?? '') === 'core_user_update_users'));
}

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('pssfp.foad_url', 'https://ecampus.test');
    config()->set('pssfp.ecampus.jeton', 'jeton-de-test-ecampus');
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);

    $this->agent = fn (string $role): User => tap(
        User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']),
        fn (User $u) => $u->assignRole($role),
    );
    $this->admin = ($this->agent)('admin');
    $campagne = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2025-2026'], 'promotion_numero' => 13]);
    $c = Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'nom' => 'Essai', 'prenom' => 'Auditeur', 'statut' => Candidature::STATUT_ACCEPTE,
        'email' => 'auditeur-'.Str::random(6).'@example.org',
    ]);
    app(AttributionMatricules::class)->attribuer($campagne, '25', $this->admin, [$c->id]);
    $this->dossier = $c->fresh();
    $this->ancien = (string) $this->dossier->moodle_password;
    $this->compte = [
        'id' => 4242, 'username' => mb_strtolower((string) $this->dossier->matricule), 'idnumber' => (string) $this->dossier->matricule,
        'firstname' => 'Auditeur', 'lastname' => 'ESSAI', 'email' => 'autre@example.org', 'suspended' => false, 'lastaccess' => 1795000000,
    ];
});

describe('réinitialisation du mot de passe', function (): void {
    it('pose un mot de passe simple sur eCampus, puis le garde dans le portail, sans le journaliser', function (): void {
        fauxEcampusComptes($this, [$this->compte]);

        $nouveau = app(MotsDePasseEcampus::class)->reinitialiser($this->dossier, $this->admin);

        $envoi = changementsDeMotDePasse($this);
        expect($nouveau)->toMatch('/^(?=.*[a-z])(?=.*\d)[a-z2-9]{6}$/')
            ->and($nouveau)->not->toBe($this->ancien)
            ->and($envoi)->toHaveCount(1)
            ->and((int) $envoi[0]['users'][0]['id'])->toBe(4242)
            ->and($envoi[0]['users'][0]['password'])->toBe($nouveau)
            ->and($this->dossier->fresh()->moodle_password)->toBe($nouveau);

        $journal = Activity::query()->where('event', MotsDePasseEcampus::EVENEMENT)->sole();
        expect($journal->causer_id)->toBe($this->admin->id)
            ->and(json_encode($journal->properties))->not->toContain($nouveau);
    });

    it('reconnaît le compte par l\'e-mail quand la marque diffère (promotion antérieure)', function (): void {
        fauxEcampusComptes($this, [['idnumber' => 'ANCIEN-12', 'email' => mb_strtoupper((string) $this->dossier->email)] + $this->compte]);

        app(MotsDePasseEcampus::class)->reinitialiser($this->dossier, $this->admin);

        expect(changementsDeMotDePasse($this))->toHaveCount(1);
    });

    it('garde l\'ancien mot de passe quand eCampus refuse le changement', function (): void {
        fauxEcampusComptes($this, [$this->compte], [['item' => 'user', 'itemid' => 4242, 'warningcode' => 'usernotupdatedadmin', 'message' => 'x']]);

        expect(fn () => app(MotsDePasseEcampus::class)->reinitialiser($this->dossier, $this->admin))->toThrow(EchecEcampus::class);

        expect($this->dossier->fresh()->moodle_password)->toBe($this->ancien)
            ->and(Activity::query()->where('event', MotsDePasseEcampus::EVENEMENT)->count())->toBe(0);
    });

    it('ne touche à rien sans compte, ni sur un compte qui n\'est pas celui de l\'auditeur', function (array $comptes): void {
        fauxEcampusComptes($this, $comptes);

        expect(fn () => app(MotsDePasseEcampus::class)->reinitialiser($this->dossier, $this->admin))->toThrow(EchecEcampus::class);

        expect(changementsDeMotDePasse($this))->toBe([])
            ->and($this->dossier->fresh()->moodle_password)->toBe($this->ancien);
    })->with([
        'aucun compte' => fn () => [],
        'compte d\'un autre' => fn () => [['idnumber' => 'AUTRE', 'email' => 'autre@example.org'] + $this->compte],
    ]);
});

describe('fiche de l\'auditeur', function (): void {
    it('réserve les trois boutons à l\'administrateur', function (): void {
        fauxEcampusComptes($this, [$this->compte]);
        $page = fn () => $this->livewire(ViewAuditeur::class, ['record' => ficheDe($this->dossier)->getRouteKey()]);

        $this->actingAs($this->admin);
        $page()->assertActionVisible('verifierEcampus')->assertActionVisible('reinitialiserMotDePasseEcampus')->assertActionVisible('ouvrirEcampus');

        $this->actingAs(($this->agent)('scolarite_responsable'));
        $page()->assertActionHidden('verifierEcampus')->assertActionHidden('reinitialiserMotDePasseEcampus')->assertActionHidden('ouvrirEcampus');
    });

    it('montre le compte réel, puis réinitialise et affiche les nouveaux identifiants', function (): void {
        fauxEcampusComptes($this, [$this->compte]);
        $this->actingAs($this->admin);

        $page = $this->livewire(ViewAuditeur::class, ['record' => ficheDe($this->dossier)->getRouteKey()]);
        $page->mountAction('verifierEcampus')->assertSee($this->compte['username'])->assertSee('Actif');

        $page = $this->livewire(ViewAuditeur::class, ['record' => ficheDe($this->dossier)->getRouteKey()])
            ->callAction('reinitialiserMotDePasseEcampus');
        $nouveau = (string) $this->dossier->fresh()->moodle_password;
        expect($nouveau)->not->toBe($this->ancien);
        $page->assertActionMounted('identifiantsMoodle')->assertSee($nouveau);
    });

    it('ouvre la fiche eCampus du compte, ou revient à la fiche sans compte', function (): void {
        $url = route('filament.admin.auditeurs.ecampus', ['auditeur' => ficheDe($this->dossier)]);

        fauxEcampusComptes($this, [$this->compte]);
        $this->actingAs(($this->agent)('scolarite_responsable'))->get($url)->assertForbidden();
        $this->actingAs($this->admin)->get($url)->assertRedirect('https://ecampus.test/user/profile.php?id=4242');
        expect(Activity::query()->where('event', 'ecampus_compte_ouvert')->count())->toBe(1);

        $this->comptesEcampus = [];
        $this->actingAs($this->admin)->get($url)->assertRedirect(AuditeurResource::getUrl('view', ['record' => ficheDe($this->dossier)]));
        expect(Activity::query()->where('event', 'ecampus_compte_ouvert')->count())->toBe(1);
    });
});
