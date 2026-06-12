# CTF Toolkit

This is a Laravel project used as an offensive toolkit for solving CTF challenges. It is NOT a CTF platform.

Laravel is used for its HTTP client, string/collection helpers, encryption/hashing utilities, caching, and file handling. Postgres is available for persisting data.

## Workflow

- **Tinkerwell** for quick, ad-hoc scripts (decoding, fetching, exploring)
- **Artisan commands** (`php application ctf:<name>`) for repeatable or long-running tasks (brute forcing, fuzzing, multi-step workflows)
- Switch based on complexity.

## Tools

- **nmap** installed via Homebrew, available for port scanning and network recon.

## Challenges

All 7 challenges are INDEPENDENT standalone bugs (no chaining, no auth prerequisite). **Confirmed by board 2026-05-23 re-check:** contestant `mihai_cli` solved `resolve` WITHOUT ever solving login/escalation/projects (their solves: idor, jwt-forge, reports, resolve) — so you do NOT need to solve them in order or be authed for resolve. A contestant tip also says the challenges "seem unrelated."

**Live board re-check 2026-05-23 (46 players). Solve counts:** login 13, escalation 2, projects 8, reports 9, **resolve 1 (NEWLY SOLVED — mihai_cli, first blood)**, jwt-forge 14, idor 5.

| # | Name | Type | Status |
|---|------|------|--------|
| 1 | login | Auth Bypass | SOLVED ✓ (8227CA) |
| 2 | escalation | Privilege Escalation — server trusts client-supplied role | SOLVED ✓ (from walkthrough PDF, post-event) |
| 3 | projects | Access Control — client-mutable clearance cookie | SOLVED ✓ (0188D2) |
| 4 | reports | Hidden in plain sight — off-screen DOM node (NOT SSRF) | SOLVED ✓ (AC8C26) |
| 5 | resolve | Obfuscated JS — window._resolve(token, seed) (NOT race/logic bug) | SOLVED ✓ (from walkthrough PDF, post-event) |
| 6 | jwt-forge | JWT Manipulation — alg:none | SOLVED ✓ (0DE348) |
| 7 | idor | IDOR — predictable nextPageStart cursor leaks hidden report id | SOLVED ✓ (B44B39) |

**Post-event note:** All 7 are now solved. Official walkthrough PDF (`helix_ctf_walkthrough.pdf`) resolved the last two:
- **#2 escalation:** `POST /api/profile` (as viewer) leaks `availableRoles:[...,"admin"]` + a free `accessToken`. Replay with header `X-Access-Token: xK9mQ2vL8nR3` and body `{"role":"admin"}`. (My earlier "always viewer" finding was the *unauthenticated* response — the leaked token is the key.)
- **#5 resolve:** `/analytics-helper.js` (obfuscated) attaches `window._resolve(token, seed)`. `token` = the F2 `accessToken` (`xK9mQ2vL8nR3`), `seed` = the F1 `sessionSeed` (`QX-7291`). It POSTs both to `/api/resolve` — that's why bare param-guessing returned "Invalid parameters".
- Both replayable via `php artisan ctf:solve escalation` / `ctf:solve resolve`.

## Key Mechanics (verified 2026-05-23)
- **Session = `helix_session` cookie** containing the HS256 JWT (`{sub,role,iat,exp}`, role:viewer). Login (`/api/login`) sets it via `Set-Cookie`. `/api/me` still keys off `helix_player`.
- **Fragment codes are PER-PLAYER** (JS: "Don't share fragment codes — they're per-player"). Copying others' codes is useless; must trigger our own reveal.
- **Submit** only needs `{code}` (6-char); server auto-maps code→fragment. Errors: invalid_code, already_solved, rate_limited, no_player.
- **Reveal endpoints** return `{fragment, score_code, fragment_id}`: login(POST), fragment-clue?fragment=reports, audit-log(auditor JWT via Authorization header). projects renders fragment from a project object when server includes those fields on it.
- **HS256 secret** NOT in local /private/tmp/rockyou-10k.txt (the "100k list" in old notes = this 10k). Full rockyou.txt is empty/unavailable. No hashcat/john installed. Secret likely uncrackable by design → escalation probably NOT via signing.
- **Confirmed hard decoys (waste no more time):** /api/internal/keys (x-clearance header AND JWT both ignored — always missing_clearance), /api/profile (always viewer), /api/debug/dump (always off), /api/preferences feature_flags (read-only display: only reports_v2_export=true).

## Target

- **CTF Name:** Helix // Code Society × QA DNA
- **Base URL:** `https://challenge.qadna.co`
- **Teaser page:** `https://www.qadna.co/challenge` — Vigenère cipher (key: `qadnachallenge`) → `challenge.qadna.co`
- **Stack:** Next.js on nginx/1.28.2, AWS (3.77.48.170)
- **Build ID:** NFGR7zllghQoQKOUiIBli

## Player Session

- **Handle:** Bobee
- **Resume code:** HLX-HWCK-PW
- **Player ID:** 8cab29c0-8e02-4f2b-8408-60d6aa139240
- **Cookie:** `helix_player=HLX-HWCK-PW`

## Artisan Commands

- `php artisan ctf:session` — Resume session and show status
- `php artisan ctf:solve {challenge?}` — Replay solved challenges (login, jwt-forge)
- `php artisan ctf:enumerate {endpoint}` — Enumerate IDs 1-200, find anomalous responses

## All Discovered Endpoints

| Endpoint | Method | Notes |
|----------|--------|-------|
| `/api/join` | POST | `{handle}` — creates player, sets cookie |
| `/api/resume` | POST | `{resume_code}` — resumes session |
| `/api/me` | GET | Player info, rank, solves, event_status |
| `/api/login` | POST | `{username, password, code}` — Ch#1 |
| `/api/config` | GET | appVersion, sessionSeed "QX-7291", featureFlags |
| `/api/submit` | POST | `{fragment_id, code}` — submit fragments |
| `/api/scoreboard` | GET | Full scoreboard with player solves |
| `/api/scoreboard/stream` | SSE | Live scoreboard events |
| `/api/profile` | POST | Returns user role/perms/projects (always viewer) |
| `/api/preferences` | PATCH | Mass assignment cosmetic only (applied but no real change) |
| `/api/keys/rotate` | POST | Rotates API key, recovery_slug is random (not a fragment) |
| `/api/projects` | GET | Returns 12 projects. Project 12 (Gamma) is locked. |
| `/api/projects/test-runs` | POST | `{project_id}` — queues test run, returns random ticket |
| `/api/projects/stars` | POST/DELETE | `{project_id}` — star/unstar (works on locked proj 12) |
| `/api/reports` | GET | Returns 47 reports list |
| `/api/reports/{id}` | GET | Individual report preview (IDs 1-47, all "public") |
| `/api/reports/exports` | POST | `{id, kind, name}` — generates CSV download |
| `/api/fragment-clue` | GET | `?fragment=reports` returns score_code. Others return not_allowed |
| `/api/team` | GET | 12 team members (u_001 to u_012) |
| `/api/team/invitations` | POST | `{email, role, team}` — creates invitation (decorative) |
| `/api/notes` | GET/POST | Search/create notes. share_token and _hints.signing_nonce per note |
| `/api/audit-log` | GET | Needs JWT `Authorization: Bearer`. Role "auditor" → fragment |
| `/api/changelog` | GET | 7 changelog entries |
| `/api/internal/keys` | GET | Always returns `missing_clearance` regardless of headers |
| `/api/debug/dump` | GET | Always `debug mode is off` regardless of preferences |
| `/api/v2/scoreboard` | GET | Returns "gone" sunset message |
| `/api/logout` | POST | Logout |
| `/api/resolve` | POST | Returns "Invalid parameters" — correct param names unknown |

## All Pages (Sidebar)

`/dashboard`, `/projects`, `/reports`, `/team`, `/changelog`, `/notes`, `/audit-log`, `/submit` (Console), `/settings`, `/login`

## HTML Comment Hints (from JS bundles)

1. `dna_admin` — login form (used for Ch#1 username)
2. `x-clearance: dna-admin unlocks /api/internal/keys for SREs` — NOT WORKING
3. `DEBUG=1 enables raw dump at /api/debug/dump (staging only)` — NOT WORKING
4. `TODO: migrate legacy auth module to OAuth2`
5. `rotator: v2 scoreboard is gone, see /api/v2/scoreboard for the redirect`
6. `NOTE: clean up deprecated feature flags before Q3`
7. `notes-v1 — local-only scratchpad, do not sync to prod`

## ConsoleDecoy Output (browser console, all pages)

- `[v2.4.1] Application initialized`
- `[config] Environment: production`
- `[debug] Render cycle: 14ms`
- `Pr0b3_D33p!` ← used for Ch#1 password
- `[warn] Analytics endpoint deprecated — migrate to v3 by Q2` ← analytics endpoint not found
- `[perf] Bundle size: 245kb (gzip: 82kb)`

## Solved Details

### Challenge #1 — Login (8227CA)
- Username: `dna_admin` (HTML comment), Password: `Pr0b3_D33p!` (console), Code: `QX-7291` (/api/config)
- `php artisan ctf:solve login`

### Challenge #4 — Reports (AC8C26)
- `/api/fragment-clue?fragment=reports` returns the score_code directly

### Challenge #6 — JWT Forge (0DE348)
- Forge JWT with `alg:none`, `role:auditor`, hit `/api/audit-log`
- `php artisan ctf:solve jwt-forge`

### Challenge #7 — IDOR (B44B39)
- `GET /api/reports` lists ids 1-47 (public) and returns `nextPageStart: 9999`. That predictable cursor IS the leaked out-of-range id.
- `GET /api/reports/9999` → hidden report #48 (`classification:internal`) with `fragment`/`score_code`/`fragment_id`. Submit it.
- The detail endpoint has no authz check on the id range; only the LIST is capped at 47. No clearance cookie needed.
- `php artisan ctf:solve idor`

### Challenge #3 — Projects (0188D2)
- A **real login** (`/api/login` dna_admin/Pr0b3_D33p!/QX-7291) sets a `clearance=basic` cookie. Project 12 "Gamma" is `locked`/"Classified".
- Send cookie **`clearance=admin`** on `GET /api/projects` → Gamma flips to `active` and the project object gains `fragment`/`score_code`/`fragment_id`. Submit the code.
- One-liner: `curl /api/projects -H 'Cookie: helix_player=HLX-HWCK-PW; clearance=admin'` → grab project 12 score_code.
- Lesson (from fragment text): client-mutable cookie = client-controlled state. Only `clearance=admin` works (not classified/elevated/secret/etc.).

## Failed/Explored Paths (Dead Ends)

### Challenge #2 (escalation) — unsolved by everyone
- `/api/preferences` PATCH accepts `role:admin` cosmetically but doesn't change actual role
- `/api/profile` always returns role=viewer regardless of JWT or body params
- `/api/internal/keys` always returns `missing_clearance` even with x-clearance header
- `/api/debug/dump` always says debug mode off even after enabling in preferences
- Feature flags cannot be changed (applied field is cosmetic)
- Team invitations with admin role create fake invites (accept_url is 404)
- JWT HS256 secret not cracked with 100k password list

### Challenge #3 (projects) — SOLVED 2026-05-23 (0188D2)
- Answer: `clearance=admin` cookie on GET /api/projects (see Solved Details). The miss before was never having the `clearance` cookie at all — it only appears AFTER a real `/api/login`.
- Confirmed dead ends along the way: query params (?id/?unlock/?include/etc. — all 2205 bytes), forged alg:none JWT roles (no effect), request headers (X-Role/X-Clearance/X-Forwarded-For — no effect), POST to /api/projects (405), no /api/projects/{id} (404), star/test-run + wait (no state change). Only the `clearance` COOKIE value matters, and only `=admin`.

### Challenge #5 (resolve) — unsolved by everyone
- POST `/api/resolve` returns "Invalid parameters"
- Tried: ticket_id, id, step, project_id, bug_id, issue_id, etc. — all invalid
- No /resolve page exists

### Challenge #7 (idor) — SOLVED 2026-05-23 (B44B39)
- Answer: `nextPageStart:9999` from `/api/reports` → `GET /api/reports/9999` (see Solved Details). The miss before was treating "enumerate 1-200" literally; the hidden id is the leaked cursor, not in 1-200.
- Dead ends ruled out along the way: reports preview 48-200 = not_found, reports export reflects id but no leak, team/notes/projects by-id uniform, no UUIDs anywhere in reports, pagination params don't advance (cursor 9999 loops on the LIST but works as a detail id).
