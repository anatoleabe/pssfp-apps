<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\Deliberation;
use App\Models\DocumentDelivre;
use App\Models\ResultatAnnuel;
use App\Models\ResultatScenario;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * PV de délibération et liste d'affichage en PDF (D35, D37 ; spec lot 16a). Avant l'arrêt, le PV
 * est un projet produit à la demande depuis le scénario retenu. À l'arrêt, il est archivé au
 * registre des documents délivrés avec son code de vérification : tout téléchargement sert ce
 * fichier, jamais régénéré.
 */
final class PvDeliberation
{
    public function __construct(private readonly Deliberations $deliberations, private readonly DocumentsOfficiels $documents) {}

    /** @throws InvalidArgumentException fichier archivé absent ou altéré */
    public function pv(Deliberation $d): string
    {
        return $d->statut === Deliberation::SIMULATION
            ? $this->rendre($d, null, null)
            : $this->documents->contenu($this->archiver($d, null));
    }

    /** Archive le PV d'une délibération arrêtée (rejouable) ; celui d'une délibération rectifiée est aussitôt révoqué. */
    public function archiver(Deliberation $d, ?User $auteur): DocumentDelivre
    {
        $document = $this->documents->archiverPv($d, fn (string $code, string $url): string => $this->rendre($d, $code, $url), $auteur);
        if ($d->statut === Deliberation::RECTIFIEE && ! $document->estRevoque()) {
            $this->documents->revoquer($document, 'Délibération rectifiée par le '.($d->rectification?->numero_pv ?? 'PV rectificatif'), null);
        }

        return $document;
    }

    private function rendre(Deliberation $d, ?string $code, ?string $url): string
    {
        $d->loadMissing(['jury', 'parcours', 'rectifiee']);
        $arretee = $d->statut !== Deliberation::SIMULATION;
        $resultats = $this->resultats($d, $arretee);
        $scenario = $d->scenarioRetenu;
        $mesures = $scenario?->mesures()->get() ?? collect();
        $participations = $d->participations()->with('membre')->get()
            ->sortBy(fn ($p): array => [array_search($p->membre->role, ['president', 'vice_president', 'rapporteur', 'membre'], true), $p->membre->rang])->values();

        return Pdf::loadView('pdf.lmd.pv-deliberation', [
            'd' => $d,
            'numero' => $d->numero_pv, 'code' => $code, 'url' => $url, 'edite_le' => now('Africa/Douala'),
            'qr' => $url === null ? null : 'data:image/svg+xml;base64,'.base64_encode((string) QrCode::format('svg')->size(110)->margin(0)->generate($url)),
            'resultats' => $resultats,
            'mesures' => $mesures,
            'participations' => $participations,
            'ecarts' => $d->rectifie_id !== null ? $this->deliberations->ecartsRectification($d) : ['favorables' => 0, 'defavorables' => 0],
            'synthese' => [
                'effectif' => $resultats->count(),
                'AD' => $resultats->where('decision', 'AD')->count(),
                'AS' => $resultats->where('decision', 'AS')->count(),
                'AJ' => $resultats->where('decision', 'AJ')->count(),
            ],
        ])->setPaper('a4', 'landscape')->output();
    }

    /** Liste d'affichage sans notes (ENF-12), seulement pour une délibération arrêtée. */
    public function affichage(Deliberation $d): string
    {
        $d->loadMissing('parcours');

        return Pdf::loadView('pdf.lmd.affichage-resultats', ['d' => $d, 'resultats' => $this->resultats($d, true)])
            ->setPaper('a4', 'portrait')->output();
    }

    /** @return Collection<int, ResultatAnnuel|ResultatScenario> */
    private function resultats(Deliberation $d, bool $arretee): Collection
    {
        $query = $arretee
            ? ResultatAnnuel::query()->where('deliberation_id', $d->id)
            : ResultatScenario::query()->whereIn('scenario_id', $d->scenarios()->where('retenu', true)->select('id'));

        return $query->with('inscriptionAnnuelle.auditeur.candidature')->get()
            ->sortBy(fn ($r): string => mb_strtoupper((string) $r->inscriptionAnnuelle?->auditeur?->candidature?->nom).' '.$r->inscriptionAnnuelle?->auditeur?->candidature?->prenom)
            ->values();
    }
}
