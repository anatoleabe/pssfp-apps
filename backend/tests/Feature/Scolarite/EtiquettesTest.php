<?php

declare(strict_types=1);

use App\Models\Auditeur;
use App\Models\Etiquette;
use App\Models\Role;
use App\Models\User;
use App\Services\Scolarite\Etiquettes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('scolarite');

beforeEach(function (): void {
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->agent = ($this->compte)('scolarite_agent');
    $this->etiquettes = app(Etiquettes::class);
    [$this->a, $this->b, $this->c] = Auditeur::factory()->campagneCommune()->count(3)->create()->all();
});

it('crée une étiquette au nom nettoyé, avec sa couleur et son auteur, et la journalise', function (): void {
    $etiquette = $this->etiquettes->creer('  Président   de l\'amicale ', 'warning', $this->agent);

    $journal = Activity::query()->where('event', 'etiquette_creee')->sole();
    expect($etiquette->nom)->toBe('Président de l\'amicale')
        ->and($etiquette->couleur)->toBe('warning')
        ->and($etiquette->cree_par)->toBe($this->agent->id)
        ->and($journal->log_name)->toBe('scolarite')
        ->and($journal->causer_id)->toBe($this->agent->id)
        ->and($journal->subject_id)->toBe($etiquette->id)
        ->and($journal->description)->toContain('Président de l\'amicale');
});

it('refuse un nom vide, trop long, une couleur inconnue ou un nom déjà pris (casse et accents compris)', function (string $nom, string $couleur, string $message): void {
    $this->etiquettes->creer('Campus B', 'info', $this->agent);
    $this->etiquettes->creer('Élu', 'gray', $this->agent);

    expect(fn () => $this->etiquettes->creer($nom, $couleur, $this->agent))->toThrow(InvalidArgumentException::class, $message);
    expect(Etiquette::query()->count())->toBe(2);
})->with([
    'vide' => ['   ', 'gray', 'nom'],
    'trop long' => [str_repeat('a', 61), 'gray', '60'],
    'couleur inconnue' => ['Campus A', 'fuchsia', 'couleur'],
    'même nom en minuscules' => ['campus b', 'gray', 'existe déjà'],
    'même nom avec espaces' => [' CAMPUS   B ', 'gray', 'existe déjà'],
    'accent en majuscule' => ['ÉLU', 'gray', 'existe déjà'],
    'accent en minuscule' => ['élu', 'gray', 'existe déjà'],
]);

it('garantit en base l\'unicité insensible à la casse', function (): void {
    Etiquette::factory()->create(['nom' => 'Élu']);

    expect(fn () => Etiquette::factory()->create(['nom' => 'éLU']))->toThrow(QueryException::class);
});

it('refuse en base une couleur hors liste', function (): void {
    expect(fn () => Etiquette::factory()->create(['couleur' => 'fuchsia']))->toThrow(QueryException::class);
});

it('pose une étiquette sur un groupe, sans doublon, et journalise chaque fiche', function (): void {
    $campus = Etiquette::factory()->create(['nom' => 'Campus B']);
    $this->etiquettes->poser(collect([$this->a]), $campus, $this->agent);

    $posees = $this->etiquettes->poser(collect([$this->a, $this->b]), $campus, $this->agent);

    expect($posees)->toBe(1)
        ->and($campus->auditeurs()->pluck('auditeurs.id')->sort()->values()->all())->toBe([$this->a->id, $this->b->id])
        ->and($campus->auditeurs()->whereKey($this->b->id)->first()->pivot->ajoute_par)->toBe($this->agent->id);
    $journal = Activity::query()->where('event', 'etiquette_posee')->get();
    expect($journal)->toHaveCount(2)
        ->and($journal->pluck('subject_id')->sort()->values()->all())->toBe([$this->a->id, $this->b->id])
        ->and($journal->first()->description)->toBe('Étiquette posée : Campus B');
});

it('retire une étiquette d\'un groupe, et ne journalise que les retraits réels', function (): void {
    $campus = Etiquette::factory()->create(['nom' => 'Campus B']);
    $this->etiquettes->poser(collect([$this->a, $this->b]), $campus, $this->agent);

    $retirees = $this->etiquettes->retirer(collect([$this->a, $this->c]), $campus, $this->agent);

    expect($retirees)->toBe(1)
        ->and($campus->auditeurs()->pluck('auditeurs.id')->all())->toBe([$this->b->id])
        ->and(Activity::query()->where('event', 'etiquette_retiree')->pluck('subject_id')->all())->toBe([$this->a->id]);
});

it('fixe les étiquettes d\'une fiche : pose les nouvelles, retire les absentes, ignore les inconnues', function (): void {
    $campus = Etiquette::factory()->create(['nom' => 'Campus B']);
    $amicale = Etiquette::factory()->create(['nom' => 'Amicale']);
    $delegue = Etiquette::factory()->create(['nom' => 'Délégué']);
    $this->etiquettes->poser(collect([$this->a]), $campus, $this->agent);
    $this->etiquettes->poser(collect([$this->a]), $amicale, $this->agent);

    $bilan = $this->etiquettes->definir($this->a, [$amicale->id, $delegue->id, 999999], $this->agent);

    expect($bilan)->toBe(['posees' => 1, 'retirees' => 1])
        ->and($this->a->etiquettes()->pluck('nom')->all())->toBe(['Amicale', 'Délégué'])
        ->and(Activity::query()->where('event', 'etiquette_retiree')->sole()->description)->toBe('Étiquette retirée : Campus B');
});

it('renomme et recolore une étiquette, sans heurter un autre nom', function (): void {
    $campus = Etiquette::factory()->create(['nom' => 'Campus B', 'couleur' => 'gray']);
    Etiquette::factory()->create(['nom' => 'Amicale']);

    $this->etiquettes->modifier($campus, 'campus b', 'success', $this->agent);
    expect($campus->fresh()->only(['nom', 'couleur']))->toBe(['nom' => 'campus b', 'couleur' => 'success'])
        ->and(Activity::query()->where('event', 'etiquette_modifiee')->sole()->properties['ancien']['nom'])->toBe('Campus B');

    expect(fn () => $this->etiquettes->modifier($campus, 'AMICALE', 'success', $this->agent))->toThrow(InvalidArgumentException::class, 'existe déjà');
});

it('supprime une étiquette, la retire des auditeurs et journalise combien la portaient', function (): void {
    $campus = Etiquette::factory()->create(['nom' => 'Campus B']);
    $this->etiquettes->poser(collect([$this->a, $this->b]), $campus, $this->agent);

    $porteurs = $this->etiquettes->supprimer($campus, $this->agent);

    $journal = Activity::query()->where('event', 'etiquette_supprimee')->sole();
    expect($porteurs)->toBe(2)
        ->and(Etiquette::query()->count())->toBe(0)
        ->and($this->a->etiquettes()->count())->toBe(0)
        ->and(Auditeur::query()->count())->toBe(3)
        ->and($journal->properties['nom'])->toBe('Campus B')
        ->and($journal->properties['auditeurs'])->toBe(2);
});

it('refuse chaque geste à qui n\'a pas la permission', function (Closure $geste): void {
    $financier = ($this->compte)('service_financier');
    $etiquette = Etiquette::factory()->create();

    expect(fn () => $geste($this->etiquettes, $financier, $etiquette, $this->a))->toThrow(HttpException::class);
})->with([
    'créer' => [fn (Etiquettes $s, User $u) => $s->creer('Campus B', 'gray', $u)],
    'modifier' => [fn (Etiquettes $s, User $u, Etiquette $e) => $s->modifier($e, 'Autre', 'gray', $u)],
    'supprimer' => [fn (Etiquettes $s, User $u, Etiquette $e) => $s->supprimer($e, $u)],
    'poser' => [fn (Etiquettes $s, User $u, Etiquette $e, Auditeur $a) => $s->poser(collect([$a]), $e, $u)],
    'retirer' => [fn (Etiquettes $s, User $u, Etiquette $e, Auditeur $a) => $s->retirer(collect([$a]), $e, $u)],
    'définir' => [fn (Etiquettes $s, User $u, Etiquette $e, Auditeur $a) => $s->definir($a, [$e->id], $u)],
]);

it('donne la permission aux rôles de scolarité et à l\'administration sur une base neuve', function (string $role, bool $attendu): void {
    expect(Role::findByName($role, 'web')->hasPermissionTo(Etiquettes::PERMISSION))->toBe($attendu);
})->with([
    ['scolarite_agent', true],
    ['scolarite_responsable', true],
    ['admin', true],
    ['super_admin', true],
    ['service_financier', false],
    ['admission_committee', false],
]);

it('accorde la permission par migration additive, sans toucher aux autres droits', function (): void {
    $existante = Permission::findOrCreate('view_any_auditeur', 'web');
    Permission::query()->where('name', Etiquettes::PERMISSION)->delete();
    foreach (['scolarite_agent', 'scolarite_responsable', 'admin', 'super_admin', 'service_financier'] as $nom) {
        Role::findByName($nom, 'web')->syncPermissions([$existante]);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $migration = require database_path('migrations/2026_11_30_100100_grant_scolarite_etiquettes_permission.php');

    $migration->up();
    $migration->up();

    foreach (['scolarite_agent', 'scolarite_responsable', 'admin', 'super_admin'] as $nom) {
        expect(Role::findByName($nom, 'web')->permissions->pluck('name')->sort()->values()->all())
            ->toBe([Etiquettes::PERMISSION, 'view_any_auditeur']);
    }
    expect(Role::findByName('service_financier', 'web')->permissions->pluck('name')->all())->toBe(['view_any_auditeur']);

    $migration->down();
    expect(Permission::query()->where('name', Etiquettes::PERMISSION)->exists())->toBeFalse();
});

it('crée et retire proprement les tables des étiquettes', function (): void {
    $migration = require database_path('migrations/2026_11_30_100000_create_etiquettes_tables.php');

    $migration->down();
    expect(Schema::hasTable('etiquettes'))->toBeFalse()->and(Schema::hasTable('auditeur_etiquette'))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable('etiquettes'))->toBeTrue()->and(Schema::hasTable('auditeur_etiquette'))->toBeTrue();
});
