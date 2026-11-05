<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\Versement;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Scolarite\Palier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Situation de l'auditeur telle qu'il la voit dans son espace : aucun
 * identifiant interne, ni agent, ni ligne annulée, ni mot de passe (ce dernier
 * ne sort que par POST /me/acces/reveler).
 *
 * @property Candidature $resource
 */
final class ScolariteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $c = $this->resource;
        $inscription = AccesEcampus::inscriptionCourante($c);
        $s = $inscription?->situation();
        $etat = AccesEcampus::etat($c);

        return [
            'matricule' => $c->matricule,
            'identifiant' => IdentifiantsMoodle::identifiant($c),
            'promotion_numero' => $c->campagne?->promotion_numero,
            'annee_academique' => $inscription?->annee_academique,
            'niveau' => $inscription?->niveau,
            'du_total' => $s?->duTotal,
            'remise' => $s?->remise,
            'verse' => $s?->verse ?? 0,
            'reste' => $s?->reste,
            'trop_percu' => $s?->tropPercu ?? 0,
            'en_retard' => $s?->enRetard() ?? false,
            'paliers' => $s === null ? [] : array_map(fn (Palier $p): array => [
                'cle' => $p->cle,
                'du' => $p->du,
                'paye' => $p->paye,
                'reste' => $p->reste,
                'solde' => $p->solde(),
                'en_retard' => $p->enRetard,
                'echeance' => $p->echeance?->toDateString(),
                'echeance_texte' => $p->cle === Palier::INSCRIPTION ? $inscription?->bareme?->inscription_echeance : null,
            ], $s->paliers),
            'versements' => $inscription === null ? [] : self::versements($inscription),
            'acces' => array_filter([
                'etat' => $etat,
                'foad_url' => $etat === AccesEcampus::DISPONIBLE ? (string) config('pssfp.foad_url') : null,
                'reste_inscription' => $etat === AccesEcampus::BLOQUE ? ($s?->resteInscription()) : null,
            ], fn ($v): bool => $v !== null),
        ];
    }

    /** @return list<array{date: string, montant: int, mode: string, reference: string}> */
    private static function versements(InscriptionAnnuelle $inscription): array
    {
        return $inscription->versementsValides()->orderByDesc('date_versement')->orderByDesc('id')->get()
            ->map(fn (Versement $v): array => [
                'date' => $v->date_versement->toDateString(),
                'montant' => $v->montant,
                'mode' => $v->mode,
                'reference' => $v->reference,
            ])->all();
    }
}
