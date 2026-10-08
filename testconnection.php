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
 * Admin page: test the connection to the companion service and the LiveKit server.
 *
 * The server-side checks run only on a POST with the session key. Addresses are shown; secrets
 * and tokens never are.
 *
 * @package    mavrruntime_companion
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mavrruntime_companion\local\config;
use mavrruntime_companion\local\connection_tester;

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('mavrruntimecompaniontest');

$component = config::COMPONENT;
$results = null;
if (data_submitted()) {
    require_sesskey();
    $results = (new connection_tester())->run();
}

echo $OUTPUT->header();
echo $OUTPUT->heading(s(get_string('testconnection', $component)));
echo html_writer::tag('p', s(get_string('testintro', $component)));

// The addresses in use (never the secrets).
$none = get_string('none');
$companionurl = config::base_url();
$livekiturl = config::livekit_url();
echo html_writer::start_tag('dl', ['class' => 'row']);
echo html_writer::tag('dt', s(get_string('url', $component)), ['class' => 'col-sm-3']);
echo html_writer::tag('dd', s($companionurl !== '' ? $companionurl : $none), ['class' => 'col-sm-9']);
echo html_writer::tag('dt', s(get_string('livekiturl', $component)), ['class' => 'col-sm-3']);
echo html_writer::tag('dd', s($livekiturl !== '' ? $livekiturl : $none), ['class' => 'col-sm-9']);
echo html_writer::end_tag('dl');

// The server-side test.
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false), 'class' => 'mb-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::tag('button', s(get_string('testrun', $component)),
    ['type' => 'submit', 'name' => 'runtest', 'value' => '1', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

if ($results !== null) {
    $statuses = [
        connection_tester::STATUS_OK => ['testok', 'badge bg-success'],
        connection_tester::STATUS_FAIL => ['testfail', 'badge bg-danger'],
        connection_tester::STATUS_SKIP => ['testskipped', 'badge bg-secondary'],
    ];
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = [s(get_string('check')), s(get_string('status')), s(get_string('details'))];
    foreach ($results as $result) {
        [$statuskey, $badgeclass] = $statuses[$result['status']];
        $table->data[] = [
            s(get_string('testcheck_' . $result['check'], $component)),
            html_writer::span(s(get_string($statuskey, $component)), $badgeclass),
            s($result['detail']),
        ];
    }
    echo html_writer::table($table);
}

// The browser check (only with an address to connect to).
$syncurl = config::sync_url();
if ($syncurl !== '') {
    echo html_writer::start_div('mt-4', ['id' => 'mavr-companion-browsertest']);
    echo html_writer::tag('button', s(get_string('testbrowser', $component)),
        ['type' => 'button', 'class' => 'btn btn-secondary', 'data-action' => 'mavr-companion-browsertest']);
    echo html_writer::tag('p', '', ['role' => 'status', 'class' => 'mt-2', 'data-region' => 'mavr-companion-browserstatus']);
    echo html_writer::end_div();
    $PAGE->requires->js_call_amd('mavrruntime_companion/testconnection', 'init', ['mavr-companion-browsertest', $syncurl]);
}

echo $OUTPUT->footer();
