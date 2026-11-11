<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Pages\Auth\Connexion;
use App\Http\Controllers\Admin\OuvrirEspaceCandidatController;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
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
            ->maxContentWidth(MaxWidth::Full)
            ->navigationGroups(array_map(
                static fn (string $groupe): NavigationGroup => NavigationGroup::make($groupe)->collapsible(),
                GroupeNavigation::ORDRE,
            ))
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
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
            ->authMiddleware([
                Authenticate::class,
            ])
            ->authenticatedRoutes(function (): void {
                Route::get('candidatures/{candidature}/espace', OuvrirEspaceCandidatController::class)
                    ->name('candidatures.espace');
            });
    }
}
