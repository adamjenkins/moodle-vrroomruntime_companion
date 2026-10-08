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

namespace mavrruntime_companion\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;

/**
 * Privacy provider for the MAVR companion service run mode.
 *
 * Nothing is stored in Moodle: this plugin has no tables, and its tokens are made on request and
 * not kept. In a live room the user's browser connects to the companion service (mavr-sync) and,
 * for voice, to the LiveKit server of the companion stack. They receive a pseudonymous peer id,
 * the user's full name, IP address, pose and state or voice, keep them in memory only while the
 * user is in the room, and pass name, pose and voice on to the other participants. These are
 * described as external locations; the request methods have nothing to export or delete.
 *
 * @package    mavrruntime_companion
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe the data that leaves Moodle in a companion live room.
     *
     * @param collection $collection the collection to add to
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link('mavrcompanion', [
            'peerid' => 'privacy:metadata:companion:peerid',
            'fullname' => 'privacy:metadata:companion:fullname',
            'ipaddress' => 'privacy:metadata:companion:ipaddress',
            'pose' => 'privacy:metadata:companion:pose',
        ], 'privacy:metadata:companion');

        $collection->add_external_location_link('livekit', [
            'peerid' => 'privacy:metadata:livekit:peerid',
            'fullname' => 'privacy:metadata:livekit:fullname',
            'ipaddress' => 'privacy:metadata:livekit:ipaddress',
            'voice' => 'privacy:metadata:livekit:voice',
        ], 'privacy:metadata:livekit');

        $collection->add_external_location_link('livepeers', [
            'fullname' => 'privacy:metadata:livepeers:fullname',
            'pose' => 'privacy:metadata:livepeers:pose',
            'voice' => 'privacy:metadata:livepeers:voice',
        ], 'privacy:metadata:livepeers');

        return $collection;
    }

    /**
     * No context holds data of this plugin.
     *
     * @param int $userid the user
     * @return contextlist an empty list
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        return new contextlist();
    }

    /**
     * No user has data of this plugin in any context.
     *
     * @param userlist $userlist the list to add to
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        // Nothing is stored in Moodle.
    }

    /**
     * Nothing to export.
     *
     * @param approved_contextlist $contextlist the approved contexts
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        // Nothing is stored in Moodle.
    }

    /**
     * Nothing to delete.
     *
     * @param \context $context the context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        // Nothing is stored in Moodle.
    }

    /**
     * Nothing to delete.
     *
     * @param approved_contextlist $contextlist the approved contexts
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        // Nothing is stored in Moodle.
    }

    /**
     * Nothing to delete.
     *
     * @param approved_userlist $userlist the approved users
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        // Nothing is stored in Moodle.
    }
}
