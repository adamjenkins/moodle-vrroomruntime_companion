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
 * Tests of the client side of the companion wire protocol (vrroomruntime_companion/syncprotocol).
 *
 * Run from the repository root: node --test tests/js/syncprotocol.test.mjs
 *
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {
    SUBPROTOCOL, MAX_FRAME, CLOSE, GIVE_UP_AFTER, isPeerId, hello, auth, pose, state, ping, leave, parse,
    closeAction, backoff, refreshDelay,
} from '../../amd/src/syncprotocol.js';

const P1 = '0123456789abcdef0123456789abcdef';
const P2 = 'fedcba9876543210fedcba9876543210';
const POSE = '{"t":"p","q":1}';
const STATE = '{"t":"s","m":"3d","sc":"","mic":"off"}';

/**
 * A valid welcome frame object.
 *
 * @param {Object} [changes] Keys to replace.
 * @returns {Object}
 */
const welcome = (changes = {}) => Object.assign({
    t: 'welcome',
    protocol: 1,
    server: '0.1.0',
    self: {p: P1, name: 'Sam Student', kind: 'attempt'},
    room: {max: 50},
    peers: [{p: P2, name: 'Tia Teacher', kind: 'preview', s: STATE}],
    exp: 1760000300,
    posehz: 15,
}, changes);

/**
 * Parse an object sent as JSON.
 *
 * @param {Object} frame
 * @returns {Object|null}
 */
const roundtrip = (frame) => parse(JSON.stringify(frame));

test('constants', () => {
    assert.equal(SUBPROTOCOL, 'vrroom-sync.1');
    assert.equal(MAX_FRAME, 4096);
    assert.equal(GIVE_UP_AFTER, 120000);
    assert.deepEqual(Object.assign({}, CLOSE), {
        NORMAL: 1000, GOING_AWAY: 1001, TOO_BIG: 1009, PROTOCOL: 4000, HELLO_TIMEOUT: 4001, SLOW: 4008,
        UNAUTHORISED: 4401, FORBIDDEN: 4403, REPLACED: 4409, EXPIRED: 4410, RATE: 4429, FULL: 4503, BUSY: 4504,
    });
    assert.ok(Object.isFrozen(CLOSE));
});

test('isPeerId accepts 32 lowercase hex characters only', () => {
    assert.equal(isPeerId(P1), true);
    assert.equal(isPeerId(P1.toUpperCase()), false);
    assert.equal(isPeerId(P1.slice(1)), false);
    assert.equal(isPeerId(P1 + '0'), false);
    assert.equal(isPeerId('r' + P1.slice(1)), false);
    assert.equal(isPeerId(42), false);
    assert.equal(isPeerId(null), false);
});

test('frame builders produce exactly the protocol keys', () => {
    assert.deepEqual(JSON.parse(hello('abc.def.ghi')), {t: 'hello', token: 'abc.def.ghi'});
    assert.deepEqual(JSON.parse(auth('abc.def.ghi')), {t: 'auth', token: 'abc.def.ghi'});
    assert.deepEqual(JSON.parse(pose(POSE)), {t: 'pose', d: POSE});
    assert.deepEqual(JSON.parse(state(STATE)), {t: 'state', d: STATE});
    assert.deepEqual(JSON.parse(state(STATE, P2)), {t: 'state', d: STATE, to: P2});
    assert.deepEqual(JSON.parse(state(STATE, '')), {t: 'state', d: STATE});
    assert.deepEqual(JSON.parse(state(STATE, null)), {t: 'state', d: STATE});
    assert.deepEqual(JSON.parse(ping(7)), {t: 'ping', n: 7});
    assert.deepEqual(JSON.parse(ping(-1)), {t: 'ping', n: 0});
    assert.deepEqual(JSON.parse(ping(2147483648)), {t: 'ping', n: 0});
    assert.deepEqual(JSON.parse(leave()), {t: 'leave'});
});

test('client frames of the largest codec payloads fit into MAX_FRAME', () => {
    const big = 'x'.repeat(1024);
    assert.ok(new TextEncoder().encode(pose(big)).length <= MAX_FRAME);
    assert.ok(new TextEncoder().encode(state('y'.repeat(512), P2)).length <= MAX_FRAME);
    assert.ok(new TextEncoder().encode(hello('z'.repeat(3000))).length <= MAX_FRAME);
});

test('parse accepts a welcome and rebuilds it from known keys', () => {
    const frame = welcome({extra: 'dropped', self: {p: P1, name: 'Sam Student', kind: 'attempt', x: 1}});
    const parsed = roundtrip(frame);
    assert.deepEqual(parsed, {
        t: 'welcome',
        protocol: 1,
        server: '0.1.0',
        self: {p: P1, name: 'Sam Student', kind: 'attempt'},
        room: {max: 50},
        peers: [{p: P2, name: 'Tia Teacher', kind: 'preview', s: STATE}],
        exp: 1760000300,
        posehz: 15,
    });
    const nostate = roundtrip(welcome({peers: [{p: P2, name: 'Tia', kind: 'attempt', s: null}]}));
    assert.equal(nostate.peers[0].s, null);
    assert.deepEqual(roundtrip(welcome({peers: []})).peers, []);
});

test('parse rejects invalid welcomes', () => {
    assert.equal(roundtrip(welcome({protocol: 2})), null);
    assert.equal(roundtrip(welcome({server: 5})), null);
    assert.equal(roundtrip(welcome({self: {p: 'nothex', name: 'Sam', kind: 'attempt'}})), null);
    assert.equal(roundtrip(welcome({self: {p: P1, name: 'Sam', kind: 'teacher'}})), null);
    assert.equal(roundtrip(welcome({self: {p: P1, name: '', kind: 'attempt'}})), null);
    assert.equal(roundtrip(welcome({room: {max: 0}})), null);
    assert.equal(roundtrip(welcome({room: {max: 101}})), null);
    assert.equal(roundtrip(welcome({room: null})), null);
    assert.equal(roundtrip(welcome({peers: 'none'})), null);
    assert.equal(roundtrip(welcome({peers: [{p: P2, name: 'Tia', kind: 'attempt', s: 42}]})), null);
    assert.equal(roundtrip(welcome({peers: [{p: P2, name: 'Tia', kind: 'attempt', s: 'x'.repeat(2049)}]})), null);
    assert.equal(roundtrip(welcome({peers: [{p: P2, name: 'Tia', kind: 'attempt'}]})), null);
    const many = [];
    for (let i = 0; i < 101; i++) {
        many.push({p: P2, name: 'Tia', kind: 'attempt', s: null});
    }
    assert.equal(roundtrip(welcome({peers: many})), null);
    assert.equal(roundtrip(welcome({exp: 'soon'})), null);
    assert.equal(roundtrip(welcome({posehz: 0})), null);
});

test('parse checks names: 1..100 code points, no control characters', () => {
    const join = (name) => roundtrip({t: 'join', peer: {p: P2, name: name, kind: 'attempt'}});
    assert.deepEqual(join('Zoë'), {t: 'join', peer: {p: P2, name: 'Zoë', kind: 'attempt'}});
    assert.notEqual(join('😀'.repeat(100)), null);
    assert.equal(join('😀'.repeat(101)), null);
    assert.equal(join('a'.repeat(101)), null);
    assert.equal(join(''), null);
    assert.equal(join('Sam\nStudent'), null);
    assert.equal(join(42), null);
});

test('parse accepts the other server frames with exactly their keys', () => {
    assert.deepEqual(roundtrip({t: 'join', peer: {p: P2, name: 'Tia', kind: 'preview', s: 'x'}, z: 1}),
        {t: 'join', peer: {p: P2, name: 'Tia', kind: 'preview'}});
    ['left', 'timeout', 'replaced', 'expired', 'removed'].forEach((r) => {
        assert.deepEqual(roundtrip({t: 'leave', p: P2, r: r, z: 1}), {t: 'leave', p: P2, r: r});
    });
    assert.deepEqual(roundtrip({t: 'poses', b: [[P2, POSE], [P1, POSE]], z: 1}), {t: 'poses', b: [[P2, POSE], [P1, POSE]]});
    assert.deepEqual(roundtrip({t: 'poses', b: []}), {t: 'poses', b: []});
    assert.deepEqual(roundtrip({t: 'state', p: P2, d: STATE, to: P1}), {t: 'state', p: P2, d: STATE});
    assert.deepEqual(roundtrip({t: 'authok', exp: 1760000600, z: 1}), {t: 'authok', exp: 1760000600});
    assert.deepEqual(roundtrip({t: 'pong', n: 3, z: 1}), {t: 'pong', n: 3});
    ['auth', 'ratelimited', 'protocol', 'full', 'busy'].forEach((code) => {
        assert.deepEqual(roundtrip({t: 'error', code: code, message: 'x'}), {t: 'error', code: code});
    });
});

test('parse rejects unknown types, bad shapes and non-JSON', () => {
    assert.equal(roundtrip({t: 'hello', token: 'x'}), null);
    assert.equal(roundtrip({t: 'unknown'}), null);
    assert.equal(roundtrip({t: '__proto__'}), null);
    assert.equal(roundtrip({t: 'toString'}), null);
    assert.equal(roundtrip({p: P2}), null);
    assert.equal(roundtrip({t: 'leave', p: 'P2', r: 'left'}), null);
    assert.equal(roundtrip({t: 'leave', p: P2, r: 'kicked'}), null);
    assert.equal(roundtrip({t: 'state', p: P2, d: {t: 's'}}), null);
    assert.equal(roundtrip({t: 'state', p: P2, d: ''}), null);
    assert.equal(roundtrip({t: 'state', p: P2, d: 'x'.repeat(2049)}), null);
    assert.notEqual(roundtrip({t: 'state', p: P2, d: 'x'.repeat(2048)}), null);
    assert.equal(roundtrip({t: 'poses', b: [[P2]]}), null);
    assert.equal(roundtrip({t: 'poses', b: [[P2, 5]]}), null);
    assert.equal(roundtrip({t: 'poses', b: [['bad', POSE]]}), null);
    assert.equal(roundtrip({t: 'poses', b: [[P2, POSE, 'extra']]}), null);
    assert.equal(roundtrip({t: 'poses', b: {}}), null);
    const many = [];
    for (let i = 0; i < 101; i++) {
        many.push([P2, POSE]);
    }
    assert.equal(roundtrip({t: 'poses', b: many}), null);
    assert.notEqual(roundtrip({t: 'poses', b: many.slice(0, 100)}), null);
    assert.equal(roundtrip({t: 'authok', exp: -1}), null);
    assert.equal(roundtrip({t: 'pong', n: 1.5}), null);
    assert.equal(roundtrip({t: 'error', code: 'other'}), null);
    assert.equal(parse('not json'), null);
    assert.equal(parse('[1, 2]'), null);
    assert.equal(parse('"welcome"'), null);
    assert.equal(parse('null'), null);
    assert.equal(parse(''), null);
    assert.equal(parse(42), null);
    assert.equal(parse(new Uint8Array([123, 125])), null);
    assert.equal(parse(null), null);
});

test('parsed frames are fresh objects', () => {
    const frame = welcome();
    const parsed = parse(JSON.stringify(frame));
    parsed.self.name = 'Changed';
    assert.equal(parse(JSON.stringify(frame)).self.name, 'Sam Student');
});

test('closeAction follows the close-code table while joining', () => {
    const expect = {
        1000: ['fail', 'error'],
        1001: ['retry', 'network'],
        1006: ['retry', 'network'],
        1009: ['fail', 'error'],
        4000: ['fail', 'error'],
        4001: ['retry', 'network'],
        4008: ['fail', 'network'],
        4401: ['refresh', 'notallowed'],
        4403: ['fail', 'notallowed'],
        4409: ['left', 'replaced'],
        4410: ['refresh', 'notallowed'],
        4429: ['fail', 'error'],
        4503: ['fail', 'full'],
        4504: ['fail', 'busy'],
    };
    Object.keys(expect).forEach((code) => {
        assert.deepEqual(closeAction(Number(code), false), {action: expect[code][0], reason: expect[code][1]}, 'code ' + code);
    });
});

test('closeAction follows the close-code table while joined', () => {
    const expect = {
        1000: ['left', ''],
        1001: ['retry', 'network'],
        1006: ['retry', 'network'],
        1009: ['retry', 'network'],
        4000: ['retry', 'network'],
        4001: ['retry', 'network'],
        4008: ['retry', 'network'],
        4401: ['refresh', 'closed'],
        4403: ['left', 'closed'],
        4409: ['left', 'replaced'],
        4410: ['refresh', 'closed'],
        4429: ['retry', 'network'],
        4503: ['left', ''],
        4504: ['retry', 'network'],
    };
    Object.keys(expect).forEach((code) => {
        assert.deepEqual(closeAction(Number(code), true), {action: expect[code][0], reason: expect[code][1]}, 'code ' + code);
    });
});

test('closeAction: no code counts as a lost connection; unknown codes', () => {
    [undefined, null, 0, 1005].forEach((code) => {
        assert.deepEqual(closeAction(code, false), {action: 'retry', reason: 'network'});
        assert.deepEqual(closeAction(code, true), {action: 'retry', reason: 'network'});
    });
    assert.deepEqual(closeAction(4999, false), {action: 'fail', reason: 'error'});
    assert.deepEqual(closeAction(4999, true), {action: 'retry', reason: 'network'});
    assert.deepEqual(closeAction('constructor', true), {action: 'retry', reason: 'network'});
});

test('backoff sequence', () => {
    assert.deepEqual([0, 1, 2, 3, 4, 5, 6, 50].map(backoff), [1000, 2000, 4000, 8000, 15000, 15000, 15000, 15000]);
    assert.equal(backoff(-3), 1000);
    assert.equal(backoff(1.5), 1000);
    assert.equal(backoff(undefined), 1000);
});

test('refreshDelay has a 10 s floor', () => {
    assert.equal(refreshDelay(240), 240000);
    assert.equal(refreshDelay(10), 10000);
    assert.equal(refreshDelay(3), 10000);
    assert.equal(refreshDelay(0), 10000);
    assert.equal(refreshDelay(-5), 10000);
    assert.equal(refreshDelay('abc'), 10000);
    assert.equal(refreshDelay(undefined), 10000);
});
