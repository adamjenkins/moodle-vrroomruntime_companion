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

namespace vrroomruntime_companion;

use mod_vrroom\local\runtime\cleanup_scope;
use mod_vrroom\local\runtime\launch_descriptor;
use mod_vrroom\local\runtime\registry;

/**
 * Tests for the companion service run mode.
 *
 * @package    vrroomruntime_companion
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(runtime::class)]
final class runtime_test extends \advanced_testcase {
    /** @var string Shared secret of the tests (40 characters). */
    private const SECRET = 'companion-shared-secret-0123456789abcdef';

    /** @var string LiveKit API secret of the tests. */
    private const LIVEKIT_SECRET = 'livekit-api-secret-0123456789abcdefghijkl';

    /**
     * The registry finds the run mode under its short name.
     */
    public function test_registry_discovery(): void {
        $this->resetAfterTest();
        $runtime = registry::get('companion');
        $this->assertInstanceOf(runtime::class, $runtime);
        $this->assertSame('companion', $runtime->get_name());
        $this->assertSame(get_string('pluginname', 'vrroomruntime_companion'), $runtime->get_display_name());
        $this->assertArrayHasKey('companion', registry::get_choices());
    }

    /**
     * Not ready and one user while not configured; ready with the address and the secret.
     */
    public function test_readiness_and_capabilities(): void {
        $this->resetAfterTest();
        $runtime = new runtime();

        $ready = $runtime->readiness();
        $this->assertFalse($ready->ready);
        $this->assertSame(get_string('notconfigured', 'vrroomruntime_companion'), $ready->message);
        $caps = $runtime->capabilities();
        $this->assertSame(1, $caps->maxusers);
        $this->assertFalse($caps->voice);
        $this->assertFalse($caps->avatars);
        $this->assertSame(['vr', 'ar', 'mr'], $caps->xrmodes);
        $this->assertSame(['visit_zone', 'interact', 'answer_question', 'collect', 'sequence'], $caps->objectivetypes);

        // A secret one character too short is not enough.
        set_config('url', 'https://companion.example.org', 'vrroomruntime_companion');
        set_config('secret', str_repeat('x', 31), 'vrroomruntime_companion');
        $this->assertFalse($runtime->readiness()->ready);

        set_config('secret', self::SECRET, 'vrroomruntime_companion');
        $ready = $runtime->readiness();
        $this->assertTrue($ready->ready);
        $this->assertSame('', $ready->message);
        $caps = $runtime->capabilities();
        $this->assertSame(50, $caps->maxusers);
        $this->assertFalse($caps->voice);
        $this->assertTrue($caps->avatars);

        // The site's maximum is respected.
        set_config('maxusers', 12, 'vrroomruntime_companion');
        $this->assertSame(12, $runtime->capabilities()->maxusers);

        // Voice once LiveKit is set up.
        $this->configure_livekit();
        $this->assertTrue($runtime->capabilities()->voice);
    }

    /**
     * Statement channel, client entry and cleanup.
     */
    public function test_static_properties(): void {
        global $DB;
        $this->resetAfterTest();
        $runtime = new runtime();
        $this->assertSame('browser', $runtime->statement_channel());
        $this->assertSame(['module' => 'vrroomruntime_builtin/launch', 'function' => 'init'], $runtime->client_entry());
        $this->assertSame(\vrroomruntime_builtin\runtime::CLIENT_MODULE, $runtime->client_entry()['module']);

        // Cleanup has nothing to do for any scope, and writes nothing.
        $before = $DB->count_records('config_plugins', ['plugin' => 'vrroomruntime_companion']);
        $runtime->cleanup(new cleanup_scope(cleanup_scope::COURSE_RESET, 2));
        $runtime->cleanup(new cleanup_scope(cleanup_scope::INSTANCE_DELETE, 2, 3));
        $runtime->cleanup(new cleanup_scope(cleanup_scope::USER_DELETE, 0, 0, 4));
        $this->assertSame($before, $DB->count_records('config_plugins', ['plugin' => 'vrroomruntime_companion']));
    }

    /**
     * An attempt launch is inline, with the companion's live block.
     */
    public function test_launch_inline_live_block(): void {
        $this->resetAfterTest();
        $this->configure();
        $this->configure_livekit();
        [$cm, $vrroom, $student] = $this->create_activity(['livemode' => 1, 'livemaxusers' => 30]);
        /** @var \mod_vrroom_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_vrroom');
        $attempt = $generator->create_attempt((int)$vrroom->id, (int)$student->id);
        $this->setUser($student);

        $descriptor = registry::get('companion')->launch($cm, $attempt, $student);
        $this->assertSame(launch_descriptor::TYPE_INLINE, $descriptor->type);
        $config = $descriptor->config;
        $this->assertSame('vrroom-launch/1', $config['schema']);
        $this->assertEquals($attempt->id, $config['attemptid']);

        $live = $config['live'];
        $this->assertSame(['available', 'canjoin', 'reason', 'transport', 'transportmodule', 'cmid', 'attemptid',
            'maxpeers', 'groups', 'defaultgroupid', 'selfname', 'voice', 'notice'], array_keys($live));
        $this->assertTrue($live['available']);
        $this->assertTrue($live['canjoin']);
        $this->assertSame('companion', $live['transport']);
        $this->assertSame('vrroomruntime_companion/transport_companion', $live['transportmodule']);
        $this->assertSame((int)$cm->id, $live['cmid']);
        $this->assertSame((int)$attempt->id, $live['attemptid']);
        $this->assertSame(30, $live['maxpeers']);
        $this->assertSame(fullname($student), $live['selfname']);
        $this->assertTrue($live['voice']);
        $this->assertSame(get_string('livenotice', 'vrroomruntime_companion'), $live['notice']);

        // No secret in what the browser receives.
        $json = json_encode($descriptor->to_array());
        $this->assertStringNotContainsString(self::SECRET, $json);
        $this->assertStringNotContainsString(self::LIVEKIT_SECRET, $json);
    }

    /**
     * A preview launch is inline, without an attempt; a teacher may join.
     */
    public function test_preview(): void {
        $this->resetAfterTest();
        $this->configure();
        [$cm, , , $teacher] = $this->create_activity(['livemode' => 1]);
        $this->setUser($teacher);

        $descriptor = registry::get('companion')->preview($cm, $teacher);
        $this->assertSame(launch_descriptor::TYPE_INLINE, $descriptor->type);
        $this->assertTrue($descriptor->config['preview']);
        $live = $descriptor->config['live'];
        $this->assertSame(0, $live['attemptid']);
        $this->assertTrue($live['canjoin']);
        $this->assertFalse($live['voice']);
        $this->assertSame('companion', $live['transport']);
    }

    /**
     * Without live mode the live block is unavailable but complete.
     */
    public function test_launch_live_off(): void {
        $this->resetAfterTest();
        $this->configure();
        [$cm, , , $teacher] = $this->create_activity();
        $this->setUser($teacher);

        $live = registry::get('companion')->preview($cm, $teacher)->config['live'];
        $this->assertFalse($live['available']);
        $this->assertFalse($live['canjoin']);
        $this->assertSame(0, $live['maxpeers']);
        $this->assertSame('', $live['selfname']);
        $this->assertFalse($live['voice']);
        $this->assertSame('vrroomruntime_companion/transport_companion', $live['transportmodule']);
        $this->assertNotSame('', $live['notice']);
    }

    /**
     * Set the companion address and secret.
     */
    private function configure(): void {
        set_config('url', 'https://companion.example.org', 'vrroomruntime_companion');
        set_config('secret', self::SECRET, 'vrroomruntime_companion');
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
     * Create an activity of this run mode with a student and a teacher.
     *
     * @param array $fields Extra activity fields.
     * @return array [cm_info, vrroom row, student, teacher]
     */
    private function create_activity(array $fields = []): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $record = $this->getDataGenerator()->create_module(
            'vrroom',
            ['course' => $course->id, 'runtime' => 'companion'] + $fields
        );
        $cm = get_fast_modinfo($course)->get_cm($record->cmid);
        $vrroom = $DB->get_record('vrroom', ['id' => $record->id], '*', MUST_EXIST);
        return [$cm, $vrroom, $student, $teacher];
    }
}
