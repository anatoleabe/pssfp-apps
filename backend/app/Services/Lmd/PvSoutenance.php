<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\DossierMemoire;
use App\Models\RegleEvaluation;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Procès-verbal de soutenance (spec lot 24a) : un PV par auditeur, produit avant la soutenance avec le jury,
 * puis repris avec la note, la mention et les observations une fois le résultat saisi.
 */
final class PvSoutenance
{
    public const MAX = 100;

    /** Ce qui manque au dossier pour produire son PV ; vide quand il est prêt. @return list<string> */
    public static function manques(DossierMemoire $dossier): array
    {
        $manques = [];
        if (in_array($dossier->statut, ['suspendu', 'abandon'], true)) {
            $manques[] = 'dossier '.mb_strtolower(DossierMemoire::STATUTS[$dossier->statut]);
        }
        foreach (['theme' => 'thème', 'date_soutenance' => 'date de soutenance', 'jury_president' => 'président du jury', 'jury_rapporteur' => 'rapporteur'] as $champ => $libelle) {
            if (blank($dossier->getAttribute($champ))) {
                $manques[] = $libelle;
            }
        }

        return $manques;
    }

    /** Note du jury, une fois le résultat saisi. */
    public static function note(DossierMemoire $dossier): ?float
    {
        $etape = $dossier->etapes->firstWhere('etape', 'soutenance');

        return $etape !== null && $etape->statut === 'valide' && $etape->note !== null ? (float) $etape->note : null;
    }

    /** Mention de la note, par la grille de l'année ; aucune sous le premier seuil. */
    public static function mention(float $note, RegleEvaluation $regle): ?string
    {
        $mention = null;
        foreach (collect($regle->grille_mentions)->sortBy('min') as $ligne) {
            if ($note >= (float) $ligne['min']) {
                $mention = (string) $ligne['mention'];
            }
        }

        return $mention;
    }

    /**
     * @param  Collection<int, DossierMemoire>  $dossiers
     *
     * @throws InvalidArgumentException dossier incomplet, ou trop de dossiers
     */
    public function pdf(Collection $dossiers, User $auteur): string
    {
        abort_unless($auteur->can(PermissionsLmd::MEMOIRES_SUIVRE), 403);
        if ($dossiers->isEmpty()) {
            throw new InvalidArgumentException('Aucun dossier sélectionné.');
        }
        if ($dossiers->count() > self::MAX) {
            throw new InvalidArgumentException('Au plus '.self::MAX.' procès-verbaux par fichier : procédez par option ou par vague.');
        }
        $dossiers = EloquentCollection::make($dossiers->all())->loadMissing(['auditeur.candidature', 'inscriptionAnnuelle.parcours', 'etapes']);
        $pages = [];
        foreach ($dossiers as $dossier) {
            $manques = self::manques($dossier);
            if ($manques !== []) {
                throw new InvalidArgumentException('PV impossible pour '.$dossier->auditeur?->candidature?->matricule.' : il manque '.implode(', ', $manques).'.');
            }
            $note = self::note($dossier);
            $pages[] = [
                'dossier' => $dossier,
                'candidat' => $dossier->auditeur->candidature,
                'option' => $dossier->inscriptionAnnuelle?->parcours?->libelle,
                'annee' => (string) $dossier->inscriptionAnnuelle?->annee_academique,
                'note' => $note,
                'mention' => $note === null ? null : self::mention($note, RegleEvaluation::pourAnnee((string) $dossier->inscriptionAnnuelle->annee_academique)),
                'observations' => $note === null ? null : $dossier->etapes->firstWhere('etape', 'soutenance')?->appreciation,
            ];
        }
        activity('lmd')->causedBy($auteur)->event('pv_soutenance_edite')
            ->withProperties(['dossiers' => $dossiers->count()])->log('Procès-verbaux de soutenance édités');

        return Pdf::loadView('pdf.lmd.pv-soutenance', ['pages' => $pages, 'logo' => self::image('uy2.png'), 'edite_le' => now()->timezone('Africa/Douala')])
            ->setPaper('a4', 'portrait')->output();
    }

    private static function image(string $fichier): ?string
    {
        $chemin = resource_path('images/pdf/'.$fichier);

        return is_file($chemin) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($chemin)) : null;
    }
}
