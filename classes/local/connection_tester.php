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
 * The admin page's server-side connection test.
 *
 * Five checks, in order: the settings are complete; the companion service answers its health
 * address; it proves that it holds the same shared secret by answering a challenge with an HMAC
 * (a stranger's server at that address cannot, and the secret itself is never sent); the LiveKit
 * server answers; and it accepts the API key and secret. A failed check skips the checks that
 * depend on it. Results are plain text and never contain a secret or a token.
 *
 * @package    mavrruntime_companion
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class connection_tester {
    /** @var string The check passed. */
    public const STATUS_OK = 'ok';

    /** @var string The check failed. */
    public const STATUS_FAIL = 'fail';

    /** @var string The check was not run. */
    public const STATUS_SKIP = 'skip';

    /** @var int Seconds per request. */
    public const TIMEOUT = 5;

    /** @var int Largest clock difference accepted in the challenge answer, seconds. */
    public const MAX_CLOCK_SKEW = 120;

    /** @var string Service name in the companion's answers. */
    private const SERVICE = 'mavr-sync';

    /** @var int Protocol version this plugin speaks. */
    private const PROTOCOL = 1;

    /** @var http_client The HTTP client. */
    private http_client $client;

    /**
     * Constructor.
     *
     * @param http_client|null $client The HTTP client (core curl when null).
     */
    public function __construct(?http_client $client = null) {
        $this->client = $client ?? new curl_http_client();
    }

    /**
     * Run every check.
     *
     * @param int|null $now Current time.
     * @return array<int, array{check: string, status: string, detail: string}> in check order
     */
    public function run(?int $now = null): array {
        $now = $now ?? time();
        $results = [];

        $settings = config::is_configured()
            ? $this->ok('settings')
            : $this->fail('settings', get_string('notconfigured', config::COMPONENT));
        $results[] = $settings;

        $health = $settings['status'] === self::STATUS_OK ? $this->check_health() : $this->skip('health');
        $results[] = $health;

        $results[] = $health['status'] === self::STATUS_OK ? $this->check_challenge($now) : $this->skip('challenge');

        if (!config::livekit_any()) {
            $novoice = get_string('testdetail_novoice', config::COMPONENT);
            $results[] = $this->skip('livekit', $novoice);
            $results[] = $this->skip('livekitauth', $novoice);
            return $results;
        }
        if (!config::livekit_configured()) {
            $results[] = $this->fail('livekit', get_string('errorlivekitincomplete', config::COMPONENT));
            $results[] = $this->skip('livekitauth');
            return $results;
        }
        $livekit = $this->check_livekit();
        $results[] = $livekit;
        $results[] = $livekit['status'] === self::STATUS_OK ? $this->check_livekit_auth($now) : $this->skip('livekitauth');
        return $results;
    }

    /**
     * The companion service answers its health address.
     *
     * @return array{check: string, status: string, detail: string}
     */
    private function check_health(): array {
        $response = $this->client->request('GET', config::server_url() . config::HEALTH_PATH,
            ['Accept: application/json'], '', self::TIMEOUT);
        $problem = $this->transport_problem($response);
        if ($problem !== null) {
            return $this->fail('health', $problem);
        }
        $data = json_decode($response['body'], true);
        $problem = $this->service_problem($data);
        if ($problem !== null) {
            return $this->fail('health', $problem);
        }
        return $this->ok('health');
    }

    /**
     * The companion service answers the challenge with the right MAC.
     *
     * @param int $now Current time.
     * @return array{check: string, status: string, detail: string}
     */
    private function check_challenge(int $now): array {
        $test = token_service::test_token($now);
        $response = $this->client->request('GET', config::server_url() . config::CHALLENGE_PATH,
            ['Accept: application/json', 'Authorization: Bearer ' . $test['token']], '', self::TIMEOUT);
        if ($response['status'] === 401) {
            return $this->fail('challenge', get_string('testdetail_unauthorised', config::COMPONENT));
        }
        if ($response['status'] === 403) {
            return $this->fail('challenge', get_string('testdetail_forbidden', config::COMPONENT, config::issuer()));
        }
        $problem = $this->transport_problem($response);
        if ($problem !== null) {
            return $this->fail('challenge', $problem);
        }
        $data = json_decode($response['body'], true);
        $problem = $this->service_problem($data);
        if ($problem !== null) {
            return $this->fail('challenge', $problem);
        }
        if (!isset($data['nonce'], $data['ts'], $data['mac']) || !is_string($data['nonce']) || !is_int($data['ts'])
                || !is_string($data['mac'])) {
            return $this->fail('challenge', get_string('testdetail_invalid', config::COMPONENT));
        }
        if (!hash_equals($test['nonce'], $data['nonce'])) {
            return $this->fail('challenge', get_string('testdetail_badmac', config::COMPONENT));
        }
        $expected = token_service::challenge_mac($test['nonce'], config::issuer(), $data['ts']);
        if (!hash_equals($expected, strtolower($data['mac']))) {
            return $this->fail('challenge', get_string('testdetail_badmac', config::COMPONENT));
        }
        if (abs($data['ts'] - $now) > self::MAX_CLOCK_SKEW) {
            return $this->fail('challenge', get_string('testdetail_clock', config::COMPONENT));
        }
        return $this->ok('challenge');
    }

    /**
     * The LiveKit server answers.
     *
     * @return array{check: string, status: string, detail: string}
     */
    private function check_livekit(): array {
        $response = $this->client->request('GET', config::livekit_http_url() . '/', [], '', self::TIMEOUT);
        $problem = $this->transport_problem($response);
        return $problem === null ? $this->ok('livekit') : $this->fail('livekit', $problem);
    }

    /**
     * The LiveKit server accepts a token made with the API key and secret (Twirp ListRooms).
     *
     * @param int $now Current time.
     * @return array{check: string, status: string, detail: string}
     */
    private function check_livekit_auth(int $now): array {
        $token = token_service::livekit_admin_token(['roomList' => true], $now);
        $response = $this->client->request('POST', config::livekit_http_url() . '/twirp/livekit.RoomService/ListRooms',
            ['Content-Type: application/json', 'Authorization: Bearer ' . $token], '{}', self::TIMEOUT);
        if ($response['status'] === 401 || $response['status'] === 403) {
            return $this->fail('livekitauth', get_string('testdetail_unauthorised', config::COMPONENT));
        }
        $problem = $this->transport_problem($response);
        return $problem === null ? $this->ok('livekitauth') : $this->fail('livekitauth', $problem);
    }

    /**
     * The problem with a response's transport: no connection or a status other than 200.
     *
     * @param array $response The client's answer.
     * @return string|null Plain-text detail, or null for HTTP 200.
     */
    private function transport_problem(array $response): ?string {
        $status = (int)($response['status'] ?? 0);
        if ($status === 0) {
            return get_string('testdetail_network', config::COMPONENT, (string)($response['error'] ?? ''));
        }
        if ($status !== 200) {
            return get_string('testdetail_http', config::COMPONENT, $status);
        }
        return null;
    }

    /**
     * The problem with a companion answer's identity: not mavr-sync, or another protocol.
     *
     * @param mixed $data The decoded JSON.
     * @return string|null Plain-text detail, or null when it is mavr-sync protocol 1.
     */
    private function service_problem($data): ?string {
        if (!is_array($data) || ($data['service'] ?? null) !== self::SERVICE) {
            return get_string('testdetail_invalid', config::COMPONENT);
        }
        $protocol = $data['protocol'] ?? null;
        if ($protocol !== self::PROTOCOL) {
            if (!is_int($protocol) && !is_string($protocol)) {
                return get_string('testdetail_invalid', config::COMPONENT);
            }
            return get_string('testdetail_protocol', config::COMPONENT, \core_text::substr((string)$protocol, 0, 20));
        }
        return null;
    }

    /**
     * A passed check.
     *
     * @param string $check The check name.
     * @return array{check: string, status: string, detail: string}
     */
    private function ok(string $check): array {
        return ['check' => $check, 'status' => self::STATUS_OK, 'detail' => ''];
    }

    /**
     * A failed check.
     *
     * @param string $check The check name.
     * @param string $detail Plain-text reason.
     * @return array{check: string, status: string, detail: string}
     */
    private function fail(string $check, string $detail): array {
        return ['check' => $check, 'status' => self::STATUS_FAIL, 'detail' => $detail];
    }

    /**
     * A check that was not run.
     *
     * @param string $check The check name.
     * @param string $detail Plain-text reason ('' when an earlier check failed).
     * @return array{check: string, status: string, detail: string}
     */
    private function skip(string $check, string $detail = ''): array {
        return ['check' => $check, 'status' => self::STATUS_SKIP, 'detail' => $detail];
    }
}
