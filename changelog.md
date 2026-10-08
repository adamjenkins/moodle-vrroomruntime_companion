# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- Run mode `mavrruntime_companion` (*Companion service*) for `mod_mavr`: the built-in viewer with
  live rooms of up to 50 people through the mavr-companion stack (`mavr-sync` WebSocket service
  for presence, poses and states; LiveKit for voice).
- Web service `mavrruntime_companion_join`: after the built-in live mode's access checks, returns
  a five-minute HS256 room token for `mavr-sync` (signed with the shared secret) and, unless
  renewing, a LiveKit token allowing the microphone only (signed with the LiveKit API secret).
  Peer and room ids are HMAC pseudonyms over a per-site salt.
- Site settings: companion address, optional server-side address, shared secret, maximum people
  per room (2 to 50), LiveKit address, API key and API secret.
- Admin page *Test the companion connection*: settings, health, an HMAC challenge proving the
  service holds the shared secret, LiveKit health and API key; plus a WebSocket check from the
  administrator's browser.
- Browser transport `mavrruntime_companion/transport_companion` for the built-in live controller,
  the wire-protocol module `syncprotocol`, and the vendored `livekit-client` bundle.
- Privacy metadata: the companion service, the LiveKit server and the other participants as
  external locations. No tables.
