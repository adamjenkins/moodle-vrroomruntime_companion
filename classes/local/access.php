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

namespace mavrruntime_companion\local;

use mavrruntime_builtin\local\live\access as builtin_access;
use mod_mavr\local\attempt\attempt_manager;

/**
 * Who may join which companion live room, and the live block of the launch config.
 *
 * The rules are the built-in live mode's (\mavrruntime_builtin\local\live\access): a learner needs
 * an own attempt in progress, a teacher previewing needs mod/mavr:manage, and with groups a person
 * joins only the rooms of groups they may see. Only availability, the room size and the launch
 * block differ, because the built-in class ties those to the built-in run mode.
 *
 * @package    mavrruntime_companion
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class access {
    /** @var string Transport name in the launch block. */
    public const TRANSPORT = 'companion';

    /** @var string AMD module that provides the transport. */
    public const TRANSPORT_MODULE = 'mavrruntime_companion/transport_companion';

    /** @var string This run mode's short name. */
    public const RUNTIME = 'companion';

    /**
     * Whether live mode is available in an activity: companion configured, live mode on, this run mode.
     *
     * @param \stdClass $mavr mavr row.
     * @return bool
     */
    public static function is_available(\stdClass $mavr): bool {
        return config::is_configured()
            && (int)($mavr->livemode ?? 0) === 1
            && (string)($mavr->runtime ?? '') === self::RUNTIME;
    }

    /**
     * The room size of an activity, capped at the site's maximum.
     *
     * @param \stdClass $mavr mavr row.
     * @return int Between 2 and config::max_users().
     */
    public static function max_peers(\stdClass $mavr): int {
        $max = config::max_users();
        return min($max, max(config::MIN_USERS, (int)($mavr->livemaxusers ?? $max)));
    }

    /**
     * The checks of the built-in live_join: who joins as what, and which room.
     *
     * @param \cm_info $cm Course module.
     * @param \context_module $context Module context.
     * @param \stdClass $mavr mavr row.
     * @param int $attemptid The attempt in progress, or 0 for a preview.
     * @param int $userid The user.
     * @param int $requestedgroup The group asked for, 0 for the default room.
     * @return array{kind: string, attemptid: int, groupid: int}
     * @throws \moodle_exception errorlivenotallowed, errorlivenoroom, or mod_mavr's attempt errors
     */
    public static function check_join(\cm_info $cm, \context_module $context, \stdClass $mavr, int $attemptid,
            int $userid, int $requestedgroup): array {
        $kind = builtin_access::resolve_kind($cm, $context, $mavr, $attemptid, $userid);
        $groupid = builtin_access::resolve_group($cm, $context, $userid, $requestedgroup);
        return ['kind' => $kind['kind'], 'attemptid' => (int)$kind['attemptid'], 'groupid' => $groupid];
    }

    /**
     * The live block of the launch config (PHASE3-CONTRACT §3.1, plus voice and notice).
     *
     * @param \cm_info $cm Course module.
     * @param \context_module $context Module context.
     * @param \stdClass $mavr mavr row.
     * @param \stdClass|null $attempt The attempt, or null for a preview.
     * @param \stdClass $user The user launching.
     * @return array
     */
    public static function launch_block(\cm_info $cm, \context_module $context, \stdClass $mavr, ?\stdClass $attempt,
            \stdClass $user): array {
        $block = [
            'available' => false,
            'canjoin' => false,
            'reason' => '',
            'transport' => self::TRANSPORT,
            'transportmodule' => self::TRANSPORT_MODULE,
            'cmid' => (int)$cm->id,
            'attemptid' => $attempt ? (int)$attempt->id : 0,
            'maxpeers' => 0,
            'groups' => [],
            'defaultgroupid' => 0,
            'selfname' => '',
            'voice' => false,
            'notice' => get_string('livenotice', config::COMPONENT),
        ];
        if (!self::is_available($mavr)) {
            return $block;
        }
        $userid = (int)$user->id;
        $block['available'] = true;
        $block['maxpeers'] = self::max_peers($mavr);
        $block['selfname'] = fullname($user);
        $block['voice'] = config::voice_configured();

        if ($attempt) {
            $mayjoin = (int)$attempt->userid === $userid && (int)$attempt->mavrid === (int)$mavr->id
                && $attempt->state === attempt_manager::STATE_INPROGRESS && !attempt_manager::is_overdue($attempt);
        } else {
            $mayjoin = has_capability('mod/mavr:manage', $context, $userid);
        }

        $groupok = true;
        if (groups_get_activity_groupmode($cm) != NOGROUPS) {
            foreach (builtin_access::allowed_groups($cm, $context, $userid) as $id => $name) {
                $block['groups'][] = ['id' => (int)$id, 'name' => $name];
            }
            try {
                $block['defaultgroupid'] = builtin_access::resolve_group($cm, $context, $userid, 0);
            } catch (\moodle_exception $e) {
                $groupok = false;
            }
        }

        $block['canjoin'] = $mayjoin && $groupok;
        if ($mayjoin && !$groupok) {
            $block['reason'] = 'nogroup';
        }
        return $block;
    }
}
