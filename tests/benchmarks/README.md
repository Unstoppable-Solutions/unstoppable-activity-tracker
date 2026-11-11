# UATR benchmark harness

Synthetic-load harness introduced in 2.3.1 to catch performance regressions in the hot paths — `/stats`, retention cleanup, and the concurrency primitives.

## What this measures

Five scenarios, from the 2.3.1 roadmap:

| # | Scenario | Measures |
|---|---|---|
| i | `/stats` queries at scale | Each of the three aggregate queries (full-table `COUNT(*)`, time-filtered `COUNT(*)`, `GROUP BY object`) at 100k / 1M / 10M rows |
| ii | End-to-end retention pass | Wall time for a full `run_cleanup()` call with a representative backlog |
| iii | Single-batch `DELETE` cost | Timing of one `DELETE … LIMIT 1000` under realistic conditions — the smallest unit of work cleanup can be interrupted at |
| iv | Two concurrent workers | Verify the atomic claim rejects the second worker and the first completes cleanly |
| v | Stale / evicted lock mid-run | Verify the stale-lock recovery path reclaims correctly after the 10-minute buffer |

## Prerequisites

Run from a WordPress installation that has UATR activated. The harness bootstraps WordPress via `wp-load.php`, so run it from inside the plugin directory of a live WP install (or pass `--wp-load=/path/to/wp-load.php`).

Each script is CLI-only — `php-cli` must be installed. These scripts are destructive to the UATR events table and should never be run against production data.

## Usage

```bash
# 1. Seed synthetic events.
php tests/benchmarks/seed.php --rows=1000000

# 2. Scenario (i): /stats query timing.
php tests/benchmarks/run.php --scenario=stats

# 3. Scenarios (ii) and (iii): retention pass + single-batch DELETE.
php tests/benchmarks/run.php --scenario=retention

# 4. Scenario (iv): two workers racing on the lock.
php tests/benchmarks/run.php --scenario=concurrent

# 5. Scenario (v): stale-lock recovery.
php tests/benchmarks/run.php --scenario=stale-lock

# Run everything in order:
php tests/benchmarks/run.php --scenario=all
```

Output is tab-separated, one measurement per line, so results can be piped into a spreadsheet or diff'd against a baseline:

```
scenario    rows        metric                  ms
stats       1000000     count_total             12.4
stats       1000000     count_24h               1.8
stats       1000000     group_by_object         34.7
retention   1000000     single_batch_delete     8.3
retention   1000000     full_cleanup_pass       412.5
concurrent  -           first_worker_claimed    yes
concurrent  -           second_worker_rejected  yes
stale-lock  -           reclaimed_after_stale   yes
```

## When to run this

- Before every release (per the Non-feature maintenance section of the roadmap).
- After any change to `run_cleanup()`, `claim_cleanup_lock()`, `release_cleanup_lock()`, or the `/stats` queries.
- When investigating a user-reported performance issue.

## Baseline

Baselines are not checked into the repo — hardware varies. Capture a baseline run before each release and compare against the previous release's baseline on the same machine.

## Not shipped to WordPress.org

This directory is excluded from the distributed plugin zip via `.distignore` (`tests/` entry). It exists only in the GitHub repository.
