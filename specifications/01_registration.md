# 01 — Registration

## Purpose

Allow a guest to create a new account with a unique identity, gated by validation, optional
verification, and admin-configurable policy, without leaking whether a given email/username
already exists.

## Inputs

| Field | Type | Source | Required |
|---|---|---|---|
| `username` | string | form/JSON | yes |
| `email` | string | form/JSON | yes |
| `password` | string | form/JSON | yes |
| `password_confirmation` | string | form/JSON | yes |
| `accept_terms` | boolean | form/JSON | yes |
| `first_name` | string | form/JSON | optional |
| `last_name` | string | form/JSON | optional |
| `invite_code` | string | form/JSON | optional (required if invite-only mode) |
| `captcha_token` | string | form/JSON | required if captcha enabled |

## Validation (server-side authoritative)

- `username`: 3–32 chars; `[a-zA-Z0-9_.]`; not in reserved list (admin, api, root, …); unique
  (case-insensitive); no leading/trailing separators.
- `email`: RFC-valid; max 254; unique (case-insensitive); optional MX/disposable-domain check
  when enabled.
- `password`: min 10 chars; must not equal username/email local-part; checked against a common
  password blocklist; confirmed by `password_confirmation`.
- `accept_terms`: must be `true`.
- `invite_code`: must exist, be unused/unexpired when registration mode = invite-only.
- `captcha_token`: must verify with provider when captcha enabled.

## Authorization

- Guests only; authenticated users are redirected away.
- Blocked entirely when admin setting `registration.enabled = false` (→ 403).
- When `registration.mode = invite_only`, a valid `invite_code` is mandatory.

## Business rules

1. All checks pass → create `users` row with `status = pending_verification` (or `active` if
   verification disabled).
2. Hash password with Argon2id (or bcrypt fallback).
3. Create `user_profiles` row; assign default `role` (member).
4. If email verification enabled → generate token, enqueue verification email (async job).
5. If admin-approval mode → set `status = pending_approval`, notify admins.
6. Consume `invite_code` (mark used, bind to new user) atomically in a transaction.
7. Write `audit_logs` entry (`event = user.registered`, no secrets).
8. Do not auto-login until verification/approval requirements are satisfied (configurable).

## Outputs

- Web: 302 redirect to "verify your email" notice (or dashboard if auto-active).
- API: `201 Created` with `{ id, username, status }` (never returns password/hash/token).

## Failure states

| Condition | Response |
|---|---|
| Validation error | `422` with per-field messages |
| Duplicate email/username | `422` generic ("could not complete") — no existence disclosure |
| Registration disabled | `403` |
| Invite required/invalid | `422` |
| Captcha failed | `422` |
| Rate limit exceeded | `429` with `Retry-After` |

## Privacy

- Passwords stored only as Argon2id/bcrypt hashes; never logged.
- **Enumeration resistance:** duplicate email/username returns a generic message and, where
  policy requires, the same "check your email" outcome regardless of existence.
- Audit log records event + actor IP/UA hash, never the password or raw token.

## Performance limits

- Rate limit: ≤ 5 attempts / minute / IP and ≤ 20 / hour / IP.
- Email dispatch is asynchronous (queued job), never inline.
- Endpoint p95 < 300 ms excluding async email.

## Acceptance tests

- Given valid inputs, When registering, Then a `pending_verification` user + profile exist and a
  verification email is queued.
- Given an existing email, When registering, Then response is `422` generic and no new user is
  created and no existence is disclosed.
- Given `registration.enabled = false`, When registering, Then `403`.
- Given invite-only mode without a valid code, When registering, Then `422`.
- Given a weak/blocklisted password, When registering, Then `422`.
- Given 6 attempts in a minute from one IP, Then the 6th returns `429`.
- Given verification disabled, When registering, Then user is `active` and (if configured)
  logged in.
- Given valid registration, Then an `audit_logs` row exists with no secret material.
