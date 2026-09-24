# Ramza-next — Behavior Specifications (Phase 1)

These specs describe **required behavior** derived from the observed product, expressed
independently. They are the contract that `ramza-next/` implements and that acceptance tests
verify.

## Rules

- **No copied source.** No PHP/PHTML/JS/CSS/SQL snippets from the legacy app. Behavior only.
- Each spec is implementation-agnostic (no framework/table names beyond the original schema).
- Every spec drives at least one automated acceptance test in `ramza-next/`.

## Required structure (every spec)

1. **Purpose** — one paragraph.
2. **Inputs** — fields, types, sources.
3. **Validation** — per-field rules (server-side authoritative).
4. **Authorization** — who may perform it; guest/auth/role gates.
5. **Business rules** — state changes, side effects, ordering.
6. **Outputs** — success responses/state.
7. **Failure states** — error conditions + status codes.
8. **Privacy** — PII handling, enumeration resistance, logging.
9. **Performance limits** — rate limits, pagination, async boundaries.
10. **Acceptance tests** — Given/When/Then list.

## Spec index

| # | Spec | Status |
|---|---|---|
| 01 | Registration | DRAFTED |
| 02 | Authentication (login/logout/sessions) | DRAFTED |
| 03 | Verification (email/phone) | planned |
| 04 | Password recovery | planned |
| 05 | Two-factor authentication | planned |
| 06 | Profiles | planned |
| 07 | Privacy settings | planned |
| 08 | Relationships (followers/friends) | planned |
| 09 | User blocking | planned |
| 10 | Posts | planned |
| 11 | Media | planned |
| 12 | Comments | planned |
| 13 | Reactions | planned |
| 14 | Feed | planned |
| 15 | Notifications | planned |
| 16 | Search | planned |
| 17 | Groups | planned |
| 18 | Pages | planned |
| 19 | Messages | planned |
| 20 | Reporting | planned |
| 21 | Moderation | planned |
| 22 | Administration | planned |
| 23 | Storage | planned |
| 24 | API | planned |
| 25 | Installer | planned |
| 26 | Updates | planned |
