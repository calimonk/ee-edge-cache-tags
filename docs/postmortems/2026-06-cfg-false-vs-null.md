# Postmortem: `cfg()` false-vs-null sentinel — silent zero-purge for weeks

**Date of incident detection:** 2026-06-02
**Fix shipped in:** v2.4.15
**Severity:** High (silent data-staleness across all configured installs running affected EE versions)
**Affected versions:** v2.0.0 through v2.4.14
**Duration:** ~6 weeks (since the rewritten settings model landed in v2.4.0, 2026-04-21)

## TL;DR

A single helper, `Edge_cache_tags_ext::cfg()`, used the wrong null-check
sentinel when reading config values from EE. On installs where
`ee()->config->item()` returns `false` (not `null`) for a missing key,
the helper returned an empty string *before* the DB-row fallback could
read the real configured value. Net effect: `backend()` resolved to
`'none'` and every auto-purge after entry save — plus every manual
purge — silently bailed.

The headers kept emitting fine (different code path), so caches were
still being *tagged*. But nothing was being *invalidated*. Editors saved;
readers kept seeing stale pages until the cache's natural TTL expired.

No log entries. No errors. No alerts. The CP Setup tab even rendered the
configured backend correctly, because it read from the DB row directly
instead of going through `cfg()`.

## Impact

- **All affected installs:** every channel-entry save and every manual
  purge button click between 2026-04-21 and the v2.4.15 deploy date was a
  no-op for backend dispatch.
- **Customer-facing:** stale article content visible until natural cache
  TTL. On a 30-day TTL config this meant up to a month of stale content
  per page.
- **Activity log:** completely empty across the affected window — the
  log write was downstream of the bail, so even the "we tried but
  failed" record was missing.
- **No data loss; no security exposure.** Pure cache-invalidation
  outage.
- **Lost back-fill:** the v2.4.15 fix only restores forward behavior.
  Activity log entries that should have existed during the affected
  window are gone forever; we can't reconstruct what was saved when
  without re-querying EE's own audit tables.

We learned about this from a single customer report — "I don't see
any Activity log entries on rpggamers or platformgamers" — which is the
canonical observation a customer would make if their plugin was doing
exactly nothing.

## What broke

```php
// ext.edge_cache_tags.php, bugged version (v2.4.0 → v2.4.14)
private function cfg($key, $dbKey = null) {
    $val = ee()->config->item('edge_cache_tags_' . $key);

    // BUG: handles null, doesn't handle false
    if ($val !== null && $val !== '') {
        return (string) $val;
    }

    // DB-row fallback — never reached when $val is `false`
    $row = $this->settings_row();
    $col = $dbKey ?: $key;
    return isset($row[$col]) ? (string) $row[$col] : '';
}
```

The intent: "if config.php has a real value, use it; otherwise fall
back to the per-site DB row." The bug: the check enumerates `null` and
`''` as "missing" but forgets `false` and `0`. On affected EE installs
`ee()->config->item()` returns boolean `false` for unset keys (not
`null` — varies by EE version, CodeIgniter version, and config
initialization order — there is no documented contract).

When `$val === false`:
- `false !== null` → true ✓
- `false !== ''` → true ✓
- → the early-return fires
- → `(string) false` evaluates to `''`
- → `cfg('backend')` returns `''`
- → `backend()` falls through to the `'none'` default
- → `dispatch_purge()` sees `backend === 'none'` and bails silently.

## Symptom matrix vs. actual behavior

| Surface                       | What it showed | What was happening |
|---|---|---|
| CP **Setup** tab UI           | "Backend: Nivoli · click to edit" | Reads from `loadSettings()` → DB row direct, bypasses `cfg()`. Correct. |
| CP **Status** diag tab        | "Backend: nivoli · OK"            | Also reads via different path. Correct. |
| Headers on a front-end GET    | `Surrogate-Key: entry-123 …`      | Emit path uses `keys_for_request()`, not `cfg()`. Working as designed. |
| Manual purge button           | "Backend is 'none' — nowhere to dispatch" | First place the bug was *visible* — most users never click this button. |
| Entry save / auto-purge       | Silent. No log row. No request. No error. | The bug, in production. |
| Activity tab                  | Empty forever                     | The bug's most reportable symptom. |
| Edge cache                    | Tagged but never invalidated      | Customer-visible stale content. |

What made this hard to spot: **the CP claimed everything was configured
and working**. Every diagnostic surface said "Backend: nivoli" because
every diagnostic surface bypassed the buggy code path. Only the
*production* code path went through `cfg()`. The probe was lying.

## Why we didn't catch it sooner

1. **No test fixture for `false`-returning config.** Our test harness'
   `Mock_EE_Config` returned `null` for missing keys, matching one of
   the two possible EE behaviors. The real-install behavior we caught
   in the wild — `false` for missing keys — was never exercised. 44/44
   tests green doesn't mean much when the harness only models half the
   reality.

2. **The Status tab's "OK" was load-bearing in the wrong direction.**
   We trusted the probe page to tell us if the plugin was working.
   The probe didn't go through the same code path as the actual
   functionality, so it could say "OK" while the plugin did nothing.
   This is a classic ["dummy probe"](https://en.wikipedia.org/wiki/Anti-pattern)
   trap — diagnostics that ratify rather than verify.

3. **No "did the last save fire a purge?" probe.** The Status tab
   probed *static* things (file presence, hook registration, credential
   format). It did not probe the *dynamic* fact "the last N entry
   saves on this site each emitted a purge dispatch within seconds."
   That probe would have shown empty immediately after the first
   broken save.

4. **No alerting on Activity-log-stays-empty.** A site that has been
   configured for >24h but has zero Activity log entries is, in
   practice, broken. We had no automatic check for this — neither
   in-CP nor at the Nivoli end.

5. **Single-mode null check.** `cfg()` was written with a specific
   model of "missing" in mind. PHP has many flavors of empty/missing
   (`null`, `false`, `0`, `'0'`, `''`, `[]`); the helper needed to
   collapse all of them into one decision but tested only two.

6. **Defensive cast pattern wasn't standard.** We had three roughly
   similar config readers across the codebase — one in `cfg()`,
   one in `loadSettings()`, one in the diagnostic probe. They each
   handled the missing case slightly differently. There was no
   single helper to audit.

## The fix (v2.4.15)

```php
private function cfg($key, $dbKey = null) {
    $val = ee()->config->item('edge_cache_tags_' . $key);

    // Cast-first-then-empty-check: collapses null / false / 0 / ''  / '  '
    $valStr = trim((string) ($val ?? ''));
    if ($valStr !== '') return $valStr;

    // DB-row fallback
    $row = $this->settings_row();
    $col = $dbKey ?: $key;
    if (isset($row[$col]) && trim((string) $row[$col]) !== '') {
        return (string) $row[$col];
    }

    return '';
}
```

Pattern: **normalize first, decide second.** `?? ''` handles `null`.
`(string)` handles `false` → `''` and other types. `trim()` handles
whitespace-only values. A single empty-string check after the cast
catches every flavor of "missing" in one expression. No truth-table to
mentally walk through.

## Regression tests added

Two new test cases in `tests/test-keys.php`:

1. `cfg_returns_db_value_when_config_returns_false()` — installs an
   anonymous-class override of `Mock_EE_Config` whose `item()` returns
   `false` (not `null`) for missing keys, mirroring the wild EE
   install. Asserts `cfg('backend')` returns the DB-row value
   (`'nivoli'`), not `''`.

2. `backend_resolves_to_db_value_when_config_returns_false()` —
   end-to-end equivalent: the public `backend()` method must return
   `'nivoli'` under the same harness, proving the bail in
   `dispatch_purge()` is no longer reachable on a configured install.

44 → 46 tests, all passing.

## Why the sister WordPress plugin wasn't affected

The WP plugin (`calimonk/wp-edge-cache-tags`) uses PHP's native
`defined()` + `constant()` for `wp-config.php` constant lookup, and
`get_option()` for the DB-row fallback. Neither returns `false` as a
"missing" sentinel ambiguously:

- `defined($const)` is `true`/`false` with a clear meaning.
- `constant($const)` is only called *after* `defined()` returned
  `true`, so its value can only be the actual constant value.
- `get_option($opt, '')` accepts an explicit default.

No false-vs-null ambiguity to trip over. We audited the WP code path
during the EE fix and confirmed it doesn't have the analogous bug.

## Process changes shipping with the fix

### Done in v2.4.15

- **Test fixture for both null and false config behaviors.** All future
  config-reading helpers must pass tests against both.
- **`cfg()` is now the single config-reader.** The three slightly
  different config-readers across the codebase were consolidated to one
  helper with one empty-check pattern.

### To-do (not in v2.4.15)

- **Status tab "live probe" panel.** Add a diagnostic that calls the
  *actual* production code path (`backend()`, `dispatch_purge()` in dry-run
  mode) instead of probing static things. If the production path says
  "I would dispatch to backend X with URL Y", we get the same answer as
  a real save would. Need to be careful to make this dry-run (no actual
  HTTP call) so clicking it doesn't generate spurious purges.
- **"No activity in 24h" warning.** If a site has been configured >24h
  and has zero Activity log entries, show a warning banner on the
  Setup tab: "this looks like a silent-fail. Last save was X; last
  purge was [never]." Same in the WP plugin.
- **Deploy-time-aware Activity banner.** Store the plugin's install/upgrade
  timestamp (`exp_extensions.updated`-style column, or a row in
  `exp_edge_cache_tags_settings`). On the Activity tab, if any
  `exp_channel_titles.edit_date` predates the current plugin version's
  deploy time, surface a one-line note: "N entries published before the
  current plugin version was deployed — those saves did not log purges
  here." Same in WP. Closes the "fix didn't take" vs. "fix landed
  after these entries were saved" ambiguity caught on 2026-06-03.
- **Cross-plugin codified pattern.** Both plugins now own a
  `docs/CONVENTIONS.md` line: *"normalize first, decide second" — never
  enumerate possible missing values in a conditional; cast to the
  canonical type, then test for emptiness once.*
- **No `/tmp` diagnostics under SELinux.** Both plugins (and any future
  ad-hoc tracers) must use the DB, EE's logger, or stderr — never
  `/tmp/*.log` — for diagnostic side-channels. Add a contrib-docs note.

## Lessons

1. **PHP's many flavors of "missing" are a recurring landmine.** Any
   conditional that enumerates a subset of `null` / `false` / `0` /
   `''` is likely wrong. Normalize first.

2. **Diagnostics that bypass production code paths are anti-tests.**
   The Status tab said "OK" because it had its own implementation of
   "does this work" that didn't share code with the actual save flow.
   Where possible, diagnostics should *call the real thing* in a
   side-effect-free mode.

3. **"Tests pass" ≠ "code works in production"** when the test harness
   models only one of the two possible behaviors of an external API.
   Our mock returned `null`; reality returned `false`. We needed both
   fixtures.

4. **Silent failure is the worst failure mode.** A plugin that crashes
   tells you exactly what's wrong. A plugin that successfully does
   nothing tells you nothing for as long as your TTL is long. Empty
   activity logs after configured installs should trigger an alarm,
   not be a passive thing the user notices weeks later.

5. **Customer reports are slow, expensive, and infrequent.** This bug
   shipped on 2026-04-21 and the first customer report came in
   2026-06-02 — six weeks of silent staleness. The next-bug equivalent
   should be caught by an automatic in-product probe, not the next
   customer who happens to look at the Activity tab.

6. **"Fix didn't take" and "entries published before fix deployed" look
   identical from the Activity tab.** The follow-up report on 2026-06-03
   was "still no activities for today's posts" — which felt like the
   v2.4.15 fix had failed. It hadn't. The published entries were edited
   between 07:47 and 14:07 local; v2.4.15 deployed at ~16:00. Those saves
   ran against v2.4.14 and were genuinely lost. Any new save after the
   deploy logged correctly — proven by three forced-test saves at
   00:06–00:07 the next day producing three clean log rows with the
   right token, HTTP 200, properly tagged. The fix WORKS; the older
   entries are simply unrecoverable history. **A deploy-time-aware
   diagnostic would have answered this instantly:** "12 channel entries
   published before v2.4.15 deploy at 16:00 — those saves did not fire
   purges; their pages will refresh at natural TTL. 3 entries published
   since deploy — all logged. Plugin is working."

7. **`/tmp`-based diagnostics are unreliable on SELinux-enforcing hosts.**
   During the 2026-06-03 follow-up the in-code path was instrumented
   with `@file_put_contents('/tmp/ect-trace.log', ...)`. The file was
   created with `install -o nginx -g wwwusers -m 666` so DAC perms were
   correct, but the SELinux context stayed at `user_tmp_t` — and the
   `httpd_t` (php-fpm) policy denies writes to that type. Every trace
   call silently no-op'd because of the `@` error suppressor. The DB
   activity log was the actual evidence that the code path ran. Lesson:
   never trust `/tmp` for httpd/php-fpm diagnostics; use the DB, EE's
   own logger, or stderr (which php-fpm captures into its own log).
   Same gotcha as files mv'd from /tmp into a webroot keeping
   `user_tmp_t` — well-known on this host, easy to forget when writing
   ad-hoc tracers.

## Timeline

- **2026-04-21** — v2.4.0 ships with the rewritten settings model and
  the buggy `cfg()` helper. All installs on EE versions where
  `ee()->config->item()` returns `false` for missing keys start
  silently no-op-ing their auto-purges from this point.
- **2026-04-21 → 2026-06-02** — silent zero-purge window. ~6 weeks.
  Customer caches gradually fill with stale content as TTLs expire and
  re-fill from origin without ever being invalidated.
- **2026-06-02 14:30 (approx.)** — customer reports "no Activity log
  entries on rpggamers or platformgamers". Initial assumption: the log
  is broken, not the purges.
- **2026-06-02 ~15:30** — trace: log writes are downstream of
  `dispatch_purge`, which bails early when `backend === 'none'`. CP
  shows Nivoli configured. Therefore `backend()` is the lie, not the
  log.
- **2026-06-02 ~16:00** — minimal repro: pasted backend value into the
  CP form, hit save, manually triggered a purge with
  `tail -F` on the backend. Nothing arrived. Confirmed `cfg('backend')`
  returns `''`.
- **2026-06-02 16:00** — v2.4.15 commit lands with cast-first fix and
  regression tests. Deployed to affected customer's install ~16:30 same
  day.
- **2026-06-02 16:10–16:12** — operator runs two manual purge-tag tests
  via the CP danger-zone buttons (using throwaway dashboard URLs). These
  produce activity log rows id=1 (404, wrong token) and id=2 (200) —
  the FIRST log rows on this install since v2.4.0 shipped 6 weeks
  earlier. No on-save auto-purges have actually fired yet — none of
  the day's published entries were re-saved post-deploy.
- **2026-06-02 16:29 → 16:37** — v2.4.18 (Setup-tab host-scope banner,
  Setup-tab-only change) committed and deployed. Save path unchanged
  from v2.4.15.
- **2026-06-02 evening** — postmortem written.
- **2026-06-03 ~00:00** — customer follow-up: "several new posts done
  today, no activities fired in the Edge Cache plugin page." Initial
  fear: v2.4.15 fix didn't actually take. Investigation shows the day's
  edits in `exp_channel_titles` (07:47 → 14:07) all predate the
  16:00 deploy — they ran against v2.4.14 and were lost.
- **2026-06-03 ~00:05** — ext file instrumented with
  `@file_put_contents('/tmp/ect-trace.log', ...)` at the top of
  `after_channel_entry_save`, `after_channel_entry_delete`, and `flush()`.
  Installed via `sudo install -o nginx`. Operator publishes test
  entries.
- **2026-06-03 00:06–00:07** — three new rows land in
  `exp_edge_cache_tags_purge_log` (id=3,4,5; entries 16621, 16622,
  16625; backend=nivoli; correct token; HTTP 200; ~90–120ms). **The fix
  works.** Trace file remains 0 bytes — SELinux `user_tmp_t` denies
  php-fpm writes to /tmp, every `@file_put_contents` silently failed.
  The DB log was always the ground truth; the file trace was a
  red-herring diagnostic.
- **2026-06-03** — instrumentation reverted, postmortem amended with
  the two new lessons (deploy-time-aware diagnostic, no /tmp under
  SELinux) and the to-do list extended accordingly.

## References

- Fix commit: [`1041479c`](https://github.com/calimonk/ee-edge-cache-tags/commit/1041479c)
  — `v2.4.15: CRITICAL — cfg() false-vs-null sentinel bug`
- Affected file: `system/user/addons/edge_cache_tags/ext.edge_cache_tags.php`
- Test fixture: `tests/test-keys.php` (two new cases added)
- Sister-plugin audit: WordPress version not affected — uses
  `defined()` + `get_option($opt, '')`, no false-vs-null ambiguity.
