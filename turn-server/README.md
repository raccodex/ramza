# Ramza self-hosted TURN relay

Ramza uses the open-source coturn server. PHP creates short-lived TURN REST
credentials; coturn relays the media when direct WebRTC connectivity is blocked.

1. Install Docker on a Linux server with a public IP.
2. Copy `turnserver.conf.example` to `turnserver.conf`.
3. Set `realm`, `server-name`, and a long random `static-auth-secret`.
4. Put the same secret in **Admin > Settings > Video & Audio Settings > TURN shared secret**.
5. Point the TURN hostname to the server and allow TCP/UDP 3478, TCP 5349, and
   UDP 49160-49200 in the firewall.
6. Run `docker compose up -d` from this folder.

The admin setting can leave the TURN host blank when coturn uses the same host
as the website. A dedicated `turn.example.com` host is recommended. PHP is not a
UDP media relay, so the coturn service must be running for TURN fallback.

Keep `turnserver.conf` private. It is ignored by the release configuration and
must never be included in a public support bundle.
