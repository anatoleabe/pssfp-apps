<?php

declare(strict_types=1);

namespace App\Services\Ecampus;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;
use Throwable;

/**
 * Appels aux services web de Moodle (eCampus).
 *
 * Le jeton et le mot de passe partent uniquement dans le corps d'un POST HTTPS,
 * jamais dans l'adresse. Aucune exception d'origine n'est relayée ni journalisée
 * avec son message : seule sa classe est notée.
 */
final class ClientMoodle
{
    private const MEMBRE_DEJA_PRESENT = 'record already exists';

    /** Refus qui valent pour tous les dossiers : jeton, adresse IP, droits, maintenance. */
    private const EXCEPTIONS_GENERALES = ['webservice_access_exception', 'required_capability_exception'];

    private const CODES_GENERAUX = ['invalidtoken', 'sitemaintenance', 'nopermissions', 'accessexception'];

    /**
     * @param  list<string>  $valeurs
     * @return list<array<string, mixed>>
     */
    public function comptesParChamp(string $champ, array $valeurs): array
    {
        return array_values($this->appeler('core_user_get_users_by_field', ['field' => $champ, 'values' => $valeurs]));
    }

    /** @param  array<string, string>  $compte  doit contenir le mot de passe initial */
    public function creerCompte(#[SensitiveParameter] array $compte): void
    {
        $reponse = $this->appeler('core_user_create_users', ['users' => [$compte]]);

        if (($reponse[0]['username'] ?? null) !== $compte['username'] || ! isset($reponse[0]['id'])) {
            throw new EchecEcampus('eCampus n\'a pas confirmé la création du compte.');
        }
    }

    public function ajouterACohorte(string $cohorte, string $identifiant): void
    {
        $reponse = $this->appeler('core_cohort_add_cohort_members', ['members' => [[
            'cohorttype' => ['type' => 'idnumber', 'value' => $cohorte],
            'usertype' => ['type' => 'username', 'value' => $identifiant],
        ]]]);

        foreach ($reponse['warnings'] ?? [] as $avertissement) {
            if (! str_contains((string) ($avertissement['message'] ?? ''), self::MEMBRE_DEJA_PRESENT)) {
                throw new EchecEcampus("Ajout à la cohorte {$cohorte} refusé par eCampus.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $parametres
     * @return array<int|string, mixed>
     */
    private function appeler(string $fonction, #[SensitiveParameter] array $parametres): array
    {
        $base = (string) config('pssfp.foad_url');
        if (! str_starts_with($base, 'https://')) {
            throw new EchecEcampus('L\'adresse d\'eCampus doit être en HTTPS.', general: true);
        }

        try {
            $reponse = Http::asForm()
                ->withoutRedirecting()
                ->connectTimeout(3)
                ->timeout((int) config('pssfp.ecampus.delai_secondes', 8))
                ->post($base.'/webservice/rest/server.php', [
                    'wstoken' => (string) config('pssfp.ecampus.jeton'),
                    'wsfunction' => $fonction,
                    'moodlewsrestformat' => 'json',
                ] + $parametres);
        } catch (Throwable $e) {
            Log::warning('eCampus injoignable.', ['fonction' => $fonction, 'erreur' => $e::class]);

            throw new EchecEcampus(ComptesEcampus::DETAIL_INJOIGNABLE, general: true);
        }

        return $this->lire($reponse, $fonction);
    }

    /** @return array<int|string, mixed> */
    private function lire(Response $reponse, string $fonction): array
    {
        if ($reponse->status() !== 200) {
            Log::warning('eCampus a répondu autrement que 200.', ['fonction' => $fonction, 'http' => $reponse->status()]);

            throw new EchecEcampus(ComptesEcampus::DETAIL_INJOIGNABLE, general: true);
        }

        $contenu = $reponse->json();
        if (is_array($contenu) && isset($contenu['exception'])) {
            // Seul le code est gardé : le texte de Moodle peut reprendre les valeurs envoyées.
            $code = mb_substr(strip_tags((string) ($contenu['errorcode'] ?? 'inconnu')), 0, 80);
            Log::warning('eCampus a refusé un appel.', ['fonction' => $fonction, 'code' => $code]);

            throw new EchecEcampus("Refus d'eCampus ({$code}).", general: in_array($contenu['exception'], self::EXCEPTIONS_GENERALES, true)
                || in_array($code, self::CODES_GENERAUX, true));
        }

        // Toutes ces fonctions répondent par un tableau : autre chose vient d'un
        // intermédiaire ou d'une panne, et ne doit pas passer pour un succès.
        if (! is_array($contenu)) {
            Log::warning('eCampus a répondu de façon illisible.', ['fonction' => $fonction]);

            throw new EchecEcampus(ComptesEcampus::DETAIL_INJOIGNABLE, general: true);
        }

        return $contenu;
    }
}
