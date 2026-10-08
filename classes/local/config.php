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
 * Site settings of the companion run mode, the addresses derived from them, and the pseudonyms.
 *
 * The companion service and LiveKit never learn user, activity or group ids: peers and rooms are
 * named by HMACs over a per-site random salt that stays in this site's configuration.
 *
 * @package    mavrruntime_companion
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class config {
    /** @var string This component. */
    public const COMPONENT = 'mavrruntime_companion';

    /** @var int Shortest shared secret or LiveKit API secret accepted. */
    public const MIN_SECRET_LENGTH = 32;

    /** @var int Smallest room. */
    public const MIN_USERS = 2;

    /** @var int Largest room (mod_mavr clamps livemaxusers to the same value). */
    public const MAX_USERS = 50;

    /** @var int Default largest room. */
    public const DEFAULT_USERS = 50;

    /** @var string WebSocket path of the companion service. */
    public const SYNC_PATH = '/sync';

    /** @var string Health path of the companion service. */
    public const HEALTH_PATH = '/healthz';

    /** @var string Challenge path of the companion service. */
    public const CHALLENGE_PATH = '/v1/challenge';

    /** @var string Config name of the pseudonym salt. */
    public const SALT_NAME = 'pseudonymsalt';

    /** @var int Length of the pseudonym salt. */
    private const SALT_LENGTH = 64;

    /** @var string Characters allowed in a URL path. */
    private const PATH_PATTERN = '~^[A-Za-z0-9._\~/-]*$~';

    /** @var string One label of a DNS name (also matches the parts of an IPv4 address). */
    private const HOST_LABEL = '[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?';

    /** @var string A DNS name or an IPv4 address. */
    private const HOST_PATTERN = '/^' . self::HOST_LABEL . '(\.' . self::HOST_LABEL . ')*$/';

    /**
     * The companion address browsers use: trimmed, without a trailing slash, '' when unset or invalid.
     *
     * @return string
     */
    public static function base_url(): string {
        return self::clean_url((string)get_config(self::COMPONENT, 'url'), ['https', 'http'], true);
    }

    /**
     * The companion address this server uses for the connection test: 'serverurl' when set and
     * valid, else the browser address.
     *
     * @return string
     */
    public static function server_url(): string {
        $url = self::clean_url((string)get_config(self::COMPONENT, 'serverurl'), ['https', 'http'], true);
        return $url !== '' ? $url : self::base_url();
    }

    /**
     * The WebSocket address of the companion service ('' when not configured).
     *
     * @return string wss://… for an https address, ws://… for an http address.
     */
    public static function sync_url(): string {
        if (!self::is_configured()) {
            return '';
        }
        return self::to_websocket(self::base_url()) . self::SYNC_PATH;
    }

    /**
     * The shared secret, trimmed. Never shown or sent anywhere.
     *
     * @return string
     */
    public static function secret(): string {
        return trim((string)get_config(self::COMPONENT, 'secret'));
    }

    /**
     * Whether the companion address and the shared secret are set.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return self::base_url() !== '' && strlen(self::secret()) >= self::MIN_SECRET_LENGTH;
    }

    /**
     * The largest room teachers can choose.
     *
     * @return int Between MIN_USERS and MAX_USERS.
     */
    public static function max_users(): int {
        $value = (int)get_config(self::COMPONENT, 'maxusers');
        if ($value <= 0) {
            $value = self::DEFAULT_USERS;
        }
        return min(self::MAX_USERS, max(self::MIN_USERS, $value));
    }

    /**
     * The LiveKit address browsers use: trimmed, without a trailing slash, '' when unset or invalid.
     *
     * @return string
     */
    public static function livekit_url(): string {
        return self::clean_url((string)get_config(self::COMPONENT, 'livekiturl'), ['wss', 'ws'], false);
    }

    /**
     * The LiveKit API key, trimmed ('' when it holds characters an API key cannot have).
     *
     * @return string
     */
    public static function livekit_key(): string {
        $key = trim((string)get_config(self::COMPONENT, 'livekitkey'));
        return preg_match('/^[A-Za-z0-9_-]+$/', $key) ? $key : '';
    }

    /**
     * The LiveKit API secret, trimmed. Never shown or sent anywhere.
     *
     * @return string
     */
    public static function livekit_secret(): string {
        return trim((string)get_config(self::COMPONENT, 'livekitsecret'));
    }

    /**
     * Whether any of the three LiveKit settings is set (the test reports a partial setup).
     *
     * @return bool
     */
    public static function livekit_any(): bool {
        foreach (['livekiturl', 'livekitkey', 'livekitsecret'] as $name) {
            if (trim((string)get_config(self::COMPONENT, $name)) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the three LiveKit settings are complete and valid (whatever the companion settings).
     *
     * @return bool
     */
    public static function livekit_configured(): bool {
        return self::livekit_url() !== '' && self::livekit_key() !== ''
            && strlen(self::livekit_secret()) >= self::MIN_SECRET_LENGTH;
    }

    /**
     * Whether live rooms of this run mode have voice: companion and LiveKit both configured.
     *
     * @return bool
     */
    public static function voice_configured(): bool {
        return self::is_configured() && self::livekit_configured();
    }

    /**
     * The HTTP address of the LiveKit server, for the server-side checks ('' when not set).
     *
     * @return string https://… for a wss address, http://… for a ws address.
     */
    public static function livekit_http_url(): string {
        $url = self::livekit_url();
        if (str_starts_with($url, 'wss://')) {
            return 'https://' . substr($url, 6);
        }
        if (str_starts_with($url, 'ws://')) {
            return 'http://' . substr($url, 5);
        }
        return '';
    }

    /**
     * The token issuer: this site's address without a trailing slash.
     *
     * @return string
     */
    public static function issuer(): string {
        global $CFG;
        return rtrim((string)$CFG->wwwroot, '/');
    }

    /**
     * Validate a companion address.
     *
     * @param string $url The address ('' is allowed: not configured).
     * @return bool|string true, or the error message.
     */
    public static function validate_base_url(string $url): bool|string {
        $url = trim($url);
        if ($url === '' || self::parse($url, ['https', 'http'], true) !== null) {
            return true;
        }
        return get_string('errorinvalidurl', self::COMPONENT);
    }

    /**
     * Validate a LiveKit address.
     *
     * @param string $url The address ('' is allowed: no voice).
     * @return bool|string true, or the error message.
     */
    public static function validate_livekit_url(string $url): bool|string {
        $url = trim($url);
        if ($url === '' || self::parse($url, ['wss', 'ws'], false) !== null) {
            return true;
        }
        return get_string('errorinvalidlivekiturl', self::COMPONENT);
    }

    /**
     * The per-site pseudonym salt, made on first use. Never shown or sent anywhere.
     *
     * @return string
     */
    public static function salt(): string {
        $salt = (string)get_config(self::COMPONENT, self::SALT_NAME);
        if (strlen($salt) < self::SALT_LENGTH) {
            $salt = random_string(self::SALT_LENGTH);
            set_config(self::SALT_NAME, $salt, self::COMPONENT);
        }
        return $salt;
    }

    /**
     * A pseudonym: the first 32 hex characters of an HMAC-SHA256 over the data with the salt.
     *
     * @param string $data What to name.
     * @return string 32 lowercase hex characters.
     */
    public static function pseudonym(string $data): string {
        return substr(hash_hmac('sha256', $data, self::salt()), 0, 32);
    }

    /**
     * The peer id of a user in an activity (stable, so a second tab replaces the first).
     *
     * @param int $userid The user.
     * @param int $mavrid The activity.
     * @return string 32 lowercase hex characters.
     */
    public static function peer_id(int $userid, int $mavrid): string {
        return self::pseudonym("peer|{$userid}|{$mavrid}");
    }

    /**
     * The room id of an activity's room, or of one of its groups' rooms.
     *
     * @param int $mavrid The activity.
     * @param int $groupid The group, 0 without groups.
     * @return string 'r' and 32 lowercase hex characters.
     */
    public static function room_id(int $mavrid, int $groupid): string {
        return 'r' . self::pseudonym("room|{$mavrid}|{$groupid}");
    }

    /**
     * A setting's address when valid: trimmed, without a trailing slash; '' otherwise.
     *
     * @param string $url The setting.
     * @param string[] $schemes Allowed schemes.
     * @param bool $allowpath Whether a path is allowed.
     * @return string
     */
    private static function clean_url(string $url, array $schemes, bool $allowpath): string {
        $url = trim($url);
        if ($url === '' || self::parse($url, $schemes, $allowpath) === null) {
            return '';
        }
        return rtrim($url, '/');
    }

    /**
     * Check an address: scheme, host (DNS name, IPv4 or bracketed IPv6), optional port and path,
     * no user, password, query or fragment.
     *
     * @param string $url A trimmed, non-empty address.
     * @param string[] $schemes Allowed schemes (lower case).
     * @param bool $allowpath Whether a path is allowed (else only an empty path or '/').
     * @return array|null The parse_url() parts, or null when not valid.
     */
    private static function parse(string $url, array $schemes, bool $allowpath): ?array {
        if (strpbrk($url, "?#@\\ \t\r\n") !== false) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        // Lower-case schemes only, so the derived addresses can swap them by prefix.
        if (!in_array($parts['scheme'], $schemes, true) || !str_starts_with($url, $parts['scheme'] . '://')) {
            return null;
        }
        foreach (['user', 'pass', 'query', 'fragment'] as $forbidden) {
            if (array_key_exists($forbidden, $parts)) {
                return null;
            }
        }
        $host = (string)$parts['host'];
        if (str_starts_with($host, '[')) {
            $inner = str_ends_with($host, ']') ? substr($host, 1, -1) : '';
            if (filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }
        } else if (!preg_match(self::HOST_PATTERN, $host) || strlen($host) > 253) {
            return null;
        }
        if (array_key_exists('port', $parts) && ((int)$parts['port'] < 1 || (int)$parts['port'] > 65535)) {
            return null;
        }
        $path = (string)($parts['path'] ?? '');
        if ($allowpath) {
            if (!preg_match(self::PATH_PATTERN, $path) || str_contains($path, '//')) {
                return null;
            }
        } else if ($path !== '' && $path !== '/') {
            return null;
        }
        return $parts;
    }

    /**
     * Turn an http(s) address into the matching ws(s) address.
     *
     * @param string $url A valid https or http address.
     * @return string
     */
    private static function to_websocket(string $url): string {
        if (str_starts_with($url, 'https://')) {
            return 'wss://' . substr($url, 8);
        }
        if (str_starts_with($url, 'http://')) {
            return 'ws://' . substr($url, 7);
        }
        return $url;
    }
}
