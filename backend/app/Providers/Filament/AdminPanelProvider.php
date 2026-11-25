<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\GlobalSearch\RechercheGlobale;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Pages\Auth\Connexion;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MaSecurite;
use App\Http\Controllers\Admin\ExtraitCompteController;
use App\Http\Controllers\Admin\ImprimerRecuController;
use App\Http\Controllers\Admin\OuvrirCompteEcampusController;
use App\Http\Controllers\Admin\OuvrirEspaceCandidatController;
use App\Http\Middleware\ExigerDoubleAuthentification;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

final class AdminPanelProvider extends PanelProvider
{
    /**
     * Rubriques du menu (lot 33). Filament fixe l'ordre à la déclaration et n'en change plus : on déclare donc des
     * emplacements, dont le libellé est calculé pour le compte connecté (Finances en tête pour le service financier).
     * « Réglages » est repliée par défaut.
     *
     * @return list<NavigationGroup>
     */
    private static function groupes(): array
    {
        $libelle = static fn (int $rang): string => GroupeNavigation::ordrePour(self::compte())[$rang];

        return array_map(static fn (int $rang): NavigationGroup => NavigationGroup::make(static fn (): string => $libelle($rang))
            ->collapsible()
            ->collapsed(static fn (): bool => $libelle($rang) === GroupeNavigation::REGLAGES), array_keys(GroupeNavigation::ORDRE));
    }

    private static function compte(): ?User
    {
        $u = auth()->user();

        return $u instanceof User ? $u : null;
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('PSSFP — Administration')
            ->brandLogo(asset('images/pssfp-logo.png'))
            ->brandLogoHeight('2.75rem')
            ->favicon(asset('favicon.ico'))
            ->login(Connexion::class)
            ->passwordReset()
            ->emailVerification()
            ->profile()
            // Charte ADR-0008. La prune institutionnelle (#4A2E67) est la
            // teinte 600, celle des boutons et liens ; 700 est la prune
            // profonde (#3A2452). L'or champagne reste un accent (thème),
            // pas la couleur d'alerte : « warning » garde un ambre lisible.
            ->colors([
                'primary' => [
                    50 => '246, 242, 249',
                    100 => '237, 229, 243',
                    200 => '218, 203, 230',
                    300 => '192, 168, 211',
                    400 => '156, 125, 184',
                    500 => '110, 77, 145',
                    600 => '74, 46, 103',
                    700 => '58, 36, 82',
                    800 => '46, 28, 66',
                    900 => '36, 22, 52',
                    950 => '23, 13, 34',
                ],
                'gray' => Color::Stone,
                'info' => Color::hex('#0F3A4A'),
                'warning' => Color::Amber,
            ])
            ->font('Source Sans 3', url: 'https://fonts.bunny.net/css?family=source-sans-3:400,500,600,700&display=swap')
            ->renderHook(PanelsRenderHook::STYLES_AFTER, static fn (): View => view('filament.theme.charte'))
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, static fn (): View => view('filament.messagerie.enveloppe'))
            ->renderHook(PanelsRenderHook::BODY_END, static fn (): View => view('filament.saisie.champs-nombre'))
            ->maxContentWidth(MaxWidth::Full)
            ->navigationGroups(self::groupes())
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Persistant : Livewire rejoue l'obligation de double authentification sur ses échanges (revue de sécurité 14d-4).
            ->authMiddleware([
                Authenticate::class,
                ExigerDoubleAuthentification::class,
            ], isPersistent: true)
            ->globalSearch(RechercheGlobale::class)
            // Lot 32 : la recherche unique s'ouvre aussi au clavier, partout dans le panneau.
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->userMenuItems([
                'ma-securite' => MenuItem::make()->label('Ma sécurité')->icon('heroicon-o-key')
                    ->url(fn (): string => MaSecurite::getUrl()),
            ])
            ->authenticatedRoutes(function (): void {
                Route::get('candidatures/{candidature}/espace', OuvrirEspaceCandidatController::class)
                    ->name('candidatures.espace');
                Route::get('recus/{recu}/imprimer', ImprimerRecuController::class)
                    ->name('recus.imprimer');
                Route::get('auditeurs/{auditeur}/extrait-compte', ExtraitCompteController::class)
                    ->name('auditeurs.extrait-compte');
                Route::get('auditeurs/{auditeur}/ecampus', OuvrirCompteEcampusController::class)
                    ->name('auditeurs.ecampus');
            });
    }
}
