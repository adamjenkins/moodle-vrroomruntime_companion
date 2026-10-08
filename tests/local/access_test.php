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

namespace vrroomruntime_companion\local;

use mod_vrroom\local\attempt\attempt_manager;

/**
 * Tests for who may join which companion live room.
 *
 * @package    vrroomruntime_companion
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(access::class)]
final class access_test extends \advanced_testcase {
    /**
     * Configure the companion service.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('url', 'https://companion.example.org', 'vrroomruntime_companion');
        set_config('secret', 'companion-shared-secret-0123456789abcdef', 'vrroomruntime_companion');
    }

    /**
     * Availability: configured, live mode on, this run mode.
     */
    public function test_is_available(): void {
        [, , $vrroom] = $this->create_activity();
        $this->assertTrue(access::is_available($vrroom));

        $off = clone $vrroom;
        $off->livemode = 0;
        $this->assertFalse(access::is_available($off));

        $builtin = clone $vrroom;
        $builtin->runtime = 'builtin';
        $this->assertFalse(access::is_available($builtin));

        set_config('secret', '', 'vrroomruntime_companion');
        $this->assertFalse(access::is_available($vrroom));
    }

    /**
     * The room size is clamped to 2 and the site's maximum.
     */
    public function test_max_peers(): void {
        $this->assertSame(2, access::max_peers((object)['livemaxusers' => 0]));
        $this->assertSame(2, access::max_peers((object)['livemaxusers' => 2]));
        $this->assertSame(30, access::max_peers((object)['livemaxusers' => 30]));
        $this->assertSame(50, access::max_peers((object)['livemaxusers' => 50]));
        $this->assertSame(50, access::max_peers((object)['livemaxusers' => 80]));
        $this->assertSame(50, access::max_peers((object)[]));
        set_config('maxusers', 20, 'vrroomruntime_companion');
        $this->assertSame(20, access::max_peers((object)['livemaxusers' => 30]));
        $this->assertSame(10, access::max_peers((object)['livemaxusers' => 10]));
    }

    /**
     * The launch block when live mode is not available: every key, empty values.
     */
    public function test_launch_block_unavailable(): void {
        [$cm, $context, $vrroom, $course] = $this->create_activity();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $attempt = $this->vrroom_generator()->create_attempt((int)$vrroom->id, (int)$student->id);
        $vrroom->livemode = 0;

        $this->assertSame([
            'available' => false,
            'canjoin' => false,
            'reason' => '',
            'transport' => 'companion',
            'transportmodule' => 'vrroomruntime_companion/transport_companion',
            'cmid' => (int)$cm->id,
            'attemptid' => (int)$attempt->id,
            'maxpeers' => 0,
            'groups' => [],
            'defaultgroupid' => 0,
            'selfname' => '',
            'voice' => false,
            'notice' => get_string('livenotice', 'vrroomruntime_companion'),
        ], access::launch_block($cm, $context, $vrroom, $attempt, $student));
    }

    /**
     * An own attempt in progress may join; a finished attempt may not.
     */
    public function test_launch_block_attempt(): void {
        [$cm, $context, $vrroom, $course] = $this->create_activity(null, NOGROUPS, 8);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $attempt = $this->vrroom_generator()->create_attempt((int)$vrroom->id, (int)$student->id);
        $this->setUser($student);

        $block = access::launch_block($cm, $context, $vrroom, $attempt, $student);
        $this->assertTrue($block['available']);
        $this->assertTrue($block['canjoin']);
        $this->assertSame('', $block['reason']);
        $this->assertSame(8, $block['maxpeers']);
        $this->assertSame(fullname($student), $block['selfname']);
        $this->assertFalse($block['voice']);

        $finished = attempt_manager::finish($attempt);
        $block = access::launch_block($cm, $context, $vrroom, $finished, $student);
        $this->assertTrue($block['available']);
        $this->assertFalse($block['canjoin']);
    }

    /**
     * A preview may join with mod/vrroom:manage only.
     */
    public function test_launch_block_preview(): void {
        [$cm, $context, $vrroom, $course] = $this->create_activity();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setUser($teacher);
        $this->assertTrue(access::launch_block($cm, $context, $vrroom, null, $teacher)['canjoin']);
        $this->setUser($student);
        $block = access::launch_block($cm, $context, $vrroom, null, $student);
        $this->assertTrue($block['available']);
        $this->assertFalse($block['canjoin']);
        $this->assertSame('', $block['reason']);
    }

    /**
     * Voice in the launch block follows the LiveKit settings.
     */
    public function test_launch_block_voice(): void {
        [$cm, $context, $vrroom, $course] = $this->create_activity();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        set_config('livekiturl', 'wss://livekit.example.org', 'vrroomruntime_companion');
        set_config('livekitkey', 'APIkey123', 'vrroomruntime_companion');
        set_config('livekitsecret', 'livekit-api-secret-0123456789abcdefghijkl', 'vrroomruntime_companion');
        $this->setUser($teacher);
        $this->assertTrue(access::launch_block($cm, $context, $vrroom, null, $teacher)['voice']);
    }

    /**
     * With groups: the learner's groups and default room; no group means 'nogroup'.
     */
    public function test_launch_block_groups(): void {
        [$cm, $context, $vrroom, $course] = $this->create_activity(null, SEPARATEGROUPS);
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Group A']);
        $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Group B']);
        $member = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $member->id]);
        $loner = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setUser($member);
        $attempt = $this->vrroom_generator()->create_attempt((int)$vrroom->id, (int)$member->id);
        $block = access::launch_block($cm, $context, $vrroom, $attempt, $member);
        $this->assertTrue($block['canjoin']);
        $this->assertSame([['id' => (int)$groupa->id, 'name' => 'Group A']], $block['groups']);
        $this->assertSame((int)$groupa->id, $block['defaultgroupid']);

        $this->setUser($loner);
        $attempt = $this->vrroom_generator()->create_attempt((int)$vrroom->id, (int)$loner->id);
        $block = access::launch_block($cm, $context, $vrroom, $attempt, $loner);
        $this->assertFalse($block['canjoin']);
        $this->assertSame('nogroup', $block['reason']);
        $this->assertSame([], $block['groups']);
        $this->assertSame(0, $block['defaultgroupid']);
    }

    /**
     * Joining with an own attempt; refused for another user's attempt or another activity's.
     */
    public function test_check_join_attempts(): void {
        [$cm, $context, $vrroom, $course] = $this->create_activity();
        [, , $vrroom2] = $this->create_activity($course);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $own = $this->vrroom_generator()->create_attempt((int)$vrroom->id, (int)$student->id);
        $theirs = $this->vrroom_generator()->create_attempt((int)$vrroom->id, (int)$other->id);
        $elsewhere = $this->vrroom_generator()->create_attempt((int)$vrroom2->id, (int)$student->id);

        $this->assertSame(
            ['kind' => 'attempt', 'attemptid' => (int)$own->id, 'groupid' => 0],
            access::check_join($cm, $context, $vrroom, (int)$own->id, (int)$student->id, 0)
        );

        $this->assert_join_refused('errornotyourattempt', $cm, $context, $vrroom, (int)$theirs->id, (int)$student->id, 0);
        $this->assert_join_refused('errorlivenotallowed', $cm, $context, $vrroom, (int)$elsewhere->id, (int)$student->id, 0);

        $finished = attempt_manager::finish($own);
        $this->assert_join_refused('errorattemptclosed', $cm, $context, $vrroom, (int)$finished->id, (int)$student->id, 0);
    }

    /**
     * A preview needs mod/vrroom:manage.
     */
    public function test_check_join_preview(): void {
        [$cm, $context, $vrroom, $course] = $this->create_activity();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->assertSame(
            ['kind' => 'preview', 'attemptid' => 0, 'groupid' => 0],
            access::check_join($cm, $context, $vrroom, 0, (int)$teacher->id, 0)
        );
        $this->assert_join_refused('errorlivenotallowed', $cm, $context, $vrroom, 0, (int)$student->id, 0);
    }

    /**
     * Groups: members join their group's room, others are refused, accessallgroups joins any,
     * a learner in no group has no room.
     */
    public function test_check_join_groups(): void {
        [$cm, $context, $vrroom, $course] = $this->create_activity(null, SEPARATEGROUPS);
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Group A']);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Group B']);
        $member = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $member->id]);
        $loner = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $this->setUser($member);
        $attempt = $this->vrroom_generator()->create_attempt((int)$vrroom->id, (int)$member->id);
        $joined = access::check_join($cm, $context, $vrroom, (int)$attempt->id, (int)$member->id, 0);
        $this->assertSame((int)$groupa->id, $joined['groupid']);
        $joined = access::check_join($cm, $context, $vrroom, (int)$attempt->id, (int)$member->id, (int)$groupa->id);
        $this->assertSame((int)$groupa->id, $joined['groupid']);
        $this->assert_join_refused(
            'errorlivenoroom',
            $cm,
            $context,
            $vrroom,
            (int)$attempt->id,
            (int)$member->id,
            (int)$groupb->id
        );

        $this->setUser($teacher);
        $joined = access::check_join($cm, $context, $vrroom, 0, (int)$teacher->id, (int)$groupb->id);
        $this->assertSame((int)$groupb->id, $joined['groupid']);

        $this->setUser($loner);
        $attempt = $this->vrroom_generator()->create_attempt((int)$vrroom->id, (int)$loner->id);
        $this->assert_join_refused('errorlivenoroom', $cm, $context, $vrroom, (int)$attempt->id, (int)$loner->id, 0);
    }

    /**
     * Assert that check_join() throws the given error.
     *
     * @param string $errorcode Expected error code.
     * @param \cm_info $cm Course module.
     * @param \context_module $context Context.
     * @param \stdClass $vrroom vrroom row.
     * @param int $attemptid Attempt id.
     * @param int $userid User id.
     * @param int $groupid Requested group.
     */
    private function assert_join_refused(
        string $errorcode,
        \cm_info $cm,
        \context_module $context,
        \stdClass $vrroom,
        int $attemptid,
        int $userid,
        int $groupid
    ): void {
        try {
            access::check_join($cm, $context, $vrroom, $attemptid, $userid, $groupid);
            $this->fail("check_join() did not refuse with {$errorcode}.");
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * The mod_vrroom generator.
     *
     * @return \mod_vrroom_generator
     */
    private function vrroom_generator(): \mod_vrroom_generator {
        return $this->getDataGenerator()->get_plugin_generator('mod_vrroom');
    }

    /**
     * Create an activity of this run mode with live mode on.
     *
     * @param \stdClass|null $course Course, or null for a new one.
     * @param int $groupmode Group mode of the activity.
     * @param int $maxusers Room size.
     * @return array [cm_info, context_module, vrroom row, course]
     */
    private function create_activity(?\stdClass $course = null, int $groupmode = NOGROUPS, int $maxusers = 50): array {
        global $DB;
        $course = $course ?? $this->getDataGenerator()->create_course();
        $record = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id, 'runtime' => 'companion',
            'livemode' => 1, 'livemaxusers' => $maxusers]);
        $DB->set_field('course_modules', 'groupmode', $groupmode, ['id' => $record->cmid]);
        rebuild_course_cache((int)$course->id, true);
        $cm = get_fast_modinfo($course->id)->get_cm($record->cmid);
        $vrroom = $DB->get_record('vrroom', ['id' => $record->id], '*', MUST_EXIST);
        return [$cm, \context_module::instance($cm->id), $vrroom, $course];
    }
}
