<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Site settings of the MAVR companion service run mode, and its connection test page.
 *
 * Included by \mod_mavr\plugininfo\mavrruntime::load_settings(), which provides $ADMIN, $settings
 * (the page mavrruntimesettingcompanion) and $parentnodename (the MAVR settings category).
 *
 * @package    mavrruntime_companion
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$ADMIN->add($parentnodename, new admin_externalpage(
    'mavrruntimecompaniontest',
    new lang_string('testconnection', 'mavrruntime_companion'),
    new moodle_url('/mod/mavr/runtime/companion/testconnection.php'),
    'moodle/site:config'
));

if ($ADMIN->fulltree) {
    $testurl = (new moodle_url('/mod/mavr/runtime/companion/testconnection.php'))->out(false);
    $settings->add(new admin_setting_heading(
        'mavrruntime_companion/settingsheading',
        new lang_string('settingsheading', 'mavrruntime_companion'),
        get_string('settingsheading_desc', 'mavrruntime_companion') . ' '
            . get_string('testconnection_desc', 'mavrruntime_companion', $testurl)
    ));

    $settings->add(new \mavrruntime_companion\admin\setting_baseurl(
        'mavrruntime_companion/url',
        new lang_string('url', 'mavrruntime_companion'),
        new lang_string('url_desc', 'mavrruntime_companion')
    ));

    $settings->add(new \mavrruntime_companion\admin\setting_baseurl(
        'mavrruntime_companion/serverurl',
        new lang_string('serverurl', 'mavrruntime_companion'),
        new lang_string('serverurl_desc', 'mavrruntime_companion')
    ));

    $settings->add(new \mavrruntime_companion\admin\setting_secret(
        'mavrruntime_companion/secret',
        new lang_string('secret', 'mavrruntime_companion'),
        new lang_string('secret_desc', 'mavrruntime_companion')
    ));

    $minsize = \mavrruntime_companion\local\config::MIN_USERS;
    $maxsize = \mavrruntime_companion\local\config::MAX_USERS;
    $choices = [];
    foreach (range($minsize, $maxsize) as $size) {
        $choices[$size] = (string)$size;
    }
    $settings->add(new admin_setting_configselect(
        'mavrruntime_companion/maxusers',
        new lang_string('maxusers', 'mavrruntime_companion'),
        new lang_string('maxusers_desc', 'mavrruntime_companion'),
        \mavrruntime_companion\local\config::DEFAULT_USERS,
        $choices
    ));

    $settings->add(new admin_setting_heading(
        'mavrruntime_companion/livekitheading',
        new lang_string('livekitheading', 'mavrruntime_companion'),
        new lang_string('livekitheading_desc', 'mavrruntime_companion')
    ));

    $settings->add(new \mavrruntime_companion\admin\setting_baseurl(
        'mavrruntime_companion/livekiturl',
        new lang_string('livekiturl', 'mavrruntime_companion'),
        new lang_string('livekiturl_desc', 'mavrruntime_companion'),
        \mavrruntime_companion\admin\setting_baseurl::KIND_LIVEKIT
    ));

    $settings->add(new admin_setting_configtext(
        'mavrruntime_companion/livekitkey',
        new lang_string('livekitkey', 'mavrruntime_companion'),
        new lang_string('livekitkey_desc', 'mavrruntime_companion'),
        '',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new \mavrruntime_companion\admin\setting_secret(
        'mavrruntime_companion/livekitsecret',
        new lang_string('livekitsecret', 'mavrruntime_companion'),
        new lang_string('livekitsecret_desc', 'mavrruntime_companion')
    ));
}
