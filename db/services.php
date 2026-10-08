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
 * Web services of the VR room companion service run mode.
 *
 * The join service writes nothing except the pseudonym salt on its first use, so it is a read
 * service. It is not a read-only-session service: the default group lookup may write $SESSION.
 *
 * @package    vrroomruntime_companion
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'vrroomruntime_companion_join' => [
        'classname' => \vrroomruntime_companion\external\join::class,
        'description' => 'Get the short-lived tokens to join (or stay in) the companion live room of a VR room activity.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/vrroom:view',
    ],
];
