<?php

declare(strict_types=1);

use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\BaremeScolariteResource;
use App\Filament\Resources\MaquetteResource;
use App\Filament\Resources\SalleResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Illuminate\Support\Str;

uses()->group('filament');

/**
 * Écrans du panneau qui apparaissent dans le menu (le tableau de bord, hors
 * groupe, est exclu).
 *
 * @return list<class-string>
 */
function ecransDuMenu(): array
{
    $panel = Filament::getPanel('admin');

    return array_values(array_filter(
        [...$panel->getResources(), ...$panel->getPages()],
        static fn (string $ecran): bool => $ecran::getNavigationGroup() !== null,
    ));
}

it('range chaque écran dans un groupe métier connu', function (): void {
    $horsListe = array_filter(
        ecransDuMenu(),
        static fn (string $ecran): bool => ! in_array($ecran::getNavigationGroup(), GroupeNavigation::ORDRE, true),
    );

    expect($horsListe)->toBe([]);
});

it('affiche les groupes dans l\'ordre métier', function (): void {
    $groupes = array_map(
        static fn (NavigationGroup $groupe): string => (string) $groupe->getLabel(),
        array_values(Filament::getPanel('admin')->getNavigationGroups()),
    );

    expect($groupes)->toBe(GroupeNavigation::ORDRE);
});

it('ne laisse aucun groupe vide', function (): void {
    $utilises = array_unique(array_map(
        static fn (string $ecran): string => $ecran::getNavigationGroup(),
        ecransDuMenu(),
    ));

    expect(array_values(array_diff(GroupeNavigation::ORDRE, $utilises)))->toBe([]);
});

it('sert le thème de la charte sur l\'écran de connexion', function (): void {
    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('--pssfp-prune: #4A2E67', false)
        ->assertSee('family=source-sans-3', false)
        ->assertSee('images/pssfp-logo.png', false)
        ->assertDontSee('family=inter', false);
});

it('neutralise la molette et les flèches sur les champs numériques du panneau', function (): void {
    // Un montant saisi à 250 000 a été enregistré à 249 999 : le champ numérique du navigateur
    // retire une unité d'un coup de molette ou de flèche bas.
    $this->get('/admin/login')
        ->assertOk()
        ->assertSee("cible.type === 'number'", false)
        ->assertSee("addEventListener('wheel'", false)
        ->assertSee("evenement.key === 'ArrowDown'", false);
});

describe('menu par rôle (lot 33)', function (): void {
    beforeEach(function (): void {
        config()->set('pssfp.filament.require_2fa', false);
        $this->compte = fn (string ...$roles): User => tap(User::factory()->create(['email' => 'nav-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($roles));
    });

    it('range le paramétrage dans « Réglages », en bas et replié, et met l\'accueil en tête', function (): void {
        expect(SalleResource::getNavigationGroup())->toBe(GroupeNavigation::REGLAGES)
            ->and(MaquetteResource::getNavigationGroup())->toBe(GroupeNavigation::REGLAGES)
            ->and(BaremeScolariteResource::getNavigationGroup())->toBe(GroupeNavigation::REGLAGES)
            ->and(GroupeNavigation::ORDRE)->not->toContain('Référentiel pédagogique')
            ->and(GroupeNavigation::ORDRE[array_key_last(GroupeNavigation::ORDRE)])->toBe(GroupeNavigation::REGLAGES);

        $this->get(Dashboard::getUrl())->assertRedirect();
        $this->actingAs(($this->compte)('scolarite_agent'))->get(Dashboard::getUrl())->assertOk()
            ->assertSeeInOrder(['Accueil', 'Messagerie', 'Scolarité', 'Auditeurs', 'Planification', 'Emplois du temps', 'Réglages', 'Salles'])
            ->assertDontSee('Référentiel pédagogique')
            ->assertSee('Prises en charge')->assertDontSee('Prises En Charge');

        $replie = collect(Filament::getPanel('admin')->getNavigationGroups())->first(fn (NavigationGroup $g): bool => $g->getLabel() === GroupeNavigation::REGLAGES);
        expect($replie?->isCollapsed())->toBeTrue();
    });

    it('met Finances en tête pour le service financier, pas pour la scolarité', function (): void {
        expect(GroupeNavigation::ordrePour(($this->compte)('service_financier'))[0])->toBe(GroupeNavigation::FINANCES)
            ->and(GroupeNavigation::ordrePour(($this->compte)('scolarite_responsable'))[0])->toBe(GroupeNavigation::ADMISSIONS);

        $this->actingAs(($this->compte)('service_financier'))->get(Dashboard::getUrl())->assertOk()
            ->assertSeeInOrder(['Accueil', 'Finances', 'Synthèse', 'Versements', 'Scolarité', 'Auditeurs']);
    });
});
