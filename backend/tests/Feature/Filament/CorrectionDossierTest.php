<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Filament\Resources\CandidatureResource\Pages\ViewCandidature;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Services\CorrectionDossier\CorrectionDossier;
use App\Services\ExportsRetenus\AttributionMatricules;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Correction des informations d'un dossier décidé, auditeur compris, par l'administrateur (24/11/2026) : identité,
 * naissance, e-mail, résidence ; motif et journal ; compte de connexion et compte eCampus suivent.
 */

uses()->group('filament', 'candidatures');

/** Faux eCampus : comptes présents (modifiables par `$test->comptesEcampus`), réponse aux corrections. */
function fauxEcampusCorrection(object $test, array $comptes): void
{
    $test->comptesEcampus = $comptes;
    $test->corrections = [];
    Http::fake(['ecampus.test/*' => function (Request $r) use ($test) {
        $d = $r->data();
        if (($d['wsfunction'] ?? '') === 'core_user_update_users') {
            $test->corrections[] = array_map('strval', $d['users'][0]);

            return Http::response(['warnings' => []]);
        }

        return ($d['wsfunction'] ?? '') === 'core_user_get_users_by_field'
            ? Http::response(array_values(array_filter($test->comptesEcampus, fn (array $c): bool => in_array($c[$d['field']] ?? null, $d['values'], true))))
            : Http::response(['exception' => 'moodle_exception', 'errorcode' => 'inconnu']);
    }]);
}

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('pssfp.foad_url', 'https://ecampus.test');
    config()->set('pssfp.ecampus.jeton', 'jeton-de-test');
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->agent = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->admin = ($this->agent)('admin');

    $campagne = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    $c = Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'nom' => 'Essai', 'prenom' => 'Auditrice', 'statut' => Candidature::STATUT_ACCEPTE, 'email' => 'ancienne@example.org',
        'date_naissance' => '1990-01-01', 'lieu_naissance' => 'Yaoundé',
    ]);
    $compteCandidat = User::factory()->create(['name' => 'Auditrice Essai', 'date_naissance' => '1990-01-01']);
    $compteCandidat->assignRole('candidat');
    $c->forceFill(['user_id' => $compteCandidat->id])->save();
    app(AttributionMatricules::class)->attribuer($campagne, '26', $this->admin, [$c->id]);
    $this->dossier = $c->fresh();
    $this->compte = [
        'id' => 51, 'username' => mb_strtolower((string) $this->dossier->matricule), 'idnumber' => (string) $this->dossier->matricule,
        'firstname' => 'Auditrice', 'lastname' => 'ESSAI', 'email' => 'ancienne@example.org', 'suspended' => false, 'lastaccess' => 0,
    ];
    fauxEcampusCorrection($this, [$this->compte]);

    $this->donnees = fn (array $champs = []): array => $champs + [
        'nom' => 'Essai', 'prenom' => 'Auditrice', 'email' => 'ancienne@example.org', 'date_naissance' => '1990-01-01', 'lieu_naissance' => 'Yaoundé',
    ];
    $this->corriger = fn (array $champs, string $motif = 'Acte de naissance fourni', ?User $auteur = null): array => app(CorrectionDossier::class)
        ->corriger($this->dossier, ($this->donnees)($champs), $motif, $auteur ?? $this->admin);
});

it('corrige e-mail, date et lieu de naissance d\'un auditeur, journalise avant et après, et reporte sur eCampus', function (): void {
    $resultat = ($this->corriger)(['email' => 'Nouvelle@Example.org', 'date_naissance' => '1991-02-03', 'lieu_naissance' => 'Douala']);

    $c = $this->dossier->fresh();
    $journal = Activity::query()->where('event', CorrectionDossier::EVENEMENT)->sole();
    expect($c->email)->toBe('nouvelle@example.org')
        ->and($c->date_naissance->toDateString())->toBe('1991-02-03')
        ->and($c->lieu_naissance)->toBe('Douala')
        ->and($c->user->date_naissance->toDateString())->toBe('1991-02-03')
        ->and(array_keys($resultat['changements']))->toEqualCanonicalizing(['date_naissance', 'lieu_naissance', 'email'])
        ->and($journal->causer_id)->toBe($this->admin->id)
        ->and($journal->properties['motif'])->toBe('Acte de naissance fourni')
        ->and($journal->properties['changements']['email'])->toBe(['avant' => 'ancienne@example.org', 'apres' => 'nouvelle@example.org'])
        ->and($resultat['ecampus_ok'])->toBeTrue()
        ->and($this->corrections)->toBe([['id' => '51', 'email' => 'nouvelle@example.org']]);
});

it('fait suivre le nom affiché du compte de connexion et le nom sur eCampus', function (): void {
    ($this->corriger)(['nom' => 'Essaie', 'prenom' => 'Marie']);

    expect($this->dossier->fresh()->user->name)->toBe('Marie Essaie')
        ->and($this->corrections)->toBe([['id' => '51', 'firstname' => 'Marie', 'lastname' => 'ESSAIE']]);
});

it('réserve la correction à l\'administration', function (string $role, bool $autorise): void {
    $appel = fn () => ($this->corriger)(['lieu_naissance' => 'Bafoussam'], 'Motif', ($this->agent)($role));

    $autorise ? expect($appel)->not->toThrow(HttpException::class) : expect($appel)->toThrow(HttpException::class);
})->with([['admin', true], ['super_admin', true], ['scolarite_responsable', false], ['admission_committee', false]]);

it('refuse sans motif, sans changement, ou avec une valeur invalide', function (array $champs, string $motif, string $message): void {
    expect(fn () => ($this->corriger)($champs, $motif))->toThrow(InvalidArgumentException::class, $message);
    expect(Activity::query()->where('event', CorrectionDossier::EVENEMENT)->exists())->toBeFalse();
})->with([
    'sans motif' => [['lieu_naissance' => 'Douala'], '  ', 'motif'],
    'sans changement' => [[], 'Motif', 'Aucune information'],
    'e-mail invalide' => [['email' => 'pas-un-email'], 'Motif', 'e-mail'],
    'mineur' => [['date_naissance' => now()->subYears(10)->toDateString()], 'Motif', 'majeure'],
    'nom vide' => [['nom' => ' '], 'Motif', 'obligatoire'],
]);

it('signale l\'e-mail déjà pris sur eCampus et ne touche pas le compte', function (): void {
    $this->comptesEcampus[] = ['id' => 99, 'username' => 'autre', 'idnumber' => 'X', 'email' => 'prise@example.org'];

    $resultat = ($this->corriger)(['email' => 'prise@example.org']);

    expect($this->dossier->fresh()->email)->toBe('prise@example.org')
        ->and($resultat['ecampus_ok'])->toBeFalse()
        ->and($resultat['ecampus'])->toContain('déjà utilisé')
        ->and($this->corrections)->toBe([]);
});

it('ne touche pas un compte eCampus qui n\'est pas celui du dossier', function (): void {
    $this->comptesEcampus = [['idnumber' => 'XX', 'email' => 'x@example.org'] + $this->compte];

    $resultat = ($this->corriger)(['prenom' => 'Marie']);

    expect($this->dossier->fresh()->prenom)->toBe('Marie')
        ->and($resultat['ecampus_ok'])->toBeFalse()
        ->and($resultat['ecampus'])->toContain('laissé tel quel')
        ->and($this->corrections)->toBe([]);
});

it('n\'appelle pas eCampus pour un champ qu\'il ne connaît pas', function (): void {
    $resultat = ($this->corriger)(['lieu_naissance' => 'Kribi']);

    expect($resultat['ecampus'])->toBeNull()->and($this->corrections)->toBe([]);
});

it('corrige depuis la fiche auditeur en deux étapes, et cache l\'action au responsable de scolarité', function (): void {
    $this->actingAs($this->admin);
    $fiche = $this->dossier->auditeur;

    $this->livewire(ViewAuditeur::class, ['record' => $fiche->getRouteKey()])
        ->assertActionVisible('corrigerDossier')
        ->callAction('corrigerDossier', data: ['email' => 'fiche@example.org', 'lieu_naissance' => 'Garoua', 'motif' => 'Signalé par l\'auditrice'])
        ->assertHasNoActionErrors()
        ->assertNotified();
    expect($this->dossier->fresh()->email)->toBe('fiche@example.org')->and($this->dossier->fresh()->lieu_naissance)->toBe('Garoua');

    $this->livewire(ViewCandidature::class, ['record' => $this->dossier->getRouteKey()])->assertActionVisible('corrigerDossier');

    $this->actingAs(($this->agent)('scolarite_responsable'));
    $this->livewire(ViewAuditeur::class, ['record' => $fiche->getRouteKey()])->assertActionHidden('corrigerDossier');
});
