<?php
defined('MOODLE_INTERNAL') || die();

function theme_pssfp_get_main_scss_content($theme) {
    global $CFG;
    return file_get_contents($CFG->dirroot . '/theme/boost/scss/preset/default.scss');
}

function theme_pssfp_get_pre_scss($theme) {
    return file_get_contents(__DIR__ . '/scss/pre.scss');
}

function theme_pssfp_get_extra_scss($theme) {
    return file_get_contents(__DIR__ . '/scss/post.scss');
}
