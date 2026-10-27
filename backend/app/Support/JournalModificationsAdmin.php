<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Candidature;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Journalise toute création, modification, suppression ou restauration faite
 * par un membre du personnel connecté au back-office, quel que soit l'écran.
 *
 * On note QUELS champs ont changé, jamais leurs valeurs : le journal ne doit
 * pas devenir une seconde copie des données personnelles des candidats, ni
 * des secrets (mots de passe, jetons). Les écrans qui tracent déjà leurs
 * propres actions (décisions, envois…) gardent leur ligne détaillée ; celle-ci
 * vient en complément.
 *
 * Hors périmètre : les actions des candidats (API, journal `auth.candidat`)
 * et les traitements en ligne de commande.
 */
final class JournalModificationsAdmin
{
    /** Modèles techniques ou déjà journalisés par ailleurs. */
    private const IGNORES = [
        Activity::class,
        PersonalAccessToken::class,
    ];

    /** Champs techniques, sans intérêt pour savoir « qui a fait quoi ». */
    private const CHAMPS_IGNORES = ['updated_at', 'created_at', 'remember_token'];

    public static function enregistrer(): void
    {
        foreach (['created' => 'création', 'updated' => 'modification', 'deleted' => 'suppression', 'restored' => 'restauration'] as $evenement => $libelle) {
            Event::listen("eloquent.{$evenement}: *", function (string $nom, array $donnees) use ($evenement, $libelle): void {
                $modele = $donnees[0] ?? null;
                if ($modele instanceof Model) {
                    self::journaliser($modele, $evenement, $libelle);
                }
            });
        }
    }

    private static function journaliser(Model $modele, string $evenement, string $libelle): void
    {
        $auteur = auth('web')->user();
        if (! $auteur instanceof User || app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }
        if (in_array($modele::class, self::IGNORES, true) || in_array(LogsActivity::class, class_uses_recursive($modele), true)) {
            return;
        }

        $champs = $evenement === 'updated'
            ? array_values(array_diff(array_keys($modele->getChanges()), self::CHAMPS_IGNORES))
            : [];
        if ($evenement === 'updated' && $champs === []) {
            return;
        }

        $type = class_basename($modele);
        activity('admin')->causedBy($auteur)->performedOn($modele)
            ->event($evenement)
            ->withProperties(array_filter([
                'element' => self::libelle($modele),
                'champs' => $champs,
            ]))
            ->log(Str::ucfirst($libelle).' — '.$type);
    }

    /** Repère lisible de l'élément, sans donnée sensible. */
    private static function libelle(Model $modele): ?string
    {
        if ($modele instanceof Candidature) {
            return $modele->numero_dossier;
        }

        foreach (['slug', 'name', 'nom', 'title', 'titre'] as $attribut) {
            $valeur = $modele->getAttribute($attribut);
            if (is_string($valeur) && $valeur !== '') {
                return Str::limit($valeur, 120);
            }
            if (is_array($valeur) && is_string($valeur['fr'] ?? null)) {
                return Str::limit($valeur['fr'], 120);
            }
        }

        return null;
    }
}
