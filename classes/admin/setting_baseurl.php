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

namespace vrroomruntime_companion\admin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

use vrroomruntime_companion\local\config;

/**
 * An address setting: the companion service (https://, or http:// for testing) or the LiveKit
 * server (wss://, or ws:// for testing, without a path). Empty means not configured.
 *
 * @package    vrroomruntime_companion
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_baseurl extends \admin_setting_configtext {
    /** @var string A companion service address. */
    public const KIND_COMPANION = 'companion';

    /** @var string A LiveKit server address. */
    public const KIND_LIVEKIT = 'livekit';

    /** @var string KIND_COMPANION or KIND_LIVEKIT. */
    protected string $kind;

    /**
     * Constructor.
     *
     * @param string $name Setting name, 'vrroomruntime_companion/<name>'.
     * @param \lang_string|string $visiblename Label.
     * @param \lang_string|string $description Description.
     * @param string $kind KIND_COMPANION or KIND_LIVEKIT.
     */
    public function __construct(string $name, $visiblename, $description, string $kind = self::KIND_COMPANION) {
        if ($kind !== self::KIND_COMPANION && $kind !== self::KIND_LIVEKIT) {
            throw new \coding_exception('Unknown address kind: ' . $kind);
        }
        $this->kind = $kind;
        parent::__construct($name, $visiblename, $description, '', PARAM_RAW_TRIMMED, 60);
    }

    /**
     * Store the address trimmed.
     *
     * @param mixed $data The submitted value.
     * @return string '' when saved, else the error message.
     */
    public function write_setting($data) {
        return parent::write_setting(trim((string)$data));
    }

    /**
     * Check the address.
     *
     * @param mixed $data The submitted value.
     * @return bool|string true, or the error message.
     */
    public function validate($data) {
        $data = trim((string)$data);
        if ($this->kind === self::KIND_LIVEKIT) {
            return config::validate_livekit_url($data);
        }
        return config::validate_base_url($data);
    }
}
