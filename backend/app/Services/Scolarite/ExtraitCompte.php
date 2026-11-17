<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;

/**
 * Extrait de compte d'un auditeur (17/11/2026) : ce qui est dû année par année, ses versements avec leur reçu et
 * leur statut, le total versé, l'échu impayé et le reste à payer sur tout le parcours, et sa solvabilité.
 * Document interne de l'administration (A4 portrait), édité depuis la fiche, journalisé.
 */
final class ExtraitCompte
{
    public const EVENEMENT = 'extrait_compte_edite';

    public const PALIERS = [
        Palier::INSCRIPTION => 'Droits d\'inscription',
        Palier::TRANCHE1 => '1re tranche',
        Palier::TRANCHE2 => '2e tranche',
    ];

    public function pdf(Auditeur $auditeur, User $auteur): string
    {
        $pdf = Pdf::loadView('pdf.scolarite.extrait-compte', $this->donnees($auditeur))->setPaper('a4', 'portrait');
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $police = $dompdf->getFontMetrics()->getFont('Source Sans 3', 'normal') ?: $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 28, 'Page {PAGE_NUM} / {PAGE_COUNT}', $police, 7, [0.37, 0.37, 0.37]);

        activity('scolarite')->causedBy($auteur)->performedOn($auditeur)->event(self::EVENEMENT)
            ->withProperties(['matricule' => $auditeur->candidature?->matricule])->log('Extrait de compte édité');

        return $pdf->output();
    }

    public function nomFichier(Auditeur $auditeur): string
    {
        return 'extrait-compte-'.mb_strtolower((string) ($auditeur->candidature?->matricule ?? $auditeur->uuid)).'.pdf';
    }

    /** Données de la vue (elle se rend aussi telle quelle dans les tests). @return array<string, mixed> */
    public function donnees(Auditeur $auditeur): array
    {
        $auditeur->loadMissing(['candidature.campagne']);
        $compte = CompteAuditeur::pour($auditeur);
        $versements = Versement::query()->where('auditeur_id', $auditeur->id)
            ->with(['recus.annulation', 'confirmation', 'correction'])
            ->orderBy('date_versement')->orderBy('id')->get();
        $verseValide = (int) $versements->whereNull('annule_at')->sum('montant');

        return [
            'auditeur' => $auditeur,
            'dossier' => $auditeur->candidature,
            'annees' => $compte->inscriptions->map(fn (InscriptionAnnuelle $i): array => $this->annee($i))->all(),
            'versements' => $versements->map(fn (Versement $v): array => [
                'date' => $v->date_versement,
                'reference' => $v->reference,
                'mode' => Versement::MODES[$v->mode] ?? Versement::libelleMode($v->mode),
                'payeur' => Versement::PAYEURS[$v->payeur] ?? $v->payeur,
                'montant' => (int) $v->montant,
                'statut' => self::statut($v),
                'recu' => ($v->recuEnVigueur() ?? $v->recus->sortByDesc('id')->first())?->numero,
            ])->all(),
            'totaux' => Solvabilite::classer($compte->inscriptions, $verseValide) + ['avance' => $compte->avance(), 'verse_valide' => $verseValide],
            'edite_le' => CarbonImmutable::now(InscriptionAnnuelle::FUSEAU),
            'logo' => self::logo(),
        ];
    }

    /** @return array<string, mixed> */
    private function annee(InscriptionAnnuelle $i): array
    {
        $s = $i->situation();

        return [
            'annee' => $i->annee_academique,
            'niveau' => BaremeScolarite::NIVEAUX[$i->niveau] ?? $i->niveau,
            'remise' => $s->remise,
            'du' => $s->duTotal,
            'verse' => $s->verse,
            'a_confirmer' => $s->aConfirmer,
            'reste' => $s->reste,
            'echu_impaye' => Solvabilite::echuImpaye($s),
            'paliers' => array_map(fn (Palier $p): array => [
                'libelle' => self::PALIERS[$p->cle] ?? $p->cle,
                'echeance' => $p->echeance,
                'du' => $p->du, 'paye' => $p->paye, 'reste' => $p->reste,
                'echu' => $p->reste > 0 && ($p->cle === Palier::INSCRIPTION || $p->enRetard),
            ], $s->paliers),
        ];
    }

    /** Statut du versement d'après les relations préchargées (même règle que Versement::statut(), sans requête). */
    private static function statut(Versement $v): string
    {
        return match (true) {
            $v->estAnnule() => $v->correction?->versement_remplacant_id !== null ? Versement::STATUT_REMPLACE : Versement::STATUT_ANNULE,
            $v->confirmation !== null => Versement::STATUT_CONFIRME,
            default => Versement::STATUT_ENREGISTRE,
        };
    }

    private static function logo(): ?string
    {
        $chemin = resource_path('images/pdf/logo.png');

        return is_file($chemin) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($chemin)) : null;
    }
}
