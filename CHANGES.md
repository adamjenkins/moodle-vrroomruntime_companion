# Changes

## 0.1.0 (not yet tagged) — ALPHA

- First version (Phase 4): the **Companion service** run mode for VR room. It uses the built-in
  viewer, but live rooms go through the vrroom-companion stack: up to 50 people per room, positions
  and states through the `vrroom-sync` service, voice through a LiveKit server. Participants no
  longer see each other's IP addresses. Moodle signs short-lived room tokens with a shared secret
  after the same checks as the built-in live mode, renews them every four minutes, and names
  people and rooms by pseudonyms only. Site settings for the companion and LiveKit addresses and
  secrets, and an admin page that tests the connection from the server (including proof that the
  service knows the shared secret) and from the browser. Nothing is stored in Moodle.
- Supports Moodle 5.3 as well as 5.2; GitHub Actions CI on Moodle 5.3.
