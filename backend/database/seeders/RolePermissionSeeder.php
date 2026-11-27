<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Support\ModulePermissionMap;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

final class RolePermissionSeeder extends Seeder
{
    /**
     * Crée les rôles du projet et leur périmètre initial.
     *
     * Le périmètre s'exprime dans le même vocabulaire que l'écran de gestion
     * des rôles — module + niveau (aucun / lecture / gestion) + actions
     * sensibles — et passe par ModulePermissionMap, source unique de vérité.
     * Un rôle réglé ici peut donc être relu et ajusté depuis l'admin sans
     * divergence entre les deux chemins.
     *
     * Pour une base neuve (dev, tests, première installation) uniquement.
     * **Ne jamais relancer en prod** : `syncPermissions` écraserait les
     * réglages faits depuis l'écran Rôles. Toute nouvelle permission arrive en
     * prod par une migration additive (ex. 2026_10_17_110000_grant_library_permissions,
     * 2026_10_19_200000_library_admin_roles).
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (ModulePermissionMap::allPermissions() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Scopes Sanctum du portail candidat — hors périmètre back-office,
        // donc hors ModulePermissionMap.
        $candidatPermissions = [
            'profile.read',
            'profile.write',
            'application.create',
            'application.read',
            // application.submit séparée de application.create : Phase II pourra
            // donner create/update à un assistant USI tout en réservant submit
            // au candidat lui-même (signature engagement). En V1 le candidat a les deux.
            'application.submit',
        ];

        foreach ($candidatPermissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $none = ModulePermissionMap::LEVEL_NONE;
        $read = ModulePermissionMap::LEVEL_READ;
        $manage = ModulePermissionMap::LEVEL_MANAGE;

        /*
         * Périmètre initial de chaque rôle.
         *
         * Règle transverse : les campagnes sont lisibles par tout rôle qui a
         * accès aux admissions (lecture ou gestion) mais ne se modifient que
         * par `admin` / `super_admin` (verrou porté par
         * CampagneCandidaturePolicy). Les rôles d'autres modules (site,
         * bibliothèque) ne les voient pas.
         */
        $roles = [
            'super_admin' => [
                'label' => 'Super administrateur',
                'description' => 'Accès total, y compris réglages, rôles et suppressions définitives.',
                'modules' => ['site' => $manage, 'admissions' => $manage, 'administration' => $manage, 'bibliotheque' => $manage, 'scolarite' => $manage, 'lmd' => $manage],
                'sensitive' => array_keys(ModulePermissionMap::sensitiveActions()),
            ],
            'admin' => [
                'label' => 'Administrateur',
                'description' => 'Comptes, rôles, campagnes, réglages et exports.',
                // Scolarité en lecture depuis le 15/11/2026 : le service financier seul enregistre les versements.
                'modules' => ['site' => $manage, 'admissions' => $manage, 'administration' => $manage, 'bibliotheque' => $manage, 'scolarite' => $read, 'lmd' => $manage],
                'sensitive' => [
                    'candidature.export_csv',
                    'candidature.mark_depot_physique',
                    'candidature.regenerate_recipisse',
                    // Écrire à un groupe de candidats engage l'institution :
                    // réservé à admin et super_admin (arbitrage 3 sept. 2026).
                    'candidature.notify',
                    'campagne.download_resultats',
                    'campagne.export_retenus',
                    'candidature.matricules_moodle',
                    'candidature.ecampus_mot_de_passe',
                    'scolarite.suspension',
                    'scolarite.lettre_acces',
                    // Remplacer un bordereau joint (24/11/2026) : réservé à l'administration.
                    'scolarite.justificatif_remplacer',
                    'candidature.ajouter_retenu',
                    'candidature.dossier_corriger',
                    'candidature.lettres_signees',
                    'candidature.espace_candidat',
                    'scolarite.export',
                    'finances.consulter',
                    'finances.rubriques_gerer',
                    'scolarite.versement_annuler',
                    'scolarite.versement_confirmer',
                    'scolarite.remise',
                    'scolarite.bareme',
                    'scolarite.promotion',
                    'scolarite.statut',
                    'scolarite.messages',
                    'scolarite.deblocage',
                    'scolarite.deblocage_exceptionnel',
                    'scolarite.etiquettes',
                    'lmd.maquette_valider',
                    'lmd.regles',
                    // Changer la spécialité (27/11/2026) : réservé à l'administration.
                    'lmd.specialite_changer',
                    'lmd.notes_verrouiller',
                    'lmd.notes_rouvrir',
                    'lmd.derogation_rattrapage',
                    'lmd.deliberation_arreter',
                    'lmd.deliberation_publier',
                    'lmd.jury_signer',
                    'lmd.documents_revoquer',
                    'lmd.documents_lever',
                    'lmd.requetes_coter',
                    'lmd.requetes_jury',
                    'lmd.requetes_valider',
                    'lmd.memoires_attester',
                    'lmd.calendrier_reajuster',
                    'lmd.calendrier_signer',
                    'lmd.actes_signer',
                    'lmd.programmation_valider',
                    'lmd.paie_verifier',
                    'lmd.controle_gestion',
                    'journal.view',
                    'supervision.consulter',
                    'supervision.traiter',
                    'document.publish',
                    'library.thesaurus',
                    'library.redirects',
                    'library.stats_export',
                    'document.contribute',
                ],
            ],
            'editor' => [
                'label' => 'Éditeur',
                'description' => 'Contenus du site institutionnel : pages, actualités, médiathèque.',
                'modules' => ['site' => $manage, 'admissions' => $none, 'administration' => $none, 'bibliotheque' => $none],
                'sensitive' => [],
            ],
            'admission_committee' => [
                'label' => 'Comité d\'admission',
                'description' => 'Étude des dossiers de candidature et décisions d\'admission.',
                'modules' => ['site' => $none, 'admissions' => $manage, 'administration' => $none, 'bibliotheque' => $none],
                'sensitive' => [
                    'candidature.accept',
                    'candidature.refuse',
                    'candidature.mark_paid',
                    'candidature.export_csv',
                    'candidature.mark_depot_physique',
                    'candidature.regenerate_recipisse',
                    'campagne.download_resultats',
                    'campagne.export_retenus',
                ],
            ],
            // Guichet de la scolarité (Yaoundé-Messa, porte 231) : consulter
            // pour identifier le dossier, pointer sa réception. Rien d'autre —
            // ni décision, ni paiement, ni export.
            'receptionniste' => [
                'label' => 'Réception scolarité',
                'description' => 'Pointage des dossiers papier déposés au guichet.',
                'modules' => ['site' => $none, 'admissions' => $read, 'administration' => $none, 'bibliotheque' => $none],
                'sensitive' => ['candidature.mark_depot_physique'],
            ],
            /*
             * Bibliothèque (Plan 6) : ces rôles n'ouvrent que le module
             * bibliothèque du back-office (cf. ModuleAccess). L'encaissement
             * des frais de dossier, autrefois porté par `librarian`, est
             * passé au rôle `caisse` — à cumuler si besoin.
             *
             * Libellés et descriptions identiques à la migration
             * 2026_10_19_200000_library_admin_roles (vérifié par
             * LibraryAdminRolesMigrationTest).
             */
            // Scolarité (spec module 4) : depuis le 15/11/2026, le service financier
            // enregistre l'argent (gestion du module) et la scolarité est en lecture ;
            // le responsable corrige et accorde. Aucun des deux n'ouvre les dossiers ;
            // depuis le 13/11/2026, tous deux téléchargent la lettre d'accès FOAD d'un
            // auditeur soldé, un dossier à la fois, et en renvoient les accès
            // (scolarite.lettre_acces). Tous deux posent les étiquettes des auditeurs
            // (scolarite.etiquettes, 30/11/2026).
            'scolarite_agent' => [
                'label' => 'Agent de scolarité',
                'description' => 'Consultation des auditeurs et export de la situation financière ; lettre d\'accès FOAD d\'un auditeur soldé et renvoi de ses accès ; préparation des maquettes et inscriptions pédagogiques.',
                'modules' => ['scolarite' => $read, 'lmd' => $manage],
                'sensitive' => ['scolarite.export', 'scolarite.lettre_acces', 'scolarite.etiquettes', 'scolarite.deblocage', 'scolarite.versement_confirmer'],
            ],
            'scolarite_responsable' => [
                'label' => 'Responsable de scolarité',
                'description' => 'Droits de l\'agent, plus les remises, les barèmes, les réglages de promotion, le statut des auditeurs, les messages, la validation des maquettes et les règles d\'évaluation ; annuler un versement revient à un administrateur.',
                'modules' => ['scolarite' => $read, 'lmd' => $manage],
                'sensitive' => ['scolarite.export', 'finances.consulter', 'scolarite.lettre_acces', 'scolarite.etiquettes', 'scolarite.versement_confirmer', 'scolarite.remise', 'scolarite.bareme', 'supervision.consulter',
                    'scolarite.promotion', 'scolarite.statut', 'scolarite.messages', 'scolarite.deblocage', 'scolarite.deblocage_exceptionnel',
                    'lmd.maquette_valider', 'lmd.regles',
                    'lmd.notes_verrouiller', 'lmd.notes_rouvrir', 'lmd.derogation_rattrapage',
                    'lmd.deliberation_arreter', 'lmd.deliberation_publier', 'lmd.jury_signer',
                    'lmd.documents_revoquer', 'lmd.documents_lever', 'lmd.requetes_coter', 'lmd.requetes_jury',
                    'lmd.memoires_attester', 'lmd.calendrier_reajuster', 'lmd.actes_signer', 'lmd.programmation_valider', 'lmd.paie_verifier'],
            ],
            'enseignant' => [
                'label' => 'Enseignant',
                'description' => 'Saisie du contrôle continu et de l\'assiduité des ECUE qui lui sont attribués.',
                'modules' => [],
                'sensitive' => ['lmd.notes_enseignant', 'lmd.programmation_repondre', 'lmd.pointage_emarger'],
            ],
            'point_focal' => [
                'label' => 'Point focal de classe',
                'description' => 'Assiduité et contrôle continu des cours de sa classe ; liste de ses auditeurs en lecture seule.',
                'modules' => [],
                'sensitive' => ['lmd.point_focal'],
            ],
            'membre_jury' => [
                'label' => 'Membre de jury',
                'description' => 'Consultation des délibérations de ses jurys et signature de leurs PV.',
                'modules' => [],
                'sensitive' => ['lmd.jury_signer'],
            ],
            'chef_commission_requetes' => [
                'label' => 'Chef de la commission des requêtes',
                'description' => 'Validation définitive des PV du jury des requêtes : les notes changent à ce moment.',
                'modules' => [],
                'sensitive' => ['lmd.requetes_valider'],
            ],
            'gestionnaire_anonymats' => [
                'label' => 'Gestionnaire des anonymats',
                'description' => 'Épreuves anonymes : numéros, étiquettes, constats, verrouillage et levée avec la clé. Ne saisit pas de notes.',
                'modules' => [],
                'sensitive' => ['lmd.anonymat_gerer'],
            ],
            'operateur_saisie' => [
                'label' => 'Opérateur de saisie',
                'description' => 'Saisie des notes d\'examen sur les numéros d\'anonymat, sans accès à la correspondance.',
                'modules' => [],
                'sensitive' => ['lmd.anonymat_saisir'],
            ],
            'service_financier' => [
                'label' => 'Service administratif et financier',
                'description' => 'Encaissement des versements de scolarité et émission de leurs reçus ; dossiers de paie des enseignants : NIU, banque et compte de virement.',
                'modules' => ['scolarite' => $manage],
                'sensitive' => ['lmd.dossier_paie', 'scolarite.recu_emettre', 'lmd.paie_payer', 'lmd.controle_gestion', 'finances.consulter', 'scolarite.export', 'scolarite.versement_corriger'],
            ],
            'chef_structure' => [
                'label' => 'Chef de la structure',
                'description' => 'Signature du calendrier académique préparé par la scolarité, ou renvoi en préparation.',
                'modules' => [],
                'sensitive' => ['lmd.calendrier_signer', 'lmd.actes_signer'],
            ],
            'library_manager' => [
                'label' => 'Responsable de la bibliothèque',
                'description' => 'Bibliothèque seulement : documents, thésaurus, publication, anciennes adresses et statistiques.',
                'modules' => ['site' => $none, 'admissions' => $none, 'administration' => $none, 'bibliotheque' => $manage],
                'sensitive' => ['document.publish', 'library.thesaurus', 'library.redirects', 'library.stats_export'],
            ],
            'librarian' => [
                'label' => 'Bibliothécaire',
                'description' => 'Bibliothèque seulement : gestion des documents et du thésaurus, publication.',
                'modules' => ['site' => $none, 'admissions' => $none, 'administration' => $none, 'bibliotheque' => $manage],
                'sensitive' => ['document.publish'],
            ],
            'library_contributor' => [
                'label' => 'Contributeur bibliothèque',
                'description' => 'Bibliothèque seulement : dépôt de ses propres documents, soumis à validation.',
                'modules' => ['site' => $none, 'admissions' => $none, 'administration' => $none, 'bibliotheque' => $none],
                'sensitive' => ['document.contribute'],
            ],
            'library_viewer' => [
                'label' => 'Consultation bibliothèque',
                'description' => 'Bibliothèque seulement : consultation du catalogue et export des statistiques.',
                'modules' => ['site' => $none, 'admissions' => $none, 'administration' => $none, 'bibliotheque' => $read],
                'sensitive' => ['library.stats_export'],
            ],
            'caisse' => [
                'label' => 'Caisse — frais de dossier',
                'description' => 'Admissions en lecture et encaissement des frais de dossier.',
                'modules' => ['site' => $none, 'admissions' => $read, 'administration' => $none, 'bibliotheque' => $none],
                'sensitive' => ['candidature.mark_paid'],
            ],
        ];

        // Archives ACMA (lot 15d, spec du lot 15, §7.10) : consultation pour l'administration et la scolarité ;
        // compléments et arbitrages pour l'administrateur et le responsable de scolarité (super_admin les a tous).
        foreach (['super_admin', 'admin', 'scolarite_agent', 'scolarite_responsable'] as $name) {
            $roles[$name]['modules']['archives'] = $read;
        }
        foreach (['admin', 'scolarite_responsable'] as $name) {
            $roles[$name]['sensitive'] = [...$roles[$name]['sensitive'], 'archives.completer', 'archives.rapprocher'];
        }
        // Réinscription des anciens (lot 15j, 16/11/2026) : promotions antérieures, au responsable de scolarité et à
        // l'administrateur (super_admin les a toutes).
        foreach (['admin', 'scolarite_responsable'] as $name) {
            $roles[$name]['sensitive'] = [...$roles[$name]['sensitive'], 'scolarite.promotion_anterieure', 'scolarite.inscrire_ancien', 'scolarite.derogation_m2'];
        }

        // Inscrire (lot 22, 23/11/2026) : réinscription et passage en groupe, à l'administration et à la scolarité.
        foreach (['admin', 'scolarite_responsable', 'scolarite_agent'] as $name) {
            $roles[$name]['sensitive'] = [...$roles[$name]['sensitive'], 'scolarite.inscrire'];
        }

        // Certificat de scolarité (lot 25, 23/11/2026) : délivré par la scolarité ou l'administration.
        foreach (['admin', 'scolarite_responsable', 'scolarite_agent'] as $name) {
            $roles[$name]['sensitive'] = [...$roles[$name]['sensitive'], 'lmd.certificat_delivrer'];
        }

        foreach ($roles as $name => $definition) {
            $role = Role::findOrCreate($name, 'web');
            $role->forceFill([
                'label' => $definition['label'],
                'description' => $definition['description'],
            ])->save();

            $role->syncPermissions(ModulePermissionMap::toPermissions(
                $definition['modules'],
                $definition['sensitive'],
            ));
        }

        $candidat = Role::findOrCreate('candidat', 'web');
        $candidat->forceFill([
            'label' => 'Candidat',
            'description' => 'Compte du portail de candidature — jamais un accès back-office.',
        ])->save();
        $candidat->syncPermissions($candidatPermissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
