<?php

declare(strict_types=1);

namespace Tests\Support\Acma;

/**
 * Petite base ACMA fictive (lot 15, §14) : schéma d'ACMA réduit aux tables utiles, et données inventées qui reproduisent
 * ses pièges. Aucun nom, matricule ni date réels : les matricules `90EFP…` et `99XX…` n'existent pas.
 *
 * Pièges : dates impossibles (`0000-00-00`, `2021-06-00`), reçus en 2230 et en 1120, montants négatifs appariés ou
 * isolés, total nul, doublon exact, matricules en minuscules, avec espace, tiret ou préfixe `zz`, longueurs variables,
 * collision sans les zéros de tête, fiches fusionnées en conflit, note écrasée à reconstituer depuis l'historique,
 * programme supprimé, inscriptions sans année, grille vide de 2026-2027, cohortes sans décision, « né vers ».
 */
final class JeuAcmaFictif
{
    /** Colonnes au format « nom:type », `*` pour la clé primaire. */
    public const SCHEMA = [
        'anneeacademic' => ['*anneeacademic:varchar(12)', 'debutannee:varchar(50)', 'finanne:varchar(50)', 'validesNotes:varchar(50)'],
        'attestationr' => ['*Idattes:int(10) unsigned', 'Matricule:varchar(10)', 'Niveau:varchar(30)', 'datedujour:datetime', 'specialite:varchar(100)', 'datenaissance:varchar(45)', 'NomEtudiant:varchar(100)', 'session:varchar(45)', 'Memoire:longtext', 'Parcourt:varchar(100)', 'imprimer:tinyint(1)', 'recu:tinyint(1)', 'envoyer:tinyint(1)'],
        'categorie' => ['*codcategorie:varchar(10)', 'libelecategorie:varchar(45)', 'codematricule:varchar(2)', 'idcampus:int(2)'],
        'certificat' => ['*IdCertificat:int(10) unsigned', 'Matricule:varchar(10)', 'Niveau:varchar(30)', 'Datedujour:varchar(45)', 'datenaissance:varchar(45)', 'Nometudiant:varchar(100)', 'specialite:varchar(100)', 'Parcourt:varchar(100)'],
        'configurationdu' => ['*IDdroitC:int(10) unsigned', 'anneaacademic:varchar(12)', 'Categorie:varchar(10)', 'montantAregler:double', 'niveau:varchar(30)', 'tranche1:double', 'tranche2:double', 'tranche3:double', 'inscription:double', 'TenuSport:double'],
        'du' => ['*IddroitU:int(10) unsigned', 'matricule:varchar(10)', 'Niveau:varchar(30)', 'Categorie:varchar(10)', 'Payer:tinyint(1)', 'Montant:double', 'NumeroRecu:varchar(45)', 'RestePayer:double', 'NomBanque:varchar(45)', 'anneacademic:varchar(12)', 'AUTRESdetail:longtext', 'nometudiant:varchar(100)', 'montantautres:double', 'ok:tinyint(1)', 'dateoperation:datetime', 'dateRecu:date', 'username:varchar(40)', 'stan:varchar(200)', 'codephase:varchar(50)', 'Exporte:tinyint(1)', 'Versement:double', 'inscriptions:double', 'tenuesport:double', 'bibliotheque:double', 'fraisperiscol:double'],
        'etablissement' => ['*codEtablissement:varchar(45)', 'LibelleEtab:varchar(100)', 'Lengende 1:longtext', 'MoyenSeuil:double', 'plateforme:varchar(200)', 'keyplateforme:varchar(100)', 'userftp:varchar(100)', 'paswdftp:varchar(200)', 'serverftp:varchar(255)', 'repftp:varchar(50)'],
        'etudiant' => ['*matricule:varchar(10)', 'parcours:varchar(10)', 'codcategorie:varchar(10)', 'nometudiant:varchar(50)', 'datenaissance:date', 'sexe:char(4)', 'lieunaissance:varchar(20)', 'RegionBis:varchar(20)', 'region:varchar(20)', 'departement:varchar(100)', 'numcni:varchar(30)', 'nationalite:varchar(30)', 'telephone:varchar(30)', 'expression:varchar(5)', 'email:varchar(50)', 'adresse:varchar(200)', 'Suspendu:tinyint(1)', 'mobile:varchar(200)', 'idcampus:int(2)', 'EmailB:varchar(50)', 'typedatenais:smallint(1)', 'datenaisbis:varchar(50)'],
        'filiere' => ['codecycle:varchar(50)', '*parcours:varchar(10)', 'libelefil:varchar(100)', 'codematricule:varchar(2)', 'descriptionfil:varchar(100)', 'idcampus:int(2)'],
        'grpue' => ['*codgrp:varchar(10)', 'libelegrp:varchar(100)', 'codniveau:varchar(30)', 'centreI:varchar(100)', 'idcampus:int(2)', 'Optionnel:tinyint(1)', 'TCrd:int(2)'],
        'inscription_academic' => ['*id_inscription:bigint(20) unsigned', 'parcours:varchar(10)', 'niveau:varchar(30)', 'matricule:varchar(10)', 'anneeacademic:varchar(12)', 'moydunivo:double', 'sexe:char(4)', 'admis:tinyint(1)', 'redouble:tinyint(1)', 'Fraiscolarite:double', 'NbreDette:int(2)', 'idcampus:int(2)', 'Totalcredit:int(2)'],
        'misedispos' => ['*Idmise:int(10) unsigned', 'Matricule:varchar(10)', 'Niveau:varchar(30)', 'jourOperation:datetime', 'specialite:varchar(100)', 'datenaissance:varchar(45)', 'session:varchar(45)', 'NoteMemoire:double', 'Mention:varchar(45)', 'NomEtudiant:varchar(100)', 'Memoire:longtext', 'Parcourt:varchar(100)'],
        'niveau' => ['*codniveau:varchar(30)', 'parcours:varchar(10)', 'niveau:varchar(20)', 'descriptioncycle:varchar(100)', 'FraisRattrapage:double', 'specialite:varchar(100)', 'level:tinyint(1)', 'idcampus:int(2)', 'codediplome:varchar(50)'],
        'nonperceptionb' => ['*Idnonper:int(10) unsigned', 'Matricule:varchar(10)', 'Niveau:varchar(30)', 'datedujour:datetime', 'Nometudiant:varchar(100)', 'Parcourt:varchar(100)'],
        'notes' => ['*idnote:bigint(20) unsigned', 'idprog:bigint(20) unsigned', 'codue:varchar(10)', 'matricule:varchar(10)', 'cc:double', 'tp:double', 'anonymatexa:varchar(10)', 'exam:double', 'anonymatrat:varchar(10)', 'ratra:double', 'ok:int(10) unsigned', 'annee:varchar(10)', 'validation:int(10) unsigned', 'idcampus:int(2)', 'semestre:smallint(6)', 'total:double', 'credit:int(10)', 'anneeacademic:varchar(12)', 'codgrp:varchar(10)', 'codniveau:varchar(30)', 'coef:double', 'nometudiant:varchar(50)', 'moy:double', 'IDUtilisateur:int(2)', 'dateoperation:datetime'],
        'notes_hist' => ['*idnotes:bigint(20)', 'idprog:bigint(20)', 'codue:varchar(10)', 'matricule:varchar(10)', 'cc:double', 'tp:double', 'exem:double', 'anoexam:varchar(10)', 'anoratra:varchar(10)', 'ok:int(10)', 'annee:varchar(10)', 'validation:int(10)', '*changedon:datetime', 'datedujour:date', 'usercon:varchar(30)', 'Rattra:double', 'IDutilisateur:bigint(20)'],
        'phasepayement' => ['*codephase:varchar(50)', 'libellephase:varbinary(100)', 'ordrenum:int(1)'],
        'prisecours' => ['*IdPrise:int(10) unsigned', 'Matricule:varchar(10)', 'Niveau:varchar(30)', 'datedujour:datetime', 'Specialite:varchar(100)', 'DebutPriseCours:varchar(100)', 'datenaissance:varchar(45)', 'FinpriseCours:varchar(45)', 'Nometudiant:varchar(100)', 'Parcourt:varchar(100)', 'Destinataire:longtext', 'themestage:longtext', 'structureAcueil:varchar(100)', 'anneeacademique:varchar(12)', 'dureestage:varchar(50)'],
        'programe' => ['*idprog:bigint(20) unsigned', 'anneeacademic:varchar(12)', 'codniveau:varchar(30)', 'codgrp:varchar(10)', 'codue:varchar(10)', 'semestre:smallint(5) unsigned', 'pcc:double', 'ptp:double', 'pexam:double', 'coef:double', 'credit:int(10) unsigned', 'cm:int(10) unsigned', 'tp:int(10) unsigned', 'semin:int(10) unsigned', 'tpe:int(10) unsigned', 'tge:int(10) unsigned', 'idcampus:int(2)'],
        'pvsoutenance' => ['*idpvSoute:int(10) unsigned', 'matricule:varchar(10)', 'niveau:varchar(30)', 'jourDeSoutence:varchar(45)', 'Memoire:longtext', 'President:longtext', 'Rapporteur:longtext', 'Membre1:longtext', 'Membre2:longtext', 'NoteSoutenance:double unsigned', 'MentionSoutena:varchar(20)', 'dateoperation:datetime', 'specialite:varchar(100)', 'observation:longtext', 'nometudiant:varchar(100)', 'Parcourt:varchar(100)'],
        'ue' => ['*codue:varchar(10)', 'codniveau:varchar(30)', 'libeleue:varchar(100)', 'idcampus:int(2)'],
        // Tables présentes dans ACMA mais jamais extraites.
        'utilisateur' => ['*IDUtilisateur:bigint(20)', 'NomU:varchar(50)', 'LogginU:varchar(50)', 'Motdepasse:varchar(32)'],
        'tachesutili' => ['*id:int(10)', 'action:varchar(100)'],
    ];

    /**
     * Le jeu d'essai : table → lignes (tableaux associatifs ; une colonne absente vaut NULL).
     *
     * @return array<string, list<array<string, string|null>>>
     */
    public static function lignes(): array
    {
        $annees = [];
        foreach (range(2013, 2026) as $a) {
            $annees[] = ['anneeacademic' => $a.'-'.($a + 1), 'debutannee' => $a <= 2016 ? 'CLOTUREE' : 'EN COURS', 'finanne' => $a <= 2016 ? 'OUI' : 'NON'];
        }
        $etudiant = fn (string $m, string $nom, ?string $naissance, string $sexe = 'M', array $autres = []): array => array_merge([
            'matricule' => $m, 'parcours' => 'MFP', 'codcategorie' => 'AC', 'nometudiant' => $nom, 'datenaissance' => $naissance,
            'sexe' => $sexe, 'lieunaissance' => 'VILLE FICTIVE', 'nationalite' => 'FICTIVE', 'telephone' => '600000000', 'email' => 'x',
            'adresse' => 'ADRESSE FICTIVE', 'region' => 'REGION', 'numcni' => 'REGION', 'typedatenais' => '1',
        ], $autres);
        $inscription = fn (string $id, ?string $niveau, string $m, ?string $annee, ?string $admis = null, array $autres = []): array => array_merge([
            'id_inscription' => $id, 'parcours' => 'MFP', 'niveau' => $niveau, 'matricule' => $m, 'anneeacademic' => $annee, 'admis' => $admis,
        ], $autres);
        $programme = fn (string $id, string $annee, string $niveau, string $grp, string $ue, string $sem, string $pcc, string $ptp, string $pexam, string $credit): array => [
            'idprog' => $id, 'anneeacademic' => $annee, 'codniveau' => $niveau, 'codgrp' => $grp, 'codue' => $ue, 'semestre' => $sem,
            'pcc' => $pcc, 'ptp' => $ptp, 'pexam' => $pexam, 'coef' => '1', 'credit' => $credit, 'cm' => '20', 'tp' => '0', 'semin' => '0', 'tpe' => '10', 'tge' => '0',
        ];
        $note = fn (string $id, string $prog, string $ue, string $m, ?string $cc, ?string $tp, ?string $exam, ?string $ratra = null, string $ok = '0', array $autres = []): array => array_merge([
            'idnote' => $id, 'idprog' => $prog, 'codue' => $ue, 'matricule' => $m, 'cc' => $cc, 'tp' => $tp, 'exam' => $exam, 'ratra' => $ratra,
            'ok' => $ok, 'moy' => '99', 'dateoperation' => '2025-07-01 10:00:00', 'IDUtilisateur' => '3', 'nometudiant' => 'NOM COPIE',
        ], $autres);
        $version = fn (string $id, string $quand, string $prog, string $ue, string $m, ?string $cc, ?string $exem, ?string $rattra = null, string $ok = '0'): array => [
            'idnotes' => $id, 'changedon' => $quand, 'idprog' => $prog, 'codue' => $ue, 'matricule' => $m, 'cc' => $cc, 'tp' => '0', 'exem' => $exem,
            'Rattra' => $rattra, 'ok' => $ok, 'usercon' => 'agent', 'IDutilisateur' => '3',
        ];
        $paiement = fn (string $id, string $m, string $niveau, string $annee, string $montant, ?string $date, array $autres = []): array => array_merge([
            'IddroitU' => $id, 'matricule' => $m, 'Niveau' => $niveau, 'Categorie' => 'AC', 'Montant' => $montant, 'NumeroRecu' => 'R-'.$id,
            'NomBanque' => 'CREMIN-CAM', 'anneacademic' => $annee, 'dateRecu' => $date, 'codephase' => 'AVANCE FRAIS SCOLARITE',
            'inscriptions' => '0', 'tenuesport' => '0', 'bibliotheque' => '0', 'fraisperiscol' => '0', 'Versement' => '258000000000',
            'RestePayer' => '0', 'username' => 'agent', 'nometudiant' => 'NOM COPIE', 'dateoperation' => $date === null ? null : $date.' 09:00:00',
        ], $autres);
        $bareme = fn (string $id, string $annee, string $niveau, string $cat, string $total): array => [
            'IDdroitC' => $id, 'anneaacademic' => $annee, 'Categorie' => $cat, 'montantAregler' => $total, 'niveau' => $niveau,
            'tranche1' => '1000000', 'tranche2' => '35000', 'tranche3' => '50000', 'inscription' => '50000', 'TenuSport' => $niveau === 'FPTC4' ? '50000' : '0',
        ];

        return [
            'anneeacademic' => $annees,
            'categorie' => [
                ['codcategorie' => 'TC', 'libelecategorie' => 'Tronc commun', 'codematricule' => 'EF'],
                ['codcategorie' => 'AC', 'libelecategorie' => 'Audit et contrôle', 'codematricule' => 'EF'],
            ],
            'filiere' => [
                ['codecycle' => 'Master', 'parcours' => 'MFP', 'libelefil' => 'Master'],
                ['codecycle' => 'Master', 'parcours' => 'POURTEST', 'libelefil' => 'Test'],
            ],
            'niveau' => array_map(fn (string $c): array => ['codniveau' => $c, 'parcours' => 'MFP', 'niveau' => $c === 'FPTC4' ? '1' : '2', 'level' => $c === 'FPTC4' ? '4' : '5'],
                ['AUCO5', 'EPGP5', 'FFCP5', 'FPTC4', 'GTFP5', 'MPPP5']),
            'phasepayement' => [
                ['codephase' => 'AVANCE FRAIS SCOLARITE', 'libellephase' => "Avance\x00\xff", 'ordrenum' => '1'],
                ['codephase' => 'FRAIS INSCRIPTION', 'libellephase' => 'Inscription', 'ordrenum' => '2'],
            ],
            'etablissement' => [[
                'codEtablissement' => 'PSSFP', 'LibelleEtab' => 'Établissement fictif', 'Lengende 1' => "Légende \"fictive\"\nsur deux lignes", 'MoyenSeuil' => '12',
                'plateforme' => 'https://plateforme.invalid', 'keyplateforme' => 'CLE-PLATEFORME-FICTIVE', 'userftp' => 'IDENTIFIANT-FTP-FICTIF',
                'paswdftp' => 'MOTDEPASSE-FTP-FICTIF', 'serverftp' => 'ftp.invalid', 'repftp' => '/depot',
            ]],
            'utilisateur' => [['IDUtilisateur' => '3', 'NomU' => 'AGENT FICTIF', 'LogginU' => 'agent', 'Motdepasse' => 'MOTDEPASSE-CLAIR-FICTIF']],
            'tachesutili' => [['id' => '1', 'action' => 'connexion']],
            'ue' => [
                ['codue' => 'FP101', 'codniveau' => 'FPTC4', 'libeleue' => 'Finances publiques (Public finance)'],
                ['codue' => 'FP102', 'codniveau' => 'FPTC4', 'libeleue' => 'Comptabilité publique'],
                ['codue' => 'AU301', 'codniveau' => 'AUCO5', 'libeleue' => 'Audit interne (Internal audit)'],
                ['codue' => 'AU302', 'codniveau' => 'AUCO5', 'libeleue' => 'Contrôle de gestion (Management control)'],
                ['codue' => 'GT301', 'codniveau' => 'GTFP5', 'libeleue' => 'Gouvernance locale (Local governance)'],
                ['codue' => 'FF301', 'codniveau' => 'FFCP5', 'libeleue' => 'Fiscalité (Taxation)'],
                ['codue' => 'IPS03', 'codniveau' => 'FPTC4', 'libeleue' => 'Initiation (Introduction)'],
            ],
            'grpue' => [
                ['codgrp' => 'G1M1', 'libelegrp' => 'Semestre 1', 'codniveau' => 'FPTC4'],
                ['codgrp' => 'G2M1', 'libelegrp' => 'Semestre 2', 'codniveau' => 'FPTC4'],
                ['codgrp' => 'G1M2', 'libelegrp' => 'Semestres 3 et 4', 'codniveau' => 'AUCO5'],
                ['codgrp' => 'G1GT', 'libelegrp' => 'Semestres 3 et 4', 'codniveau' => 'GTFP5'],
                ['codgrp' => 'G1FF', 'libelegrp' => 'Semestres 3 et 4', 'codniveau' => 'FFCP5'],
            ],
            'programe' => [
                $programme('101', '2024-2025', 'FPTC4', 'G1M1', 'FP101', '1', '0.3', '0', '0.7', '30'),
                $programme('102', '2024-2025', 'FPTC4', 'G2M1', 'FP102', '2', '0.4', '0.2', '0.4', '30'),
                $programme('201', '2025-2026', 'FPTC4', 'G1M1', 'FP101', '1', '0.3', '0', '0.7', '30'),
                $programme('202', '2025-2026', 'FPTC4', 'G2M1', 'FP102', '2', '0.4', '0.2', '0.4', '30'),
                $programme('301', '2025-2026', 'AUCO5', 'G1M2', 'AU301', '1', '0.3', '0', '0.7', '22'),
                $programme('302', '2025-2026', 'AUCO5', 'G1M2', 'AU302', '4', '0.5', '0.2', '0.5', '22'),
                $programme('303', '2025-2026', 'GTFP5', 'G1GT', 'GT301', '3', '0.3', '0', '0.7', '44'),
                $programme('401', '2022-2023', 'FPTC4', 'G1M1', 'FP101', '1', '0.3', '0', '0.7', '60'),
                $programme('501', '2026-2027', 'FPTC4', 'G1M1', 'IPS03', '1', '0.3', '0', '0.7', '30'),
                $programme('601', '2017-2018', 'FPTC4', 'G1M2', 'FP101', '7', '0.3', '0', '0.7', '60'),
                $programme('701', '2024-2025', 'FFCP5', 'G1FF', 'FF301', '3', '0.3', '0', '0.7', '60'),
            ],
            'etudiant' => [
                $etudiant('90EFP001', 'ESSAI  ALPHA BÉTA', '1985-03-12'),
                $etudiant('90efp002 ', 'essai gamma', '0000-00-00', 'F'),
                $etudiant('90EFP003', 'ESSAI DOUBLE', '1990-01-01'),
                $etudiant('90efp003', 'ESSAI DOUBLE', '1990-01-01'),
                $etudiant('zz90EFP004', 'ESSAI NEUTRE', '1991-01-01'),
                $etudiant('26EFP905', 'ESSAI NOUVEAU', '1992-02-02'),
                $etudiant('90EFP006', 'ESSAI SANS', '1993-03-03'),
                $etudiant('90EFP007', 'ESSAI PV', '2021-06-00'),
                $etudiant('90EFP008', 'ESSAI DELTA', '1980-07-07', 'H'),
                $etudiant('99XX12', 'ESSAI ANCIEN UN', '0012-01-01'),
                $etudiant('99XX012', 'ESSAI ANCIEN DEUX', null, ''),
                $etudiant('90EFP010-', 'ESSAI TIRET', '1981-01-01'),
                $etudiant('90EFP011', 'ESSAI EPSILON', '1982-02-02'),
                $etudiant('90EFP012', 'ESSAI ZETA', '1984-04-04'),
                $etudiant('90EFP013', 'ESSAI ETA THETA', '1986-06-06', 'F'),
                $etudiant('90EFP014', 'ESSAI IOTA', '1987-08-08'),
                $etudiant('90EFP015', 'ESSAI KAPPA', '1988-09-09'),
                $etudiant('90EFP016', 'ESSAI LAMBDA', '1975-01-01', 'F', ['typedatenais' => '3', 'datenaisbis' => '1975']),
                $etudiant('24EFP917', 'ESSAI HORS', '1979-10-10'),
                $etudiant('POURTEST1', 'ESSAI TEST', '1990-01-01', 'M', ['parcours' => 'POURTEST']),
            ],
            'inscription_academic' => [
                $inscription('1', 'FPTC4', '90EFP001', '2024-2025', '1', ['moydunivo' => '13.5', 'Totalcredit' => '60', 'NbreDette' => '0', 'Fraiscolarite' => '1185000']),
                $inscription('2', 'AUCO5', '90EFP001', '2025-2026', '0', ['moydunivo' => '0', 'Totalcredit' => '22']),
                $inscription('3', null, '90EFP001', null),
                $inscription('4', 'FPTC4', '90efp002 ', '2025-2026', '0', ['moydunivo' => '8.7']),
                $inscription('5', 'FPTC4', '90EFP003', '2025-2026', '0'),
                $inscription('6', 'FPTC4', '90efp003', '2025-2026', '0'),
                $inscription('7', 'FPTC4', 'zz90EFP004', '2025-2026', '0'),
                $inscription('8', 'FPTC4', '26EFP905', '2026-2027', '0'),
                $inscription('9', null, '90EFP007', null),
                $inscription('10', 'FPTC4', '90EFP008', '2022-2023', '1', ['moydunivo' => '13.7']),
                $inscription('11', 'FPTC4', '99XX12', '2013-2014', '0'),
                $inscription('12', 'FPTC4', '99XX012', '2013-2014', '0'),
                $inscription('13', 'FPTC4', '90EFP010-', '2017-2018', '1', ['moydunivo' => '12', 'Totalcredit' => '60']),
                $inscription('14', 'FPTC4', '90EFP011', '2025-2026', '0'),
                $inscription('15', 'FPTC4', '90EFP012', '2025-2026', '0'),
                $inscription('16', 'FPTC4', '90EFP013', '2025-2026', '0'),
                $inscription('17', 'FPTC4', '90EFP014', '2024-2025', '0'),
                $inscription('18', 'FPTC4', '90EFP014', '2025-2026', '1', ['moydunivo' => '13.9', 'Totalcredit' => '60', 'redouble' => '1']),
                $inscription('19', 'GTFP5', '90EFP015', '2025-2026', '0'),
                $inscription('20', 'FPTC4', '90EFP015', '2025-2026', '0', ['NbreDette' => '1']),
                $inscription('21', 'FFCP5', '90EFP016', '2024-2025', '1'),
                $inscription('22', 'AUCO5', '24EFP917', '2025-2026', '0'),
                $inscription('23', 'AUCO5', '24EFP917', '2026-2027', '0'),
                $inscription('24', 'FPTC4', 'POURTEST1', '2025-2026', '0'),
                $inscription('25', 'FPTC4', '90EFP011', '2025/2026', '0'),
            ],
            'notes' => [
                $note('1001', '101', 'FP101', '90EFP001', '14', '0', '13'),
                $note('1002', '102', 'FP102', '90EFP001', '12', '15', '11'),
                $note('1003', '301', 'AU301', '90EFP001', '10', '0', '8', '12.5', '1'),
                $note('1004', '302', 'AU302', '90EFP001', '25', '10', '9'),
                $note('1005', '201', 'FP101', '90efp002 ', '8', '0', '9', '14', '0'),
                $note('1006', '201', 'FP101', '90EFP003', '12', '0', '12'),
                $note('1007', '201', 'FP101', '90efp003', '15', '0', '15'),
                $note('1008', '401', 'FP101', '90EFP008', '13', '0', '14', null, '0', ['anneeacademic' => '2022-2023']),
                $note('1009', '601', 'FP101', '90EFP010-', '12', '0', '12'),
                $note('1010', '501', 'IPS03', '26EFP905', '0', '0', '0', null, '0', ['dateoperation' => '2026-11-01 08:00:00']),
                $note('1011', '303', 'GT301', '90EFP015', '12', '0', '13'),
                $note('1012', '701', 'FF301', '90EFP016', '14', '0', '13'),
                $note('1013', '201', 'FP101', 'zz90EFP004', '10', '0', '10'),
                $note('1014', '201', 'FP101', '90EFP013', '11', '0', '12.333333333'),
                $note('7001', '201', 'FP101', '90EFP014', '13', '0', '13', null, '0', ['anneeacademic' => '2026-2027']),
                $note('7002', '202', 'FP102', '90EFP014', '14', '14', '14'),
            ],
            'notes_hist' => [
                $version('1001', '2025-03-03 10:00:00', '101', 'FP101', '90EFP001', '14', '12'),
                $version('1001', '2025-03-04 10:00:00', '101', 'FP101', '90EFP001', '14', '13'),
                $version('7001', '2025-01-10 10:00:00', '101', 'FP101', '90EFP014', '9', '8'),
                $version('7001', '2025-02-01 09:00:00', '101', 'FP101', '90EFP014', '10', '9'),
                $version('7001', '2026-01-15 08:00:00', '201', 'FP101', '90EFP014', '13', '13'),
                $version('8001', '2024-06-01 10:00:00', '999', 'XX999', '90EFP014', '11', '12'),
                $version('1010', '2026-11-01 08:00:00', '501', 'IPS03', '26EFP905', '0', '0'),
                $version('1013', '2025-05-05 10:00:00', '201', 'FP101', 'zz90EFP004', '10', '10'),
            ],
            'configurationdu' => [
                $bareme('1', '2013-2014', 'FPTC4', 'AC', '1145000'),
                $bareme('2', '2024-2025', 'FPTC4', 'AC', '1185000'),
                $bareme('3', '2024-2025', 'FFCP5', 'AC', '1135000'),
                $bareme('4', '2025-2026', 'FPTC4', 'AC', '1185000'),
                $bareme('5', '2025-2026', 'FPTC4', 'TC', '1185000'),
                $bareme('6', '2025-2026', 'AUCO5', 'AC', '1135000'),
                $bareme('7', '2025-2026', 'GTFP5', 'AC', '1135000'),
                $bareme('8', '2026-2027', 'FPTC4', 'AC', '1185000'),
            ],
            'du' => [
                $paiement('1', '90EFP001', 'FPTC4', '2024-2025', '685000', '2024-11-02', ['inscriptions' => '50000']),
                $paiement('2', '90EFP001', 'FPTC4', '2024-2025', '-100000', '2024-12-01', ['NumeroRecu' => 'R-2']),
                $paiement('3', '90EFP001', 'FPTC4', '2024-2025', '100000', '2024-12-01', ['NumeroRecu' => 'R-2']),
                $paiement('4', '90EFP001', 'AUCO5', '2025-2026', '500000', '2025-12-10'),
                $paiement('5', '90EFP001', 'AUCO5', '2025-2026', '500000', '2025-12-10', ['NumeroRecu' => 'R-4']),
                $paiement('6', '90efp002 ', 'FPTC4', '2025-2026', '-50000', '2026-01-05'),
                $paiement('7', '90EFP011', 'FPTC4', '2025-2026', '0', '2230-01-01'),
                $paiement('8', '90EFP012', 'FPTC4', '2025-2026', '300000', '1120-05-05', ['NomBanque' => 'EN-ESPECES']),
                $paiement('9', '90EFP013', 'FPTC4', '2025-2026', '400000', null),
                $paiement('10', '90EFP014', 'FPTC4', '2024-2025', '200000', '2019-01-01'),
                $paiement('11', '90EFP015', 'AUCO5', '2025-2026', '100000', '2025-11-11', ['bibliotheque' => '35000', 'fraisperiscol' => '50000', 'codephase' => 'FRAIS INSCRIPTION']),
                $paiement('12', 'zz90EFP004', 'FPTC4', '2025-2026', '1185000', '2025-11-01'),
                $paiement('13', '26EFP905', 'FPTC4', '2026-2027', '500000', '2026-10-01', ['inscriptions' => '185000', 'NumeroRecu' => 'CR 2026/001']),
                $paiement('14', '26EFP905', 'FPTC4', '2026-2027', '300000', '2026-10-20', ['NumeroRecu' => 'R-ACMA-14']),
                $paiement('15', '24EFP917', 'AUCO5', '2026-2027', '350000', '2026-10-05'),
                $paiement('16', '26EFP905', 'FPTC4', '2026-2027', '200000', '2026-11-02', ['NumeroRecu' => 'R-PARTAGE']),
                $paiement('17', '26EFP905', 'FPTC4', '2026-2027', '100000', '2026-11-02', ['NumeroRecu' => 'R-PARTAGE']),
                $paiement('18', '90EFP001', 'AUCO5', '2026-2027', '250000', '2026-10-15'),
            ],
            'pvsoutenance' => [
                ['idpvSoute' => '1', 'matricule' => '90EFP001', 'niveau' => 'AUCO5', 'jourDeSoutence' => '15  juillet  2026', 'Memoire' => 'Mémoire fictif', 'President' => 'PRÉSIDENT FICTIF', 'Rapporteur' => 'RAPPORTEUR FICTIF', 'Membre1' => 'MEMBRE UN', 'Membre2' => null, 'NoteSoutenance' => '16.5', 'MentionSoutena' => 'TRES-BIEN', 'dateoperation' => '2026-07-16 10:00:00'],
                ['idpvSoute' => '2', 'matricule' => '90EFP001', 'niveau' => 'AUCO5', 'jourDeSoutence' => '12/08/2026', 'Memoire' => 'Mémoire fictif repris', 'NoteSoutenance' => '17', 'MentionSoutena' => 'TRES BIEN', 'dateoperation' => '2026-08-12 10:00:00'],
                ['idpvSoute' => '3', 'matricule' => '90EFP007', 'niveau' => 'AUCO5', 'jourDeSoutence' => '3 aout 2019', 'NoteSoutenance' => '14', 'MentionSoutena' => 'BIEN ', 'dateoperation' => '2019-08-03 10:00:00'],
                ['idpvSoute' => '4', 'matricule' => 'zz90EFP004', 'niveau' => 'AUCO5', 'jourDeSoutence' => 'date illisible', 'NoteSoutenance' => '12', 'MentionSoutena' => '', 'dateoperation' => null],
                ['idpvSoute' => '5', 'matricule' => '90EFP016', 'niveau' => 'FFCP5', 'jourDeSoutence' => '9decembre 2025', 'NoteSoutenance' => '15', 'MentionSoutena' => 'TRES-BIEN / VERY GOO', 'dateoperation' => '2025-12-09 10:00:00'],
            ],
            'certificat' => [
                ['IdCertificat' => '1', 'Matricule' => '90EFP001', 'Niveau' => 'FPTC4', 'Datedujour' => '2024-2025', 'datenaissance' => 'COPIE', 'Nometudiant' => 'NOM COPIE', 'specialite' => 'Tronc commun', 'Parcourt' => 'Master'],
            ],
            'attestationr' => [
                ['Idattes' => '1', 'Matricule' => '90EFP001', 'Niveau' => 'AUCO5', 'datedujour' => '2026-08-01 10:00:00', 'specialite' => 'Audit', 'session' => 'Juillet 2026', 'Memoire' => 'Mémoire fictif', 'NomEtudiant' => 'NOM COPIE'],
            ],
            'misedispos' => [
                ['Idmise' => '1', 'Matricule' => '90EFP016', 'Niveau' => 'FFCP5', 'jourOperation' => null, 'specialite' => 'Fiscalité', 'NoteMemoire' => '15', 'Mention' => 'Très bien', 'NomEtudiant' => 'NOM COPIE'],
            ],
            'nonperceptionb' => [
                ['Idnonper' => '1', 'Matricule' => '90EFP014', 'Niveau' => 'FPTC4', 'datedujour' => '2025-09-01 00:00:00', 'Nometudiant' => 'NOM COPIE', 'Parcourt' => 'Master'],
            ],
            'prisecours' => [
                ['IdPrise' => '1', 'Matricule' => '90EFP011', 'Niveau' => 'FPTC4', 'datedujour' => '2025-10-10 00:00:00', 'Specialite' => 'Tronc commun', 'DebutPriseCours' => '01/11/2025', 'FinpriseCours' => '31/12/2025', 'Nometudiant' => 'NOM COPIE', 'themestage' => 'Stage fictif', 'structureAcueil' => 'Structure fictive', 'anneeacademique' => '2025-2026', 'dureestage' => '2 mois'],
            ],
        ];
    }

    /**
     * Tables au format de la source : colonnes et lignes en listes, dans l'ordre du schéma.
     *
     * @param  array<string, list<array<string, string|null>>>|null  $lignes
     * @return array<string, array{colonnes: list<array{nom: string, type: string, cle: bool}>, lignes: list<list<string|null>>}>
     */
    public static function tables(?array $lignes = null): array
    {
        $lignes ??= self::lignes();
        $tables = [];
        foreach (self::SCHEMA as $table => $colonnes) {
            $definition = array_map(function (string $c): array {
                $cle = str_starts_with($c, '*');
                [$nom, $type] = explode(':', ltrim($c, '*'), 2);

                return ['nom' => $nom, 'type' => $type, 'cle' => $cle];
            }, $colonnes);
            $noms = array_column($definition, 'nom');
            $tables[$table] = [
                'colonnes' => $definition,
                'lignes' => array_map(function (array $l) use ($noms, $table): array {
                    $inconnues = array_diff(array_keys($l), $noms);
                    if ($inconnues !== []) {
                        throw new \LogicException("Colonnes inconnues dans {$table} : ".implode(', ', $inconnues));
                    }

                    return array_map(fn (string $n): ?string => $l[$n] ?? null, $noms);
                }, $lignes[$table] ?? []),
            ];
        }

        return $tables;
    }

    /** Noms et matricules du jeu (pour vérifier qu'aucune sortie ne les cite). @return list<string> */
    public static function donneesNominatives(): array
    {
        $valeurs = [];
        foreach (self::lignes()['etudiant'] as $e) {
            $valeurs[] = trim((string) $e['matricule']);
            $valeurs[] = trim((string) $e['nometudiant']);
        }

        return array_values(array_unique(array_filter($valeurs)));
    }
}
