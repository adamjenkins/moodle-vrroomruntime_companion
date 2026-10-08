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

/**
 * Tests for the settings, derived addresses and pseudonyms of the companion run mode.
 *
 * @package    mavrruntime_companion
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(config::class)]
final class config_test extends \advanced_testcase {
    /** @var string Shared secret of the tests (40 characters). */
    private const SECRET = 'companion-shared-secret-0123456789abcdef';

    /**
     * Valid companion addresses.
     *
     * @return array
     */
    public static function valid_base_url_provider(): array {
        return [
            'empty' => [''],
            'https' => ['https://companion.example.org'],
            'http' => ['http://companion.example.org'],
            'trailing slash' => ['https://companion.example.org/'],
            'port' => ['https://companion.example.org:8443'],
            'path' => ['https://example.org/mavr-companion'],
            'path and port' => ['http://192.0.2.10:8090/prefix/sub'],
            'ipv4' => ['http://127.0.0.1:8090'],
            'ipv6' => ['http://[::1]:8090'],
            'spaces around' => ['  https://companion.example.org  '],
        ];
    }

    /**
     * Valid addresses pass.
     *
     * @param string $url The address.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('valid_base_url_provider')]
    public function test_validate_base_url_valid(string $url): void {
        $this->assertTrue(config::validate_base_url($url));
    }

    /**
     * Invalid companion addresses.
     *
     * @return array
     */
    public static function invalid_base_url_provider(): array {
        return [
            'ftp' => ['ftp://companion.example.org'],
            'wss' => ['wss://companion.example.org'],
            'no scheme' => ['companion.example.org'],
            'query' => ['https://companion.example.org/?a=1'],
            'fragment' => ['https://companion.example.org/#top'],
            'user and password' => ['https://user:pass@companion.example.org'],
            'user' => ['https://user@companion.example.org'],
            'empty host' => ['https:///path'],
            'bad host' => ['https://compa_nion.example.org'],
            'bad path' => ['https://companion.example.org/a%20b'],
            'port 0' => ['https://companion.example.org:0'],
            'bad ipv6' => ['http://[zz::1]:8090'],
            'upper-case scheme' => ['HTTPS://companion.example.org'],
            'space inside' => ['https://companion example.org'],
        ];
    }

    /**
     * Invalid addresses are refused with the message.
     *
     * @param string $url The address.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_base_url_provider')]
    public function test_validate_base_url_invalid(string $url): void {
        $this->assertSame(get_string('errorinvalidurl', 'mavrruntime_companion'), config::validate_base_url($url));
    }

    /**
     * LiveKit addresses: wss or ws, no path.
     */
    public function test_validate_livekit_url(): void {
        $error = get_string('errorinvalidlivekiturl', 'mavrruntime_companion');
        $this->assertTrue(config::validate_livekit_url(''));
        $this->assertTrue(config::validate_livekit_url('wss://livekit.example.org'));
        $this->assertTrue(config::validate_livekit_url('wss://livekit.example.org/'));
        $this->assertTrue(config::validate_livekit_url('ws://127.0.0.1:7880'));
        $this->assertSame($error, config::validate_livekit_url('https://livekit.example.org'));
        $this->assertSame($error, config::validate_livekit_url('wss://livekit.example.org/rtc'));
        $this->assertSame($error, config::validate_livekit_url('wss://livekit.example.org?x=1'));
        $this->assertSame($error, config::validate_livekit_url('wss://key:secret@livekit.example.org'));
    }

    /**
     * Derived addresses: trailing slash removed, https to wss, http to ws, path prefix kept.
     */
    public function test_derived_urls(): void {
        $this->resetAfterTest();
        $this->assertSame('', config::base_url());
        $this->assertSame('', config::sync_url());
        $this->assertSame('', config::server_url());

        set_config('secret', self::SECRET, 'mavrruntime_companion');
        set_config('url', 'https://companion.example.org/', 'mavrruntime_companion');
        $this->assertSame('https://companion.example.org', config::base_url());
        $this->assertSame('wss://companion.example.org/sync', config::sync_url());
        $this->assertSame('https://companion.example.org', config::server_url());

        set_config('url', 'http://example.org:8080/mavr/', 'mavrruntime_companion');
        $this->assertSame('ws://example.org:8080/mavr/sync', config::sync_url());

        // The server address is used when valid, else the browser address.
        set_config('serverurl', 'http://127.0.0.1:8090', 'mavrruntime_companion');
        $this->assertSame('http://127.0.0.1:8090', config::server_url());
        set_config('serverurl', 'ftp://nonsense', 'mavrruntime_companion');
        $this->assertSame('http://example.org:8080/mavr', config::server_url());

        // An invalid stored address counts as not set.
        set_config('url', 'https://companion.example.org/?x=1', 'mavrruntime_companion');
        $this->assertSame('', config::base_url());
        $this->assertSame('', config::sync_url());

        // LiveKit.
        set_config('livekiturl', 'wss://livekit.example.org/', 'mavrruntime_companion');
        $this->assertSame('wss://livekit.example.org', config::livekit_url());
        $this->assertSame('https://livekit.example.org', config::livekit_http_url());
        set_config('livekiturl', 'ws://127.0.0.1:7880', 'mavrruntime_companion');
        $this->assertSame('http://127.0.0.1:7880', config::livekit_http_url());
    }

    /**
     * Configured needs the address and a secret of at least 32 characters; voice needs all three
     * LiveKit settings too.
     */
    public function test_configured_combinations(): void {
        $this->resetAfterTest();
        $this->assertFalse(config::is_configured());
        $this->assertFalse(config::voice_configured());

        set_config('url', 'https://companion.example.org', 'mavrruntime_companion');
        $this->assertFalse(config::is_configured());
        set_config('secret', str_repeat('a', 31), 'mavrruntime_companion');
        $this->assertFalse(config::is_configured());
        set_config('secret', str_repeat('a', 32), 'mavrruntime_companion');
        $this->assertTrue(config::is_configured());
        set_config('secret', '  ' . str_repeat('a', 31) . '  ', 'mavrruntime_companion');
        $this->assertFalse(config::is_configured());
        set_config('secret', self::SECRET, 'mavrruntime_companion');

        $this->assertFalse(config::livekit_any());
        set_config('livekiturl', 'wss://livekit.example.org', 'mavrruntime_companion');
        $this->assertTrue(config::livekit_any());
        $this->assertFalse(config::voice_configured());
        set_config('livekitkey', 'APIkey123', 'mavrruntime_companion');
        $this->assertFalse(config::voice_configured());
        set_config('livekitsecret', str_repeat('b', 31), 'mavrruntime_companion');
        $this->assertFalse(config::voice_configured());
        set_config('livekitsecret', str_repeat('b', 32), 'mavrruntime_companion');
        $this->assertTrue(config::livekit_configured());
        $this->assertTrue(config::voice_configured());

        // A key with characters an API key cannot have counts as unset.
        set_config('livekitkey', 'API key', 'mavrruntime_companion');
        $this->assertSame('', config::livekit_key());
        $this->assertFalse(config::voice_configured());
        set_config('livekitkey', 'APIkey123', 'mavrruntime_companion');

        // Voice also needs the companion.
        set_config('url', '', 'mavrruntime_companion');
        $this->assertTrue(config::livekit_configured());
        $this->assertFalse(config::voice_configured());
    }

    /**
     * The site's room size is clamped to 2..50 and defaults to 50.
     */
    public function test_max_users(): void {
        $this->resetAfterTest();
        $this->assertSame(50, config::max_users());
        set_config('maxusers', 10, 'mavrruntime_companion');
        $this->assertSame(10, config::max_users());
        set_config('maxusers', 1, 'mavrruntime_companion');
        $this->assertSame(2, config::max_users());
        set_config('maxusers', 500, 'mavrruntime_companion');
        $this->assertSame(50, config::max_users());
    }

    /**
     * The issuer is the site address without a trailing slash.
     */
    public function test_issuer(): void {
        global $CFG;
        $this->assertSame(rtrim($CFG->wwwroot, '/'), config::issuer());
        $this->assertStringEndsNotWith('/', config::issuer());
    }

    /**
     * The salt is made once and reused.
     */
    public function test_salt_created_once(): void {
        $this->resetAfterTest();
        $this->assertFalse(get_config('mavrruntime_companion', config::SALT_NAME));
        $salt = config::salt();
        $this->assertSame(64, strlen($salt));
        $this->assertSame($salt, get_config('mavrruntime_companion', config::SALT_NAME));
        $this->assertSame($salt, config::salt());
    }

    /**
     * Peer and room ids: deterministic, well formed, different per user, activity and group, and
     * independent of the shared secret.
     */
    public function test_pseudonyms(): void {
        $this->resetAfterTest();
        $peer = config::peer_id(5, 7);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $peer);
        $this->assertSame($peer, config::peer_id(5, 7));
        $this->assertNotSame($peer, config::peer_id(6, 7));
        $this->assertNotSame($peer, config::peer_id(5, 8));

        $room = config::room_id(7, 0);
        $this->assertMatchesRegularExpression('/^r[0-9a-f]{32}$/', $room);
        $this->assertSame($room, config::room_id(7, 0));
        $this->assertNotSame($room, config::room_id(7, 3));
        $this->assertNotSame($room, config::room_id(8, 0));

        // A peer id never equals a room id's hex part for the same numbers.
        $this->assertNotSame(substr(config::room_id(5, 7), 1), config::peer_id(5, 7));

        // The shared secret plays no part.
        set_config('secret', self::SECRET, 'mavrruntime_companion');
        $this->assertSame($peer, config::peer_id(5, 7));
        set_config('secret', strrev(self::SECRET), 'mavrruntime_companion');
        $this->assertSame($room, config::room_id(7, 0));

        // A new salt gives new ids.
        set_config(config::SALT_NAME, str_repeat('z', 64), 'mavrruntime_companion');
        $this->assertNotSame($peer, config::peer_id(5, 7));
    }
}
