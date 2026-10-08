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
 * English strings for the VR room companion service run mode.
 *
 * @package    vrroomruntime_companion
 * @category   string
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['errorinvalidlivekiturl'] = 'Enter the LiveKit address starting with wss:// (or ws:// for testing), with no path, query or fragment.';
$string['errorinvalidsecret'] = 'The secret must be at least 32 characters long and contain no spaces.';
$string['errorinvalidurl'] = 'Enter the address starting with https:// (or http:// for testing), with no query or fragment.';
$string['errorlivedisabled'] = 'The live room is not available in this activity.';
$string['errorlivekitincomplete'] = 'Enter the LiveKit address, API key and API secret, or leave all three empty.';
$string['livekitheading'] = 'Voice (LiveKit)';
$string['livekitheading_desc'] = 'Voice in live rooms goes through the LiveKit server of the companion stack. Leave these three settings empty for live rooms without voice.';
$string['livekitkey'] = 'LiveKit API key';
$string['livekitkey_desc'] = 'The LIVEKIT_API_KEY value from the companion stack\'s .env file.';
$string['livekitsecret'] = 'LiveKit API secret';
$string['livekitsecret_desc'] = 'The LIVEKIT_API_SECRET value from the companion stack\'s .env file. It stays on this server: browsers only receive short-lived room tokens made with it.';
$string['livekiturl'] = 'LiveKit address';
$string['livekiturl_desc'] = 'The address browsers use for voice, for example wss://livekit.example.org.';
$string['livenotice'] = 'When you join the live room, your browser connects to this site\'s companion service and voice server. They pass your name, where you are in the room and, while your microphone is on, your voice to the other people in the room. These servers see your IP address; the other people do not. Nothing is recorded.';
$string['maxusers'] = 'Maximum people per room';
$string['maxusers_desc'] = 'The largest live room teachers can choose for an activity that uses the companion service.';
$string['notconfigured'] = 'The companion service has not been set up on this site yet. Please ask the site administrator.';
$string['pluginname'] = 'Companion service';
$string['privacy:metadata:companion'] = 'The VR room companion service passes positions and states between the people in a live room. It keeps them in memory only while you are in the room and stores nothing.';
$string['privacy:metadata:companion:fullname'] = 'Your full name, shown to the other people in the live room.';
$string['privacy:metadata:companion:ipaddress'] = 'Your IP address, which the companion service sees while your browser is connected to it.';
$string['privacy:metadata:companion:peerid'] = 'An identifier made from your account and the activity. It does not contain your user ID and cannot be turned back into it without this site\'s secret.';
$string['privacy:metadata:companion:pose'] = 'Your position and direction in the room, and whether you are in the 3D room, VR, AR, mixed reality or the list view.';
$string['privacy:metadata:livekit'] = 'The LiveKit voice server of the companion stack passes the voices of the people in a live room to each other while they are in it. It does not record them.';
$string['privacy:metadata:livekit:fullname'] = 'Your full name.';
$string['privacy:metadata:livekit:ipaddress'] = 'Your IP address, which the voice server sees while your browser is connected to it.';
$string['privacy:metadata:livekit:peerid'] = 'The same identifier as for the companion service.';
$string['privacy:metadata:livekit:voice'] = 'Your voice, while your microphone is on.';
$string['privacy:metadata:livepeers'] = 'The other people in the same live room.';
$string['privacy:metadata:livepeers:fullname'] = 'Your full name.';
$string['privacy:metadata:livepeers:pose'] = 'Your position and direction in the room.';
$string['privacy:metadata:livepeers:voice'] = 'Your voice, while your microphone is on.';
$string['secret'] = 'Shared secret';
$string['secret_desc'] = 'The VRROOM_SYNC_SECRET value from the companion stack\'s .env file. This site signs room tokens with it and the companion service checks them. At least 32 characters.';
$string['serverurl'] = 'Address used by this server (optional)';
$string['serverurl_desc'] = 'The address this Moodle server uses to reach the companion service for the connection test, if it differs from the address browsers use. Leave empty to use the address above.';
$string['settingsheading'] = 'Companion service';
$string['settingsheading_desc'] = 'With the companion service, up to 50 people can meet in a live room, and voice goes through a voice server instead of directly between browsers. Install the vrroom-companion stack first (see its README), then enter its addresses and secrets here.';
$string['testbrowser'] = 'Check from this browser';
$string['testbrowserfailed'] = 'This browser could not connect to {$a}.';
$string['testbrowserok'] = 'This browser connected to {$a}.';
$string['testbrowserrunning'] = 'Connecting…';
$string['testcheck_challenge'] = 'The companion service proved that it knows the shared secret';
$string['testcheck_health'] = 'The companion service answers';
$string['testcheck_livekit'] = 'The LiveKit server answers';
$string['testcheck_livekitauth'] = 'The LiveKit server accepts the API key and secret';
$string['testcheck_settings'] = 'The settings are complete';
$string['testconnection'] = 'Test the companion connection';
$string['testconnection_desc'] = 'After saving these settings, <a href="{$a}">test the connection</a>.';
$string['testdetail_badmac'] = 'The answer was not signed with this site\'s shared secret. Another server may be answering at this address.';
$string['testdetail_clock'] = 'The clock of the companion server differs from this server\'s by more than two minutes.';
$string['testdetail_forbidden'] = 'The companion service does not accept tokens from this site. Add {$a} to VRROOM_SYNC_ISSUERS in the stack\'s .env file.';
$string['testdetail_http'] = 'Unexpected answer (HTTP status {$a}).';
$string['testdetail_invalid'] = 'The answer could not be read.';
$string['testdetail_network'] = 'No connection: {$a}';
$string['testdetail_novoice'] = 'Voice is not set up.';
$string['testdetail_protocol'] = 'The companion service uses protocol version {$a}; this plugin needs version 1.';
$string['testdetail_unauthorised'] = 'The server refused this site\'s token. Check that both sides use the same secret.';
$string['testfail'] = 'Failed';
$string['testintro'] = 'This test contacts the companion service from this Moodle server. It checks that the service answers, that it knows the same shared secret (the secret itself is never sent) and that the LiveKit server accepts the API key. The browser check opens a connection from your own browser.';
$string['testok'] = 'Passed';
$string['testrun'] = 'Run the test';
$string['testskipped'] = 'Not run';
$string['url'] = 'Companion address';
$string['url_desc'] = 'The address of the companion service behind your web server, for example https://companion.example.org. Browsers connect to its /sync path with a secure WebSocket.';
