<?php
namespace theme_pssfp\output;

use core_course_category;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

/**
 * Identite PSSFP : logo, accueil editorial et pied de page institutionnel.
 */
class core_renderer extends \theme_boost\output\core_renderer {
    /** Sites du PSSFP, repris des adresses de production. */
    private const SITE_URL = 'https://pssfp.org';
    private const FORMATIONS_URL = 'https://pssfp.org/formations';
    private const LIBRARY_URL = 'https://library.pssfp.org';
    private const AUDITOR_URL = 'https://apply.pssfp.org/login?mode=matricule';
    private const CONTACT_URL = 'https://pssfp.org/contact';
    private const CONTACT_EMAIL = 'admissions@pssfp.org';

    public function get_logo_url($maxwidth = null, $maxheight = 200) {
        return $this->image_url('logo', 'theme_pssfp');
    }

    public function get_compact_logo_url($maxwidth = 300, $maxheight = 300) {
        return $this->image_url('logo', 'theme_pssfp');
    }

    public function should_display_navbar_logo() {
        return true;
    }

    public function favicon() {
        return $this->image_url('favicon', 'theme_pssfp');
    }

    /**
     * Accueil editorial, affiche uniquement sur la page d'accueil du site.
     */
    public function pssfp_hero(): string {
        global $DB, $USER;

        if ($this->page->pagelayout !== 'frontpage') {
            return '';
        }

        $loggedin = isloggedin() && !isguestuser();
        $context = [
            'loggedin' => $loggedin,
            'firstname' => $loggedin ? $USER->firstname : '',
            'loginurl' => (new moodle_url('/login/index.php'))->out(false),
            'mycoursesurl' => (new moodle_url('/my/courses.php'))->out(false),
            'photourl' => $this->image_url('campus-cercle', 'theme_pssfp')->out(false),
            'coursecount' => $DB->count_records_select('course', 'id <> :site AND visible = 1', ['site' => SITEID]),
            'auditorcount' => $this->pssfp_auditor_count(),
            'announcement' => $this->pssfp_announcement(),
            'formationsurl' => self::FORMATIONS_URL,
        ];
        return $this->render_from_template('theme_pssfp/hero', $context);
    }

    /**
     * Bas de l'accueil, sous le contenu de Moodle : les comptes connectes retrouvent d'abord leurs cours.
     */
    public function pssfp_home_sections(): string {
        if ($this->page->pagelayout !== 'frontpage') {
            return '';
        }

        $loggedin = isloggedin() && !isguestuser();
        return $this->render_from_template('theme_pssfp/homesections', [
            'loggedin' => $loggedin,
            // Les cours sont prives : le detail des parcours ne part pas vers un visiteur non connecte.
            'programmes' => $loggedin ? $this->pssfp_programmes() : [],
            'libraryurl' => self::LIBRARY_URL,
            'auditorurl' => self::AUDITOR_URL,
            'siteurl' => self::SITE_URL,
            'contacturl' => self::CONTACT_URL,
        ]);
    }

    /**
     * Tous les auditeurs : comptes ayant le role etudiant dans un cours, y compris suspendus d'un cours passe.
     */
    private function pssfp_auditor_count(): int {
        global $DB;

        return $DB->count_records_sql(
            "SELECT COUNT(DISTINCT ra.userid)
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
               JOIN {context} x ON x.id = ra.contextid AND x.contextlevel = :level
               JOIN {user} u ON u.id = ra.userid AND u.deleted = 0 AND u.suspended = 0",
            ['level' => CONTEXT_COURSE]
        );
    }

    /**
     * Annonce de l'accueil, dans la langue affichee, jusqu'a sa date de fin incluse.
     */
    private function pssfp_announcement(): ?array {
        $config = get_config('theme_pssfp');
        $lang = str_starts_with(current_language(), 'en') ? 'en' : 'fr';
        $title = trim($config->{"announcement_title_{$lang}"} ?? '');
        $until = trim($config->announcement_until ?? '');
        $today = (new \DateTimeImmutable('now', \core_date::get_server_timezone_object()))->format('Y-m-d');

        if ($title === '' || ($until !== '' && $today > $until)) {
            return null;
        }
        return [
            'title' => $title,
            'when' => $config->{"announcement_when_{$lang}"} ?? '',
            'where' => $config->{"announcement_where_{$lang}"} ?? '',
            'note' => $config->{"announcement_note_{$lang}"} ?? '',
        ];
    }

    /**
     * Les parcours : chaque categorie de premier niveau et ses subdivisions qui contiennent des cours.
     */
    private function pssfp_programmes(): array {
        $programmes = [];
        foreach (core_course_category::top()->get_children() as $category) {
            $items = $this->pssfp_categories_with_courses($category);
            if ($items) {
                $programmes[] = ['name' => $category->get_formatted_name(), 'items' => $items];
            }
        }
        return $programmes;
    }

    private function pssfp_categories_with_courses(core_course_category $parent): array {
        $items = [];
        foreach ($parent->get_children() as $category) {
            if ($category->get_courses_count() > 0) {
                $items[] = [
                    'name' => $category->get_formatted_name(),
                    'url' => (new moodle_url('/course/index.php', ['categoryid' => $category->id]))->out(false),
                    'count' => $category->get_courses_count(),
                ];
            }
            $items = array_merge($items, $this->pssfp_categories_with_courses($category));
        }
        return $items;
    }

    /**
     * Pied de page institutionnel, sous le contenu de toutes les pages avec navigation.
     */
    public function pssfp_footer(): string {
        return $this->render_from_template('theme_pssfp/footer', [
            'logourl' => $this->image_url('logo', 'theme_pssfp')->out(false),
            'year' => date('Y'),
            'siteurl' => self::SITE_URL,
            'libraryurl' => self::LIBRARY_URL,
            'auditorurl' => self::AUDITOR_URL,
            'contacturl' => self::CONTACT_URL,
            'contactemail' => self::CONTACT_EMAIL,
        ]);
    }
}
