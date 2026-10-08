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

use vrroomruntime_companion\admin\setting_baseurl;
use vrroomruntime_companion\admin\setting_secret;

/**
 * Tests for the settings page, the test page registration and the setting classes.
 *
 * @package    vrroomruntime_companion
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(setting_baseurl::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(setting_secret::class)]
final class settings_test extends \advanced_testcase {
    /**
     * Load the admin library.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->libdir . '/adminlib.php');
    }

    /**
     * The settings page and the test page are in the VR room category, and the settings page renders.
     */
    public function test_pages(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $root = admin_get_root(true, true);
        $page = $root->locate('vrroomruntimesettingcompanion');
        $this->assertNotNull($page);
        $html = $page->output_html();
        foreach (['url', 'serverurl', 'secret', 'maxusers', 'livekiturl', 'livekitkey', 'livekitsecret'] as $name) {
            $this->assertStringContainsString('s_vrroomruntime_companion_' . $name, $html, $name);
        }
        $this->assertStringContainsString('/mod/vrroom/runtime/companion/testconnection.php', $html);
        $this->assertStringNotContainsString('[[', $html);

        $test = $root->locate('vrroomruntimecompaniontest');
        $this->assertInstanceOf(\admin_externalpage::class, $test);
        $this->assertSame(['moodle/site:config'], $test->req_capability);
    }

    /**
     * The secret setting refuses short secrets and spaces, and stores the value trimmed.
     */
    public function test_setting_secret(): void {
        $this->resetAfterTest();
        $setting = new setting_secret('vrroomruntime_companion/secret', 'Secret', '');
        $error = get_string('errorinvalidsecret', 'vrroomruntime_companion');
        $this->assertTrue($setting->validate(''));
        $this->assertTrue($setting->validate(str_repeat('a', 32)));
        $this->assertSame($error, $setting->validate(str_repeat('a', 31)));
        $this->assertSame($error, $setting->validate(str_repeat('a', 20) . ' ' . str_repeat('a', 20)));

        $this->assertSame('', $setting->write_setting('  ' . str_repeat('k', 40) . '  '));
        $this->assertSame(str_repeat('k', 40), get_config('vrroomruntime_companion', 'secret'));
        $this->assertSame($error, $setting->write_setting('short'));
    }

    /**
     * The address settings refuse other schemes and paths where not allowed.
     */
    public function test_setting_baseurl(): void {
        $this->resetAfterTest();
        $companion = new setting_baseurl('vrroomruntime_companion/url', 'Address', '');
        $this->assertTrue($companion->validate(''));
        $this->assertTrue($companion->validate('https://companion.example.org'));
        $this->assertSame(get_string('errorinvalidurl', 'vrroomruntime_companion'), $companion->validate('ftp://x'));
        $this->assertSame('', $companion->write_setting(' https://companion.example.org/ '));
        $this->assertSame('https://companion.example.org/', get_config('vrroomruntime_companion', 'url'));

        $livekit = new setting_baseurl('vrroomruntime_companion/livekiturl', 'LiveKit', '', setting_baseurl::KIND_LIVEKIT);
        $this->assertTrue($livekit->validate('wss://livekit.example.org'));
        $this->assertSame(
            get_string('errorinvalidlivekiturl', 'vrroomruntime_companion'),
            $livekit->validate('https://livekit.example.org')
        );

        $this->expectException(\coding_exception::class);
        new setting_baseurl('vrroomruntime_companion/other', 'Other', '', 'nonsense');
    }
}
