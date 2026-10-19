<?php

declare(strict_types=1);

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Pages\Parametres;
use App\Filament\Resources\CampagneCandidatureResource;
use App\Filament\Resources\CandidatureResource;
use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\MotCleResource;
use App\Filament\Widgets\AvancementCampagneWidget;
use App\Filament\Widgets\LibraryOverview;
use App\Filament\Widgets\LibraryZeroResultSearches;
use App\Jobs\ProcessDocumentFile;
use App\Models\Role;
use App\Models\User;
use App\Support\ModuleAccess;
use App\Support\ModulePermissionMap;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\PermissionRegistrar;

/*
 * Cloisonnement du back-office par module : un rôle n'ouvre que les écrans
 * des modules sur lesquels il porte au moins un droit, en plus des Policies.
 *
 * Les écrans sont lus dans l'enregistrement réel du panneau : un écran ajouté
 * demain sans déclaration de module fait échouer ce fichier.
 */
uses()->group('filament', 'roles', 'module-isolation');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Bus::fake([ProcessDocumentFile::class]);
    $this->seed([RolePermissionSeeder::class]);
});

/**
 * Rôles créés par le seeder. Un test vérifie que cette liste suit le seeder :
 * un rôle ajouté sans entrée ici fait échouer la suite.
 *
 * @return list<string>
 */
function isolationSeededRoles(): array
{
    return [
        'super_admin', 'admin', 'editor', 'admission_committee', 'receptionniste',
        'librarian', 'library_manager', 'library_contributor', 'library_viewer', 'caisse',
        'teacher', 'auditor', 'candidat',
    ];
}

function isolationUser(string ...$roles): User
{
    $user = User::factory()->create(['email' => implode('-', $roles).'-'.uniqid().'@isolation.pssfp.local']);
    $user->assignRole($roles);

    return $user->fresh();
}

/**
 * Écrans du panneau `admin`, tels que Filament les a enregistrés.
 *
 * @return array{resource: list<class-string>, page: list<class-string>, widget: list<class-string>}
 */
function isolationScreens(): array
{
    $panel = Filament::getPanel('admin');

    return [
        'resource' => array_values($panel->getResources()),
        'page' => array_values($panel->getPages()),
        'widget' => array_values(array_map(
            fn (string|WidgetConfiguration $widget): string => $widget instanceof WidgetConfiguration ? $widget->widget : $widget,
            $panel->getWidgets(),
        )),
    ];
}

/** @return list<class-string> */
function isolationAllScreens(): array
{
    return array_merge(...array_values(isolationScreens()));
}

/** L'écran s'ouvre-t-il à l'utilisateur connecté (navigation, URL, widget) ? */
function isolationScreenOpens(string $screen): bool
{
    return is_subclass_of($screen, Widget::class) ? $screen::canView() : $screen::canAccess();
}

/**
 * Modules ouverts à l'utilisateur, déduits de la carte des modules —
 * indépendamment de ModuleAccess, pour ne pas tester une implémentation
 * contre elle-même : lecture, gestion ou action autonome. Une action
 * sensible ordinaire n'ouvre pas le module à elle seule.
 *
 * @return list<string>
 */
function isolationGrantedModules(User $user): array
{
    $permissions = $user->getAllPermissions()->pluck('name')->all();

    return collect(ModulePermissionMap::modules())
        ->filter(fn (array $module): bool => array_intersect(
            [...$module['read'], ...$module['manage'], ...($module['standalone'] ?? [])],
            $permissions,
        ) !== [])
        ->keys()
        ->all();
}

/**
 * La méthode d'accès de l'écran applique-t-elle le contrôle par module ?
 * Soit c'est l'implémentation du trait (RestrictedToModule), soit une
 * redéfinition dont le code appelle moduleAllows().
 */
function isolationMethodChecksModule(string $screen, string $method): bool
{
    $reflection = new ReflectionMethod($screen, $method);
    $file = (string) $reflection->getFileName();

    if (str_ends_with($file, 'app/Filament/Concerns/RestrictedToModule.php')) {
        return true;
    }

    if (! str_starts_with($reflection->getDeclaringClass()->getName(), 'App\\')) {
        return false;
    }

    $lines = array_slice(file($file), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1);

    return str_contains(implode('', $lines), 'moduleAllows()');
}

it('suit la liste des rôles du seeder', function (): void {
    expect(Role::query()->pluck('name')->sort()->values()->all())
        ->toBe(collect(isolationSeededRoles())->sort()->values()->all());
});

it('déclare un module connu pour chaque écran enregistré dans le panneau', function (): void {
    $known = [...array_keys(ModulePermissionMap::modules()), ModuleAccess::CORE];
    $screens = isolationAllScreens();

    expect($screens)->not->toBeEmpty();

    foreach ($screens as $screen) {
        $module = ModuleAccess::moduleOf($screen);

        expect($module)->not->toBeNull("{$screen} ne déclare pas son module (#[BelongsToModule(...)])")
            ->and(in_array($module, $known, true))->toBeTrue("{$screen} déclare un module inconnu : {$module}");

        if (str_starts_with($screen, 'App\\')) {
            $method = is_subclass_of($screen, Widget::class) ? 'canView' : 'canAccess';

            expect(in_array(ChecksModuleAccess::class, class_uses_recursive($screen), true))
                ->toBeTrue("{$screen} n'applique pas le contrôle d'accès par module")
                ->and(isolationMethodChecksModule($screen, $method))
                ->toBeTrue("{$screen}::{$method}() ne passe pas par moduleAllows()");
        } else {
            // Écran fourni par Filament : seul le socle commun est admis.
            expect($module)->toBe(ModuleAccess::CORE, "{$screen} (Filament) doit rester dans le socle commun");
        }
    }
});

it('ferme chaque écran d\'un module à qui n\'y a ni lecture, ni gestion, ni action autonome', function (): void {
    $admin = Role::findByName('admin', 'web');
    $user = isolationUser('admin');

    foreach (array_keys(ModulePermissionMap::modules()) as $moduleKey) {
        // Le rôle `admin` figure dans toutes les listes de rôles des widgets et
        // garde les actions sensibles ordinaires du module : seul le contrôle
        // par module peut encore fermer l'écran.
        $admin->syncPermissions(array_values(array_diff(
            ModulePermissionMap::allPermissions(),
            ModulePermissionMap::accessPermissions($moduleKey),
        )));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($user = $user->fresh());

        $screens = array_filter(isolationAllScreens(), fn (string $screen): bool => ModuleAccess::moduleOf($screen) === $moduleKey);

        foreach ($screens as $screen) {
            expect(isolationScreenOpens($screen))->toBeFalse("{$screen} reste ouvert sans aucun droit sur « {$moduleKey} »");
        }
    }
});

it('n\'ouvre à un rôle pris seul que les écrans des modules qu\'il donne', function (string $roleName): void {
    $user = isolationUser($roleName);
    $panel = Filament::getPanel('admin');
    $dashboardUrl = Dashboard::getUrl(panel: 'admin');

    if (! $user->canAccessPanel($panel)) {
        expect(['teacher', 'auditor', 'candidat'])->toContain($roleName);
        $this->actingAs($user)->get($dashboardUrl)->assertForbidden();

        return;
    }

    $this->actingAs($user);
    $granted = isolationGrantedModules($user);
    $sidebar = (string) $this->get($dashboardUrl)->assertOk()->getContent();
    $screens = isolationScreens();

    foreach ($screens['resource'] as $resource) {
        $module = ModuleAccess::moduleOf($resource);
        $open = ($module === ModuleAccess::CORE || in_array($module, $granted, true)) && $resource::canViewAny();
        $url = $resource::getUrl('index');

        $this->get($url)->assertStatus($open ? 200 : 403);
        expect(str_contains($sidebar, 'href="'.e($url).'"'))
            ->toBe($open && $resource::shouldRegisterNavigation(), "{$roleName} : lien de navigation vers {$resource}");
    }

    foreach ($screens['page'] as $page) {
        $module = ModuleAccess::moduleOf($page);

        if ($module !== ModuleAccess::CORE && ! in_array($module, $granted, true)) {
            $this->get($page::getUrl())->assertForbidden();
            expect(str_contains($sidebar, 'href="'.e($page::getUrl()).'"'))->toBeFalse("{$roleName} : lien vers {$page}");
        }
    }

    foreach ($screens['widget'] as $widget) {
        $module = ModuleAccess::moduleOf($widget);

        if ($module !== ModuleAccess::CORE && ! in_array($module, $granted, true)) {
            expect($widget::canView())->toBeFalse("{$roleName} voit le widget {$widget}");
        }
    }
})->with(isolationSeededRoles());

/**
 * @return array{resources: list<class-string>, widgets: list<class-string>, pages: list<class-string>}
 */
function isolationVisibleScreens(): array
{
    $screens = isolationScreens();

    return [
        'resources' => array_values(array_filter($screens['resource'], fn (string $r): bool => $r::canAccess())),
        'widgets' => array_values(array_filter($screens['widget'], fn (string $w): bool => $w::canView() && ModuleAccess::moduleOf($w) !== ModuleAccess::CORE)),
        'pages' => array_values(array_filter($screens['page'], fn (string $p): bool => $p::canAccess())),
    ];
}

it('cantonne chaque rôle de la bibliothèque à la bibliothèque', function (string $roleName, array $resources, array $widgets): void {
    $this->actingAs(isolationUser($roleName));

    $visible = isolationVisibleScreens();

    expect($visible['resources'])->toEqualCanonicalizing($resources)
        ->and($visible['widgets'])->toEqualCanonicalizing($widgets)
        ->and($visible['pages'])->toBe([Dashboard::class])
        ->and(Parametres::canAccess())->toBeFalse();

    $this->get(Dashboard::getUrl(panel: 'admin'))->assertOk();
    $this->get(CandidatureResource::getUrl('index'))->assertForbidden();
    $this->get(Parametres::getUrl())->assertForbidden();
})->with([
    'responsable' => ['library_manager', [DocumentResource::class, MotCleResource::class], [LibraryOverview::class, LibraryZeroResultSearches::class]],
    'bibliothécaire' => ['librarian', [DocumentResource::class, MotCleResource::class], [LibraryOverview::class, LibraryZeroResultSearches::class]],
    'consultation' => ['library_viewer', [DocumentResource::class, MotCleResource::class], [LibraryOverview::class, LibraryZeroResultSearches::class]],
    // Le contributeur n'ouvre que l'écran Documents (limité à ses dépôts) :
    // ni thésaurus, ni widgets du catalogue.
    'contributeur' => ['library_contributor', [DocumentResource::class], []],
]);

it('additionne les modules quand les rôles se cumulent', function (): void {
    $user = isolationUser('librarian', 'caisse');
    $this->actingAs($user);

    $visible = isolationVisibleScreens();

    expect($visible['resources'])->toEqualCanonicalizing([
        DocumentResource::class, MotCleResource::class,
        CandidatureResource::class, CampagneCandidatureResource::class,
    ])
        ->and($visible['widgets'])->toContain(LibraryOverview::class)->toContain(AvancementCampagneWidget::class)
        ->and($user->can('candidature.mark_paid'))->toBeTrue()
        // Admissions en lecture : consulter, pas modifier.
        ->and($user->can('update_candidature'))->toBeFalse();

    $this->get(CandidatureResource::getUrl('index'))->assertOk();
    $this->get(DocumentResource::getUrl('index'))->assertOk();
    $this->get(Parametres::getUrl())->assertForbidden();
});

it('ouvre la caisse sur les admissions seulement', function (): void {
    $this->actingAs(isolationUser('caisse'));

    expect(isolationVisibleScreens()['resources'])->toEqualCanonicalizing([
        CandidatureResource::class, CampagneCandidatureResource::class,
    ])
        ->and(AvancementCampagneWidget::canView())->toBeTrue()
        ->and(LibraryOverview::canView())->toBeFalse();
});

it('tranche l\'accès à un module sur les droits réels', function (): void {
    $contributor = isolationUser('library_contributor');
    $editor = isolationUser('editor');

    expect(ModuleAccess::allows($contributor, 'bibliotheque'))->toBeTrue()
        ->and(ModuleAccess::allows($contributor, 'admissions'))->toBeFalse()
        ->and(ModuleAccess::allows($editor, 'site'))->toBeTrue()
        ->and(ModuleAccess::allows($editor, 'bibliotheque'))->toBeFalse()
        ->and(ModuleAccess::allows($editor, ModuleAccess::CORE))->toBeTrue()
        ->and(ModuleAccess::allows($editor, 'module_inconnu'))->toBeFalse()
        ->and(ModuleAccess::allows(null, ModuleAccess::CORE))->toBeFalse()
        ->and(ModuleAccess::accessibleModules($contributor))->toBe(['bibliotheque'])
        ->and(ModuleAccess::moduleOf(DocumentResource::class))->toBe('bibliotheque')
        ->and(ModuleAccess::moduleOf(stdClass::class))->toBeNull();
});

it('n\'ouvre pas un module sur une simple action sensible', function (): void {
    $role = Role::create(['name' => 'export_seul', 'label' => 'Export seul', 'guard_name' => 'web']);
    $role->givePermissionTo('candidature.export_csv');
    $user = isolationUser('export_seul');
    $this->actingAs($user);

    $admissions = array_filter(isolationAllScreens(), fn (string $screen): bool => ModuleAccess::moduleOf($screen) === 'admissions');

    expect(ModuleAccess::allows($user, 'admissions'))->toBeFalse()
        ->and(ModuleAccess::accessibleModules($user))->toBe([])
        ->and($admissions)->not->toBeEmpty();

    foreach ($admissions as $screen) {
        expect(isolationScreenOpens($screen))->toBeFalse("{$screen} s'ouvre sur une action sensible seule");
    }
});

it('ne montre aucun écran des admissions à un bibliothécaire doté d\'un export', function (): void {
    $librarian = isolationUser('librarian');
    $librarian->givePermissionTo('candidature.export_csv');
    $this->actingAs($librarian = $librarian->fresh());

    expect(AvancementCampagneWidget::canView())->toBeFalse()
        ->and(CandidatureResource::canAccess())->toBeFalse()
        ->and(ModuleAccess::allows($librarian, 'bibliotheque'))->toBeTrue();
});
