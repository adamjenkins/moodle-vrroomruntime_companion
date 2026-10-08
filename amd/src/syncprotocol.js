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
 * Client side of the companion wire protocol 'mavr-sync.1': frame builders, the parser of server
 * frames, the close-code table and the reconnect and refresh timings.
 *
 * Every frame is one JSON object with a type 't'. Client frames: hello {token}, auth {token},
 * pose {d}, state {d, to?}, ping {n}, leave. Server frames: welcome, join, leave, poses, state,
 * authok, pong, error. Pose and state payloads ('d') are strings of the Phase 3 codec
 * (mavrruntime_builtin/live/codec); this module does not decode them.
 *
 * Server frames come from the network: parse() accepts only the known frame types with valid
 * shapes, and rebuilds every frame from its known keys.
 *
 * Dependency-free ES module (no imports) so that both grunt (AMD build) and node --test load it.
 *
 * @module     mavrruntime_companion/syncprotocol
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** WebSocket subprotocol of this protocol version. */
export const SUBPROTOCOL = 'mavr-sync.1';

/** Largest client frame in bytes (the server closes with 1009 above it). */
export const MAX_FRAME = 4096;

/** Largest server frame this client parses, in characters (a poses batch of a full room fits). */
export const MAX_SERVER_FRAME = 524288;

/** Close codes of the server (and 1000/1001/1006/1009 of the WebSocket standard). */
export const CLOSE = Object.freeze({
    NORMAL: 1000,
    GOING_AWAY: 1001,
    TOO_BIG: 1009,
    PROTOCOL: 4000,
    HELLO_TIMEOUT: 4001,
    SLOW: 4008,
    UNAUTHORISED: 4401,
    FORBIDDEN: 4403,
    REPLACED: 4409,
    EXPIRED: 4410,
    RATE: 4429,
    FULL: 4503,
    BUSY: 4504,
});

/** Give up reconnecting after this long of failed attempts (ms). */
export const GIVE_UP_AFTER = 120000;

/** Reconnect delays in ms; the last one repeats. */
const BACKOFF = Object.freeze([1000, 2000, 4000, 8000, 15000]);

/** Largest number of entries in a poses batch or a welcome peer list. */
const MAX_ENTRIES = 100;

/** Largest codec payload string in a server frame. */
const MAX_PAYLOAD = 2048;

/** Largest display name, in code points. */
const MAX_NAME = 100;

/** Largest integer of the protocol (ping counter, room size bound). */
const MAX_INT = 2147483647;

/** Peer kinds. */
const KINDS = Object.freeze(['attempt', 'preview']);

/** Reasons of a leave frame. */
const LEAVE_REASONS = Object.freeze(['left', 'timeout', 'replaced', 'expired', 'removed']);

/** Codes of an error frame. */
const ERROR_CODES = Object.freeze(['auth', 'ratelimited', 'protocol', 'full', 'busy']);

/**
 * Whether a value is a peer id (32 lowercase hexadecimal characters).
 *
 * @param {*} value
 * @returns {boolean}
 */
export const isPeerId = (value) => typeof value === 'string' && /^[0-9a-f]{32}$/.test(value);

/**
 * Whether a value is an integer in a range.
 *
 * @param {*} value
 * @param {number} min
 * @param {number} max
 * @returns {boolean}
 */
const isInt = (value, min, max) => Number.isInteger(value) && value >= min && value <= max;

/**
 * Whether a value is a display name: a string of 1..100 code points, none below U+0020 (the rule
 * mavr-sync applies to the token's name claim).
 *
 * @param {*} value
 * @returns {boolean}
 */
const isName = (value) => {
    if (typeof value !== 'string' || value === '') {
        return false;
    }
    // eslint-disable-next-line no-control-regex
    if (/[\u0000-\u001f]/.test(value)) {
        return false;
    }
    return Array.from(value).length <= MAX_NAME;
};

/**
 * Whether a value is a codec payload string.
 *
 * @param {*} value
 * @returns {boolean}
 */
const isPayload = (value) => typeof value === 'string' && value !== '' && value.length <= MAX_PAYLOAD;

/**
 * Whether a value is a plain object (not null, not an array).
 *
 * @param {*} value
 * @returns {boolean}
 */
const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * A peer description {p, name, kind} rebuilt from known keys, or null.
 *
 * @param {*} value
 * @returns {{p: string, name: string, kind: string}|null}
 */
const parsePeer = (value) => {
    if (!isObject(value) || !isPeerId(value.p) || !isName(value.name) || KINDS.indexOf(value.kind) === -1) {
        return null;
    }
    return {p: value.p, name: value.name, kind: value.kind};
};

/**
 * The hello frame: the first frame after the socket opens.
 *
 * @param {string} token Join token from Moodle.
 * @returns {string}
 */
export const hello = (token) => JSON.stringify({t: 'hello', token: String(token)});

/**
 * The auth frame: a fresh token for the open session.
 *
 * @param {string} token Join token from Moodle.
 * @returns {string}
 */
export const auth = (token) => JSON.stringify({t: 'auth', token: String(token)});

/**
 * A pose frame.
 *
 * @param {string} codecstring A codec pose string.
 * @returns {string}
 */
export const pose = (codecstring) => JSON.stringify({t: 'pose', d: String(codecstring)});

/**
 * A state frame, to everybody or to one peer.
 *
 * @param {string} codecstring A codec state string.
 * @param {string} [to] Peer id of the only receiver.
 * @returns {string}
 */
export const state = (codecstring, to) => {
    const frame = {t: 'state', d: String(codecstring)};
    if (to) {
        frame.to = String(to);
    }
    return JSON.stringify(frame);
};

/**
 * A ping frame.
 *
 * @param {number} n Counter echoed by the pong.
 * @returns {string}
 */
export const ping = (n) => JSON.stringify({t: 'ping', n: isInt(n, 0, MAX_INT) ? n : 0});

/**
 * The leave frame.
 *
 * @returns {string}
 */
export const leave = () => JSON.stringify({t: 'leave'});

/**
 * Parsers of server frames by type. Each returns a fresh frame or null.
 */
const PARSERS = {
    welcome: (frame) => {
        if (frame.protocol !== 1 || typeof frame.server !== 'string' || frame.server.length > 64) {
            return null;
        }
        const self = parsePeer(frame.self);
        if (!self || !isObject(frame.room) || !isInt(frame.room.max, 1, MAX_ENTRIES)) {
            return null;
        }
        if (!Array.isArray(frame.peers) || frame.peers.length > MAX_ENTRIES) {
            return null;
        }
        const peers = [];
        for (let i = 0; i < frame.peers.length; i++) {
            const entry = frame.peers[i];
            const peer = parsePeer(entry);
            if (!peer || (entry.s !== null && !isPayload(entry.s))) {
                return null;
            }
            peer.s = entry.s;
            peers.push(peer);
        }
        if (!isInt(frame.exp, 0, Number.MAX_SAFE_INTEGER) || !isInt(frame.posehz, 1, 60)) {
            return null;
        }
        return {
            t: 'welcome',
            protocol: 1,
            server: frame.server,
            self: self,
            room: {max: frame.room.max},
            peers: peers,
            exp: frame.exp,
            posehz: frame.posehz,
        };
    },
    join: (frame) => {
        const peer = parsePeer(frame.peer);
        return peer ? {t: 'join', peer: peer} : null;
    },
    leave: (frame) => {
        if (!isPeerId(frame.p) || LEAVE_REASONS.indexOf(frame.r) === -1) {
            return null;
        }
        return {t: 'leave', p: frame.p, r: frame.r};
    },
    poses: (frame) => {
        if (!Array.isArray(frame.b) || frame.b.length > MAX_ENTRIES) {
            return null;
        }
        const batch = [];
        for (let i = 0; i < frame.b.length; i++) {
            const entry = frame.b[i];
            if (!Array.isArray(entry) || entry.length !== 2 || !isPeerId(entry[0]) || !isPayload(entry[1])) {
                return null;
            }
            batch.push([entry[0], entry[1]]);
        }
        return {t: 'poses', b: batch};
    },
    state: (frame) => {
        if (!isPeerId(frame.p) || !isPayload(frame.d)) {
            return null;
        }
        return {t: 'state', p: frame.p, d: frame.d};
    },
    authok: (frame) => (isInt(frame.exp, 0, Number.MAX_SAFE_INTEGER) ? {t: 'authok', exp: frame.exp} : null),
    pong: (frame) => (isInt(frame.n, 0, MAX_INT) ? {t: 'pong', n: frame.n} : null),
    error: (frame) => (ERROR_CODES.indexOf(frame.code) === -1 ? null : {t: 'error', code: frame.code}),
};

/**
 * Parse a server frame.
 *
 * @param {*} data The message data (a string; anything else is refused).
 * @returns {Object|null} A frame rebuilt from known keys, or null for anything invalid or unknown.
 */
export const parse = (data) => {
    if (typeof data !== 'string' || data === '' || data.length > MAX_SERVER_FRAME) {
        return null;
    }
    let frame;
    try {
        frame = JSON.parse(data);
    } catch (error) {
        return null;
    }
    if (!isObject(frame) || typeof frame.t !== 'string' || !Object.prototype.hasOwnProperty.call(PARSERS, frame.t)) {
        return null;
    }
    return PARSERS[frame.t](frame);
};

/**
 * Close-code actions while joining (before the first welcome) and while joined.
 * Each entry is [joining, joined] with [action, reason].
 */
const CLOSE_ACTIONS = {
    '1000': [['fail', 'error'], ['left', '']],
    '1001': [['retry', 'network'], ['retry', 'network']],
    '1006': [['retry', 'network'], ['retry', 'network']],
    '1009': [['fail', 'error'], ['retry', 'network']],
    '4000': [['fail', 'error'], ['retry', 'network']],
    '4001': [['retry', 'network'], ['retry', 'network']],
    '4008': [['fail', 'network'], ['retry', 'network']],
    '4401': [['refresh', 'notallowed'], ['refresh', 'closed']],
    '4403': [['fail', 'notallowed'], ['left', 'closed']],
    '4409': [['left', 'replaced'], ['left', 'replaced']],
    '4410': [['refresh', 'notallowed'], ['refresh', 'closed']],
    '4429': [['fail', 'error'], ['retry', 'network']],
    '4503': [['fail', 'full'], ['left', '']],
    '4504': [['fail', 'busy'], ['retry', 'network']],
};

/** Action for a code not in the table. */
const UNKNOWN_ACTION = [['fail', 'error'], ['retry', 'network']];

/**
 * What the client does when the socket closes.
 *
 * - fail: joining failed with the reason (state 'failed').
 * - left: the session ends with the reason (state 'left').
 * - retry: reconnect with back-off; the reason applies when retrying is given up.
 * - refresh: get a new token from Moodle, then reconnect; the reason applies when Moodle refuses
 *   (or when a refresh was already tried while joining).
 *
 * A missing code (or 1005, no status) counts as 1006, connection lost.
 *
 * @param {number} code Close code.
 * @param {boolean} joined Whether the session had been welcomed (joined) before.
 * @returns {{action: string, reason: string}}
 */
export const closeAction = (code, joined) => {
    let key = Number(code);
    if (!key || key === 1005) {
        key = 1006;
    }
    const entry = Object.prototype.hasOwnProperty.call(CLOSE_ACTIONS, key) ? CLOSE_ACTIONS[key] : UNKNOWN_ACTION;
    const pick = entry[joined ? 1 : 0];
    return {action: pick[0], reason: pick[1]};
};

/**
 * Delay before a reconnect attempt.
 *
 * @param {number} attempt Number of failed attempts so far (0 for the first retry).
 * @returns {number} Milliseconds: 1000, 2000, 4000, 8000, then 15000.
 */
export const backoff = (attempt) => {
    const index = Number.isInteger(attempt) && attempt > 0 ? attempt : 0;
    return BACKOFF[Math.min(index, BACKOFF.length - 1)];
};

/**
 * Delay before the next token refresh.
 *
 * @param {number} refreshafter Seconds, from the join service (refreshafter).
 * @returns {number} Milliseconds, at least 10 s.
 */
export const refreshDelay = (refreshafter) => {
    const seconds = Number(refreshafter);
    return Math.max(10, isFinite(seconds) ? seconds : 10) * 1000;
};
