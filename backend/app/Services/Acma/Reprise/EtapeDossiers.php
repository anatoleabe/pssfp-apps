<?php

declare(strict_types=1);

namespace App\Services\Acma\Reprise;

use App\Services\Acma\NormalisationAcma;

/**
 * Étape 2 (§6.1, §6.2) : un dossier d'archive par matricule normalisé. Les fiches au même matricule normalisé sont
 * fusionnées (conflits gardés côte à côte, jamais tranchés d'office) ; les collisions sans les zéros de tête sont
 * seulement listées. Les exclusions sont comptées et listées dans le classeur privé.
 */
final class EtapeDossiers implements Etape
{
    public function nom(): string
    {
        return 'dossiers';
    }

    public function executer(ContexteReprise $contexte): array
    {
        $ecriture = new EcritureArchive('acma_dossiers', 'matricule', $contexte->maintenant, avecUuid: true);
        $exclusions = array_fill_keys(array_keys(FichesAcma::MOTIFS), 0);
        $fusions = $parasites = $divergentes = $fiches = 0;
        $formes = [];

        foreach (FichesAcma::grouper() as $groupe) {
            $fiches += count($groupe['fiches']);
            if ($groupe['exclusion'] !== null) {
                $exclusions[$groupe['exclusion']]++;
                foreach ($groupe['fiches'] as $f) {
                    $contexte->lister('exclusions', ['matricule' => (string) $f->matricule, 'nom' => (string) $f->nometudiant, 'motif' => FichesAcma::MOTIFS[$groupe['exclusion']]]);
                }

                continue;
            }
            $matricule = $groupe['matricule'];
            $formes['m'.NormalisationAcma::sansZerosDeTete($matricule)][] = $matricule;
            $sources = array_map(fn (object $f): string => (string) $f->matricule, $groupe['fiches']);
            sort($sources);
            // Fiche de référence : celle dont le matricule est déjà normalisé, sinon la première dans l'ordre.
            $reference = collect($groupe['fiches'])->first(fn (object $f): bool => $f->matricule === $matricule)
                ?? collect($groupe['fiches'])->sortBy('matricule')->first();
            $identites = array_unique(array_map(fn (object $f): string => implode('|', [NormalisationAcma::nomComplet($f->nometudiant), trim((string) $f->datenaissance), trim((string) $f->sexe)]), $groupe['fiches']));

            $signalements = [];
            if (NormalisationAcma::aCaractereParasite($matricule)) {
                $signalements[] = 'caractere_parasite';
                $parasites++;
            }
            if (count($identites) > 1) {
                $signalements[] = 'identites_divergentes';
                $divergentes++;
            }
            if ($groupe['annees'] === []) {
                $signalements[] = 'sans_inscription_datee';
            }
            if (count($sources) > 1) {
                $fusions++;
                $contexte->lister('fusions', ['matricule' => $matricule, 'fiches' => implode(' ; ', $sources), 'identites_divergentes' => count($identites) > 1 ? 'oui' : 'non']);
            }
            $naissance = NormalisationAcma::dateNaissance($reference->datenaissance, $reference->typedatenais, $reference->datenaisbis);

            $ecriture->ajouter([
                'matricule' => $matricule,
                'matricule_source' => (string) $reference->matricule,
                'nom_complet' => mb_substr((string) NormalisationAcma::nomComplet($reference->nometudiant), 0, 100) ?: null,
                'sexe' => NormalisationAcma::sexe($reference->sexe),
                'date_naissance' => $naissance['date'],
                'date_naissance_source' => $naissance['source'] === null ? null : mb_substr($naissance['source'], 0, 50),
                'date_naissance_precision' => $naissance['precision'],
                'annee_naissance' => $naissance['annee'],
                'date_naissance_a_verifier' => $naissance['a_verifier'],
                'lieu_naissance' => self::court($reference->lieunaissance, 100),
                'nationalite' => self::court($reference->nationalite, 50),
                'categorie_acma' => self::court($reference->codcategorie, 10),
                'filiere_acma' => self::court($reference->parcours, 10),
                'premiere_annee' => $groupe['annees'][0] ?? null,
                'derniere_annee' => $groupe['annees'] === [] ? null : $groupe['annees'][count($groupe['annees']) - 1],
                'fiches_fusionnees' => count($sources) > 1 ? json_encode($sources, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
                'signalements' => $signalements === [] ? null : json_encode($signalements, JSON_THROW_ON_ERROR),
            ]);
        }

        $collisions = array_filter($formes, fn (array $g): bool => count($g) > 1);
        foreach ($collisions as $groupe) {
            $contexte->lister('collisions', ['forme_sans_zeros' => NormalisationAcma::sansZerosDeTete((string) $groupe[0]), 'matricules' => implode(' ; ', $groupe)]);
        }
        $contexte->infos['collisions'] = count($collisions);
        $contexte->infos['fusions'] = $fusions;

        return [
            'dossiers' => $ecriture->terminer(),
            'fiches_lues' => $fiches,
            'exclusions' => $exclusions,
            'fusions' => $fusions,
            'identites_divergentes' => $divergentes,
            'caractere_parasite' => $parasites,
            'collisions_sans_zeros' => count($collisions),
        ];
    }

    private static function court(?string $v, int $max): ?string
    {
        $t = NormalisationAcma::texte($v);

        return $t === null ? null : mb_substr($t, 0, $max);
    }
}
