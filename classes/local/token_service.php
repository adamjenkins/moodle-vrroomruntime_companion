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

use Firebase\JWT\JWT;

/**
 * Short-lived tokens for the companion service and the LiveKit server.
 *
 * All tokens are HS256 JWTs made with core's Firebase php-jwt. Sync tokens are signed with the
 * shared secret and checked by mavr-sync; LiveKit tokens are signed with the LiveKit API secret in
 * the format of livekit-server-sdk's AccessToken (claims and video grant mirrored from its
 * src/AccessToken.ts and src/grants.ts, Apache-2.0; no LiveKit code is copied). No secret ever
 * leaves this server: only the signed tokens do.
 *
 * @package    mavrruntime_companion
 * @copyright  2024 LiveKit, Inc.
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class token_service {
    /** @var string Audience of sync tokens. */
    public const AUDIENCE = 'mavr-sync';

    /** @var string Purpose of a token that joins a room. */
    public const PURPOSE_JOIN = 'join';

    /** @var string Purpose of the admin test's token. */
    public const PURPOSE_TEST = 'test';

    /** @var int Token format version. */
    public const VERSION = 1;

    /** @var int Lifetime of a join token, seconds. */
    public const TTL = 300;

    /** @var int Lifetime of a test token, seconds. */
    public const TEST_TTL = 60;

    /** @var int Lifetime of a LiveKit room token, seconds. */
    public const LIVEKIT_TTL = 300;

    /** @var int Lifetime of a LiveKit admin token, seconds. */
    public const LIVEKIT_ADMIN_TTL = 60;

    /** @var int The client asks for a new token this many seconds before expiry. */
    public const REFRESH_MARGIN = 60;

    /** @var int Longest display name, in characters. */
    public const NAME_MAX = 100;

    /** @var int A LiveKit room closes this many seconds after the last person leaves. */
    public const LIVEKIT_EMPTY_TIMEOUT = 60;

    /** @var int A LiveKit room closes this many seconds after the last person drops out. */
    public const LIVEKIT_DEPARTURE_TIMEOUT = 20;

    /** @var string JWT algorithm. */
    private const ALG = 'HS256';

    /**
     * The name others see: full name, trimmed, without control characters, at most 100 characters.
     *
     * @param \stdClass $user The user.
     * @return string Plain text, never empty.
     */
    public static function display_name(\stdClass $user): string {
        $name = fix_utf8((string)fullname($user));
        $name = (string)preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', '', $name);
        $name = trim(\core_text::substr(trim($name), 0, self::NAME_MAX));
        if ($name === '') {
            $name = get_string('user');
        }
        return $name;
    }

    /**
     * A token that admits a peer to a room of the companion service.
     *
     * @param string $peerid The peer id (32 hex).
     * @param string $room The room id ('r' + 32 hex).
     * @param string $name The display name.
     * @param string $kind 'attempt' or 'preview'.
     * @param int $max The room size.
     * @param int|null $now Current time.
     * @return array{token: string, expires: int}
     */
    public static function sync_token(
        string $peerid,
        string $room,
        string $name,
        string $kind,
        int $max,
        ?int $now = null
    ): array {
        self::require_configured();
        $now = $now ?? time();
        $expires = $now + self::TTL;
        $payload = [
            'iss' => config::issuer(),
            'aud' => self::AUDIENCE,
            'sub' => $peerid,
            'room' => $room,
            'name' => $name,
            'kind' => $kind,
            'max' => $max,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $expires,
            'mavr' => self::PURPOSE_JOIN,
            'v' => self::VERSION,
        ];
        return ['token' => JWT::encode($payload, config::secret(), self::ALG), 'expires' => $expires];
    }

    /**
     * A token for the admin test's challenge, carrying a random nonce.
     *
     * @param int|null $now Current time.
     * @return array{token: string, nonce: string, expires: int}
     */
    public static function test_token(?int $now = null): array {
        self::require_configured();
        $now = $now ?? time();
        $nonce = bin2hex(random_bytes(16));
        $expires = $now + self::TEST_TTL;
        $payload = [
            'iss' => config::issuer(),
            'aud' => self::AUDIENCE,
            'mavr' => self::PURPOSE_TEST,
            'v' => self::VERSION,
            'nonce' => $nonce,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $expires,
        ];
        return ['token' => JWT::encode($payload, config::secret(), self::ALG), 'nonce' => $nonce, 'expires' => $expires];
    }

    /**
     * A LiveKit token that lets a peer join a room's voice: microphone only, no data channel.
     *
     * @param string $peerid The peer id, used as the LiveKit identity.
     * @param string $room The room id, used as the LiveKit room name.
     * @param string $name The display name.
     * @param int $max The room size.
     * @param int|null $now Current time.
     * @return array{token: string, expires: int}
     */
    public static function livekit_token(string $peerid, string $room, string $name, int $max, ?int $now = null): array {
        self::require_livekit();
        $now = $now ?? time();
        $expires = $now + self::LIVEKIT_TTL;
        $payload = [
            'iss' => config::livekit_key(),
            'sub' => $peerid,
            'nbf' => $now,
            'exp' => $expires,
            'name' => $name,
            'video' => [
                'room' => $room,
                'roomJoin' => true,
                'canPublish' => true,
                'canPublishSources' => ['microphone'],
                'canSubscribe' => true,
                'canPublishData' => false,
                'canUpdateOwnMetadata' => false,
            ],
            'roomConfig' => [
                'maxParticipants' => $max,
                'emptyTimeout' => self::LIVEKIT_EMPTY_TIMEOUT,
                'departureTimeout' => self::LIVEKIT_DEPARTURE_TIMEOUT,
            ],
        ];
        return ['token' => JWT::encode($payload, config::livekit_secret(), self::ALG), 'expires' => $expires];
    }

    /**
     * A LiveKit server API token (for example the grant ['roomList' => true]), valid for 60 seconds.
     *
     * @param array $videogrant The video grant, not empty.
     * @param int|null $now Current time.
     * @return string
     */
    public static function livekit_admin_token(array $videogrant, ?int $now = null): string {
        self::require_livekit();
        if (!$videogrant) {
            throw new \coding_exception('A LiveKit admin token needs a grant.');
        }
        $now = $now ?? time();
        $payload = [
            'iss' => config::livekit_key(),
            'nbf' => $now,
            'exp' => $now + self::LIVEKIT_ADMIN_TTL,
            'video' => $videogrant,
        ];
        return JWT::encode($payload, config::livekit_secret(), self::ALG);
    }

    /**
     * The MAC the companion service answers a challenge with (PHASE4-CONTRACT §3.3).
     *
     * @param string $nonce The nonce of the test token.
     * @param string $issuer The issuer of the test token.
     * @param int $ts The service's time.
     * @return string 64 lowercase hex characters.
     */
    public static function challenge_mac(string $nonce, string $issuer, int $ts): string {
        self::require_configured();
        return hash_hmac('sha256', "mavr-sync/challenge/1\n{$nonce}\n{$issuer}\n{$ts}", config::secret());
    }

    /**
     * Refuse to sign while the companion service is not configured.
     *
     * @return void
     */
    private static function require_configured(): void {
        if (!config::is_configured()) {
            throw new \coding_exception('The companion service is not configured.');
        }
    }

    /**
     * Refuse to sign while the LiveKit settings are incomplete.
     *
     * @return void
     */
    private static function require_livekit(): void {
        if (!config::livekit_configured()) {
            throw new \coding_exception('The LiveKit server is not configured.');
        }
    }
}
