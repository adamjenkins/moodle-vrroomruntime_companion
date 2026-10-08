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
 * The HTTP requests of the connection test (an interface, so tests can script the answers).
 *
 * @package    vrroomruntime_companion
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface http_client {
    /**
     * Send one request, without following redirects.
     *
     * @param string $method 'GET' or 'POST'.
     * @param string $url The address.
     * @param string[] $headers Header lines, e.g. ['Content-Type: application/json'].
     * @param string $body Request body ('' for GET).
     * @param int $timeout Seconds for connecting and for the whole request.
     * @return array{status: int, body: string, error: string} status 0 on a network error
     */
    public function request(string $method, string $url, array $headers, string $body, int $timeout): array;
}
