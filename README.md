# QA Runner

Manual QA test runs for a small internal team. Testers open a page in wp-admin, pick a run,
work through a list of test cases, and mark each one pass / fail / blocked / skipped.
Failures get a comment and a link to a GitHub issue, and issues stay attached to the case,
so the next person to test it sees what is currently broken.

No automated testing, no code execution, no screenshots. Everything is human-entered.

**Requires** PHP 8.2+, WordPress 6.4+, MySQL 8.

## The three concepts

| | |
|---|---|
| **Case** | A permanent instruction, written once and edited rarely. Belongs to a suite. Carries **no status**. |
| **Run** | One pass through a chosen subset of cases, at a point in time, against a named environment and version. |
| **Result** | The intersection of a run and a case: status, who set it, when. One row per case per run. |

Putting a status on a case is the mistake this schema exists to prevent — a case has no
status, only results do, and that is what makes run #12 comparable to run #9.

Runs are independent. Several are open at once and completing one never touches another.
Completion is always an explicit action, never automatic when the last case is set, because
testers revisit results. Once a run is not `open`, the API rejects result, comment and lock
writes with a 409.

Issues attach to the **case**, not the run. That is the one cross-run link: when anyone
opens that case in any later run, they see a banner for each open issue. Resolved issues
disappear from the test flow entirely and live on only in the case library's audit view.

## Install

```
npm install && npm run build      # build/ is committed, so this is only needed after edits
composer install                  # optional: dev tooling only, no runtime dependency
```

Activate the plugin. Activation creates the tables, registers the `qa_tester` role, writes
the default options and schedules the daily digest.

The runtime has **no Composer dependency** — classes autoload from `includes/` via a PSR-4
autoloader in the main plugin file, so an rsync deploy needs no install step on the server.

## Verify the REST namespace first

Before debugging anything in the app, confirm the API is reachable. Open the QA Runner
screen and run this in the browser console:

```js
fetch(qaRunner.root + 'ping', {headers: {'X-WP-Nonce': qaRunner.nonce}, credentials: 'same-origin'})
  .then((r) => r.json())
  .then(console.log);
```

A 200 with `{ok: true, ...}` means the stack is wired correctly. A 403 is a server-side
filter on the REST API, not plugin code — resolve that before going further.

## Capabilities

Always checked as capabilities, never role names.

| Capability | Grants |
|---|---|
| `qa_view_qa` | See the QA screens |
| `qa_run_tests` | Set results, comment, raise and resolve issues, create runs |
| `qa_manage_cases` | Edit the case library and suites, delete any comment, abandon runs, change settings |

Two roles ship with the plugin, both cloned from subscriber so their users can reach
wp-admin at all:

| Role | Capabilities | Owns |
|---|---|---|
| `qa_admin` (QA Admin) | all three | The library: creates, edits and archives suites and cases, abandons runs, changes settings |
| `qa_tester` (QA Tester) | `qa_view_qa`, `qa_run_tests` | The work in flight: creates and runs runs, adds and removes their cases, claims cases, comments, raises and resolves issues |

The line between them is `qa_manage_cases`. A tester sees the whole library but cannot
create or archive anything in it; the case library, suites and settings screens are hidden
from them in the router as well as refused by the REST layer. Testers can create runs — that
is deliberate.

`Roles::ELEVATED_ROLES` names the *WordPress* roles that also receive the full set
(administrator). It is the source of truth: on install the caps are granted to the roles
listed and taken back from every role that is not, the two QA roles aside. Bump
`Roles::VERSION` after changing it so `maybe_install()` reconciles on the next admin
request, or deactivate and reactivate the plugin.

Run assignment is informational: it sends an email and shows an avatar. It does not restrict
who may set a result. Case assignment within a run is a claim testers make on themselves —
it needs no capability beyond `qa_run_tests` plus a place on the run.

## Build

`vite.config.js` emits an **IIFE bundle at fixed paths** — `build/qa-admin-page.js` and
`build/qa-admin-page.css` — rather than a hashed ES module with a manifest. `Assets.php`
enqueues those directly as a classic script, so there is no manifest to read and no
`script_loader_tag` filter adding `type="module"`. Cache busting comes from
`QA_RUNNER_VERSION`.

`build/` is committed on purpose: deployment is rsync-based with no build step on the server.

```
npm run dev      # rebuild on change, unminified, with devtools
npm run build    # production bundle
npm run lint     # eslint
npm run format   # prettier
composer lint    # phpcs, WordPress ruleset
```

## Notifications

Two emails, both plain HTML tables:

- **Assignment**, sent when someone is added to a run. `notified_at` is stamped on success,
  so re-saving a run never double-sends.
- **Daily digest**, a WP-Cron event listing outstanding untested cases per assignee. Skipped
  entirely for anyone with nothing outstanding — a digest that says "nothing to do" trains
  people to filter you.

Both are sent with the `wp_mail_content_type` filter attached immediately before the send
and removed immediately after, so other plugins' mail is untouched.

Changing the digest time in Settings reschedules the cron. Writing the option alone would do
nothing: WP-Cron fires relative to the timestamp it was handed.

## Uninstall

Removing the plugin always removes the role, its capabilities and the plugin options. The
**tables are only dropped when "delete data on uninstall" is enabled** in Settings, which is
off by default. QA history should not vanish because someone removed the plugin to
troubleshoot something else.

## Layout

```
qa-runner.php          bootstrap, constants, PSR-4 autoloader, activation hooks
uninstall.php          role + options always; tables only behind the opt-in
includes/
  Plugin.php           singleton, wires hooks
  Install/             Schema (dbDelta + migrations), Roles, Seeder
  Admin/               Menu (mount point), Assets (enqueue + window.qaRunner)
  Repository/          one per table, every query via $wpdb->prepare()
  Rest/                Controller base + one controller per resource
  Notification/        Mailer, DigestCron
  Support/             Enum, Sanitize, Dates, Settings
templates/emails/      assignment.php, digest.php
src/                   Vue 3 source
build/                 Vite output — committed
```

## Notes for future work

- **No ENUM columns.** Every status and priority field is `VARCHAR(20)`, because `dbDelta`
  mangles ENUM on subsequent migrations. The allowed values live in `Support/Enum.php` and
  are shared by the repositories and the REST `validate_callback`s.
- **Timezones.** Everything is stored and emitted as UTC. `current_time('mysql')` is never
  mixed with `gmdate()`; the client formats for display.
- **Soft locks** are compared against a 15-minute TTL on read. There is no cron clearing
  them, and they never block a second tester from submitting.
- **`$wpdb->prefix`**, not `base_prefix` — single site. Table names are built in one place,
  `Schema::table()`, so a multisite move is a one-line change.
