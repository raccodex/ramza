# 02 — Authentication (login / logout / sessions)

## Purpose

Authenticate an existing user by credentials, establish a secure session (web) or issue an API
token, support "remember me", session review/revocation, and safe logout — resistant to
brute-force and enumeration, and deferring to 2FA when enabled.

## Inputs

| Field | Type | Source | Required |
|---|---|---|---|
| `identifier` | string (username or email) | form/JSON | yes |
| `password` | string | form/JSON | yes |
| `remember` | boolean | form/JSON | optional |
| `captcha_token` | string | form/JSON | required after N failures / if enabled |
| `device_name` | string | JSON (API token issuance) | required for token grant |

## Validation

- `identifier`: non-empty; normalized (trim, lowercase for email).
- `password`: non-empty.
- `captcha_token`: verified when the failure threshold for the IP/identifier is exceeded.

## Authorization

- Guests only for the login action; already-authenticated users are redirected.
- Account must be `active`. `pending_verification` → deny with "verify email" outcome;
  `suspended`/`banned` → deny with generic message; `pending_approval` → deny.

## Business rules

1. Resolve user by `identifier`; verify password against Argon2id/bcrypt hash.
2. **Timing/enumeration safety:** run a dummy hash verification when the user is not found so
   response time and message do not reveal existence.
3. On success with 2FA enabled → do **not** fully authenticate; issue a short-lived 2FA challenge
   and require the second factor (see spec 05).
4. On full success (web): **rotate the session id** (prevent fixation), regenerate CSRF token,
   persist a `user_sessions` record (device, IP hash, UA, last_active).
5. `remember = true` → issue a long-lived, rotating remember token (hashed at rest).
6. Reset failure counter for the identifier/IP on success; write `audit_logs` (`auth.login`).
7. API token issuance (Sanctum): return a bearer token scoped by ability; store only the hash.
8. Logout: invalidate current session (web) or revoke current token (API); rotate session id;
   audit `auth.logout`.
9. Session revocation: a user may list active `user_sessions` and revoke one/all others.

## Outputs

- Web: 302 to intended URL or dashboard; `Set-Cookie` session (HttpOnly, Secure, SameSite=Lax).
- API: `200` with `{ token, abilities, expires_at }` (token shown once; never re-retrievable).
- Session list: array of `{ id, device, ip_region, last_active, current }`.

## Failure states

| Condition | Response |
|---|---|
| Bad credentials | `422`/`401` generic ("invalid credentials") |
| Unverified/suspended/pending | `403` generic |
| 2FA required | `200` with `{ two_factor: true, challenge_id }` (not authenticated yet) |
| Rate limit / lockout | `429` with `Retry-After` |
| Captcha required/failed | `422` |

## Privacy

- No enumeration: identical message + comparable timing whether or not the identifier exists.
- Store password/remember/API tokens only as hashes; never log credentials or tokens.
- Session records store an IP **hash/region**, not raw IP, where policy requires.

## Performance limits

- Throttle: ≤ 5 failed attempts / minute / (identifier+IP); progressive lockout after 10.
- Session/token writes are single-row; login p95 < 250 ms.

## Acceptance tests

- Given valid credentials (no 2FA), When logging in, Then session id rotates and a
  `user_sessions` row is created and `auth.login` is audited.
- Given a non-existent identifier, When logging in, Then response and timing match the
  bad-password case (no enumeration).
- Given 2FA enabled + valid password, When logging in, Then response is `two_factor: true` and the
  user is not yet authenticated.
- Given `remember=true`, When logging in, Then a hashed remember token is issued and re-auth works
  after session cookie expiry.
- Given 6 failed attempts in a minute, Then the 6th returns `429`.
- Given an API token request with valid credentials, Then a bearer token is returned once and only
  its hash is stored.
- Given logout, Then the session/token is invalidated and `auth.logout` is audited.
- Given two active sessions, When revoking the other, Then it can no longer authenticate.
