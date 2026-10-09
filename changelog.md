# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.1.0] - Unreleased

### Added

- Run mode `vrroomruntime_companion` (*Companion service*) for `mod_vrroom`: the built-in viewer with
  live rooms of up to 50 people through the vrroom-companion stack (`vrroom-sync` WebSocket service
  for presence, poses and states; LiveKit for voice).
- Web service `vrroomruntime_companion_join`: after the built-in live mode's access checks, returns
  a five-minute HS256 room token for `vrroom-sync` (signed with the shared secret) and, unless
  renewing, a LiveKit token allowing the microphone only (signed with the LiveKit API secret).
  Peer and room ids are HMAC pseudonyms over a per-site salt.
- Site settings: companion address, optional server-side address, shared secret, maximum people
  per room (2 to 50), LiveKit address, API key and API secret.
- Admin page *Test the companion connection*: settings, health, an HMAC challenge proving the
  service holds the shared secret, LiveKit health and API key; plus a WebSocket check from the
  administrator's browser.
- Browser transport `vrroomruntime_companion/transport_companion` for the built-in live controller,
  the wire-protocol module `syncprotocol`, and the vendored `livekit-client` bundle.
- Privacy metadata: the companion service, the LiveKit server and the other participants as
  external locations. No tables.
- GitHub Actions workflow (Moodle 5.3; PHP 8.3 + MariaDB 11.4, PHP 8.4 + PostgreSQL 17), installing
  `mod_vrroom` first.

### Changed

- `$plugin->supported` widened to `[502, 503]`.
