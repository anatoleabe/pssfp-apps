<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'theme_pssfp/announcementheading',
        get_string('settingannouncement', 'theme_pssfp'),
        get_string('settingannouncementdesc', 'theme_pssfp')
    ));
    foreach (['title', 'when', 'where', 'note'] as $field) {
        foreach (['fr', 'en'] as $lang) {
            $settings->add(new admin_setting_configtext(
                "theme_pssfp/announcement_{$field}_{$lang}",
                get_string("setting{$field}", 'theme_pssfp') . " ({$lang})",
                '',
                '',
                PARAM_TEXT
            ));
        }
    }
    $settings->add(new admin_setting_configtext(
        'theme_pssfp/announcement_until',
        get_string('settinguntil', 'theme_pssfp'),
        get_string('settinguntildesc', 'theme_pssfp'),
        '',
        '/^(\d{4}-\d{2}-\d{2})?$/'
    ));
}
