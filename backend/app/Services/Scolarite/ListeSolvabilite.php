<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Listes des auditeurs en règle, partiellement et totalement insolvables (17/11/2026), d'après `Solvabilite` :
 * tout le parcours, versements à confirmer comptés. Auditeurs inscrits seulement ; périmètre : promotion, niveau
 * de l'inscription courante, statut, dossiers retirés. Trois requêtes, quelle que soit la taille de la liste.
 */
final class ListeSolvabilite
{
    public const PERMISSION_EXPORT = RegistreVersements::PERMISSION_EXPORT;

    public const EVENEMENT = 'liste_solvabilite_editee';

    /** Lignes au plus dans un PDF (DomPDF : ~0,5 Mo par ligne, sous le memory_limit de PHP-FPM). Réglable par `scolarite.liste_pdf_max`. */
    public const PDF_MAX = 250;

    public static function plafondPdf(): int
    {
        return max(1, (int) config('scolarite.liste_pdf_max', self::PDF_MAX));
    }

    public const TITRES = [
        Solvabilite::EN_REGLE => 'Auditeurs en règle',
        Solvabilite::PARTIELLE => 'Auditeurs partiellement insolvables',
        Solvabilite::TOTALE => 'Auditeurs totalement insolvables',
    ];

    public const ENTETES = ['Matricule', 'Nom', 'Prénom', 'Promotion', 'Niveau', 'Statut', 'Dû', 'Versé', 'Dont à confirmer', 'Échu impayé', 'Reste à payer', 'Situation'];

    /**
     * @param  array{campagne_id?: mixed, niveau?: mixed, statut?: mixed, inclure_retires?: mixed}  $criteres
     * @return Collection<int, array<string, mixed>> par nom, prénom
     */
    public function lignes(array $criteres): Collection
    {
        $niveau = filled($criteres['niveau'] ?? null) ? (string) $criteres['niveau'] : null;
        if ($niveau !== null && ! array_key_exists($niveau, BaremeScolarite::NIVEAUX)) {
            throw new InvalidArgumentException('Niveau inconnu.');
        }

        return Auditeur::query()
            ->whereHas('inscriptions')
            ->when(filled($criteres['campagne_id'] ?? null), fn (Builder $q) => $q->whereHas('candidature', fn (Builder $c) => $c->where('campagne_id', (int) $criteres['campagne_id'])))
            ->when(! (bool) ($criteres['inclure_retires'] ?? false), fn (Builder $q) => $q->whereHas('candidature', fn (Builder $c) => $c->whereNull('withdrawn_at')))
            ->when(filled($criteres['statut'] ?? null), fn (Builder $q) => $q->where('statut', (string) $criteres['statut']))
            ->with([
                'candidature:id,campagne_id,nom,prenom,matricule,withdrawn_at', 'candidature.campagne:id,nom,promotion_numero',
                'inscriptions' => fn ($q) => $q->with('bareme')->withSum('imputationsActives', 'montant')->withSum('imputationsConfirmees', 'montant')->orderBy('annee_academique'),
            ])
            ->withSum(['versements as versements_valides_sum_montant' => fn (Builder $v) => $v->whereNull('annule_at')], 'montant')
            ->get()
            ->filter(fn (Auditeur $a): bool => $niveau === null || $a->inscriptions->last()?->niveau === $niveau)
            ->map(fn (Auditeur $a): array => $this->ligne($a))
            ->sortBy(fn (array $l): string => mb_strtoupper($l['nom'].' '.$l['prenom']))
            ->values();
    }

    /** @param Collection<int, array<string, mixed>> $lignes @return array<string, array{effectif: int, du: int, verse: int, a_confirmer: int, echu_impaye: int, reste: int}> */
    public static function totaux(Collection $lignes): array
    {
        $totaux = [];
        foreach (array_keys(Solvabilite::LIBELLES) as $categorie) {
            $groupe = $lignes->where('categorie', $categorie);
            $totaux[$categorie] = ['effectif' => $groupe->count()] + array_combine(
                $cles = ['du', 'verse', 'a_confirmer', 'echu_impaye', 'reste'],
                array_map(fn (string $k): int => (int) $groupe->sum($k), $cles),
            );
        }

        return $totaux;
    }

    /** @param  array<string, mixed>  $criteres */
    public function pdf(array $criteres, string $categorie, User $auteur): string
    {
        $this->exiger($categorie, $auteur);
        $lignes = $this->lignes($criteres)->where('categorie', $categorie)->values();
        if ($lignes->count() > self::plafondPdf()) {
            throw new InvalidArgumentException($lignes->count().' auditeurs : le PDF en prend '.self::plafondPdf().' au plus. Précisez la promotion ou le niveau, ou exportez en Excel.');
        }
        $html = view('pdf.scolarite.liste-solvabilite', [
            'titre' => self::TITRES[$categorie],
            'criteres' => self::libelles($criteres),
            'lignes' => $lignes,
            'total' => self::totaux($lignes)[$categorie],
            'edite_le' => CarbonImmutable::now(InscriptionAnnuelle::FUSEAU),
            'logo' => self::logo(),
        ])->render();
        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'landscape');
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $police = $dompdf->getFontMetrics()->getFont('Source Sans 3', 'normal') ?: $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 28, 'Page {PAGE_NUM} / {PAGE_COUNT}', $police, 7, [0.37, 0.37, 0.37]);
        $this->journaliser($criteres, $categorie, 'pdf', $lignes->count(), $auteur);

        return $pdf->output();
    }

    /** @param  array<string, mixed>  $criteres */
    public function excel(array $criteres, string $categorie, User $auteur): string
    {
        $this->exiger($categorie, $auteur);
        $lignes = $this->lignes($criteres)->where('categorie', $categorie)->values();
        if ($lignes->count() > VersementsExcel::plafond()) {
            throw new InvalidArgumentException('Plus de '.VersementsExcel::plafond().' auditeurs : précisez la promotion ou le niveau.');
        }
        $classeur = new Spreadsheet;
        $feuille = $classeur->getActiveSheet()->setTitle('Solvabilité');
        $feuille->fromArray(self::ENTETES, null, 'A1');
        foreach ($lignes as $n => $l) {
            $rang = $n + 2;
            // Texte explicite : un matricule n'est jamais pris pour une formule ou un nombre.
            $feuille->setCellValueExplicit('A'.$rang, (string) $l['matricule'], DataType::TYPE_STRING);
            foreach (['B' => 'nom', 'C' => 'prenom', 'D' => 'promotion', 'E' => 'niveau', 'F' => 'statut'] as $col => $k) {
                $feuille->setCellValueExplicit($col.$rang, (string) $l[$k], DataType::TYPE_STRING);
            }
            foreach (['G' => 'du', 'H' => 'verse', 'I' => 'a_confirmer', 'J' => 'echu_impaye', 'K' => 'reste'] as $col => $k) {
                $feuille->setCellValue($col.$rang, (int) $l[$k]);
            }
            $feuille->setCellValueExplicit('L'.$rang, Solvabilite::LIBELLES[$l['categorie']], DataType::TYPE_STRING);
        }
        $feuille->getStyle('A1:L1')->getFont()->setBold(true);
        $feuille->getStyle('G2:K'.max(2, $lignes->count() + 1))->getNumberFormat()->setFormatCode('#,##0');
        foreach (range('A', 'L') as $col) {
            $feuille->getColumnDimension($col)->setAutoSize(true);
        }
        ob_start();
        (new Xlsx($classeur))->save('php://output');
        $this->journaliser($criteres, $categorie, 'excel', $lignes->count(), $auteur);

        return (string) ob_get_clean();
    }

    public static function nomFichier(string $categorie, string $extension): string
    {
        return 'auditeurs-'.str_replace('_', '-', $categorie).'-'.CarbonImmutable::now(InscriptionAnnuelle::FUSEAU)->format('Ymd-Hi').'.'.$extension;
    }

    /** @return array<int, string> promotions ayant des auditeurs */
    public static function promotions(): array
    {
        return CampagneCandidature::query()->whereHas('candidatures.auditeur')->orderByDesc('promotion_numero')->get()
            ->mapWithKeys(fn (CampagneCandidature $c): array => [$c->id => CriteresListe::libellePromotion($c)])->all();
    }

    /** @return array<string, mixed> */
    private function ligne(Auditeur $a): array
    {
        $c = Solvabilite::classer($a->inscriptions, (int) $a->versements_valides_sum_montant);
        $dossier = $a->candidature;

        return $c + [
            'uuid' => $a->uuid,
            'matricule' => $dossier?->matricule ?? '—',
            'nom' => (string) $dossier?->nom,
            'prenom' => (string) $dossier?->prenom,
            'promotion' => $dossier?->campagne?->promotion_numero !== null ? 'P'.$dossier->campagne->promotion_numero : '—',
            'niveau' => (string) $a->inscriptions->last()?->niveau,
            'statut' => Auditeur::STATUTS[$a->statut] ?? (string) $a->statut,
        ];
    }

    private function exiger(string $categorie, User $auteur): void
    {
        abort_unless($auteur->can(self::PERMISSION_EXPORT) && EspaceFinances::peutConsulter($auteur), 403);
        if (! array_key_exists($categorie, Solvabilite::LIBELLES)) {
            throw new InvalidArgumentException('Situation inconnue.');
        }
    }

    /** @param array<string, mixed> $criteres @return array<string, string> */
    private static function libelles(array $criteres): array
    {
        $campagne = filled($criteres['campagne_id'] ?? null) ? CampagneCandidature::query()->find((int) $criteres['campagne_id']) : null;

        return [
            'Promotion' => $campagne === null ? 'toutes' : CriteresListe::libellePromotion($campagne),
            'Niveau' => BaremeScolarite::NIVEAUX[$criteres['niveau'] ?? ''] ?? 'tous',
            'Statut' => Auditeur::STATUTS[$criteres['statut'] ?? ''] ?? 'tous',
            'Dossiers retirés' => (bool) ($criteres['inclure_retires'] ?? false) ? 'inclus' : 'exclus',
            'Période' => 'tout le parcours (arriérés compris)',
            'Versements à confirmer' => 'comptés comme payés',
        ];
    }

    /** @param  array<string, mixed>  $criteres */
    private function journaliser(array $criteres, string $categorie, string $format, int $effectif, User $auteur): void
    {
        activity('scolarite')->causedBy($auteur)->event(self::EVENEMENT)
            ->withProperties(['categorie' => $categorie, 'format' => $format, 'effectif' => $effectif,
                'campagne_id' => $criteres['campagne_id'] ?? null, 'niveau' => $criteres['niveau'] ?? null, 'statut' => $criteres['statut'] ?? null])
            ->log('Liste de solvabilité éditée');
    }

    private static function logo(): ?string
    {
        $chemin = resource_path('images/pdf/logo.png');

        return is_file($chemin) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($chemin)) : null;
    }
}
