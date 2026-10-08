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
 * Companion transport: the live room through the site's companion service.
 *
 * Positions and states go over one WebSocket to mavr-sync (protocol 'mavr-sync.1', see
 * mavrruntime_companion/syncprotocol); voice goes through the companion stack's LiveKit server
 * (microphone only). The browser never connects to other browsers, so the other people in the room
 * do not see its IP address.
 *
 * Moodle (web service mavrruntime_companion_join) runs the access checks and hands out short-lived
 * tokens: one for mavr-sync, sent in the first frame (never in the URL) and refreshed through Moodle
 * before it expires, and one for LiveKit. Peer ids, names and kinds come from mavr-sync, which takes
 * them from the verified token only.
 *
 * Implements the Phase 3 transport interface (mavrruntime_builtin/live/transport), so the built-in
 * avatars, voice chat and panel work unchanged. Pose and state messages use the Phase 3 codec in both
 * directions; mavr-sync validates and re-encodes them with a copy of the same codec.
 *
 * The per-peer maps of presence and remote voice streams follow the built-in mesh transport, which
 * adapts mod_gear's collaborative mode.
 *
 * Nothing about tokens or names is logged; core/log receives close codes and states only.
 *
 * @module     mavrruntime_companion/transport_companion
 * @copyright  2026 Boban Blagojevic
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Log from 'core/log';
import {Transport} from 'mavrruntime_builtin/live/transport';
import {encode, decode} from 'mavrruntime_builtin/live/codec';
import * as Proto from 'mavrruntime_companion/syncprotocol';
import {Room, RoomEvent, Track} from 'mavrruntime_companion/livekit';

/** Web service that runs the access checks and returns the tokens. */
const JOIN_METHOD = 'mavrruntime_companion_join';

/** Timeout of a join service call (ms). */
const REQUEST_TIMEOUT = 10000;

/** Wait this long for the welcome after opening the socket (ms). */
const WELCOME_TIMEOUT = 10000;

/** Application ping interval (ms). */
const PING_INTERVAL = 20000;

/** No pong within this time closes the socket and reconnects (ms). */
const PONG_TIMEOUT = 10000;

/** Pose frames are dropped while more than this many bytes wait to be sent. */
const POSE_BUFFER_LIMIT = 32768;

/** After a token refresh failed for a network reason, try again after this long (ms). */
const REFRESH_RETRY = 15000;

/** Wait this long after a lost voice connection before reconnecting it (ms). */
const VOICE_RETRY_DELAY = 5000;

/** Reconnect the voice connection at most once per this interval (ms). */
const VOICE_RETRY_INTERVAL = 30000;

/** WebSocket readyState OPEN. */
const OPEN = 1;

/** Largest ping counter before it wraps. */
const MAX_PING = 2147483647;

/** Prefix of debug log lines. */
const LOGPREFIX = 'mavrruntime_companion/transport_companion: ';

/** Join service error codes → state reasons. */
const REASONS = Object.freeze({
    errorlivedisabled: 'disabled',
    errorlivenoroom: 'noroom',
    errorlivenotallowed: 'notallowed',
    errornotyourattempt: 'notallowed',
    errorattempttimeup: 'notallowed',
    errorattemptclosed: 'notallowed',
    nopermissions: 'notallowed',
    requireloginerror: 'notallowed',
    servicerequireslogin: 'notallowed',
    network: 'network',
});

/**
 * Turn a web-service rejection into an Error carrying an errorcode.
 *
 * Moodle exceptions arrive as objects with an errorcode; anything else is a network failure.
 *
 * @param {*} exception The rejection value.
 * @returns {Error} With errorcode ('network' for network failures) and cause.
 */
const toServiceError = (exception) => {
    const code = exception && typeof exception === 'object' && typeof exception.errorcode === 'string'
        ? exception.errorcode : 'network';
    const message = exception && typeof exception === 'object' && typeof exception.message === 'string'
        ? exception.message : code;
    const error = new Error(message);
    error.errorcode = code;
    error.cause = exception;
    return error;
};

/**
 * Map a join service error to a state reason.
 *
 * @param {*} error Error with errorcode.
 * @returns {string}
 */
const reasonFor = (error) => {
    const code = error && typeof error.errorcode === 'string' ? error.errorcode : '';
    return Object.prototype.hasOwnProperty.call(REASONS, code) ? REASONS[code] : 'error';
};

/**
 * An Error for a failed join.
 *
 * @param {string} reason State reason.
 * @param {*} [cause] Original error.
 * @returns {Error} With reason, errorcode and cause.
 */
const joinError = (reason, cause) => {
    const error = new Error(cause && cause.message ? cause.message : reason);
    error.reason = reason;
    error.errorcode = cause && cause.errorcode ? cause.errorcode : reason;
    error.cause = cause;
    return error;
};

/**
 * An Error for a socket that closed (or was given up) before or after the welcome.
 *
 * @param {number} code Close code.
 * @param {boolean} aborted Whether this side closed it on purpose (leave, a newer socket).
 * @returns {Error} With closefailure true, code and aborted.
 */
const closeFailure = (code, aborted) => {
    const error = new Error('Socket closed (' + code + ')');
    error.closefailure = true;
    error.code = code;
    error.aborted = aborted;
    return error;
};

/**
 * Whether a join service error is a network failure (not an answer of Moodle).
 *
 * @param {*} error Error with errorcode.
 * @returns {boolean}
 */
const isNetworkError = (error) => !error || error.errorcode === 'network';

/**
 * Copy a peer for listeners.
 *
 * @param {Object} peer
 * @returns {Object}
 */
const copyPeer = (peer) => ({peerid: peer.peerid, name: peer.name, kind: peer.kind, connected: peer.connected});

/**
 * The default join service call.
 *
 * @param {Object} args {cmid, attemptid, groupid, refresh}.
 * @param {boolean} nosessionupdate Whether the call must not extend the Moodle session.
 * @returns {Promise<Object>}
 */
const defaultCall = (args, nosessionupdate) => Promise.resolve(
    Ajax.call([{methodname: JOIN_METHOD, args: args}], true, true, nosessionupdate, REQUEST_TIMEOUT)[0]
);

/**
 * Check and normalise a join service result.
 *
 * @param {*} result The result.
 * @returns {Object|null}
 */
const normaliseResult = (result) => {
    if (!result || typeof result !== 'object' || !Proto.isPeerId(result.peerid)) {
        return null;
    }
    if (typeof result.syncurl !== 'string' || !/^wss?:\/\//.test(result.syncurl)
            || typeof result.token !== 'string' || result.token === '') {
        return null;
    }
    const voice = result.voice && typeof result.voice === 'object' ? result.voice : {};
    const voiceurl = typeof voice.url === 'string' && /^wss?:\/\//.test(voice.url) ? voice.url : '';
    const voicetoken = typeof voice.token === 'string' ? voice.token : '';
    return {
        peerid: result.peerid,
        groupid: Number(result.groupid) || 0,
        roomname: String(result.roomname || ''),
        maxpeers: Number(result.maxpeers) || 0,
        selfname: String(result.selfname || ''),
        kind: result.kind === 'preview' ? 'preview' : 'attempt',
        syncurl: result.syncurl,
        token: result.token,
        expires: Number(result.expires) || 0,
        refreshafter: Number(result.refreshafter) || 0,
        voice: {
            available: !!voice.available && voiceurl !== '',
            url: voiceurl,
            token: voicetoken,
            expires: Number(voice.expires) || 0,
        },
    };
};

/**
 * Transport over the companion service.
 */
class CompanionTransport extends Transport {
    /**
     * Constructor.
     *
     * @param {Object} liveconfig The launch config live block.
     * @param {Object} options {WebSocket?: constructor, Room?: constructor, call?: Function} (tests).
     */
    constructor(liveconfig, options) {
        super();
        this.config = liveconfig || {};
        this.WebSocket = options.WebSocket
            || (typeof window !== 'undefined' && window.WebSocket ? window.WebSocket : null);
        this.RoomClass = options.Room || Room;
        this.call = typeof options.call === 'function' ? options.call : defaultCall;
        this.state = 'idle';
        this.self = null;
        this.room = null;
        this.groupid = Number(this.config.groupid) || Number(this.config.defaultgroupid) || 0;
        /** @type {Map<string, Object>} peer id → peer */
        this.peers = new Map();
        /** Latest join service result (normalised) and when it arrived (ms). */
        this.result = null;
        this.tokenreceived = 0;
        this.joining = null;
        this.disposed = false;
        // The socket.
        this.ws = null;
        this.welcomed = false;
        this.connecting = null;
        this.welcometimer = null;
        this.pingtimer = null;
        this.pongtimer = null;
        this.pingcount = 0;
        this.refreshtimer = null;
        this.refreshing = false;
        // Reconnecting.
        this.retrytimer = null;
        this.retrying = false;
        this.retryattempt = 0;
        this.retrystart = 0;
        this.refreshfirst = false;
        /** @type {Set<Object>} pending waits {timer, reject} */
        this.waits = new Set();
        // Voice.
        this.lkroom = null;
        this.voiceconnected = false;
        this.localtrack = null;
        this.publishedtrack = null;
        this.publishqueue = Promise.resolve();
        this.voicetimer = null;
        this.lastvoiceattempt = 0;
        /** @type {Map<string, {stream: MediaStream, mst: MediaStreamTrack}>} subscribed LiveKit audio */
        this.remote = new Map();
        /** @type {Set<string>} peers whose track event was emitted */
        this.emitted = new Set();
    }

    /**
     * Transport kind.
     *
     * @returns {string}
     */
    get kind() {
        return 'companion';
    }

    /**
     * What this transport can do.
     *
     * @returns {{voice: boolean, maxpeers: number}}
     */
    get capabilities() {
        const maxpeers = this.room ? this.room.maxpeers : this.config.maxpeers;
        return {voice: !!this.config.voice, maxpeers: Number(maxpeers) || 0};
    }

    /**
     * The current state (one of STATES).
     *
     * @returns {string}
     */
    getState() {
        return this.state;
    }

    /**
     * Change state and tell listeners.
     *
     * @param {string} state
     * @param {string} reason
     */
    setState(state, reason) {
        this.state = state;
        Log.debug(LOGPREFIX + 'state ' + state + (reason ? ' (' + reason + ')' : ''));
        this.emit('state', {state: state, reason: reason});
    }

    /**
     * Whether the room is in use (joined or reconnecting).
     *
     * @returns {boolean}
     */
    isActive() {
        return this.state === 'joined' || this.state === 'reconnecting';
    }

    /**
     * What join() resolves with.
     *
     * @returns {Object}
     */
    joinInfo() {
        return {
            self: this.getSelf(),
            room: this.room ? {groupid: this.room.groupid, name: this.room.name, maxpeers: this.room.maxpeers} : null,
            peers: this.getPeers(),
        };
    }

    /**
     * Wait for a while; rejected with a 'closed' join error when the transport stops.
     *
     * @param {number} ms Milliseconds.
     * @returns {Promise<void>}
     */
    wait(ms) {
        return new Promise((resolve, reject) => {
            const entry = {timer: null, reject: reject};
            entry.timer = setTimeout(() => {
                this.waits.delete(entry);
                resolve();
            }, ms);
            this.waits.add(entry);
        });
    }

    /**
     * Cancel every pending wait.
     */
    cancelWaits() {
        this.waits.forEach((entry) => {
            clearTimeout(entry.timer);
            entry.reject(joinError('closed'));
        });
        this.waits.clear();
    }

    /**
     * Call the join service.
     *
     * @param {boolean} refresh Whether this only renews the tokens of an open session.
     * @returns {Promise<Object>} The normalised result; rejects with an Error carrying errorcode.
     */
    fetchJoin(refresh) {
        const args = {
            cmid: Number(this.config.cmid) || 0,
            attemptid: Number(this.config.attemptid) || 0,
            groupid: this.groupid,
            refresh: !!refresh,
        };
        return Promise.resolve().then(() => this.call(args, !!refresh)).then((raw) => {
            const result = normaliseResult(raw);
            if (!result) {
                const error = new Error('Invalid join result');
                error.errorcode = 'invalidresponse';
                throw error;
            }
            return result;
        }, (exception) => {
            throw toServiceError(exception);
        });
    }

    /**
     * Take over a join service result: tokens, ids, room.
     *
     * @param {Object} result Normalised result.
     * @param {boolean} refresh Whether it came from a refresh (keeps the previous voice block).
     */
    applyResult(result, refresh) {
        if (refresh && this.result) {
            result.voice = this.result.voice;
        }
        this.result = result;
        this.tokenreceived = Date.now();
        this.groupid = result.groupid;
        if (!this.room) {
            this.room = {groupid: result.groupid, name: result.roomname, maxpeers: result.maxpeers};
        } else {
            this.room.groupid = result.groupid;
            this.room.name = result.roomname;
        }
    }

    /**
     * Whether the sync token is close to its expiry (past the refresh point the server gave).
     * Measured on the local clock from when the token arrived, so clock differences do not matter.
     *
     * @returns {boolean}
     */
    tokenStale() {
        return !this.result || Date.now() >= this.tokenreceived + Proto.refreshDelay(this.result.refreshafter);
    }

    /**
     * Join the room.
     *
     * @param {number|{groupid: number}} [groupid] Group room; the default room when left out.
     * @returns {Promise<Object>} {self, room, peers}; rejects with an Error carrying reason.
     */
    join(groupid) {
        if (this.disposed) {
            return Promise.reject(joinError('closed'));
        }
        if (this.isActive()) {
            return Promise.resolve(this.joinInfo());
        }
        if (this.joining) {
            return this.joining;
        }
        const requested = Number(groupid && typeof groupid === 'object' ? groupid.groupid : groupid);
        if (isFinite(requested) && requested > 0) {
            this.groupid = requested;
        } else {
            this.groupid = Number(this.config.groupid) || Number(this.config.defaultgroupid) || 0;
        }
        if (!this.WebSocket) {
            this.setState('failed', 'error');
            return Promise.reject(joinError('error'));
        }
        this.self = null;
        this.room = null;
        this.setState('joining', '');
        this.joining = this.fetchJoin(false).then((result) => {
            if (this.state !== 'joining') {
                throw joinError('closed');
            }
            this.applyResult(result, false);
            return this.connectWhileJoining(false, false);
        }).then((welcome) => {
            this.joining = null;
            if (this.state !== 'joining') {
                this.closeSocket(Proto.CLOSE.NORMAL);
                throw joinError('closed');
            }
            this.applyWelcome(welcome);
            this.afterWelcome();
            this.setState('joined', '');
            this.startVoice(this.result.voice);
            return this.joinInfo();
        }).catch((error) => {
            this.joining = null;
            const reason = error && error.reason ? error.reason : reasonFor(error);
            if (this.state === 'joining') {
                this.stopEverything();
                this.self = null;
                this.setState('failed', reason);
            }
            throw error && error.reason ? error : joinError(reason, error);
        });
        return this.joining;
    }

    /**
     * Open the socket while joining, following the close-code table: one retry after a lost
     * connection, one token refresh after a refused token.
     *
     * @param {boolean} retried Whether a retry was already made.
     * @param {boolean} refreshed Whether a refresh was already made.
     * @returns {Promise<Object>} The welcome frame; rejects with a join error.
     */
    connectWhileJoining(retried, refreshed) {
        return this.connectOnce().catch((failure) => {
            if (this.state !== 'joining') {
                throw joinError('closed');
            }
            const code = failure && failure.code ? failure.code : 1006;
            Log.debug(LOGPREFIX + 'socket closed while joining (' + code + ')');
            const action = Proto.closeAction(code, false);
            if (action.action === 'retry') {
                if (retried) {
                    throw joinError('network');
                }
                return this.wait(Proto.backoff(0)).then(() => this.connectWhileJoining(true, refreshed));
            }
            if (action.action === 'refresh') {
                if (refreshed) {
                    throw joinError(action.reason);
                }
                return this.fetchJoin(true).then((result) => {
                    if (this.state !== 'joining') {
                        throw joinError('closed');
                    }
                    this.applyResult(result, true);
                    return this.connectWhileJoining(retried, true);
                }, (error) => {
                    throw joinError(isNetworkError(error) ? 'network' : reasonFor(error), error);
                });
            }
            if (action.action === 'left') {
                throw joinError(action.reason || 'closed');
            }
            throw joinError(action.reason || 'error');
        });
    }

    /**
     * Open one socket and wait for the welcome.
     *
     * @returns {Promise<Object>} The welcome frame; rejects with {code} when the socket closes first.
     */
    connectOnce() {
        this.closeSocket(Proto.CLOSE.NORMAL);
        return new Promise((resolve, reject) => {
            let ws;
            try {
                ws = new this.WebSocket(this.result.syncurl, [Proto.SUBPROTOCOL]);
            } catch (error) {
                Log.debug(error);
                reject(closeFailure(1006, false));
                return;
            }
            this.ws = ws;
            this.welcomed = false;
            this.connecting = {resolve: resolve, reject: reject};
            this.welcometimer = setTimeout(() => {
                this.welcometimer = null;
                if (this.ws === ws && !this.welcomed) {
                    this.dropSocket(ws, 1006);
                }
            }, WELCOME_TIMEOUT);
            ws.onopen = () => {
                if (this.ws === ws) {
                    this.sendFrame(Proto.hello(this.result.token));
                }
            };
            ws.onmessage = (e) => {
                if (this.ws === ws) {
                    this.onFrame(e.data);
                }
            };
            ws.onerror = () => {
                // A close event follows.
            };
            ws.onclose = (e) => {
                if (this.ws === ws) {
                    this.onSocketClosed(e && e.code ? e.code : 1006);
                }
            };
        });
    }

    /**
     * Give up a socket as lost (watchdogs): detach it, close it and handle it like a lost connection.
     *
     * @param {WebSocket} ws The socket.
     * @param {number} code The code to handle it with.
     */
    dropSocket(ws, code) {
        this.detachSocket();
        try {
            ws.close(Proto.CLOSE.PROTOCOL);
        } catch (error) {
            Log.debug(error);
        }
        this.onSocketClosed(code);
    }

    /**
     * Stop listening to the current socket and its timers (the socket itself stays as it is).
     *
     * @returns {WebSocket|null} The detached socket.
     */
    detachSocket() {
        const ws = this.ws;
        this.ws = null;
        if (ws) {
            ws.onopen = null;
            ws.onmessage = null;
            ws.onerror = null;
            ws.onclose = null;
        }
        if (this.welcometimer) {
            clearTimeout(this.welcometimer);
            this.welcometimer = null;
        }
        this.stopPing();
        return ws;
    }

    /**
     * Close the current socket on purpose (no reconnect). A pending connect is rejected.
     *
     * @param {number} code Close code.
     */
    closeSocket(code) {
        const ws = this.detachSocket();
        this.welcomed = false;
        if (ws) {
            try {
                ws.close(code);
            } catch (error) {
                Log.debug(error);
            }
        }
        const connecting = this.connecting;
        this.connecting = null;
        if (connecting) {
            connecting.reject(closeFailure(code, true));
        }
    }

    /**
     * The current socket closed (or was given up).
     *
     * @param {number} code Close code.
     */
    onSocketClosed(code) {
        this.detachSocket();
        const waswelcomed = this.welcomed;
        this.welcomed = false;
        const connecting = this.connecting;
        this.connecting = null;
        if (connecting) {
            connecting.reject(closeFailure(code, false));
            return;
        }
        if (waswelcomed && this.isActive()) {
            Log.debug(LOGPREFIX + 'socket closed (' + code + ')');
            this.onLost(code);
        }
    }

    /**
     * Send a frame on the open socket.
     *
     * @param {string} frame
     * @returns {boolean} Whether it was sent.
     */
    sendFrame(frame) {
        const ws = this.ws;
        if (!ws || ws.readyState !== OPEN) {
            return false;
        }
        try {
            ws.send(frame);
            return true;
        } catch (error) {
            Log.debug(error);
            return false;
        }
    }

    /**
     * A frame from the server.
     *
     * @param {*} data Message data.
     */
    onFrame(data) {
        const frame = Proto.parse(data);
        if (!frame) {
            return;
        }
        if (frame.t === 'welcome') {
            if (!this.welcomed && this.connecting) {
                this.welcomed = true;
                if (this.welcometimer) {
                    clearTimeout(this.welcometimer);
                    this.welcometimer = null;
                }
                const connecting = this.connecting;
                this.connecting = null;
                connecting.resolve(frame);
            }
            return;
        }
        if (frame.t === 'error') {
            Log.debug(LOGPREFIX + 'server error ' + frame.code);
            return;
        }
        if (!this.welcomed) {
            return;
        }
        switch (frame.t) {
            case 'join':
                this.addPeer(frame.peer);
                break;
            case 'leave':
                this.removePeer(frame.p, frame.r);
                break;
            case 'poses':
                frame.b.forEach((entry) => this.deliver(entry[0], 'pose', entry[1]));
                break;
            case 'state':
                this.deliver(frame.p, 'state', frame.d);
                break;
            case 'authok':
                // The session now lasts until frame.exp; the refresh timer is already set.
                break;
            case 'pong':
                if (this.pongtimer) {
                    clearTimeout(this.pongtimer);
                    this.pongtimer = null;
                }
                break;
            default:
                break;
        }
    }

    /**
     * Decode a codec string of a known peer and pass it on.
     *
     * @param {string} peerid
     * @param {string} channel 'pose' or 'state'.
     * @param {string} data Codec string.
     */
    deliver(peerid, channel, data) {
        if (!this.peers.has(peerid)) {
            return;
        }
        const message = decode(channel, data);
        if (message) {
            this.emit('message', {peerid: peerid, channel: channel, message: message});
        }
    }

    /**
     * Take over a welcome: self, room size and the roster (a diff against the peers already known,
     * so a reconnect keeps the people who are still there).
     *
     * @param {Object} welcome Parsed welcome frame.
     */
    applyWelcome(welcome) {
        this.self = {
            peerid: welcome.self.p,
            name: welcome.self.name,
            kind: welcome.self.kind,
            connected: true,
        };
        if (this.room) {
            this.room.maxpeers = welcome.room.max;
        }
        const seen = new Set();
        const states = [];
        welcome.peers.forEach((entry) => {
            if (entry.p === this.self.peerid) {
                return;
            }
            seen.add(entry.p);
            this.addPeer(entry);
            if (entry.s !== null) {
                states.push([entry.p, entry.s]);
            }
        });
        Array.from(this.peers.keys()).forEach((peerid) => {
            if (!seen.has(peerid)) {
                this.removePeer(peerid, 'left');
            }
        });
        states.forEach((entry) => this.deliver(entry[0], 'state', entry[1]));
    }

    /**
     * Add (or update) a peer of the roster.
     *
     * @param {{p: string, name: string, kind: string}} entry
     */
    addPeer(entry) {
        if (this.self && entry.p === this.self.peerid) {
            return;
        }
        const peer = this.peers.get(entry.p);
        if (!peer) {
            const added = {peerid: entry.p, name: entry.name, kind: entry.kind, connected: true};
            this.peers.set(entry.p, added);
            this.emit('peerjoined', {peer: copyPeer(added)});
            if (this.remote.has(entry.p)) {
                this.emitTrack(entry.p);
            }
        } else if (peer.name !== entry.name || peer.kind !== entry.kind) {
            peer.name = entry.name;
            peer.kind = entry.kind;
            this.emit('peerupdated', {peer: copyPeer(peer)});
        }
    }

    /**
     * Remove a peer of the roster.
     *
     * @param {string} peerid
     * @param {string} reason
     */
    removePeer(peerid, reason) {
        if (!this.peers.has(peerid)) {
            return;
        }
        if (this.emitted.delete(peerid)) {
            this.emit('trackended', {peerid: peerid});
        }
        this.peers.delete(peerid);
        this.emit('peerleft', {peerid: peerid, reason: reason});
    }

    /**
     * Remove every peer.
     *
     * @param {string} reason
     */
    removeAllPeers(reason) {
        Array.from(this.peers.keys()).forEach((peerid) => this.removePeer(peerid, reason));
    }

    /**
     * After a welcome: ping and token refresh run, reconnect state is reset.
     */
    afterWelcome() {
        this.retrying = false;
        this.retryattempt = 0;
        this.retrystart = 0;
        this.refreshfirst = false;
        this.startPing();
        this.scheduleRefresh(Proto.refreshDelay(this.result.refreshafter) - (Date.now() - this.tokenreceived));
    }

    /**
     * Start the application ping.
     */
    startPing() {
        this.stopPing();
        this.pingtimer = setInterval(() => {
            const ws = this.ws;
            if (!ws || !this.welcomed || this.pongtimer) {
                return;
            }
            this.pingcount = this.pingcount >= MAX_PING ? 0 : this.pingcount + 1;
            if (!this.sendFrame(Proto.ping(this.pingcount))) {
                return;
            }
            this.pongtimer = setTimeout(() => {
                this.pongtimer = null;
                if (this.ws === ws && this.welcomed) {
                    Log.debug(LOGPREFIX + 'no pong');
                    this.dropSocket(ws, 1006);
                }
            }, PONG_TIMEOUT);
        }, PING_INTERVAL);
    }

    /**
     * Stop the application ping.
     */
    stopPing() {
        if (this.pingtimer) {
            clearInterval(this.pingtimer);
            this.pingtimer = null;
        }
        if (this.pongtimer) {
            clearTimeout(this.pongtimer);
            this.pongtimer = null;
        }
    }

    /**
     * Schedule the next token refresh.
     *
     * @param {number} delay Milliseconds (at least 1 s is waited).
     */
    scheduleRefresh(delay) {
        if (this.refreshtimer) {
            clearTimeout(this.refreshtimer);
        }
        this.refreshtimer = setTimeout(() => {
            this.refreshtimer = null;
            this.refreshToken();
        }, Math.max(1000, Number(delay) || 0));
    }

    /**
     * Renew the sync token through Moodle (which re-runs every access check) and send it.
     */
    refreshToken() {
        if (this.state !== 'joined' || this.refreshing) {
            return;
        }
        this.refreshing = true;
        this.fetchJoin(true).then((result) => {
            this.refreshing = false;
            if (!this.isActive()) {
                return;
            }
            this.applyResult(result, true);
            if (this.welcomed) {
                this.sendFrame(Proto.auth(result.token));
            }
            this.scheduleRefresh(Proto.refreshDelay(result.refreshafter));
            return;
        }, (error) => {
            this.refreshing = false;
            if (!this.isActive()) {
                return;
            }
            if (isNetworkError(error)) {
                // Try again; mavr-sync closes the session (4410) if the token runs out first.
                this.scheduleRefresh(REFRESH_RETRY);
                return;
            }
            Log.debug(LOGPREFIX + 'access ended (' + reasonFor(error) + ')');
            this.shutdown('left', 'closed');
        }).catch((error) => Log.debug(error));
    }

    /**
     * The welcomed socket closed: act on the close code (§2.4 of the protocol).
     *
     * @param {number} code Close code.
     */
    onLost(code) {
        const action = Proto.closeAction(code, true);
        if (action.action === 'left') {
            this.shutdown('left', action.reason);
            return;
        }
        if (action.action === 'fail') {
            this.shutdown('failed', action.reason);
            return;
        }
        this.startReconnect(action.action === 'refresh');
    }

    /**
     * Start (or continue) reconnecting with back-off.
     *
     * @param {boolean} refreshfirst Whether the next attempt needs a new token first.
     */
    startReconnect(refreshfirst) {
        if (refreshfirst) {
            this.refreshfirst = true;
        }
        if (this.refreshtimer) {
            clearTimeout(this.refreshtimer);
            this.refreshtimer = null;
        }
        if (!this.retrying) {
            this.retrying = true;
            this.retryattempt = 0;
            this.retrystart = Date.now();
        }
        if (this.state === 'joined') {
            this.setState('reconnecting', 'network');
        }
        if (Date.now() - this.retrystart >= Proto.GIVE_UP_AFTER) {
            this.shutdown('left', 'network');
            return;
        }
        const delay = Proto.backoff(this.retryattempt);
        this.retryattempt++;
        if (this.retrytimer) {
            clearTimeout(this.retrytimer);
        }
        this.retrytimer = setTimeout(() => {
            this.retrytimer = null;
            this.reconnectAttempt();
        }, delay);
    }

    /**
     * One reconnect attempt: refresh the token when needed, open the socket, take over the welcome.
     */
    reconnectAttempt() {
        if (this.state !== 'reconnecting') {
            return;
        }
        const needtoken = this.refreshfirst || this.tokenStale();
        const token = needtoken ? this.fetchJoin(true).then((result) => {
            if (this.state !== 'reconnecting') {
                throw joinError('closed');
            }
            this.applyResult(result, true);
            this.refreshfirst = false;
            return undefined;
        }, (error) => {
            if (isNetworkError(error)) {
                // Moodle not reachable: count it as a failed attempt.
                throw closeFailure(1006, false);
            }
            throw joinError('closed', error);
        }) : Promise.resolve();
        token.then(() => this.connectOnce()).then((welcome) => {
            if (this.state !== 'reconnecting') {
                this.closeSocket(Proto.CLOSE.NORMAL);
                return;
            }
            this.applyWelcome(welcome);
            this.afterWelcome();
            this.setState('joined', '');
            if (!this.lkroom && this.result.voice.available) {
                this.scheduleVoiceRetry();
            }
            return;
        }).catch((failure) => {
            if (this.state !== 'reconnecting' || (failure && failure.aborted)) {
                return;
            }
            if (!failure || !failure.closefailure) {
                // Moodle refused a new token: access has ended.
                Log.debug(LOGPREFIX + 'access ended (' + reasonFor(failure ? failure.cause : null) + ')');
                this.shutdown('left', 'closed');
                return;
            }
            const code = failure.code ? failure.code : 1006;
            Log.debug(LOGPREFIX + 'reconnect failed (' + code + ')');
            this.onLost(code);
            return;
        });
    }

    /**
     * Stop the socket, timers, waits and voice.
     */
    stopEverything() {
        this.cancelWaits();
        this.closeSocket(Proto.CLOSE.NORMAL);
        [this.refreshtimer, this.retrytimer].forEach((timer) => {
            if (timer) {
                clearTimeout(timer);
            }
        });
        this.refreshtimer = null;
        this.retrytimer = null;
        this.retrying = false;
        this.refreshing = false;
        this.stopVoice();
    }

    /**
     * End the session locally.
     *
     * @param {string} state 'left' or 'failed'.
     * @param {string} reason State reason.
     */
    shutdown(state, reason) {
        this.stopEverything();
        this.removeAllPeers('left');
        this.self = null;
        this.setState(state, reason);
    }

    // Voice (LiveKit).

    /**
     * Connect the voice room in the background. A failure never affects the room itself.
     *
     * @param {Object} voice Voice block of the join result {available, url, token}.
     */
    startVoice(voice) {
        if (!voice || !voice.available || !voice.url || !voice.token || !this.RoomClass || !this.isActive()) {
            return;
        }
        this.lastvoiceattempt = Date.now();
        let room;
        try {
            room = new this.RoomClass({
                adaptiveStream: false,
                dynacast: false,
                disconnectOnPageLeave: false,
                // The voice chat owns the microphone track: LiveKit must never stop it.
                stopLocalTrackOnUnpublish: false,
            });
        } catch (error) {
            Log.debug(error);
            return;
        }
        this.lkroom = room;
        this.voiceconnected = false;
        this.publishedtrack = null;
        room.on(RoomEvent.TrackSubscribed, (track, publication, participant) => {
            if (this.lkroom === room) {
                this.onRemoteTrack(track, participant);
            }
        });
        room.on(RoomEvent.TrackUnsubscribed, (track, publication, participant) => {
            if (this.lkroom === room && participant) {
                this.onRemoteTrackEnded(participant.identity, track);
            }
        });
        room.on(RoomEvent.ParticipantDisconnected, (participant) => {
            if (this.lkroom === room && participant) {
                this.onRemoteTrackEnded(participant.identity, null);
            }
        });
        room.on(RoomEvent.Disconnected, () => {
            if (this.lkroom === room) {
                Log.debug(LOGPREFIX + 'voice disconnected');
                this.forgetVoiceRoom();
                this.scheduleVoiceRetry();
            }
        });
        Promise.resolve().then(() => room.connect(voice.url, voice.token, {autoSubscribe: true})).then(() => {
            if (this.lkroom !== room) {
                return undefined;
            }
            this.voiceconnected = true;
            return this.publishLocal();
        }).catch((error) => {
            Log.debug(error);
            if (this.lkroom === room) {
                this.forgetVoiceRoom();
                Promise.resolve().then(() => room.disconnect(false)).catch(() => undefined);
                this.scheduleVoiceRetry();
            }
        });
    }

    /**
     * Forget the voice room: remote streams end, nothing is published any more.
     */
    forgetVoiceRoom() {
        this.lkroom = null;
        this.voiceconnected = false;
        this.publishedtrack = null;
        this.remote.clear();
        Array.from(this.emitted).forEach((peerid) => {
            this.emitted.delete(peerid);
            this.emit('trackended', {peerid: peerid});
        });
    }

    /**
     * Disconnect the voice room on purpose.
     */
    stopVoice() {
        if (this.voicetimer) {
            clearTimeout(this.voicetimer);
            this.voicetimer = null;
        }
        const room = this.lkroom;
        this.forgetVoiceRoom();
        if (room) {
            // Not stopping the tracks: the voice chat owns the microphone.
            Promise.resolve().then(() => room.disconnect(false)).catch((error) => Log.debug(error));
        }
    }

    /**
     * Reconnect the voice room later with a fresh voice token (at most once per 30 s).
     */
    scheduleVoiceRetry() {
        if (this.voicetimer || this.disposed || !this.isActive() || !this.result || !this.result.voice.available) {
            return;
        }
        const delay = Math.max(VOICE_RETRY_DELAY, this.lastvoiceattempt + VOICE_RETRY_INTERVAL - Date.now());
        this.voicetimer = setTimeout(() => {
            this.voicetimer = null;
            if (!this.isActive() || this.lkroom) {
                return;
            }
            this.lastvoiceattempt = Date.now();
            this.fetchJoin(false).then((result) => {
                if (!this.isActive() || this.lkroom) {
                    return;
                }
                if (this.result) {
                    this.result.voice = result.voice;
                }
                if (result.voice.available && result.voice.token) {
                    this.startVoice(result.voice);
                }
                return;
            }, (error) => {
                Log.debug(LOGPREFIX + 'voice token not renewed (' + reasonFor(error) + ')');
                if (isNetworkError(error)) {
                    this.scheduleVoiceRetry();
                }
            }).catch((error) => Log.debug(error));
        }, delay);
    }

    /**
     * A remote LiveKit track was subscribed.
     *
     * @param {Object} track LiveKit RemoteTrack.
     * @param {Object} participant LiveKit RemoteParticipant.
     */
    onRemoteTrack(track, participant) {
        if (!track || track.kind !== Track.Kind.Audio || !track.mediaStreamTrack || !participant) {
            return;
        }
        const peerid = participant.identity;
        if (!Proto.isPeerId(peerid) || (this.self && peerid === this.self.peerid)) {
            return;
        }
        const current = this.remote.get(peerid);
        if (current && current.mst === track.mediaStreamTrack) {
            return;
        }
        let stream;
        try {
            stream = new MediaStream([track.mediaStreamTrack]);
        } catch (error) {
            Log.debug(error);
            return;
        }
        this.remote.set(peerid, {stream: stream, mst: track.mediaStreamTrack});
        if (this.peers.has(peerid)) {
            this.emitTrack(peerid);
        }
    }

    /**
     * A remote LiveKit track (or all of a participant's, when track is null) ended.
     *
     * @param {string} peerid Participant identity.
     * @param {Object|null} track LiveKit RemoteTrack.
     */
    onRemoteTrackEnded(peerid, track) {
        const current = this.remote.get(peerid);
        if (!current) {
            return;
        }
        if (track && track.mediaStreamTrack && track.mediaStreamTrack !== current.mst) {
            return;
        }
        this.remote.delete(peerid);
        if (this.emitted.delete(peerid)) {
            this.emit('trackended', {peerid: peerid});
        }
    }

    /**
     * Tell listeners about a peer's voice stream.
     *
     * @param {string} peerid
     */
    emitTrack(peerid) {
        const current = this.remote.get(peerid);
        if (!current) {
            return;
        }
        this.emitted.add(peerid);
        this.emit('track', {peerid: peerid, stream: current.stream});
    }

    /**
     * Bring the published microphone track in line with the local track (in order).
     *
     * @returns {Promise<void>} Never rejects.
     */
    publishLocal() {
        this.publishqueue = this.publishqueue.then(() => this.syncPublication()).catch((error) => {
            Log.debug(error);
        });
        return this.publishqueue;
    }

    /**
     * Publish, replace or unpublish the microphone track.
     *
     * @returns {Promise<void>}
     */
    syncPublication() {
        const room = this.lkroom;
        if (!room || !this.voiceconnected || this.publishedtrack === this.localtrack) {
            return Promise.resolve();
        }
        const want = this.localtrack;
        const old = this.publishedtrack;
        let chain = Promise.resolve();
        if (old) {
            // The voice chat owns the track: unpublish without stopping it.
            chain = chain.then(() => room.localParticipant.unpublishTrack(old, false)).then(() => {
                if (this.lkroom === room && this.publishedtrack === old) {
                    this.publishedtrack = null;
                }
                return undefined;
            });
        }
        if (want) {
            chain = chain.then(() => {
                if (this.lkroom !== room || this.localtrack !== want) {
                    return undefined;
                }
                return room.localParticipant.publishTrack(want, {source: Track.Source.Microphone, name: 'microphone'})
                    .then(() => {
                        if (this.lkroom === room) {
                            this.publishedtrack = want;
                        }
                        return undefined;
                    });
            });
        }
        return chain.then(() => {
            // The local track may have changed meanwhile.
            if (this.lkroom === room && this.voiceconnected && this.publishedtrack !== this.localtrack) {
                return this.syncPublication();
            }
            return undefined;
        });
    }

    // The transport interface.

    /**
     * Leave the room. Idempotent; never rejects.
     *
     * @returns {Promise<void>}
     */
    leave() {
        if (this.state === 'idle' || this.state === 'left' || this.state === 'failed') {
            this.stopEverything();
            return Promise.resolve();
        }
        if (this.welcomed) {
            this.sendFrame(Proto.leave());
        }
        this.stopEverything();
        this.removeAllPeers('left');
        this.self = null;
        this.setState('left', '');
        return Promise.resolve();
    }

    /**
     * Best-effort synchronous leave (pagehide).
     */
    leaveOnUnload() {
        if (this.state === 'idle' || this.state === 'left' || this.state === 'failed') {
            return;
        }
        if (this.welcomed) {
            this.sendFrame(Proto.leave());
        }
        this.stopEverything();
        this.removeAllPeers('left');
        this.self = null;
        this.setState('left', '');
    }

    /**
     * The local peer while joined.
     *
     * @returns {Object|null}
     */
    getSelf() {
        return this.self ? copyPeer(this.self) : null;
    }

    /**
     * The other peers.
     *
     * @returns {Object[]}
     */
    getPeers() {
        return Array.from(this.peers.values()).map(copyPeer);
    }

    /**
     * Send to everybody in the room through mavr-sync.
     *
     * @param {string} channel 'pose' or 'state'.
     * @param {Object} message
     */
    broadcast(channel, message) {
        if (this.state !== 'joined' || !this.welcomed || !this.ws) {
            return;
        }
        if (channel !== 'pose' && channel !== 'state') {
            return;
        }
        const data = encode(channel, message);
        if (data === null) {
            return;
        }
        if (channel === 'pose') {
            if (this.ws.bufferedAmount > POSE_BUFFER_LIMIT) {
                return;
            }
            this.sendFrame(Proto.pose(data));
        } else {
            this.sendFrame(Proto.state(data));
        }
    }

    /**
     * Send to one peer through mavr-sync (states only; poses always go to everybody).
     *
     * @param {string} peerid
     * @param {string} channel 'pose' or 'state'.
     * @param {Object} message
     */
    send(peerid, channel, message) {
        if (channel !== 'state' || this.state !== 'joined' || !this.welcomed || !this.peers.has(peerid)) {
            return;
        }
        const data = encode('state', message);
        if (data !== null) {
            this.sendFrame(Proto.state(data, peerid));
        }
    }

    /**
     * Set (or clear) the microphone track sent to everybody. Published when the voice room is
     * connected (again after a voice reconnect).
     *
     * @param {MediaStreamTrack|null} track
     * @returns {Promise<void>} Never rejects.
     */
    setLocalAudio(track) {
        this.localtrack = track || null;
        return this.publishLocal();
    }

    /**
     * A peer's remote audio stream.
     *
     * @param {string} peerid
     * @returns {MediaStream|null}
     */
    getRemoteStream(peerid) {
        const current = this.remote.get(peerid);
        return current && this.emitted.has(peerid) ? current.stream : null;
    }

    /**
     * Leave (best effort) and release everything, listeners included.
     */
    dispose() {
        if (this.disposed) {
            return;
        }
        Promise.resolve(this.leave()).catch((error) => Log.debug(error));
        this.disposed = true;
        this.localtrack = null;
        this.clear();
    }
}

/**
 * Create a companion transport (the factory the live controller loads by module name).
 *
 * @param {Object} liveconfig The launch config live block.
 * @param {Object} [options] {WebSocket?, Room?, call?} for tests.
 * @returns {CompanionTransport}
 */
export const createTransport = (liveconfig, options) => new CompanionTransport(liveconfig, options || {});
