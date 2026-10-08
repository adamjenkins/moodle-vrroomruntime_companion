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

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Tests for the sync, test and LiveKit tokens.
 *
 * @package    vrroomruntime_companion
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(token_service::class)]
final class token_service_test extends \advanced_testcase {
    /** @var string Shared secret of the tests (40 characters). */
    private const SECRET = 'companion-shared-secret-0123456789abcdef';

    /** @var string LiveKit API secret of the tests. */
    private const LIVEKIT_SECRET = 'livekit-api-secret-0123456789abcdefghijkl';

    /** @var string LiveKit API key of the tests. */
    private const LIVEKIT_KEY = 'APIkey123';

    /** @var int A fixed time. */
    private const NOW = 1760000000;

    /** @var string A peer id. */
    private const PEER = '0123456789abcdef0123456789abcdef';

    /** @var string A room id. */
    private const ROOM = 'rfedcba9876543210fedcba9876543210';

    /**
     * Configure the companion and LiveKit.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('url', 'https://companion.example.org', 'vrroomruntime_companion');
        set_config('secret', self::SECRET, 'vrroomruntime_companion');
        set_config('livekiturl', 'wss://livekit.example.org', 'vrroomruntime_companion');
        set_config('livekitkey', self::LIVEKIT_KEY, 'vrroomruntime_companion');
        set_config('livekitsecret', self::LIVEKIT_SECRET, 'vrroomruntime_companion');
        JWT::$timestamp = self::NOW;
    }

    /**
     * Restore the JWT clock.
     */
    protected function tearDown(): void {
        JWT::$timestamp = null;
        parent::tearDown();
    }

    /**
     * The sync token: header, every claim, lifetime.
     */
    public function test_sync_token(): void {
        $result = token_service::sync_token(self::PEER, self::ROOM, 'Sam Student', 'attempt', 30, self::NOW);
        $this->assertSame(self::NOW + 300, $result['expires']);
        $this->assertSame(['typ' => 'JWT', 'alg' => 'HS256'], $this->header($result['token']));

        $claims = (array)JWT::decode($result['token'], new Key(self::SECRET, 'HS256'));
        $this->assertSame([
            'iss' => config::issuer(),
            'aud' => 'vrroom-sync',
            'sub' => self::PEER,
            'room' => self::ROOM,
            'name' => 'Sam Student',
            'kind' => 'attempt',
            'max' => 30,
            'iat' => self::NOW,
            'nbf' => self::NOW,
            'exp' => self::NOW + 300,
            'vrroom' => 'join',
            'v' => 1,
        ], $claims);
        $this->assertStringNotContainsString(self::SECRET, json_encode($claims));

        // Signed with the shared secret, not with the LiveKit secret.
        $this->expectException(\Firebase\JWT\SignatureInvalidException::class);
        JWT::decode($result['token'], new Key(self::LIVEKIT_SECRET, 'HS256'));
    }

    /**
     * The test token carries a fresh nonce and the purpose 'test', for 60 seconds.
     */
    public function test_test_token(): void {
        $first = token_service::test_token(self::NOW);
        $second = token_service::test_token(self::NOW);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first['nonce']);
        $this->assertNotSame($first['nonce'], $second['nonce']);
        $this->assertSame(self::NOW + 60, $first['expires']);

        $claims = (array)JWT::decode($first['token'], new Key(self::SECRET, 'HS256'));
        $this->assertSame([
            'iss' => config::issuer(),
            'aud' => 'vrroom-sync',
            'vrroom' => 'test',
            'v' => 1,
            'nonce' => $first['nonce'],
            'iat' => self::NOW,
            'nbf' => self::NOW,
            'exp' => self::NOW + 60,
        ], $claims);
    }

    /**
     * The LiveKit room token: microphone only, no data, the room configuration.
     */
    public function test_livekit_token(): void {
        $result = token_service::livekit_token(self::PEER, self::ROOM, 'Sam Student', 12, self::NOW);
        $this->assertSame(self::NOW + 300, $result['expires']);
        $this->assertSame(['typ' => 'JWT', 'alg' => 'HS256'], $this->header($result['token']));

        $claims = json_decode(json_encode(JWT::decode($result['token'], new Key(self::LIVEKIT_SECRET, 'HS256'))), true);
        $this->assertSame([
            'iss' => self::LIVEKIT_KEY,
            'sub' => self::PEER,
            'nbf' => self::NOW,
            'exp' => self::NOW + 300,
            'name' => 'Sam Student',
            'video' => [
                'room' => self::ROOM,
                'roomJoin' => true,
                'canPublish' => true,
                'canPublishSources' => ['microphone'],
                'canSubscribe' => true,
                'canPublishData' => false,
                'canUpdateOwnMetadata' => false,
            ],
            'roomConfig' => ['maxParticipants' => 12, 'emptyTimeout' => 60, 'departureTimeout' => 20],
        ], $claims);
        $encoded = json_encode($claims);
        $this->assertStringNotContainsString(self::LIVEKIT_SECRET, $encoded);
        $this->assertStringNotContainsString(self::SECRET, $encoded);
    }

    /**
     * The LiveKit admin token: the grant given, 60 seconds.
     */
    public function test_livekit_admin_token(): void {
        $token = token_service::livekit_admin_token(['roomList' => true], self::NOW);
        $claims = json_decode(json_encode(JWT::decode($token, new Key(self::LIVEKIT_SECRET, 'HS256'))), true);
        $this->assertSame([
            'iss' => self::LIVEKIT_KEY,
            'nbf' => self::NOW,
            'exp' => self::NOW + 60,
            'video' => ['roomList' => true],
        ], $claims);

        $this->expectException(\coding_exception::class);
        token_service::livekit_admin_token([], self::NOW);
    }

    /**
     * The challenge MAC equals an independent HMAC over the documented message.
     */
    public function test_challenge_mac(): void {
        $nonce = str_repeat('ab', 16);
        $expected = hash_hmac(
            'sha256',
            "vrroom-sync/challenge/1\n" . $nonce . "\nhttps://moodle.example.org\n" . self::NOW,
            self::SECRET
        );
        $this->assertSame($expected, token_service::challenge_mac($nonce, 'https://moodle.example.org', self::NOW));
        $this->assertNotSame($expected, token_service::challenge_mac($nonce, 'https://moodle.example.org', self::NOW + 1));
    }

    /**
     * The display name: trimmed, no control characters, at most 100 characters, never empty.
     */
    public function test_display_name(): void {
        $user = (object)['firstname' => " Sam\x01\t", 'lastname' => "Stu\ndent ", 'firstnamephonetic' => '',
            'lastnamephonetic' => '', 'middlename' => '', 'alternatename' => ''];
        $name = token_service::display_name($user);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $name);
        $this->assertSame($name, trim($name));
        $this->assertStringContainsString('Sam', $name);
        $this->assertStringContainsString('Student', $name);

        $long = (object)['firstname' => str_repeat('é', 80), 'lastname' => str_repeat('ü', 80), 'firstnamephonetic' => '',
            'lastnamephonetic' => '', 'middlename' => '', 'alternatename' => ''];
        $this->assertSame(100, \core_text::strlen(token_service::display_name($long)));

        $empty = (object)['firstname' => "\x02", 'lastname' => ' ', 'firstnamephonetic' => '', 'lastnamephonetic' => '',
            'middlename' => '', 'alternatename' => ''];
        $this->assertSame(get_string('user'), token_service::display_name($empty));
    }

    /**
     * Tokens are refused while not configured.
     */
    public function test_not_configured(): void {
        set_config('secret', '', 'vrroomruntime_companion');
        try {
            token_service::sync_token(self::PEER, self::ROOM, 'Sam', 'attempt', 2, self::NOW);
            $this->fail('A sync token was made without a shared secret.');
        } catch (\coding_exception $e) {
            $this->assertStringNotContainsString(self::LIVEKIT_SECRET, $e->getMessage());
        }
        set_config('livekitsecret', '', 'vrroomruntime_companion');
        $this->expectException(\coding_exception::class);
        token_service::livekit_token(self::PEER, self::ROOM, 'Sam', 2, self::NOW);
    }

    /**
     * The decoded JOSE header of a token.
     *
     * @param string $token The JWT.
     * @return array
     */
    private function header(string $token): array {
        return json_decode(JWT::urlsafeB64Decode(explode('.', $token)[0]), true);
    }
}
