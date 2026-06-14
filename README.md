# Helix CTF Toolkit (`ctf-cli`)

A [Laravel Zero](https://laravel-zero.com) command-line toolkit for solving the
**Helix // Code Society × QA DNA** capture-the-flag challenge. It was ported
from a full Laravel app once it became clear the work was entirely
console-driven (HTTP probing, enumeration, payload replay) — a CLI is the right
shape for the job, with no HTTP layer, views, or database to carry along.

This is an **offensive toolkit**, not a CTF platform. Every command talks to the
live target and replays the techniques used to recover each fragment.

- **Target:** `https://challenge.qadna.co` (Next.js on nginx, AWS)
- **Event:** Helix // Code Society × QA DNA
- **Player handle:** Bobee
- **Status:** all 7 challenges solved (last two reconstructed post-event from the
  official walkthrough PDF)

## Requirements

- PHP 8.2+
- Composer
- Network access to the target

## Install

```bash
composer install
```

The CLI entrypoint is the `application` binary in the project root:

```bash
php application list          # show all commands
php application ctf:session   # run a command
```

## Configuration

All target settings live in [`config/ctf.php`](config/ctf.php) and can be
overridden with environment variables:

| Key | Env var | Default | Notes |
|-----|---------|---------|-------|
| `base_url` | `CTF_BASE_URL` | `https://challenge.qadna.co` | Target base URL |
| `resume_code` | `CTF_RESUME_CODE` | `HLX-LMST-QL` | Player session cookie (`helix_player`) |
| `handle` | `CTF_HANDLE` | `Bobee` | Player handle |
| `player_id` | — | `8cab29c0-…` | Used as the `sub` claim in forged JWTs |
| `login.*` | — | dna_admin / Pr0b3_D33p! / QX-7291 | Challenge #1 credentials |
| `access_token` | — | `xK9mQ2vL8nR3` | Leaked accessToken; reused by #2 and #5 |

## Commands

### `ctf:session`

Resumes the player session against `/api/resume`, stores the response in
`storage/app/session.json`, then queries `/api/me` to print rank, points, solve
count, and event status.

```bash
php application ctf:session
```

### `ctf:solve {challenge?}`

Replays the payload for a solved challenge and prints the recovered fragment /
score code. With no argument (or `all`) it runs every challenge in sequence.

```bash
php application ctf:solve            # interactive picker
php application ctf:solve all        # replay everything
php application ctf:solve jwt-forge  # one challenge
```

Valid challenge names: `login`, `escalation`, `reports`, `projects`, `resolve`,
`idor`, `jwt-forge`.

### `ctf:enumerate {endpoint}`

Walks an ID range against an endpoint and flags responses whose body length
deviates from the most common length — the technique that surfaces hidden
records (Challenge #7 / IDOR).

```bash
php application ctf:enumerate /api/reports --from=1 --to=200
php application ctf:enumerate /api/notes --method=POST --param=id --body='{"id":{ID}}'
php application ctf:enumerate /api/audit-log --header='Authorization: Bearer <jwt>'
```

| Option | Default | Purpose |
|--------|---------|---------|
| `--method` | `GET` | HTTP verb |
| `--param` | `id` | Query/body parameter carrying the ID |
| `--from` | `1` | Start ID |
| `--to` | `200` | End ID |
| `--body` | — | JSON body template; `{ID}` is substituted per request |
| `--header` | — | Extra header as `Key: Value` |

## The Challenges

All 7 are **independent, standalone bugs** — no chaining and no auth
prerequisite between them.

| # | Name | Class | Technique |
|---|------|-------|-----------|
| 1 | login | Auth bypass | Credentials hidden across an HTML comment (`dna_admin`), the browser console (`Pr0b3_D33p!`), and `/api/config` (`QX-7291`). |
| 2 | escalation | Privilege escalation | `POST /api/profile` leaks `availableRoles:[…,"admin"]` plus a free `accessToken`; replay with `X-Access-Token` + `{"role":"admin"}`. |
| 3 | projects | Broken access control | Client-mutable `clearance` cookie — resend `GET /api/projects` with `clearance=admin` to unlock locked project 12. |
| 4 | reports | Hidden in plain sight | `/api/fragment-clue?fragment=reports` returns the score code directly. |
| 5 | resolve | Obfuscated JS | `/analytics-helper.js` exposes `window._resolve(token, seed)`, which POSTs `accessToken` + `sessionSeed` to `/api/resolve`. |
| 6 | jwt-forge | JWT manipulation | Forge an `alg:none` token with `role:auditor` and hit `/api/audit-log`. |
| 7 | idor | IDOR | `/api/reports` leaks `nextPageStart:9999` — a predictable out-of-range cursor; `GET /api/reports/9999` returns the hidden internal report. |

> Challenges #2 (escalation) and #5 (resolve) were solved after the event from
> the official walkthrough PDF; their `ctf:solve` handlers are annotated as
> post-event reconstructions.

## Project Layout

```
app/Commands/
  CtfSession.php     # resume session, dump status
  CtfSolve.php       # replay solved-challenge payloads
  CtfEnumerate.php   # ID enumeration / anomaly detection
config/ctf.php       # target + player configuration
```

## Notes

- Session is keyed off the `helix_player` cookie (the resume code).
- Fragment / score codes are **per-player** — copying another contestant's code
  is useless; each technique must trigger your own reveal.
- See [`CLAUDE.md`](CLAUDE.md) for the full reconnaissance log: every discovered
  endpoint, hint, decoy, and dead end.

---

Built on [Laravel Zero](https://laravel-zero.com), an MIT-licensed micro-framework for console applications.
