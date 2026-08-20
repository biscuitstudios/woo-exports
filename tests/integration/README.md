# Integration scripts

Not PHPUnit tests. These need a booted WordPress with a real database, which
the unit suite in `tests/` deliberately does not have. PHPUnit only collects
`*Test.php`, so `composer test` ignores everything here.

They exist because of one bug class the unit tests structurally cannot catch:
**WooCommerce silently ignoring part of a query argument.** `wc_get_orders()`
discards the time component of a `date_created` range when both sides are date
strings, and drops to whole-day precision without a warning. A perfectly correct
`resolve_dates()` still produced a wrong export. Only a query against real rows
finds that.

## Setup

Everything is found automatically: WordPress by walking up from this directory,
and on a Mac running Local, the database socket by matching this site's path in
Local's `sites.json`. Nothing is hardcoded.

Two environment variables if that is not enough:

| Variable | When you need it |
|---|---|
| `WOOEX_DB_HOST` | Any host that is not Local, if CLI PHP cannot reach the database. |
| `WOOEX_PHP` | `guard.sh` only, if `php` is not on `PATH`. |

## Safety

Everything that writes calls `wooex_it_require_dev_site()` first. It refuses
unless the site reports an environment of `local` or `development`, or its host
ends in `.local`, `.test` or `localhost`. `WOOEX_IT_FORCE=1` overrides it and
should essentially never be used: these scripts create orders, delete orders,
and change the order storage backend.

Nothing can send mail. `bootstrap.php` short-circuits `wp_mail()` before
anything else runs, because creating an order and setting its status fires
WooCommerce's transactional emails.

## Usage

Seed the fixtures once, then run the guard either side of a change:

```bash
php tests/integration/fixtures.php seed
tests/integration/guard.sh before
# ... change the date logic ...
tests/integration/guard.sh after
php tests/integration/fixtures.php teardown
```

`guard.sh` runs the unit suite, captures a baseline under **both** order storage
backends, runs the end-to-end cutoff checks against each, and restores HPOS to
however it found it. Snapshots land in `tests/integration/.results/`.

## The scripts

| Script | Writes? | What it does |
|---|---|---|
| `bootstrap.php` | — | Shared. Locates WP, resolves the DB socket, kills outbound mail, holds the dev-site guard. |
| `fixtures.php` | yes | `seed` / `teardown` / `count`. 29 orders placed on exact boundary seconds. |
| `baseline.php` | no | Snapshots what every date range returns. |
| `compare.php` | no | Diffs two snapshots. Non-zero exit if anything moved. Does not boot WP. |
| `hpos.php` | yes | `status` / `on` / `off`. Toggles order storage and drains the sync queue. |
| `verify-cutoff.php` | no | Asserts exact order sets for the day-start boundary. |
| `guard.sh` | via the above | Orchestrates all of it. |

## Why the fixtures look like that

Real order data almost never lands on `23:59:59` or exactly midnight, so a
production replica cannot catch an off-by-one-second error. `fixtures.php`
places orders at `18:59:58`, `18:59:59`, `19:00:00` and `19:00:01`, either side
of month, year and week rollovers, and either side of both 2026 daylight-saving
transitions. A regression to whole-day precision cannot return the expected set
by luck.

Each order carries a human label (`cutoff Aug19 -1s`) so a failing diff names
the boundary that moved rather than printing an order ID nobody can place.

## One trap worth knowing

`wc_get_orders()` **silently ignores `meta_query`** on WooCommerce 11.0.1 legacy
post storage. It does not filter and it does not error; every order comes back.
An early version of `fixtures.php` scoped its teardown that way and reported 9
pre-existing real orders as fixtures. Only a second per-order `get_meta()` check
stopped it deleting them, and that check is still there for exactly that reason.

Fetch IDs, then re-verify the identifying meta on each object before acting on
it. Never let one query be the only thing between a delete and the wrong rows.
