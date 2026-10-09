# VR room companion service run mode (`vrroomruntime_companion`)

A run mode for the VR room activity (`mod_vrroom`) that keeps the built-in 3D
viewer but sends live rooms through the **vrroom-companion** container stack. With it, up to 50
people can meet in a live room, see each other as avatars and talk, and their browsers connect to
the companion service and its voice server instead of directly to each other, so participants no
longer see each other's IP addresses.

Everything else is the built-in viewer's: the 3D room, the list view, VR, AR and mixed reality,
the live room panel, avatars, push to talk and per-person mute. Every person still makes their own
attempt and gets their own grade; statements still go from the browser to Moodle. The companion
service stores nothing and grades nothing.

## Requirements

- Moodle 5.2 or 5.3 (`$plugin->supported = [502, 503]`).
- `mod_vrroom` and its built-in run mode `vrroomruntime_builtin`, version 2026100800 or later.
- The **vrroom-companion** stack (separate repository): the `vrroom-sync` room service and, for voice,
  a LiveKit server. Its README explains the installation with rootless Podman (Quadlet units or
  `podman compose`) or Docker, and the reverse proxy for TLS.
- HTTPS for the companion and LiveKit addresses in production (browsers allow the microphone only
  on secure pages, and a page served over HTTPS can only open secure WebSockets).

## Installation

1. Copy this repository to `public/mod/vrroom/runtime/companion` in your Moodle code.
2. Visit *Site administration > Notifications* to install it.
3. Install and start the vrroom-companion stack, and add this site's address to its
   `VRROOM_SYNC_ISSUERS` setting.
4. Enter the settings below, then open *Test the companion connection*.

## Settings

*Site administration > Plugins > Activity modules > VR room > Companion service*:

| Setting | Name | Notes |
|---|---|---|
| Companion address | `vrroomruntime_companion/url` | The address browsers use, for example `https://companion.example.org`. Browsers open a WebSocket to its `/sync` path. `http://` is accepted for testing only. A path prefix is allowed. |
| Address used by this server | `vrroomruntime_companion/serverurl` | Optional. The address Moodle uses for the connection test, if it differs (for example `http://127.0.0.1:8090` when the stack runs on the same host). |
| Shared secret | `vrroomruntime_companion/secret` | The stack's `VRROOM_SYNC_SECRET`; at least 32 characters, no spaces. Moodle signs room tokens with it; it is never sent anywhere. |
| Maximum people per room | `vrroomruntime_companion/maxusers` | 2 to 50 (default 50). Teachers choose the room size of each activity up to this value. |
| LiveKit address | `vrroomruntime_companion/livekiturl` | For voice, for example `wss://livekit.example.org` (no path). |
| LiveKit API key | `vrroomruntime_companion/livekitkey` | The stack's `LIVEKIT_API_KEY`. |
| LiveKit API secret | `vrroomruntime_companion/livekitsecret` | The stack's `LIVEKIT_API_SECRET`; at least 32 characters. It stays on the Moodle server: browsers receive only short-lived room tokens made with it. |

Leave the three LiveKit settings empty for live rooms without voice; set all three or none.

The run mode is ready as soon as the companion address and the shared secret are set. Readiness
does not contact the companion service (it is checked on every page view and launch); use the
test page to check the connection.

## Test the connection

*Site administration > Plugins > Activity modules > VR room > Test the companion connection*
(`/mod/vrroom/runtime/companion/testconnection.php`, needs `moodle/site:config`). **Run the test**
makes these checks from the Moodle server and shows each as passed, failed or not run:

1. The settings are complete.
2. The companion service answers (`GET /healthz`).
3. The companion service proves that it knows the shared secret: Moodle sends a signed test token
   with a random value, and the service must answer with an HMAC over that value, this site's
   address and its time. A different server at that address, or a stack with another secret,
   fails. The secret itself is never sent.
4. The LiveKit server answers.
5. The LiveKit server accepts the API key and secret (it lists its rooms for a signed admin token).

**Check from this browser** then opens a WebSocket to the companion service from your own
browser, which checks DNS, TLS and the reverse proxy as students will meet them.

These requests are made only from this page. They bypass Moodle's list of blocked hosts, because
the companion often runs on the same host or network, and they never follow redirects.

## How it works

- A teacher chooses the run mode **Companion service** for an activity and turns on **Live mode**
  with up to 50 participants.
- When a student presses *Join the live room*, the browser asks Moodle
  (`vrroomruntime_companion_join`) for a room token. Moodle makes the same checks as the built-in
  live mode (an own attempt in progress, or a teacher preview with `mod/vrroom:manage`; group
  membership in group mode) and signs a token valid for five minutes. The browser renews it every
  four minutes through Moodle, which repeats every check, so someone who loses access (attempt
  finished, removed from the group, live mode turned off) leaves the room within five minutes.
- The token names the person by a pseudonymous peer id and the room by a pseudonymous room id:
  both are HMACs over a random value kept in this site's configuration, so the companion service
  and LiveKit never learn user, activity or group ids.
- With voice, Moodle also signs a LiveKit token that allows publishing the microphone only.

## Privacy

Moodle stores nothing new: this plugin has no tables. While someone is in a companion live room:

- the **companion service** receives their peer id, full name, IP address, position and view
  state, keeps them in memory only while they are in the room, and passes name, position and state
  to the others;
- the **LiveKit server** receives their peer id, full name, IP address and, while the microphone
  is on, their voice, which it relays without recording;
- the **other participants** receive their name, position and voice, but not their IP address.

The privacy metadata declares these three external locations. The site's reverse proxy logs IP
addresses as it does for any page.

## Licence

GNU GPL v3 or later. See [LICENSE](LICENSE).

The LiveKit token format follows LiveKit's `livekit-server-sdk` (Apache-2.0, © 2024 LiveKit,
Inc.); no LiveKit code is included in the PHP part. The browser part bundles `livekit-client`
(Apache-2.0) and its dependencies, declared in `thirdpartylibs.xml`.

© 2026 Adam Jenkins
