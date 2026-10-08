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
use core_privacy\local\metadata\types\external_location;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;

/**
 * Tests for the privacy provider of the companion run mode (external locations only).
 *
 * @package    mavrruntime_companion
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * The metadata has exactly the three external locations with their fields.
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('mavrruntime_companion'));
        $items = $collection->get_collection();
        $this->assertCount(3, $items);

        $expected = [
            'mavrcompanion' => ['peerid', 'fullname', 'ipaddress', 'pose'],
            'livekit' => ['peerid', 'fullname', 'ipaddress', 'voice'],
            'livepeers' => ['fullname', 'pose', 'voice'],
        ];
        foreach ($items as $item) {
            $this->assertInstanceOf(external_location::class, $item);
            $name = $item->get_name();
            $this->assertArrayHasKey($name, $expected);
            $this->assertSame($expected[$name], array_keys($item->get_privacy_fields()));
            $this->assertStringStartsWith('privacy:metadata:', $item->get_summary());
            foreach ($item->get_privacy_fields() as $stringkey) {
                $this->assertTrue(get_string_manager()->string_exists($stringkey, 'mavrruntime_companion'), $stringkey);
            }
            $this->assertTrue(get_string_manager()->string_exists($item->get_summary(), 'mavrruntime_companion'));
            unset($expected[$name]);
        }
        $this->assertSame([], $expected);
    }

    /**
     * Nothing is stored: no contexts, no users, and the deletes do nothing and do not fail.
     */
    public function test_nothing_stored(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_and_enrol($course, 'student');
        $module = $generator->create_module('mavr', ['course' => $course->id]);
        $context = \context_module::instance($module->cmid);

        $this->assertSame([], provider::get_contexts_for_userid((int)$user->id)->get_contextids());

        $userlist = new userlist($context, 'mavrruntime_companion');
        provider::get_users_in_context($userlist);
        $this->assertSame([], $userlist->get_userids());

        provider::export_user_data(new approved_contextlist($user, 'mavrruntime_companion', [$context->id]));
        provider::delete_data_for_all_users_in_context($context);
        provider::delete_data_for_user(new approved_contextlist($user, 'mavrruntime_companion', [$context->id]));
        provider::delete_data_for_users(new approved_userlist($context, 'mavrruntime_companion', [(int)$user->id]));
        $this->assertFalse(\core_privacy\local\request\writer::with_context($context)->has_any_data());
    }
}
