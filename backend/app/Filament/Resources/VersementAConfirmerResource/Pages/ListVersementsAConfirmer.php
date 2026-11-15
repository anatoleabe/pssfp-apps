<?php

declare(strict_types=1);

namespace App\Filament\Resources\VersementAConfirmerResource\Pages;

use App\Filament\Resources\VersementAConfirmerResource;
use Filament\Resources\Pages\ListRecords;

class ListVersementsAConfirmer extends ListRecords
{
    protected static string $resource = VersementAConfirmerResource::class;

    public function getSubheading(): ?string
    {
        return 'Confirmez chaque versement sur présentation du reçu bancaire original par l\'auditeur : scan du bordereau et attestation. '
            .'Tant qu\'il n\'est pas confirmé, il ne compte ni pour l\'accès à eCampus ni pour la situation « à jour ». Vous ne confirmez pas un versement que vous avez saisi.';
    }
}
