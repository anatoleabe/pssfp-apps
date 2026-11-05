<?php

// Crée sur eCampus le service web du portail PSSFP : rôle minimal, compte
// technique sans connexion possible, service limité à trois fonctions, jeton
// limité à l'adresse IP du portail.
//
// Usage : sudo -u www-data php creer-service-portail.php --ip=<IP du portail>
//
// Relancer le script remplace le jeton. Le nouveau jeton sort seul sur la sortie
// standard, pour être transmis sans s'afficher ; le reste passe par stderr.

define('CLI_SCRIPT', true);

require('/var/www/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/webservice/lib.php');

const PORTAIL_NOM_COURT = 'portail_pssfp';
const PORTAIL_FONCTIONS = [
    'core_user_get_users_by_field',
    'core_user_create_users',
    'core_cohort_add_cohort_members',
];
const PORTAIL_DROITS = [
    'webservice/rest:use',
    'moodle/user:create',
    'moodle/user:viewdetails',
    'moodle/user:viewalldetails',
    'moodle/site:viewuseridentity',
    'moodle/cohort:assign',
    'moodle/cohort:view',
];

[$options] = cli_get_params(['ip' => '']);
if (filter_var($options['ip'], FILTER_VALIDATE_IP) === false) {
    fwrite(STDERR, "Indiquez l'adresse IP du portail : --ip=<adresse>\n");
    exit(1);
}

\core\session\manager::set_user(get_admin());
$contexte = context_system::instance();

// Rôle.
$role = $DB->get_record('role', ['shortname' => PORTAIL_NOM_COURT]);
$roleid = $role ? (int) $role->id : create_role(
    'Portail PSSFP (service web)',
    PORTAIL_NOM_COURT,
    'Création des comptes des auditeurs par le portail. Aucun autre usage.',
);
set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
// Relancer le script rétablit exactement ces droits.
foreach ($DB->get_records('role_capabilities', ['roleid' => $roleid, 'contextid' => $contexte->id]) as $existant) {
    if (!in_array($existant->capability, PORTAIL_DROITS, true) && $existant->capability !== 'moodle/user:changeownpassword') {
        unassign_capability($existant->capability, $roleid, $contexte->id);
    }
}
foreach (PORTAIL_DROITS as $droit) {
    assign_capability($droit, CAP_ALLOW, $roleid, $contexte->id, true);
}
// Ferme « mot de passe oublié » : ce compte ne doit jamais pouvoir se connecter.
assign_capability('moodle/user:changeownpassword', CAP_PROHIBIT, $roleid, $contexte->id, true);

// Compte technique : mot de passe inutilisable, aucun message reçu.
$compte = $DB->get_record('user', ['username' => PORTAIL_NOM_COURT, 'deleted' => 0, 'mnethostid' => $CFG->mnet_localhost_id]);
if (!$compte) {
    $id = \core\user::create_user((object) [
        'username' => PORTAIL_NOM_COURT,
        'auth' => 'manual',
        'password' => AUTH_PASSWORD_NOT_CACHED,
        'confirmed' => 1,
        'policyagreed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
        'firstname' => 'Portail',
        'lastname' => 'PSSFP',
        'email' => 'portail-service@pssfp.invalid',
        'emailstop' => 1,
    ], false, false);
    $compte = $DB->get_record('user', ['id' => $id], '*', MUST_EXIST);
}
$DB->set_field('user', 'password', AUTH_PASSWORD_NOT_CACHED, ['id' => $compte->id]);
role_assign($roleid, $compte->id, $contexte->id);

// Service restreint à ce compte et à cette adresse.
$gestion = new webservice();
$service = $gestion->get_external_service_by_shortname(PORTAIL_NOM_COURT);
if (!$service) {
    $serviceid = $gestion->add_external_service((object) [
        'name' => 'Portail PSSFP',
        'shortname' => PORTAIL_NOM_COURT,
        'enabled' => 1,
        'restrictedusers' => 1,
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ]);
    $service = $gestion->get_external_service_by_id($serviceid, MUST_EXIST);
}
$DB->update_record('external_services', (object) ['id' => $service->id, 'enabled' => 1, 'restrictedusers' => 1]);
foreach ($DB->get_records('external_services_functions', ['externalserviceid' => $service->id]) as $existante) {
    if (!in_array($existante->functionname, PORTAIL_FONCTIONS, true)) {
        $gestion->remove_external_function_from_service($existante->functionname, $service->id);
    }
}
foreach (PORTAIL_FONCTIONS as $fonction) {
    if (!$gestion->service_function_exists($fonction, $service->id)) {
        $gestion->add_external_function_to_service($fonction, $service->id);
    }
}
$DB->delete_records('external_services_users', ['externalserviceid' => $service->id, 'userid' => $compte->id]);
$gestion->add_ws_authorised_user((object) [
    'externalserviceid' => $service->id,
    'userid' => $compte->id,
    'iprestriction' => $options['ip'],
    'validuntil' => null,
]);

// Un seul jeton vivant pour ce service.
$DB->delete_records('external_tokens', ['externalserviceid' => $service->id, 'userid' => $compte->id]);
$jeton = \core_external\util::generate_token(
    EXTERNAL_TOKEN_PERMANENT,
    $service,
    (int) $compte->id,
    $contexte,
    0,
    $options['ip'],
    'Portail PSSFP',
);

fwrite(STDERR, "Service prêt : {$service->shortname}, jeton limité à {$options['ip']}.\n");
echo $jeton . "\n";
