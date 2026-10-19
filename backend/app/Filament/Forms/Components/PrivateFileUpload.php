<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\FileUpload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Champ fichier pour un disque privé (PDF de la bibliothèque, pièces…).
 *
 * Par défaut, Filament fait confiance aux chemins présents dans l'état du
 * champ : il signe une URL pour n'importe quelle chaîne (méthode Livewire
 * publique `getFormUploadedFiles`) et l'enregistre telle quelle. Un compte du
 * panneau pouvait donc forger le chemin d'un autre fichier du bucket et
 * obtenir son URL signée, ou l'attacher à sa propre fiche.
 *
 * Ici, une chaîne n'est admise que si c'est le fichier **déjà enregistré**
 * sur la fiche (valeur d'origine de la colonne) ; tout autre fichier doit
 * être un nouveau dépôt (TemporaryUploadedFile).
 *
 * La même garde sert sur un disque public (couverture personnalisée d'un
 * document) : appeler `->visibility('public')` après `make()`.
 */
class PrivateFileUpload extends FileUpload
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->visibility('private');

        $signStoredFile = $this->getUploadedFileUsing;

        $this->getUploadedFileUsing(static function (PrivateFileUpload $component, string $file, string|array|null $storedFileNames) use ($signStoredFile): ?array {
            if ($signStoredFile === null || ! $component->isStoredFile($file)) {
                return null;
            }

            return $component->evaluate($signStoredFile, ['file' => $file, 'storedFileNames' => $storedFileNames]);
        });
    }

    /**
     * Fichiers enregistrés sur la fiche, tels qu'en base (avant modification).
     *
     * @return list<string>
     */
    public function getStoredFiles(): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Model || ! $record->exists) {
            return [];
        }

        return array_values(array_filter(
            Arr::wrap($record->getOriginal($this->getName())),
            static fn (mixed $file): bool => is_string($file) && $file !== '',
        ));
    }

    public function isStoredFile(string $file): bool
    {
        return in_array($file, $this->getStoredFiles(), true);
    }

    /** @return array<mixed> */
    public function getValidationRules(): array
    {
        return [
            ...parent::getValidationRules(),
            function (string $attribute, mixed $value, Closure $fail): void {
                foreach (Arr::wrap($value) as $file) {
                    if ($file instanceof TemporaryUploadedFile) {
                        continue;
                    }

                    if (! is_string($file) || ! $this->isStoredFile($file)) {
                        $fail('Fichier non reconnu : déposez à nouveau le fichier.');

                        return;
                    }
                }
            },
        ];
    }
}
