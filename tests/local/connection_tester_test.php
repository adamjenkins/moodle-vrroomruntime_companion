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
 * Tests for the admin connection test, with a scripted HTTP client (no network).
 *
 * @package    vrroomruntime_companion
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(connection_tester::class)]
final class connection_tester_test extends \advanced_testcase {
    /** @var string Shared secret of the tests (40 characters). */
    private const SECRET = 'companion-shared-secret-0123456789abcdef';

    /** @var string LiveKit API secret of the tests. */
    private const LIVEKIT_SECRET = 'livekit-api-secret-0123456789abcdefghijkl';

    /** @var int A fixed time. */
    private const NOW = 1760000000;

    /** @var string Health answer of a working companion. */
    private const HEALTHY = '{"status":"ok","service":"vrroom-sync","protocol":1}';

    /** @var array Requests the fake client received. */
    private array $requests = [];

    /**
     * Configure the companion service and fix the JWT clock.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('url', 'https://companion.example.org', 'vrroomruntime_companion');
        set_config('secret', self::SECRET, 'vrroomruntime_companion');
        JWT::$timestamp = self::NOW;
        $this->requests = [];
    }

    /**
     * Restore the JWT clock.
     */
    protected function tearDown(): void {
        JWT::$timestamp = null;
        parent::tearDown();
    }

    /**
     * Not configured: the settings check fails and the companion checks are not run.
     */
    public function test_not_configured(): void {
        set_config('secret', '', 'vrroomruntime_companion');
        $results = $this->run_tester(fn() => $this->fail_request());
        $this->assertSame(['settings', 'health', 'challenge', 'livekit', 'livekitauth'], array_column($results, 'check'));
        $this->assert_result($results, 'settings', 'fail', get_string('notconfigured', 'vrroomruntime_companion'));
        $this->assert_result($results, 'health', 'skip', '');
        $this->assert_result($results, 'challenge', 'skip', '');
        $novoice = get_string('testdetail_novoice', 'vrroomruntime_companion');
        $this->assert_result($results, 'livekit', 'skip', $novoice);
        $this->assert_result($results, 'livekitauth', 'skip', $novoice);
        $this->assertSame([], $this->requests);
    }

    /**
     * Health failures: network error, HTTP 500, not vrroom-sync, another protocol.
     */
    public function test_health_failures(): void {
        $cases = [
            [['status' => 0, 'body' => '', 'error' => 'Connection refused'],
                get_string('testdetail_network', 'vrroomruntime_companion', 'Connection refused')],
            [['status' => 500, 'body' => 'oops', 'error' => ''], get_string('testdetail_http', 'vrroomruntime_companion', 500)],
            [['status' => 200, 'body' => '<html>Welcome</html>', 'error' => ''],
                get_string('testdetail_invalid', 'vrroomruntime_companion')],
            [['status' => 200, 'body' => '{"service":"something-else","protocol":1}', 'error' => ''],
                get_string('testdetail_invalid', 'vrroomruntime_companion')],
            [['status' => 200, 'body' => '{"service":"vrroom-sync","protocol":2}', 'error' => ''],
                get_string('testdetail_protocol', 'vrroomruntime_companion', '2')],
        ];
        foreach ($cases as [$response, $detail]) {
            $this->requests = [];
            $results = $this->run_tester(fn() => $response);
            $this->assert_result($results, 'settings', 'ok', '');
            $this->assert_result($results, 'health', 'fail', $detail);
            $this->assert_result($results, 'challenge', 'skip', '');
            $this->assertCount(1, $this->requests);
            $this->assertSame('GET', $this->requests[0]['method']);
            $this->assertSame('https://companion.example.org/healthz', $this->requests[0]['url']);
        }
    }

    /**
     * The server address is used for the server-side checks when set.
     */
    public function test_server_url(): void {
        set_config('serverurl', 'http://127.0.0.1:8090', 'vrroomruntime_companion');
        $this->run_tester(fn() => ['status' => 0, 'body' => '', 'error' => 'refused']);
        $this->assertSame('http://127.0.0.1:8090/healthz', $this->requests[0]['url']);
    }

    /**
     * A correct challenge answer passes; the request carries a Bearer test token.
     */
    public function test_challenge_ok(): void {
        $results = $this->run_tester($this->companion(fn(array $claims) => $this->answer($claims)));
        $this->assert_result($results, 'health', 'ok', '');
        $this->assert_result($results, 'challenge', 'ok', '');

        $request = $this->requests[1];
        $this->assertSame('https://companion.example.org/v1/challenge', $request['url']);
        $claims = $this->bearer_claims($request['headers'], self::SECRET);
        $this->assertSame('test', $claims['vrroom']);
        $this->assertSame('vrroom-sync', $claims['aud']);
        $this->assertSame(config::issuer(), $claims['iss']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $claims['nonce']);
        $this->assertSame(self::NOW + 60, $claims['exp']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($this->requests));
    }

    /**
     * Challenge refusals: 401, 403 (naming this site), and other statuses.
     */
    public function test_challenge_refused(): void {
        $results = $this->run_tester($this->companion(fn() => ['status' => 401, 'body' => '{"error":"unauthorised"}',
            'error' => '']));
        $this->assert_result($results, 'challenge', 'fail', get_string('testdetail_unauthorised', 'vrroomruntime_companion'));

        $results = $this->run_tester($this->companion(fn() => ['status' => 403, 'body' => '{"error":"forbidden"}',
            'error' => '']));
        $this->assert_result(
            $results,
            'challenge',
            'fail',
            get_string('testdetail_forbidden', 'vrroomruntime_companion', config::issuer())
        );
        $this->assertStringContainsString(config::issuer(), $this->find_result($results, 'challenge')['detail']);

        $results = $this->run_tester($this->companion(fn() => ['status' => 429, 'body' => '', 'error' => '']));
        $this->assert_result($results, 'challenge', 'fail', get_string('testdetail_http', 'vrroomruntime_companion', 429));
    }

    /**
     * A wrong MAC, a changed nonce and a stale timestamp fail.
     */
    public function test_challenge_bad_answers(): void {
        $badmac = get_string('testdetail_badmac', 'vrroomruntime_companion');

        // Signed with another secret: a stranger's server.
        $results = $this->run_tester($this->companion(fn(array $claims) => $this->answer(
            $claims,
            ['secret' => strrev(self::SECRET)]
        )));
        $this->assert_result($results, 'challenge', 'fail', $badmac);

        // Another nonce, correctly signed for it.
        $results = $this->run_tester($this->companion(fn(array $claims) => $this->answer(
            $claims,
            ['nonce' => str_repeat('0', 32)]
        )));
        $this->assert_result($results, 'challenge', 'fail', $badmac);

        // A clock 121 seconds off.
        $results = $this->run_tester($this->companion(fn(array $claims) => $this->answer($claims, ['ts' => self::NOW + 121])));
        $this->assert_result($results, 'challenge', 'fail', get_string('testdetail_clock', 'vrroomruntime_companion'));

        // 120 seconds off is still fine.
        $results = $this->run_tester($this->companion(fn(array $claims) => $this->answer($claims, ['ts' => self::NOW - 120])));
        $this->assert_result($results, 'challenge', 'ok', '');

        // A MAC over another issuer.
        $results = $this->run_tester($this->companion(fn(array $claims) => $this->answer(
            $claims,
            ['iss' => 'https://other.example.org']
        )));
        $this->assert_result($results, 'challenge', 'fail', $badmac);

        // Missing fields.
        $results = $this->run_tester($this->companion(fn() => ['status' => 200,
            'body' => '{"service":"vrroom-sync","protocol":1}', 'error' => '']));
        $this->assert_result($results, 'challenge', 'fail', get_string('testdetail_invalid', 'vrroomruntime_companion'));
    }

    /**
     * LiveKit: none set is skipped, partly set fails, a refused key fails, both answers pass.
     */
    public function test_livekit(): void {
        $companion = $this->companion(fn(array $claims) => $this->answer($claims));

        set_config('livekiturl', 'wss://livekit.example.org', 'vrroomruntime_companion');
        $results = $this->run_tester($companion);
        $this->assert_result($results, 'livekit', 'fail', get_string('errorlivekitincomplete', 'vrroomruntime_companion'));
        $this->assert_result($results, 'livekitauth', 'skip', '');

        set_config('livekitkey', 'APIkey123', 'vrroomruntime_companion');
        set_config('livekitsecret', self::LIVEKIT_SECRET, 'vrroomruntime_companion');

        // LiveKit down.
        $results = $this->run_tester(function (string $method, string $url, array $headers) use ($companion) {
            if (str_starts_with($url, 'https://livekit.example.org')) {
                return ['status' => 0, 'body' => '', 'error' => 'timeout'];
            }
            return $companion($method, $url, $headers);
        });
        $this->assert_result($results, 'livekit', 'fail', get_string('testdetail_network', 'vrroomruntime_companion', 'timeout'));
        $this->assert_result($results, 'livekitauth', 'skip', '');

        // Key refused.
        $results = $this->run_tester(function (string $method, string $url, array $headers) use ($companion) {
            if ($url === 'https://livekit.example.org/') {
                return ['status' => 200, 'body' => 'OK', 'error' => ''];
            }
            if (str_starts_with($url, 'https://livekit.example.org')) {
                return ['status' => 401, 'body' => '', 'error' => ''];
            }
            return $companion($method, $url, $headers);
        });
        $this->assert_result($results, 'livekit', 'ok', '');
        $this->assert_result($results, 'livekitauth', 'fail', get_string('testdetail_unauthorised', 'vrroomruntime_companion'));

        // Everything passes.
        $this->requests = [];
        $results = $this->run_tester(function (string $method, string $url, array $headers) use ($companion) {
            if (str_starts_with($url, 'https://livekit.example.org')) {
                return ['status' => 200, 'body' => $method === 'POST' ? '{"rooms":[]}' : 'OK', 'error' => ''];
            }
            return $companion($method, $url, $headers);
        });
        foreach ($results as $result) {
            $this->assertSame('ok', $result['status'], $result['check']);
        }
        $listrooms = end($this->requests);
        $this->assertSame('POST', $listrooms['method']);
        $this->assertSame('https://livekit.example.org/twirp/livekit.RoomService/ListRooms', $listrooms['url']);
        $this->assertSame('{}', $listrooms['body']);
        $this->assertContains('Content-Type: application/json', $listrooms['headers']);
        $claims = $this->bearer_claims($listrooms['headers'], self::LIVEKIT_SECRET);
        $this->assertSame('APIkey123', $claims['iss']);
        $this->assertSame(['roomList' => true], $claims['video']);

        // No secret anywhere in the results or the requests.
        $json = json_encode([$results, $this->requests]);
        $this->assertStringNotContainsString(self::SECRET, $json);
        $this->assertStringNotContainsString(self::LIVEKIT_SECRET, $json);
    }

    /**
     * Run the tester with a scripted client.
     *
     * @param callable $handler fn(string $method, string $url, array $headers, string $body): array
     * @return array The results.
     */
    private function run_tester(callable $handler): array {
        $test = $this;
        $client = new class ($handler, $test) implements http_client {
            /**
             * Constructor.
             *
             * @param mixed $handler The scripted answers.
             * @param connection_tester_test $test The test, which records the requests.
             */
            public function __construct(
                /** @var mixed The scripted answers. */
                private $handler,
                /** @var connection_tester_test The test. */
                private connection_tester_test $test,
            ) {
            }

            /**
             * Record the request and answer it from the script.
             *
             * @param string $method Method.
             * @param string $url Address.
             * @param array $headers Header lines.
             * @param string $body Body.
             * @param int $timeout Seconds.
             * @return array
             */
            public function request(string $method, string $url, array $headers, string $body, int $timeout): array {
                $this->test->record(['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body,
                    'timeout' => $timeout]);
                return ($this->handler)($method, $url, $headers, $body);
            }
        };
        return (new connection_tester($client))->run(self::NOW);
    }

    /**
     * Record a request of the fake client.
     *
     * @param array $request The request.
     */
    public function record(array $request): void {
        $this->assertSame(connection_tester::TIMEOUT, $request['timeout']);
        $this->requests[] = $request;
    }

    /**
     * A healthy companion whose challenge answer comes from $challenge.
     *
     * @param callable $challenge fn(array $claims): array, given the verified test token's claims.
     * @return callable The handler.
     */
    private function companion(callable $challenge): callable {
        return function (string $method, string $url, array $headers) use ($challenge): array {
            if (str_ends_with($url, '/healthz')) {
                return ['status' => 200, 'body' => self::HEALTHY, 'error' => ''];
            }
            if (str_ends_with($url, '/v1/challenge')) {
                return $challenge($this->bearer_claims($headers, self::SECRET));
            }
            return ['status' => 404, 'body' => '', 'error' => ''];
        };
    }

    /**
     * A challenge answer as vrroom-sync makes it, with optional changes.
     *
     * @param array $claims The verified test token's claims.
     * @param array $change Overrides: secret, nonce, ts, iss.
     * @return array The response.
     */
    private function answer(array $claims, array $change = []): array {
        $nonce = $change['nonce'] ?? $claims['nonce'];
        $ts = $change['ts'] ?? self::NOW;
        $iss = $change['iss'] ?? $claims['iss'];
        $mac = hash_hmac('sha256', "vrroom-sync/challenge/1\n{$nonce}\n{$iss}\n{$ts}", $change['secret'] ?? self::SECRET);
        return ['status' => 200, 'error' => '', 'body' => json_encode(['service' => 'vrroom-sync', 'protocol' => 1,
            'version' => '0.1.0', 'nonce' => $nonce, 'ts' => $ts, 'livekitadmin' => false, 'mac' => $mac])];
    }

    /**
     * The verified claims of the Bearer token in a request's headers.
     *
     * @param array $headers Header lines.
     * @param string $secret The key.
     * @return array
     */
    private function bearer_claims(array $headers, string $secret): array {
        foreach ($headers as $header) {
            if (str_starts_with($header, 'Authorization: Bearer ')) {
                $token = substr($header, strlen('Authorization: Bearer '));
                return json_decode(json_encode(JWT::decode($token, new Key($secret, 'HS256'))), true);
            }
        }
        $this->fail('No Bearer token in the request.');
    }

    /**
     * A handler that fails the test when called.
     *
     * @return array
     */
    private function fail_request(): array {
        $this->fail('No request may be made.');
    }

    /**
     * The result of one check.
     *
     * @param array $results All results.
     * @param string $check The check.
     * @return array
     */
    private function find_result(array $results, string $check): array {
        foreach ($results as $result) {
            if ($result['check'] === $check) {
                return $result;
            }
        }
        $this->fail("No result for {$check}.");
    }

    /**
     * Assert one check's status and detail.
     *
     * @param array $results All results.
     * @param string $check The check.
     * @param string $status Expected status.
     * @param string $detail Expected detail.
     */
    private function assert_result(array $results, string $check, string $status, string $detail): void {
        $result = $this->find_result($results, $check);
        $this->assertSame($status, $result['status'], $check . ': ' . $result['detail']);
        $this->assertSame($detail, $result['detail'], $check);
    }
}
