<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\Field;

/**
 * Messages de validation en français : l'application n'a pas de
 * lang/fr/validation.php, Filament afficherait sinon la clé brute.
 */
final class MessagesValidation
{
    private const MESSAGES = [
        'required' => 'Ce champ est obligatoire.',
        'accepted' => 'Cochez la case pour confirmer.',
        'integer' => 'Saisissez un nombre entier.',
        'numeric' => 'Saisissez un nombre.',
        'min' => 'Cette valeur est trop petite.',
        'max' => 'Cette valeur est trop longue ou trop grande.',
        'date' => 'Saisissez une date valide.',
        'before_or_equal' => 'La date ne peut pas être dans le futur.',
        'in' => 'Choisissez une valeur de la liste.',
        'regex' => 'Saisissez l\'année au format 2026-2027.',
        'unique' => 'Un barème existe déjà pour cette année et ce niveau.',
        'mimetypes' => 'Format accepté : PDF, JPG ou PNG.',
    ];

    /**
     * @param  list<Component>  $composants
     * @return list<Component>
     */
    public static function appliquer(array $composants): array
    {
        foreach ($composants as $composant) {
            if ($composant instanceof Field) {
                $composant->validationMessages(self::MESSAGES);
            }
            self::appliquer($composant->getChildComponents());
        }

        return $composants;
    }
}
