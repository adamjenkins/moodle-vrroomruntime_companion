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
 * A secret setting (unmaskable password): empty, or at least 32 characters without spaces.
 *
 * The config log masks the value (admin_setting_configpasswordunmask::add_to_config_log()).
 *
 * @package    vrroomruntime_companion
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_secret extends \admin_setting_configpasswordunmask {
    /**
     * Constructor.
     *
     * @param string $name Setting name, 'vrroomruntime_companion/<name>'.
     * @param \lang_string|string $visiblename Label.
     * @param \lang_string|string $description Description.
     */
    public function __construct(string $name, $visiblename, $description) {
        parent::__construct($name, $visiblename, $description, '');
    }

    /**
     * Store the secret trimmed.
     *
     * @param mixed $data The submitted value.
     * @return string '' when saved, else the error message.
     */
    public function write_setting($data) {
        return parent::write_setting(trim((string)$data));
    }

    /**
     * Check the secret.
     *
     * @param mixed $data The submitted value.
     * @return bool|string true, or the error message.
     */
    public function validate($data) {
        $data = trim((string)$data);
        if ($data === '') {
            return true;
        }
        if (strlen($data) < config::MIN_SECRET_LENGTH || preg_match('/\s/', $data)) {
            return get_string('errorinvalidsecret', config::COMPONENT);
        }
        return true;
    }
}
