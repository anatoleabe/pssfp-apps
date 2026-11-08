<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuotaHoraireResource;

use App\Models\CalendrierAcademique;
use App\Models\EvenementCalendrier;
use App\Models\Seance;
use App\Models\User;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use InvalidArgumentException;

/** Éléments communs aux écrans de la programmation des enseignants (lot 12d). */
final class ChampsProgrammation
{
    /** Périodes de cours des calendriers signés. @return array<int, string> */
    public static function periodes(): array
    {
        return EvenementCalendrier::query()->where('type', 'periode_cours')->where('statut', '<>', 'annule')
            ->whereHas('calendrier', fn ($q) => $q->whereIn('statut', [CalendrierAcademique::SIGNE, CalendrierAcademique::PUBLIE]))
            ->orderBy('debut_prevu')->get()
            ->mapWithKeys(fn (EvenementCalendrier $e): array => [$e->id => self::periode($e)])->all();
    }

    public static function periode(EvenementCalendrier $e): string
    {
        return $e->libelle.' ('.$e->debut_prevu->format('d/m/Y').' – '.$e->fin_prevue->format('d/m/Y').')';
    }

    /** @return array<int, string> */
    public static function enseignants(): array
    {
        return User::role('enseignant')->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Heures de CM, TD et TP, au demi-point. @return list<Forms\Components\Component> */
    public static function heures(): array
    {
        $champ = fn (string $nom, string $libelle) => Forms\Components\TextInput::make($nom)->label($libelle)->numeric()->minValue(0)->maxValue(999)
            ->step(0.5)->default(0)->suffix('h');

        return [$champ('heures_cm', 'CM'), $champ('heures_td', 'TD'), $champ('heures_tp', 'TP')];
    }

    /** @return array<string, string> */
    public static function modalites(): array
    {
        return Seance::MODALITES;
    }

    /** Exécute une opération du service : notification de succès ; sur refus motivé, la modale reste ouverte avec la saisie. */
    public static function executer(callable $operation): void
    {
        try {
            $message = $operation();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Opération refusée')->body($e->getMessage())->persistent()->send();

            throw new Halt;
        }
        Notification::make()->success()->title((string) $message)->send();
    }

    public static function heuresLibelle(float $cm, float $td, float $tp): string
    {
        $f = fn (float $h): string => rtrim(rtrim(number_format($h, 1, ',', ''), '0'), ',');

        return 'CM '.$f($cm).' · TD '.$f($td).' · TP '.$f($tp).' = '.$f($cm + $td + $tp).' h';
    }
}
