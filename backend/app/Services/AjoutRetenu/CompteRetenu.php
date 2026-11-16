<?php

declare(strict_types=1);

namespace App\Services\AjoutRetenu;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\Pays;
use App\Models\User;
use InvalidArgumentException;

/**
 * Règles du téléphone et du compte candidat d'un dossier créé depuis l'admin, communes à « Ajouter un retenu » et à
 * la réinscription d'un ancien depuis l'archive ACMA : un seul endroit, pour qu'un agent ne devienne jamais auditeur
 * et qu'un dossier ne soit jamais rattaché au compte d'une autre personne.
 */
final class CompteRetenu
{
    /** @throws InvalidArgumentException téléphone hors du format international */
    public static function telephone(string $saisie): string
    {
        $telephone = (string) preg_replace('/[\s.\-()]/', '', $saisie);
        if (preg_match('/^\+[1-9]\d{6,14}$/', $telephone) !== 1) {
            throw new InvalidArgumentException('Le téléphone doit être au format international, par exemple +237699000000.');
        }

        return $telephone;
    }

    /** @throws InvalidArgumentException téléphone déjà porté par un dossier de la campagne */
    public static function refuserTelephonePris(CampagneCandidature $campagne, string $telephone): void
    {
        // withTrashed : l'index unique (campagne, téléphone) couvre aussi les dossiers supprimés.
        $existant = Candidature::withTrashed()->forCampagne($campagne->id)->where('phone_e164', $telephone)->first();
        if ($existant !== null) {
            throw new InvalidArgumentException(
                "Ce téléphone figure déjà sur le dossier {$existant->numero_dossier}".($existant->trashed() ? ' (supprimé)' : '').' de cette campagne.'
            );
        }
    }

    /**
     * Compte candidat existant à ce téléphone, s'il peut porter le dossier.
     *
     * @throws InvalidArgumentException compte du personnel, autre date de naissance, ou dossier déjà dans la campagne
     */
    public static function compteExistant(CampagneCandidature $campagne, string $telephone, string $naissance): ?User
    {
        $compte = User::query()->with('roles')->where('phone_e164', $telephone)->first();
        if ($compte === null) {
            return null;
        }
        // Même règle que NouveauCodeCandidat::compteCandidat : un agent ne devient jamais auditeur.
        if ($compte->roles->pluck('name')->all() !== ['candidat']) {
            throw new InvalidArgumentException('Ce téléphone appartient à un compte du personnel : il ne peut pas servir à un auditeur.');
        }
        // Rattacher le dossier à la mauvaise personne lui ouvrirait un espace qui n'est pas le sien.
        if ($compte->date_naissance?->toDateString() !== $naissance) {
            throw new InvalidArgumentException('Ce téléphone appartient à un compte candidat dont la date de naissance diffère ou manque : vérifiez le numéro.');
        }
        $dossier = Candidature::withTrashed()->forCampagne($campagne->id)->where('user_id', $compte->id)->first();
        if ($dossier !== null) {
            throw new InvalidArgumentException("Le compte de ce téléphone porte déjà le dossier {$dossier->numero_dossier} de cette campagne.");
        }

        return $compte;
    }

    /**
     * Pays de l'indicatif. Plusieurs pays partagent parfois un indicatif (+1) :
     * la nationalité départage, sinon le préfixe le plus long l'emporte.
     */
    public static function paysDuTelephone(string $telephone, ?string $nationalite): string
    {
        $candidats = Pays::query()->whereNotNull('indicatif')->get(['code_iso', 'indicatif'])
            ->filter(fn (Pays $p): bool => str_starts_with($telephone, (string) $p->indicatif));

        return ($candidats->firstWhere('code_iso', $nationalite)
            ?? $candidats->sortByDesc(fn (Pays $p): int => strlen((string) $p->indicatif))->first())?->code_iso
            ?? 'CM';
    }
}
