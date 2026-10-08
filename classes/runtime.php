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

/**
 * The companion service run mode.
 *
 * @package    vrroomruntime_companion
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace vrroomruntime_companion;

use vrroomruntime_companion\local\access;
use vrroomruntime_companion\local\config;
use mod_vrroom\local\launch\config_builder;
use mod_vrroom\local\runtime\base;
use mod_vrroom\local\runtime\cleanup_scope;
use mod_vrroom\local\runtime\launch_descriptor;
use mod_vrroom\local\runtime\readiness_result;
use mod_vrroom\local\runtime\runtime_capabilities;

/**
 * Companion run mode: the built-in viewer, with live rooms of up to 50 people through the
 * vrroom-companion stack (vrroom-sync for positions and states, LiveKit for voice).
 *
 * The viewer, avatars, voice controls and panels are the built-in run mode's; only the live
 * transport differs (vrroomruntime_companion/transport_companion). Statements still go from the
 * browser to Moodle. The companion stores nothing and grades nothing, and this plugin keeps no
 * data of its own, so cleanup has nothing to remove.
 *
 * @package    vrroomruntime_companion
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class runtime extends base {
    /**
     * Ready when the companion address and the shared secret are set. No network call: this runs
     * on every view and launch, and reachability is the admin test's job.
     *
     * @return readiness_result
     */
    public function readiness(): readiness_result {
        if (config::is_configured()) {
            return new readiness_result(true);
        }
        return new readiness_result(false, get_string('notconfigured', config::COMPONENT));
    }

    /**
     * Large live rooms with avatars when configured (voice when LiveKit is set up too), the same XR
     * modes and objective types as the built-in viewer; one user while not configured.
     *
     * @return runtime_capabilities
     */
    public function capabilities(): runtime_capabilities {
        $xrmodes = ['vr', 'ar', 'mr'];
        $objectivetypes = ['visit_zone', 'interact', 'answer_question', 'collect', 'sequence'];
        if (!config::is_configured()) {
            return new runtime_capabilities(1, false, false, $xrmodes, $objectivetypes);
        }
        return new runtime_capabilities(config::max_users(), config::voice_configured(), true, $xrmodes, $objectivetypes);
    }

    /**
     * Inline launch in the built-in viewer, with the companion's live block.
     *
     * @param \cm_info $cm The course module.
     * @param \stdClass $attempt The attempt row (vrroom_attempts).
     * @param \stdClass $user The user who launches.
     * @return launch_descriptor
     */
    public function launch(\cm_info $cm, \stdClass $attempt, \stdClass $user): launch_descriptor {
        return $this->build_descriptor($cm, $attempt, $user);
    }

    /**
     * Inline launch for a teacher preview (no attempt, nothing recorded).
     *
     * @param \cm_info $cm The course module.
     * @param \stdClass $user The user who previews.
     * @return launch_descriptor
     */
    public function preview(\cm_info $cm, \stdClass $user): launch_descriptor {
        return $this->build_descriptor($cm, null, $user);
    }

    /**
     * Statements reach Moodle from the browser through core_xapi_statement_post.
     *
     * @return string
     */
    public function statement_channel(): string {
        return 'browser';
    }

    /**
     * Nothing to remove: this run mode stores nothing, and companion rooms vanish when empty.
     *
     * @param cleanup_scope $scope What is being cleaned up.
     * @return void
     */
    public function cleanup(cleanup_scope $scope): void {
        // Nothing is stored in Moodle or in the companion service for any scope.
    }

    /**
     * The built-in viewer's AMD entry point, which view.php boots.
     *
     * @return array ['module' => string, 'function' => string]
     */
    public function client_entry(): array {
        return [
            'module' => \vrroomruntime_builtin\runtime::CLIENT_MODULE,
            'function' => \vrroomruntime_builtin\runtime::CLIENT_FUNCTION,
        ];
    }

    /**
     * Build an inline launch descriptor for an attempt or a preview.
     *
     * @param \cm_info $cm The course module.
     * @param \stdClass|null $attempt The attempt, or null for a preview.
     * @param \stdClass $user The user.
     * @return launch_descriptor
     */
    protected function build_descriptor(\cm_info $cm, ?\stdClass $attempt, \stdClass $user): launch_descriptor {
        global $DB;
        $vrroom = $DB->get_record('vrroom', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $config = config_builder::build($cm, $context, $vrroom, $attempt, $user);
        $config['live'] = access::launch_block($cm, $context, $vrroom, $attempt, $user);
        return new launch_descriptor(launch_descriptor::TYPE_INLINE, $config);
    }
}
