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

namespace mavrruntime_companion\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mavrruntime_builtin\local\live\access as builtin_access;
use mavrruntime_companion\local\access;
use mavrruntime_companion\local\config;
use mavrruntime_companion\local\token_service;

/**
 * Get the short-lived tokens to join (or stay in) the companion live room of an activity.
 *
 * Every call re-runs the checks of the built-in live_join (own attempt in progress or preview
 * with mod/mavr:manage, group membership), so a client that refreshes its token every four
 * minutes loses the room within five minutes of losing access. Nothing is written, except the
 * pseudonym salt on the site's first call.
 *
 * @package    mavrruntime_companion
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class join extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'attemptid' => new external_value(PARAM_INT, 'The attempt in progress, 0 for a teacher preview', VALUE_DEFAULT, 0),
            'groupid' => new external_value(PARAM_INT, 'The group whose room to join, 0 for the default room', VALUE_DEFAULT, 0),
            'refresh' => new external_value(
                PARAM_BOOL,
                'True to renew the room token only (no voice token)',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Check access and make the tokens.
     *
     * @param int $cmid Course module id.
     * @param int $attemptid Attempt id, 0 for a preview.
     * @param int $groupid Group id, 0 for the default room.
     * @param bool $refresh Renew the room token only.
     * @return array
     */
    public static function execute(int $cmid, int $attemptid = 0, int $groupid = 0, bool $refresh = false): array {
        global $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'attemptid' => $attemptid, 'groupid' => $groupid, 'refresh' => $refresh]
        );

        [$cm, $context, $mavr] = builtin_access::load_by_cmid((int)$params['cmid']);
        self::validate_context($context);
        require_capability('mod/mavr:view', $context);
        if (!access::is_available($mavr)) {
            throw new \moodle_exception('errorlivedisabled', config::COMPONENT);
        }

        $userid = (int)$USER->id;
        $joined = access::check_join($cm, $context, $mavr, (int)$params['attemptid'], $userid, (int)$params['groupid']);
        $room = (int)$joined['groupid'];
        $peerid = config::peer_id($userid, (int)$mavr->id);
        $roomid = config::room_id((int)$mavr->id, $room);
        $name = token_service::display_name($USER);
        $max = access::max_peers($mavr);
        $sync = token_service::sync_token($peerid, $roomid, $name, $joined['kind'], $max);

        $voice = ['available' => false, 'url' => '', 'token' => '', 'expires' => 0];
        if (config::voice_configured()) {
            $voice['available'] = true;
            $voice['url'] = config::livekit_url();
            if (!$params['refresh']) {
                $livekit = token_service::livekit_token($peerid, $roomid, $name, $max);
                $voice['token'] = $livekit['token'];
                $voice['expires'] = $livekit['expires'];
            }
        }

        return [
            'peerid' => $peerid,
            'groupid' => $room,
            'roomname' => builtin_access::room_name($cm, $context, $mavr, $room),
            'maxpeers' => $max,
            'selfname' => $name,
            'kind' => $joined['kind'],
            'syncurl' => config::sync_url(),
            'token' => $sync['token'],
            'expires' => $sync['expires'],
            'refreshafter' => token_service::TTL - token_service::REFRESH_MARGIN,
            'voice' => $voice,
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'peerid' => new external_value(PARAM_ALPHANUM, 'Own peer id (pseudonymous, stable per user and activity)'),
            'groupid' => new external_value(PARAM_INT, 'The room\'s group, 0 without groups'),
            'roomname' => new external_value(PARAM_TEXT, 'Plain name of the room'),
            'maxpeers' => new external_value(PARAM_INT, 'Most people in the room'),
            'selfname' => new external_value(PARAM_TEXT, 'Own plain name, as the others see it'),
            'kind' => new external_value(PARAM_ALPHA, 'attempt or preview'),
            'syncurl' => new external_value(PARAM_RAW, 'WebSocket address of the companion service'),
            'token' => new external_value(PARAM_RAW, 'Room token for the companion service'),
            'expires' => new external_value(PARAM_INT, 'When the room token expires (unix time)'),
            'refreshafter' => new external_value(PARAM_INT, 'Seconds after which to ask for a new room token'),
            'voice' => new external_single_structure([
                'available' => new external_value(PARAM_BOOL, 'Whether the room has voice'),
                'url' => new external_value(PARAM_RAW, 'LiveKit address, empty without voice'),
                'token' => new external_value(PARAM_RAW, 'LiveKit room token, empty without voice or on a refresh'),
                'expires' => new external_value(PARAM_INT, 'When the LiveKit token expires, 0 without a token'),
            ], 'Voice'),
        ]);
    }
}
