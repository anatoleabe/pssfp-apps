<?php

declare(strict_types=1);

namespace App\Services\Acma\Reprise;

use App\Services\Acma\CorrespondancesAcma;
use App\Services\Acma\DatesAcma;
use App\Services\Acma\LectureTransit;
use App\Services\Acma\NormalisationAcma;
use Illuminate\Support\Facades\DB;

/**
 * Étape 8a (§7.9) : PV de soutenance, tous gardés (plusieurs par dossier possibles). Date lue dans le texte, sinon date
 * de l'opération, sinon vide ; mention normalisée, texte d'origine gardé.
 */
final class EtapeSoutenances implements Etape
{
    public function nom(): string
    {
        return 'soutenances';
    }

    public function executer(ContexteReprise $contexte): array
    {
        $dossiers = DB::table('acma_dossiers')->whereNull('retire_de_la_source_at')->pluck('id', 'matricule')->all();
        $ecriture = new EcritureArchive('acma_soutenances', 'id_acma', $contexte->maintenant);
        $comptes = ['dossier_exclu' => 0, 'date_du_texte' => 0, 'date_de_l_operation' => 0, 'sans_date' => 0, 'mention_inconnue' => 0];
        foreach (LectureTransit::lignes('pvsoutenance', ['idpvSoute', 'matricule', 'niveau', 'jourDeSoutence', 'Memoire', 'President', 'Rapporteur', 'Membre1', 'Membre2', 'NoteSoutenance', 'MentionSoutena', 'dateoperation', 'observation']) as $p) {
            $dossier = $dossiers[(string) NormalisationAcma::matricule($p->matricule)] ?? null;
            if ($dossier === null) {
                $comptes['dossier_exclu']++;

                continue;
            }
            $date = DatesAcma::texte($p->jourDeSoutence);
            $origine = $date === null ? null : 'texte';
            if ($date === null && ($date = DatesAcma::date($p->dateoperation)) !== null) {
                $origine = 'operation';
            }
            $comptes[match ($origine) {
                'texte' => 'date_du_texte',
                'operation' => 'date_de_l_operation',
                default => 'sans_date',
            }]++;
            $mention = NormalisationAcma::mention($p->MentionSoutena);
            $comptes['mention_inconnue'] += (int) ($mention === null && NormalisationAcma::texte($p->MentionSoutena) !== null);
            $niveauAcma = NormalisationAcma::texte($p->niveau);
            $membres = array_values(array_filter([NormalisationAcma::texte($p->Membre1), NormalisationAcma::texte($p->Membre2)]));
            $ecriture->ajouter([
                'id_acma' => (int) $p->idpvSoute,
                'dossier_id' => (int) $dossier,
                'niveau_acma' => $niveauAcma,
                'niveau' => CorrespondancesAcma::NIVEAUX[(string) $niveauAcma]['niveau'] ?? null,
                'date' => $date,
                'date_source' => self::court($p->jourDeSoutence, 45),
                'date_origine' => $origine,
                'theme' => NormalisationAcma::texte($p->Memoire),
                'note' => LectureTransit::nombre($p->NoteSoutenance),
                'mention' => $mention,
                'mention_source' => self::court($p->MentionSoutena, 20),
                'president' => NormalisationAcma::texte($p->President),
                'rapporteur' => NormalisationAcma::texte($p->Rapporteur),
                'membres' => $membres === [] ? null : json_encode($membres, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'observation' => NormalisationAcma::texte($p->observation),
            ]);
        }

        return ['soutenances' => $ecriture->terminer(), ...$comptes];
    }

    private static function court(?string $v, int $max): ?string
    {
        $t = NormalisationAcma::texte($v);

        return $t === null ? null : mb_substr($t, 0, $max);
    }
}
