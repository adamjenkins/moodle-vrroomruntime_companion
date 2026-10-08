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

namespace vrroomruntime_companion\external;

use core_external\external_api;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use vrroomruntime_companion\local\config;

/**
 * Tests of the companion join web service.
 *
 * @package    vrroomruntime_companion
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(join::class)]
final class join_test extends \advanced_testcase {
    /** @var string Shared secret of the tests (40 characters). */
    private const SECRET = 'companion-shared-secret-0123456789abcdef';

    /** @var string LiveKit API secret of the tests. */
    private const LIVEKIT_SECRET = 'livekit-api-secret-0123456789abcdefghijkl';

    /**
     * Configure the companion service.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('url', 'https://companion.example.org', 'vrroomruntime_companion');
        set_config('secret', self::SECRET, 'vrroomruntime_companion');
    }

    /**
     * A learner with an attempt gets a room token, and with LiveKit a voice token.
     */
    public function test_join_with_voice(): void {
        $this->configure_livekit();
        [$cmid, $course, $vrroom] = $this->create_activity(20);
        [$student, $attempt] = $this->learner($course, $vrroom);
        $this->setUser($student);

        $before = time();
        $result = $this->join($cmid, (int)$attempt->id);
        $this->assertSame(config::peer_id((int)$student->id, (int)$vrroom->id), $result['peerid']);
        $this->assertSame(0, $result['groupid']);
        $this->assertSame(format_string($vrroom->name), $result['roomname']);
        $this->assertSame(20, $result['maxpeers']);
        $this->assertSame(fullname($student), $result['selfname']);
        $this->assertSame('attempt', $result['kind']);
        $this->assertSame('wss://companion.example.org/sync', $result['syncurl']);
        $this->assertSame(240, $result['refreshafter']);
        $this->assertGreaterThanOrEqual($before + 300, $result['expires']);

        $claims = (array)JWT::decode($result['token'], new Key(self::SECRET, 'HS256'));
        $this->assertSame($result['peerid'], $claims['sub']);
        $this->assertSame(config::room_id((int)$vrroom->id, 0), $claims['room']);
        $this->assertSame('attempt', $claims['kind']);
        $this->assertSame(20, $claims['max']);
        $this->assertSame('join', $claims['vrroom']);
        $this->assertSame($result['expires'], $claims['exp']);

        $this->assertTrue($result['voice']['available']);
        $this->assertSame('wss://livekit.example.org', $result['voice']['url']);
        $this->assertGreaterThanOrEqual($before + 300, $result['voice']['expires']);
        $voice = json_decode(
            json_encode(JWT::decode($result['voice']['token'], new Key(self::LIVEKIT_SECRET, 'HS256'))),
            true
        );
        $this->assertSame($result['peerid'], $voice['sub']);
        $this->assertSame($claims['room'], $voice['video']['room']);
        $this->assertSame(['microphone'], $voice['video']['canPublishSources']);
        $this->assertSame(20, $voice['roomConfig']['maxParticipants']);

        $json = json_encode($result);
        $this->assertStringNotContainsString(self::SECRET, $json);
        $this->assertStringNotContainsString(self::LIVEKIT_SECRET, $json);
        $this->assertStringNotContainsString(config::salt(), $json);
    }

    /**
     * A refresh renews the room token only.
     */
    public function test_refresh_without_voice_token(): void {
        $this->configure_livekit();
        [$cmid, $course, $vrroom] = $this->create_activity();
        [$student, $attempt] = $this->learner($course, $vrroom);
        $this->setUser($student);

        $result = external_api::clean_returnvalue(join::execute_returns(), join::execute($cmid, (int)$attempt->id, 0, true));
        $this->assertNotSame('', $result['token']);
        $this->assertTrue($result['voice']['available']);
        $this->assertSame('wss://livekit.example.org', $result['voice']['url']);
        $this->assertSame('', $result['voice']['token']);
        $this->assertSame(0, $result['voice']['expires']);
    }

    /**
     * Without LiveKit the voice block is empty.
     */
    public function test_no_voice(): void {
        [$cmid, $course, $vrroom] = $this->create_activity();
        [$student, $attempt] = $this->learner($course, $vrroom);
        $this->setUser($student);

        $result = $this->join($cmid, (int)$attempt->id);
        $this->assertSame(['available' => false, 'url' => '', 'token' => '', 'expires' => 0], $result['voice']);
    }

    /**
     * A teacher preview joins as 'preview'; a learner without an attempt cannot.
     */
    public function test_preview(): void {
        [$cmid, $course, $vrroom] = $this->create_activity();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setUser($teacher);
        $result = $this->join($cmid, 0);
        $this->assertSame('preview', $result['kind']);
        $claims = (array)JWT::decode($result['token'], new Key(self::SECRET, 'HS256'));
        $this->assertSame('preview', $claims['kind']);

        $this->setUser($student);
        try {
            join::execute($cmid, 0, 0);
            $this->fail('A learner joined without an attempt.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorlivenotallowed', $e->errorcode);
        }
    }

    /**
     * Another learner's attempt is refused.
     */
    public function test_other_users_attempt(): void {
        [$cmid, $course, $vrroom] = $this->create_activity();
        [, $otherattempt] = $this->learner($course, $vrroom);
        [$student] = $this->learner($course, $vrroom);
        $this->setUser($student);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errornotyourattempt', 'mod_vrroom'));
        join::execute($cmid, (int)$otherattempt->id, 0);
    }

    /**
     * Groups: the room of the learner's group, with its own room id.
     */
    public function test_group_room(): void {
        global $DB;
        [$cmid, $course, $vrroom] = $this->create_activity();
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cmid]);
        rebuild_course_cache((int)$course->id, true);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Group A']);
        [$student, $attempt] = $this->learner($course, $vrroom);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);
        $this->setUser($student);

        $result = $this->join($cmid, (int)$attempt->id);
        $this->assertSame((int)$group->id, $result['groupid']);
        $this->assertSame('Group A', $result['roomname']);
        $claims = (array)JWT::decode($result['token'], new Key(self::SECRET, 'HS256'));
        $this->assertSame(config::room_id((int)$vrroom->id, (int)$group->id), $claims['room']);
        $this->assertNotSame(config::room_id((int)$vrroom->id, 0), $claims['room']);
    }

    /**
     * Not configured, live mode off or another run mode: errorlivedisabled.
     */
    public function test_disabled(): void {
        global $DB;
        [$cmid, $course, $vrroom] = $this->create_activity();
        [$student, $attempt] = $this->learner($course, $vrroom);
        $this->setUser($student);

        $DB->set_field('vrroom', 'livemode', 0, ['id' => $vrroom->id]);
        $this->assert_disabled($cmid, (int)$attempt->id);
        $DB->set_field('vrroom', 'livemode', 1, ['id' => $vrroom->id]);

        set_config('secret', '', 'vrroomruntime_companion');
        $this->assert_disabled($cmid, (int)$attempt->id);
    }

    /**
     * Someone not enrolled in the course cannot join.
     */
    public function test_not_enrolled(): void {
        [$cmid] = $this->create_activity();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\require_login_exception::class);
        join::execute($cmid, 0, 0);
    }

    /**
     * Assert that joining fails with errorlivedisabled.
     *
     * @param int $cmid Course module id.
     * @param int $attemptid Attempt id.
     */
    private function assert_disabled(int $cmid, int $attemptid): void {
        try {
            join::execute($cmid, $attemptid, 0);
            $this->fail('Joined while live mode is not available.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorlivedisabled', $e->errorcode);
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    /**
     * Call the service and clean the result as the web service layer does.
     *
     * @param int $cmid Course module id.
     * @param int $attemptid Attempt id, 0 for a preview.
     * @return array
     */
    private function join(int $cmid, int $attemptid): array {
        return external_api::clean_returnvalue(join::execute_returns(), join::execute($cmid, $attemptid, 0));
    }

    /**
     * Set the three LiveKit settings.
     */
    private function configure_livekit(): void {
        set_config('livekiturl', 'wss://livekit.example.org', 'vrroomruntime_companion');
        set_config('livekitkey', 'APIkey123', 'vrroomruntime_companion');
        set_config('livekitsecret', self::LIVEKIT_SECRET, 'vrroomruntime_companion');
    }

    /**
     * A learner with an attempt in progress.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $vrroom vrroom row.
     * @return array [user, attempt]
     */
    private function learner(\stdClass $course, \stdClass $vrroom): array {
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        /** @var \mod_vrroom_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_vrroom');
        return [$user, $generator->create_attempt((int)$vrroom->id, (int)$user->id)];
    }

    /**
     * Create an activity of this run mode with live mode on.
     *
     * @param int $maxusers Room size.
     * @return array [cmid, course, vrroom row]
     */
    private function create_activity(int $maxusers = 50): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $record = $this->getDataGenerator()->create_module('vrroom', ['course' => $course->id, 'runtime' => 'companion',
            'livemode' => 1, 'livemaxusers' => $maxusers]);
        return [(int)$record->cmid, $course, $DB->get_record('vrroom', ['id' => $record->id], '*', MUST_EXIST)];
    }
}
