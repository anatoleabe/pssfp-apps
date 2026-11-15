<?php

declare(strict_types=1);

namespace App\Filament\Resources\RecuVersementResource\Pages;

use App\Filament\Resources\AuditeurResource\RecuActions;
use App\Filament\Resources\RecuVersementResource;
use App\Services\Scolarite\RecusVersements;
use Filament\Resources\Pages\ListRecords;

class ListRecusVersements extends ListRecords
{
    protected static string $resource = RecuVersementResource::class;

    public function getSubheading(): ?string
    {
        return trim($this->attente().' Un reçu se délivre depuis l\'onglet Versements de la fiche de l\'auditeur. Il ne se modifie jamais : une erreur se corrige par une écriture d\'annulation.');
    }

    /**
     * Pour le service financier : d'où part la liste d'attente et ce qu'elle compte (bascule ACMA, spec
     * lot 4 §5). Après la bascule, un versement plus ancien a déjà son reçu ACMA.
     */
    private function attente(): string
    {
        $recus = app(RecusVersements::class);
        $jour = $recus->jourDebutAttente();
        if (! RecuActions::peutEmettre() || $jour === null) {
            return '';
        }
        $n = $recus->enAttente(auth()->user())->count();
        $ligne = 'Versements enregistrés depuis le '.$jour.' : '.($n === 0 ? 'aucun' : (string) $n).' en attente de reçu.';

        return $recus->bascule() === null ? $ligne : $ligne.' Un versement plus ancien reçoit son reçu à la demande de l\'auditeur, depuis sa fiche.';
    }
}
