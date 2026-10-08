// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Browser check of the companion test page: can this browser open the companion WebSocket?
 *
 * Opens the sync address with the protocol's subprotocol and closes it again as soon as it is open
 * (no token is sent, so the server ends the session by itself if the close is lost).
 *
 * @module     mavrruntime_companion/testconnection
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import Pending from 'core/pending';
import {getString} from 'core/str';
import {SUBPROTOCOL, CLOSE} from 'mavrruntime_companion/syncprotocol';

/** Give up after this long (ms). */
const TIMEOUT = 8000;

/**
 * Try to open the WebSocket.
 *
 * @param {string} syncurl The sync address (ws:// or wss://).
 * @returns {Promise<boolean>} Whether it opened.
 */
const check = (syncurl) => new Promise((resolve) => {
    let ws = null;
    let done = false;
    let timer = null;
    const finish = (ok) => {
        if (done) {
            return;
        }
        done = true;
        if (timer) {
            clearTimeout(timer);
        }
        if (ws) {
            ws.onopen = null;
            ws.onerror = null;
            ws.onclose = null;
            try {
                ws.close(CLOSE.NORMAL);
            } catch (error) {
                // Already closed.
            }
        }
        resolve(ok);
    };
    try {
        ws = new WebSocket(syncurl, [SUBPROTOCOL]);
    } catch (error) {
        finish(false);
        return;
    }
    timer = setTimeout(() => finish(false), TIMEOUT);
    ws.onopen = () => finish(true);
    ws.onerror = () => finish(false);
    ws.onclose = () => finish(false);
});

/**
 * Set up the browser check.
 *
 * @param {string} rootid Id of the region holding the button and the status paragraph.
 * @param {string} syncurl The sync address.
 */
export const init = (rootid, syncurl) => {
    const root = document.getElementById(rootid);
    if (!root || typeof syncurl !== 'string' || !/^wss?:\/\//.test(syncurl)) {
        return;
    }
    const button = root.querySelector('button');
    const status = root.querySelector('[role="status"]');
    if (!button || !status) {
        return;
    }
    let running = false;
    button.addEventListener('click', (e) => {
        e.preventDefault();
        if (running) {
            return;
        }
        running = true;
        button.disabled = true;
        const pending = new Pending('mavrruntime_companion/testconnection:check');
        Promise.resolve(getString('testbrowserrunning', 'mavrruntime_companion')).then((text) => {
            status.textContent = text;
            return check(syncurl);
        }).then((ok) => getString(ok ? 'testbrowserok' : 'testbrowserfailed', 'mavrruntime_companion', syncurl))
        .then((text) => {
            status.textContent = text;
            return undefined;
        }).catch(Notification.exception).then(() => {
            running = false;
            button.disabled = false;
            pending.resolve();
            return undefined;
        }).catch(() => undefined);
    });
};
