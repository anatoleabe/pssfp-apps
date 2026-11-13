<?php

declare(strict_types=1);

namespace App\Services\Erreurs;

use App\Models\ErreurTechnique;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Enregistre les erreurs techniques pour l'administration.
 *
 * Règle absolue : signaler une erreur ne doit jamais en provoquer une autre.
 * Toute défaillance du journal (table absente, base injoignable) est avalée
 * et notée dans le journal fichier. L'écriture se fait dans un point de
 * sauvegarde : elle ne peut pas interrompre la transaction de l'appelant.
 */
final class JournalErreurs
{
    private const MESSAGE_MAX = 1000;

    public function depuisException(Throwable $e): void
    {
        $this->enregistrer(function () use ($e): array {
            $requete = $this->requeteHttp();

            return [
                'source' => $this->source($requete),
                'type' => Str::limit(class_basename($e), 150, ''),
                'message' => $this->message($e),
                'emplacement' => Str::limit(Str::after($e->getFile(), base_path().'/').':'.$e->getLine(), 300, ''),
                'methode' => $requete?->method(),
                'page' => $requete !== null ? $this->page($requete) : null,
                'requete' => $this->identifiantRequete(),
                'navigateur' => $requete !== null ? Str::limit((string) $requete->userAgent(), 300, '') : null,
                'ip' => $requete?->ip(),
                'user_id' => $requete !== null ? $this->utilisateur($requete)?->id : null,
            ];
        });
    }

    /**
     * Écran d'erreur affiché par le portail apply, relayé par son serveur.
     *
     * @param  array{page: string, message: string, digest?: string|null, navigateur?: string|null}  $signalement
     */
    public function depuisPortail(array $signalement, Request $requete): void
    {
        $this->enregistrer(fn (): array => [
            'source' => ErreurTechnique::SOURCE_APPLY,
            'type' => 'Écran d\'erreur',
            'message' => Str::limit($signalement['message'], self::MESSAGE_MAX, ''),
            'page' => Str::limit(Str::before($signalement['page'], '?'), 500, ''),
            'digest' => $signalement['digest'] ?? null,
            'requete' => $this->identifiantRequete(),
            'navigateur' => $signalement['navigateur'] ?? null,
            'ip' => $requete->ip(),
            'user_id' => $this->utilisateur($requete)?->id,
        ]);
    }

    /** @param  callable(): array<string, mixed>  $attributs */
    private function enregistrer(callable $attributs): void
    {
        try {
            $donnees = $attributs();
            DB::transaction(fn () => ErreurTechnique::query()->create($donnees));
        } catch (Throwable $panne) {
            try {
                Log::warning('Journal des erreurs techniques indisponible.', ['cause' => class_basename($panne)]);
            } catch (Throwable) {
                // Même le journal fichier est hors service : rien de plus à faire.
            }
        }
    }

    /** Requête HTTP réelle en cours, ou null (file d'attente, tâche planifiée, commande). */
    private function requeteHttp(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }
        $requete = app('request');

        return $requete instanceof Request && $requete->route() !== null ? $requete : null;
    }

    private function source(?Request $requete): string
    {
        if ($requete === null) {
            return ErreurTechnique::SOURCE_TACHES;
        }

        return $requete->is('admin', 'admin/*', 'livewire/*') ? ErreurTechnique::SOURCE_ADMIN : ErreurTechnique::SOURCE_API;
    }

    /** Chemin sans paramètres (un code d'accès peut y figurer) ; pour Livewire, la page d'origine. */
    private function page(Request $requete): string
    {
        $origine = $requete->is('livewire/*') ? parse_url((string) $requete->headers->get('referer', ''), PHP_URL_PATH) : null;

        return Str::limit(is_string($origine) && $origine !== '' ? $origine : '/'.ltrim($requete->path(), '/'), 500, '');
    }

    /** Message sans la requête SQL ni ses valeurs : une erreur de base peut contenir des données personnelles. */
    private function message(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            $cause = $e->getPrevious()?->getMessage() ?? 'SQLSTATE['.$e->getCode().']';

            return Str::limit(trim(Str::before($cause, 'DETAIL')), self::MESSAGE_MAX, '');
        }
        $message = trim($e->getMessage());

        return Str::limit($message !== '' ? $message : class_basename($e), self::MESSAGE_MAX, '');
    }

    private function utilisateur(Request $requete): ?User
    {
        try {
            $utilisateur = $requete->user() ?? $requete->user('sanctum');

            return $utilisateur instanceof User ? $utilisateur : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function identifiantRequete(): ?string
    {
        $identifiant = Context::get('request_id');

        return is_string($identifiant) ? Str::limit($identifiant, 64, '') : null;
    }
}
