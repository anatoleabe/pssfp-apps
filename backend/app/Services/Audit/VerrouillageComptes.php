<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Filament\Resources\UserResource;
use App\Models\Message;
use App\Models\User;
use App\Services\Messagerie\Messagerie;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Auth\ResetPassword;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Lot 14d-2 (D30) : échecs de connexion consécutifs et verrouillage des comptes du personnel.
 *
 * Verrou progressif (revue de sécurité) : un tiers qui connaît l'adresse d'un administrateur ne doit pas pouvoir bloquer
 * l'administration pour de bon. Les deux premiers verrous d'une journée expirent seuls (15 min, puis 1 h) ; le troisième
 * attend un administrateur, et sa levée impose un nouveau mot de passe (le verrou a pu masquer une recherche du mot de passe).
 * Les candidats ont leur propre limite ; ils ne sont pas comptés ici.
 */
final class VerrouillageComptes
{
    public const CLE_SEUIL = 'securite.echecs_avant_verrouillage';

    public const SEUIL_DEFAUT = 5;

    public const SEUIL_MIN = 3;

    public const SEUIL_MAX = 20;

    /** Durées des verrous successifs sur 24 heures, en minutes ; au-delà, verrou manuel. */
    public const DUREES_MINUTES = [15, 60];

    public const PERMISSION_DEVERROUILLER = 'update_user';

    public const JOURNAL = 'connexions';

    public function __construct(private readonly Messagerie $messagerie) {}

    public function seuil(): int
    {
        return max(self::SEUIL_MIN, min(self::SEUIL_MAX, (int) AppSettings::get(self::CLE_SEUIL, self::SEUIL_DEFAUT)));
    }

    public static function estVerrouille(User $compte): bool
    {
        return $compte->verrouille_le !== null && ($compte->verrouille_jusqua === null || $compte->verrouille_jusqua->isFuture());
    }

    /** Comptes du personnel dont le verrou est en cours. */
    public static function verrouilles(): Builder
    {
        return Messagerie::personnel()->whereNotNull('verrouille_le')
            ->where(fn (Builder $q) => $q->whereNull('verrouille_jusqua')->orWhere('verrouille_jusqua', '>', now()));
    }

    /** Un échec de connexion : compté pour le personnel hors verrou ; au seuil, le compte est verrouillé (une seule fois). */
    public function echec(User $compte): void
    {
        if (! Messagerie::personnel()->whereKey($compte->id)->exists()) {
            return;
        }
        $this->leverSiExpire($compte);
        // Pas de comptage pendant un verrou : le compteur ne peut pas déborder, et le verrou ne se prolonge pas indéfiniment.
        User::query()->whereKey($compte->id)->whereNull('verrouille_le')->update(['echecs_connexion' => DB::raw('echecs_connexion + 1')]);

        $precedents = $this->verrousRecents($compte);
        $duree = self::DUREES_MINUTES[$precedents] ?? null;
        $motif = $this->seuil().' échecs de connexion consécutifs'.($duree === null ? ' (verrou manuel : '.($precedents + 1).'e verrou en 24 h)' : '');
        // Mise à jour conditionnelle : deux échecs simultanés au seuil ne verrouillent (et ne préviennent) qu'une fois.
        $verrouille = User::query()->whereKey($compte->id)->whereNull('verrouille_le')->where('echecs_connexion', '>=', $this->seuil())
            ->update(['verrouille_le' => now(), 'verrouille_jusqua' => $duree === null ? null : now()->addMinutes($duree), 'verrouille_motif' => $motif]);
        if ($verrouille === 0) {
            return;
        }
        $compte->refresh();
        activity(self::JOURNAL)->performedOn($compte)->event('compte_verrouille')
            ->withProperties(['motif' => $motif, 'jusqua' => $compte->verrouille_jusqua?->toIso8601String(), 'ip' => request()->ip()])
            ->log('Compte du personnel verrouillé');
        if ($duree === null) {
            $this->prevenirAdministrateurs($compte, $motif);
        }
    }

    /** Connexion réussie d'un compte non verrouillé : un verrou expiré est levé, le compteur repart de zéro. */
    public function succes(User $compte): void
    {
        $this->leverSiExpire($compte);
        if (! self::estVerrouille($compte) && $compte->echecs_connexion > 0) {
            User::query()->whereKey($compte->id)->update(['echecs_connexion' => 0]);
            $compte->echecs_connexion = 0;
        }
    }

    /** Verrou manuel posé par le service (aussi utilisé par les tests). */
    public function verrouiller(User $compte, string $motif): void
    {
        $compte->forceFill(['verrouille_le' => now(), 'verrouille_jusqua' => null, 'verrouille_motif' => mb_substr($motif, 0, 255)])->saveQuietly();
        activity(self::JOURNAL)->performedOn($compte)->event('compte_verrouille')
            ->withProperties(['motif' => $motif, 'jusqua' => null])->log('Compte du personnel verrouillé');
        $this->prevenirAdministrateurs($compte, $motif);
    }

    /**
     * Levée manuelle, par un titulaire de `update_user` (ou par l'exploitant du serveur, `$auteur` vide, en console).
     * Le mot de passe est remplacé et un lien de réinitialisation part au titulaire : les sessions ouvertes tombent, et un
     * mot de passe deviné pendant le verrou ne sert plus.
     */
    public function deverrouiller(User $compte, ?User $auteur, string $motif): void
    {
        abort_unless($auteur === null ? app()->runningInConsole() : $auteur->can(self::PERMISSION_DEVERROUILLER), 403);
        $motif = trim($motif);
        if (mb_strlen($motif) < 10) {
            throw new InvalidArgumentException('Le déverrouillage exige un motif (10 caractères au moins).');
        }
        if (! self::estVerrouille($compte)) {
            throw new InvalidArgumentException('Ce compte n\'est pas verrouillé.');
        }
        $compte->forceFill([
            'verrouille_le' => null, 'verrouille_jusqua' => null, 'verrouille_motif' => null, 'echecs_connexion' => 0,
            'password' => Hash::make(Str::random(64)), 'remember_token' => Str::random(60),
        ])->saveQuietly();
        $lien = $this->envoyerLienReinitialisation($compte);
        $journal = activity(self::JOURNAL)->performedOn($compte)->event('compte_deverrouille')
            ->withProperties(['motif' => $motif, 'lien_reinitialisation' => $lien, 'console' => $auteur === null]);
        if ($auteur !== null) {
            $journal->causedBy($auteur);
        }
        $journal->log('Compte du personnel déverrouillé ; nouveau mot de passe à choisir');
    }

    private function leverSiExpire(User $compte): void
    {
        if ($compte->verrouille_le !== null && $compte->verrouille_jusqua !== null && ! $compte->verrouille_jusqua->isFuture()) {
            User::query()->whereKey($compte->id)->whereNotNull('verrouille_jusqua')->where('verrouille_jusqua', '<=', now())
                ->update(['verrouille_le' => null, 'verrouille_jusqua' => null, 'verrouille_motif' => null, 'echecs_connexion' => 0]);
            $compte->refresh();
        }
    }

    private function verrousRecents(User $compte): int
    {
        return DB::table('activity_log')->where('log_name', self::JOURNAL)->where('event', 'compte_verrouille')
            ->where('subject_type', User::class)->where('subject_id', $compte->id)
            ->where('created_at', '>=', CarbonImmutable::now()->subDay())->count();
    }

    /** Lien de réinitialisation du panneau (même mécanisme que « Mot de passe oublié ») ; vrai s'il est parti. */
    private function envoyerLienReinitialisation(User $compte): bool
    {
        if (blank($compte->email)) {
            return false;
        }
        try {
            $panel = Filament::getPanel('admin');
            Filament::setCurrentPanel($panel);
            $statut = Password::broker($panel->getAuthPasswordBroker())->sendResetLink(['email' => $compte->email],
                function (User $u, string $jeton) use ($panel): void {
                    $notification = new ResetPassword($jeton);
                    $notification->url = $panel->getResetPasswordUrl($jeton, $u);
                    $u->notify($notification);
                });

            return $statut === Password::RESET_LINK_SENT;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function prevenirAdministrateurs(User $compte, string $motif): void
    {
        $admins = Messagerie::personnel()->whereHas('roles', fn (Builder $q) => $q->whereIn('name', ['admin', 'super_admin']))
            ->whereKeyNot($compte->id)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($admins === []) {
            return;
        }
        try {
            $this->messagerie->envoyer(null, $admins, 'Compte verrouillé : '.$compte->name,
                'Le compte de '.$compte->name.' est verrouillé jusqu\'à sa levée par un administrateur ('.$motif.').'."\n\n"
                .'Vérifiez d\'abord le journal des connexions : des échecs répétés depuis des adresses inconnues signalent une tentative d\'intrusion. '
                .'La levée, depuis Administration › Comptes, impose au titulaire de choisir un nouveau mot de passe (lien envoyé par courriel).'."\n\n"
                .'Comptes : '.UserResource::getUrl('index'),
                Message::NOTIFICATION, Message::HAUTE, ['email']);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
