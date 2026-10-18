-- Mini-dump FICTIF au format phpMyAdmin (tests de biblio:extract-joomla).
-- Aucun contenu réel : titres, noms et chemins sont inventés.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
/*!40101 SET NAMES utf8mb4 */;

--
-- Table structure for table `tst01_abauthor`
--

CREATE TABLE `tst01_abauthor` (
  `id` int NOT NULL,
  `lastname` varchar(255) NOT NULL DEFAULT '',
  `name` varchar(255) DEFAULT '',
  `alias` varchar(255) NOT NULL,
  `description` mediumtext
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

INSERT INTO `tst01_abauthor` (`id`, `lastname`, `name`, `alias`, `description`) VALUES
(1, 'Fictivus', 'Alba', 'alba-fictivus', NULL),
(2, 'Imaginaire', 'Bruno', 'bruno-imaginaire', 'Auteur d\'exemple'),
(3, 'N\'Exemple', 'Chloé', 'chloe-n-exemple', '');

-- --------------------------------------------------------

CREATE TABLE `tst01_abbook` (
  `id` int NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `alias` varchar(255) NOT NULL,
  `pag` int DEFAULT '0',
  `description` mediumtext,
  `image` varchar(255) DEFAULT NULL,
  `file` varchar(255) DEFAULT NULL,
  `year` int DEFAULT NULL,
  `hits` int DEFAULT '0',
  `state` tinyint NOT NULL DEFAULT '0',
  `catid` int DEFAULT NULL,
  `isbn` varchar(255) DEFAULT NULL,
  `approved` tinyint(1) DEFAULT '0',
  `url` varchar(255) DEFAULT NULL,
  `dateinsert` datetime DEFAULT NULL,
  `access` tinyint NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

INSERT INTO `tst01_abbook` (`id`, `title`, `subtitle`, `alias`, `pag`, `description`, `image`, `file`, `year`, `hits`, `state`, `catid`, `isbn`, `approved`, `url`, `dateinsert`, `access`) VALUES
(10, 'Manuel imaginaire de finances', NULL, 'manuel-imaginaire-de-finances', 120, '<p>Premier paragraphe.</p>\r\n<p>Second paragraphe ; avec point-virgule.</p>', 'images/couvertures/manuel-imaginaire.jpg', 'manuel-imaginaire.pdf', 2015, 42, 1, 3, '978-0-00-000000-0', 1, '', '2016-03-01 10:00:00', 1),
(11, 'Guide (fictif) du budget', 'Tome 2', 'guide-fictif-du-budget', 0, '', '', 'guide-fictif.pdf', 0, 0, 0, 3, NULL, 0, NULL, '0000-00-00 00:00:00', 1);

-- Table sans liste de colonnes dans l'INSERT : colonnes reprises du CREATE TABLE.
CREATE TABLE `tst01_abbookauth` (
  `id` int NOT NULL,
  `idbook` int NOT NULL DEFAULT '0',
  `idauth` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

INSERT INTO `tst01_abbookauth` VALUES (3, 11, 3),(1, 10, 2),(2, 10, 1),(4, 11, 1);

CREATE TABLE `tst01_abcategories` (
  `id` int NOT NULL,
  `parent_id` int UNSIGNED NOT NULL DEFAULT '0',
  `path` varchar(255) NOT NULL DEFAULT '',
  `extension` varchar(50) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL,
  `alias` varchar(255) NOT NULL DEFAULT '',
  `published` tinyint(1) NOT NULL DEFAULT '0',
  `access` tinyint UNSIGNED NOT NULL DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

INSERT INTO `tst01_abcategories` (`id`, `parent_id`, `path`, `extension`, `title`, `alias`, `published`, `access`) VALUES
(1, 0, '', 'system', 'ROOT', 'root', 1, 1),
(2, 1, 'ouvrages-fictifs', 'com_abook', 'OUVRAGES FICTIFS', 'ouvrages-fictifs', 1, 1),
(3, 2, 'ouvrages-fictifs/finances-imaginaires', 'com_abook', 'FINANCES IMAGINAIRES', 'finances-imaginaires', 1, 1);

-- Table ignorée : sa chaîne contient un faux INSERT et des points-virgules.
CREATE TABLE `tst01_session` (
  `session_id` varchar(200) NOT NULL,
  `data` mediumtext
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

INSERT INTO `tst01_session` (`session_id`, `data`) VALUES
('abc', 'ligne un ;
INSERT INTO `tst01_menu` (`path`) VALUES
(\'piege\');
fin \\'),
('def', 'rien');

CREATE TABLE `tst01_docman_categories` (
  `docman_category_id` int NOT NULL,
  `uuid` char(36) NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text,
  `access` int NOT NULL DEFAULT '1',
  `enabled` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

INSERT INTO `tst01_docman_categories` (`docman_category_id`, `uuid`, `title`, `slug`, `description`, `access`, `enabled`) VALUES
(1, '00000000-0000-4000-8000-000000000001', 'Textes fictifs', 'textes-fictifs', NULL, 1, 1),
(2, '00000000-0000-4000-8000-000000000002', 'Arrêtés fictifs', 'arretes-fictifs', '', 1, 1),
(3, '00000000-0000-4000-8000-000000000003', 'Arrêtés de l\'exemple', 'arretes-exemple', '', 2, 0);

INSERT INTO `tst01_docman_category_relations` (`ancestor_id`, `descendant_id`, `level`) VALUES (1, 1, 0),(2, 2, 0),(1, 2, 1),(3, 3, 0),(2, 3, 1),(1, 3, 2);

INSERT INTO `tst01_docman_documents` (`docman_document_id`, `uuid`, `title`, `slug`, `docman_category_id`, `description`, `storage_type`, `storage_path`, `hits`, `enabled`, `access`, `publish_on`, `created_on`) VALUES
(101, '00000000-0000-4000-8000-000000000101', 'Arrêté n°001 portant règle de l\'exemple', 'arrete-001', 2, '<p>Ligne une
Ligne deux avec "guillemets" échappés : \"ok\"</p>', 'file', 'ARRETES FICTIFS/Arrêté n°001.pdf', 12, 1, 1, '2019-05-02 08:30:00', '2019-05-01 08:30:00'),
(102, '00000000-0000-4000-8000-000000000102', 'Circulaire C:\\chemin\\fictif', 'circulaire-fictive', 3, NULL, 'file', 'CIRCULAIRES/c-fictive.pdf', 0, 0, 1, '0000-00-00 00:00:00', '2020-01-15 00:00:00');
INSERT INTO `tst01_docman_documents` (`docman_document_id`, `uuid`, `title`, `slug`, `docman_category_id`, `description`, `storage_type`, `storage_path`, `hits`, `enabled`, `access`, `publish_on`, `created_on`) VALUES
(103, '00000000-0000-4000-8000-000000000103', 'Note ''double'' et tab\tici', 'note-double', 1, 'Texte\0nul', 'remote', 'https://exemple.invalid/note.pdf', -1, 1, 1, '2021-07-01 00:00:00', '2021-06-30 12:00:00');

INSERT INTO `tst01_menu` (`id`, `menutype`, `title`, `path`, `link`, `type`, `published`, `client_id`) VALUES
(201, 'mainmenu', 'Arrêtés', 'textes/arretes-fictifs', 'index.php?option=com_docman&view=list&layout=table&slug=arretes-fictifs', 'component', 1, 0),
(202, 'mainmenu', 'Finances', 'e-books/finances-imaginaires', 'index.php?option=com_abook&view=category&id=3', 'component', 1, 0),
(203, 'mainmenu', 'Ancien', 'ancien', 'index.php?option=com_docman&view=list&slug=textes-fictifs', 'component', 0, 0),
(204, 'mainmenu', 'Accueil', 'accueil', 'index.php?option=com_content&view=featured', 'component', 1, 0),
(205, 'mainmenu', 'Export', 'export', 'index.php?option=com_docman_export', 'component', 1, 0),
(206, 'main', 'Livres', 'com-abook/livres', 'index.php?option=com_abook&view=books', 'component', 1, 1),
(207, 'mainmenu', 'Bibliothèque', 'bibliotheque', 'index.php?option=com_docman&view=list&layout=table', 'component', 1, 0);
COMMIT;
