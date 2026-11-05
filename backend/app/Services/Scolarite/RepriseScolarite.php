<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\Versement;
use App\Services\DocumentsAuditeurs\ParametresLettres;

/**
 * Reprise de l'existant à la mise en service du registre. Idempotente.
 *
 * 1. Barèmes M1 et M2 des années des promotions déjà numérotées ;
 * 2. un auditeur et une inscription M1 par dossier doté d'un matricule, retirés
 *    compris (leur historique doit rester consultable) ;
 * 3. chaque case cochée devient un versement « Reprise du pointage » ;
 * 4. aucune vague de SMS : les inscriptions dont l'accès est déjà disponible sont
 *    notées notifiées ; à déployer après `ecampus:rapprocher` (spec §13).
 */
final class RepriseScolarite
{
    public function __construct(private readonly OuvertureAuditeur $ouverture) {}

    /** @return array{baremes: int, auditeurs: int, reprises: int} */
    public function executer(): array
    {
        $bilan = ['baremes' => 0, 'auditeurs' => 0, 'reprises' => 0];

        $campagnes = CampagneCandidature::query()
            ->whereHas('candidatures', fn ($q) => $q->whereNotNull('matricule'))
            ->get();
        foreach ($campagnes as $campagne) {
            $bilan['baremes'] += $this->baremes($campagne);
        }

        $avant = Versement::query()->where('mode', Versement::MODE_REPRISE)->count();
        Candidature::query()->whereNotNull('matricule')->orderBy('id')
            ->chunkById(100, function ($dossiers) use (&$bilan): void {
                foreach ($dossiers as $dossier) {
                    $existait = $dossier->auditeur()->exists();
                    $this->ouverture->ouvrir($dossier);
                    $bilan['auditeurs'] += $existait ? 0 : 1;
                }
            });
        $bilan['reprises'] = Versement::query()->where('mode', Versement::MODE_REPRISE)->count() - $avant;

        // Pas de vague de SMS pour ceux qui ont déjà leur accès. Ceux dont le compte
        // eCampus reste à créer seront prévenus à sa création (ComptesEcampus).
        InscriptionAnnuelle::query()->whereNull('acces_notifie_at')
            ->with(['auditeur.candidature', 'bareme'])
            ->chunkById(200, function ($inscriptions): void {
                $ids = $inscriptions->filter(fn (InscriptionAnnuelle $i): bool => $i->auditeur?->candidature !== null
                    && AccesEcampus::etat($i->auditeur->candidature->setRelation('auditeur', $i->auditeur->setRelation('inscriptionCourante', $i))) === AccesEcampus::DISPONIBLE)
                    ->pluck('id');
                InscriptionAnnuelle::query()->whereKey($ids)->update(['acces_notifie_at' => now()]);
            });

        return $bilan;
    }

    private function baremes(CampagneCandidature $campagne): int
    {
        $annee = $campagne->anneeAcademique();
        $lettres = ParametresLettres::pour($campagne, avecBareme: false);
        $defauts = (array) config('scolarite.bareme_defaut');
        $crees = 0;

        $valeurs = [
            BaremeScolarite::NIVEAU_M1 => [
                'montant_inscription' => $lettres->entier('frais_inscription_montant') ?: (int) $defauts['M1']['inscription'],
                'montant_tranche1' => $lettres->entier('frais_tranche1_montant') ?: (int) $defauts['M1']['tranche1'],
                'montant_tranche2' => $lettres->entier('frais_tranche2_montant') ?: (int) $defauts['M1']['tranche2'],
                'inscription_echeance' => $lettres->texte('frais_inscription_echeance') ?: null,
                'tranche1_echeance' => $lettres->texte('frais_tranche1_date') ?: null,
                'tranche2_echeance' => $lettres->texte('frais_tranche2_date') ?: null,
            ],
            // Échéances du M2 inconnues : le responsable les saisit.
            BaremeScolarite::NIVEAU_M2 => [
                'montant_inscription' => (int) $defauts['M2']['inscription'],
                'montant_tranche1' => (int) $defauts['M2']['tranche1'],
                'montant_tranche2' => (int) $defauts['M2']['tranche2'],
            ],
        ];

        foreach ($valeurs as $niveau => $montants) {
            $bareme = BaremeScolarite::query()->firstOrCreate(['annee_academique' => $annee, 'niveau' => $niveau], $montants);
            $crees += $bareme->wasRecentlyCreated ? 1 : 0;
        }

        return $crees;
    }
}
