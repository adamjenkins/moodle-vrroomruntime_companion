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
 * Site settings of the VR room companion service run mode, and its connection test page.
 *
 * Included by \mod_vrroom\plugininfo\vrroomruntime::load_settings(), which provides $ADMIN, $settings
 * (the page vrroomruntimesettingcompanion) and $parentnodename (the VR room settings category).
 *
 * @package    vrroomruntime_companion
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$ADMIN->add($parentnodename, new admin_externalpage(
    'vrroomruntimecompaniontest',
    new lang_string('testconnection', 'vrroomruntime_companion'),
    new moodle_url('/mod/vrroom/runtime/companion/testconnection.php'),
    'moodle/site:config'
));

if ($ADMIN->fulltree) {
    $testurl = (new moodle_url('/mod/vrroom/runtime/companion/testconnection.php'))->out(false);
    $settings->add(new admin_setting_heading(
        'vrroomruntime_companion/settingsheading',
        new lang_string('settingsheading', 'vrroomruntime_companion'),
        get_string('settingsheading_desc', 'vrroomruntime_companion') . ' '
            . get_string('testconnection_desc', 'vrroomruntime_companion', $testurl)
    ));

    $settings->add(new \vrroomruntime_companion\admin\setting_baseurl(
        'vrroomruntime_companion/url',
        new lang_string('url', 'vrroomruntime_companion'),
        new lang_string('url_desc', 'vrroomruntime_companion')
    ));

    $settings->add(new \vrroomruntime_companion\admin\setting_baseurl(
        'vrroomruntime_companion/serverurl',
        new lang_string('serverurl', 'vrroomruntime_companion'),
        new lang_string('serverurl_desc', 'vrroomruntime_companion')
    ));

    $settings->add(new \vrroomruntime_companion\admin\setting_secret(
        'vrroomruntime_companion/secret',
        new lang_string('secret', 'vrroomruntime_companion'),
        new lang_string('secret_desc', 'vrroomruntime_companion')
    ));

    $minsize = \vrroomruntime_companion\local\config::MIN_USERS;
    $maxsize = \vrroomruntime_companion\local\config::MAX_USERS;
    $choices = [];
    foreach (range($minsize, $maxsize) as $size) {
        $choices[$size] = (string)$size;
    }
    $settings->add(new admin_setting_configselect(
        'vrroomruntime_companion/maxusers',
        new lang_string('maxusers', 'vrroomruntime_companion'),
        new lang_string('maxusers_desc', 'vrroomruntime_companion'),
        \vrroomruntime_companion\local\config::DEFAULT_USERS,
        $choices
    ));

    $settings->add(new admin_setting_heading(
        'vrroomruntime_companion/livekitheading',
        new lang_string('livekitheading', 'vrroomruntime_companion'),
        new lang_string('livekitheading_desc', 'vrroomruntime_companion')
    ));

    $settings->add(new \vrroomruntime_companion\admin\setting_baseurl(
        'vrroomruntime_companion/livekiturl',
        new lang_string('livekiturl', 'vrroomruntime_companion'),
        new lang_string('livekiturl_desc', 'vrroomruntime_companion'),
        \vrroomruntime_companion\admin\setting_baseurl::KIND_LIVEKIT
    ));

    $settings->add(new admin_setting_configtext(
        'vrroomruntime_companion/livekitkey',
        new lang_string('livekitkey', 'vrroomruntime_companion'),
        new lang_string('livekitkey_desc', 'vrroomruntime_companion'),
        '',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new \vrroomruntime_companion\admin\setting_secret(
        'vrroomruntime_companion/livekitsecret',
        new lang_string('livekitsecret', 'vrroomruntime_companion'),
        new lang_string('livekitsecret_desc', 'vrroomruntime_companion')
    ));
}
