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

/**
 * The connection test's requests through core's curl class.
 *
 * The companion stack usually runs on the same host or LAN, which core's default blocked-hosts
 * list refuses, so the security helper is bypassed ('ignoresecurity'). This is acceptable because
 * the addresses come from site settings only and requests are made only from the admin test page
 * (moodle/site:config), without redirects.
 *
 * @package    vrroomruntime_companion
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class curl_http_client implements http_client {
    /** @var int Longest response body kept, bytes. */
    private const MAX_BODY = 65536;

    /**
     * Send one request.
     *
     * @param string $method 'GET' or 'POST'.
     * @param string $url The address.
     * @param string[] $headers Header lines.
     * @param string $body Request body ('' for GET).
     * @param int $timeout Seconds.
     * @return array{status: int, body: string, error: string}
     */
    public function request(string $method, string $url, array $headers, string $body, int $timeout): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $method = strtoupper($method);
        if ($method !== 'GET' && $method !== 'POST') {
            throw new \coding_exception('Unsupported method: ' . $method);
        }
        $curl = new \curl(['ignoresecurity' => true]);
        foreach ($headers as $header) {
            $curl->setHeader($header);
        }
        $options = [
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_CONNECTTIMEOUT' => $timeout,
            'CURLOPT_FOLLOWLOCATION' => false,
            'CURLOPT_MAXREDIRS' => 0,
        ];
        if ($method === 'GET') {
            $response = $curl->get($url, [], $options);
        } else {
            $response = $curl->post($url, $body, $options);
        }
        $info = $curl->get_info();
        $errno = (int)$curl->get_errno();
        return [
            'status' => $errno ? 0 : (int)(is_array($info) ? ($info['http_code'] ?? 0) : 0),
            'body' => $errno ? '' : substr((string)$response, 0, self::MAX_BODY),
            'error' => $errno ? ((string)$curl->error !== '' ? (string)$curl->error : 'cURL error ' . $errno) : '',
        ];
    }
}
