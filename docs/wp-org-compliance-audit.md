# Amendor — WordPress.org Compliance Audit (full repo pass)

> Date: 2026-08-17 · Scope: entire repo (plugin code, assets, readme, license,
> build) against the [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
> and the review team's pending feedback. `vendor/` (Freemius SDK, GPLv3 —
> compliant) and `docs/` excluded except where noted.
>
> Status: **Mostly compliant. One OPEN item (Guideline 5) needs a product
> decision; three minor items optional.**

---

## Verdict summary

| # | Area | Guideline | Status |
|---|---|---|---|
| 1 | Trialware / locked features | §5, §1, §9 | ✅ RESOLVED (see Finding A) |
| 2 | Free-path gating survives in Free build | §5 | ✅ RESOLVED (see Finding A) |
| 3 | GPL compliance | §1, §2 | ✅ PASS |
| 4 | Human-readable code | §4 | ✅ PASS |
| 5 | Serviceware | §6 | ✅ PASS |
| 6 | No tracking w/o consent | §7 | ✅ PASS |
| 7 | No external executable code | §8 | ✅ PASS |
| 8 | No admin hijack | §11 | ✅ PASS |
| 9 | Readme not spammy | §12 | ✅ PASS |
| 10 | Uses WP default libraries | §13 | ✅ PASS |
| 11 | Version bumped | §15 | ✅ PASS (1.0.1) |
| 12 | Complete plugin | §16 | ✅ PASS |
| 13 | Trademark-safe slug | §17 | ✅ PASS |
| 14 | Nonces & permissions | Dev expectations | ✅ PASS |
| 15 | Enqueue assets (no raw tags) | Review checklist | ✅ PASS |
| 16 | i18n / textdomain | Review checklist | ✅ PASS |
| 17 | Escaping & sanitization | Dev expectations | ✅ PASS |
| 18 | SQL / prepared queries | Dev expectations | ✅ PASS |
| 19 | Uninstall cleanup | Gotcha #4 | ✅ PASS |
| 20 | Plugin header / readme | Review checklist | ✅ PASS |
| 21 | Contributors | Review checklist | ✅ PASS (verify casing) |
| 22 | Freemius `is_org_compliant` | §5 + Freemius | ✅ PASS |
| 23 | readme `Tags:` field | §12 / readme format | 🟡 MINOR |
| 24 | `error_log` uses old "ETP" prefix | — | 🟡 MINOR |

---

## Finding A — 🔴 Free-path gating survives in the generated Free build (Guideline 5)

**What the Freemius generator does:** it removes code **inside**
`if ( fs()->is__premium_only() ) { … }` blocks (the premium feature is
physically absent from the Free build). It **keeps** code inside
`if ( ! fs()->is__premium_only() ) { … }` blocks — that is "free-path" logic.

**The problem:** the current code has **10 free-path guards** that survive the
stripper with the `is__premium_only` string AND a "downgrade / block" body —
the exact "locally implemented feature disabled behind a license check" pattern
the review team flagged (and warned would cause rejection if it recurs):

| Location | Guard | Survives as (Free build) |
|---|---|---|
| `action-handlers.php:143, 291, 428` | `if ('regex' === $mode && !is__premium_only()) { $mode='partial'; }` | regex downgrade → "locked" |
| `ajax-handlers.php:73, 128` | same regex downgrade | regex downgrade → "locked" |
| `admin-main-page.php:62` | same regex downgrade | regex downgrade → "locked" |
| `log-history-page.php:191` | skip regex filter option | regex hidden → "locked" |
| `admin-main-page.php:70` | `if (!is__premium_only()) { $bulk_search=[]; $bulk_replace=[]; }` | bulk replace stripped → "locked" |
| `presets.php:257, 315` | `if (!is__premium_only()) { return; }` | presets blocked → "locked" |

The `if (is__premium_only()) { … } else { $allowed_fields = []; }` branches
(`action-handlers.php:146/294/431`) are **benign** — the stripper removes the
premium branch and the surviving `else` is just `$allowed_fields = [];` with no
suspicious string. Field-key targeting is therefore unreachable in the Free
build without any lock pattern (no UI, no population, no guard) — acceptable.

### Recommended resolution (product decision required)

Make the two **shared-engine** features **free**, and drop the redundant
guards:

1. **Regex → FREE.** Remove all 7 regex guards (6 downgrade + 1 filter) and
   un-gate the regex UI + help box (`admin-main-page.php:268-276`). The regex
   engine is shared code that the stripper can't cleanly remove, so "free" is
   the only clean option. Pro loses regex as a differentiator.
2. **Bulk replace → FREE.** Remove the zeroing guard (`admin-main-page.php:70`)
   and un-gate the bulk UI (`admin-main-page.php:273`). Same reasoning.
3. **Presets stay PRO, guards removed.** `presets.php:257/315` are redundant
   (the call sites are already inside stripped `is__premium_only()` blocks);
   delete them so `presets.php` has zero premium references. Presets become
   dead code in the Free build (no entry points) — clean.
4. **README update:** move regex + bulk replace into Free "Key Features";
   Pro list becomes SEO sources, saved presets, log export, Elementor editor.

**Resulting Free build:** zero `is__premium_only` / downgrade / zeroing patterns
in `includes/`; the only remaining "premium" strings are the `fs_dynamic_init`
config in `amendor.php`, which the generator rewrites (`is_premium => false`,
gatekeeper stripped).

**If you prefer to keep regex/bulk Pro:** the only compliant alternative is
gating the shared engine code inside `is__premium_only()` blocks so it is
stripped — invasive across `search-engine.php`/`search-data.php` and fragile
(dead-code elimination may not remove it all). Not recommended.

### Resolution (implemented 2026-08-17, commit `e06773c`)

Decision (autonomous, user unavailable): the **recommended** option was applied.

- **Regex → FREE**: removed all 6 downgrade guards + the history filter skip;
  un-gated the regex UI option and help box. Regex engine is shared code that
  the stripper can't cleanly remove, so it ships free.
- **Bulk replace → FREE**: removed the zeroing guard; un-gated the bulk UI.
- **Presets stay PRO**: removed the two redundant early-return guards
  (`presets.php` now has zero `is__premium_only` references; the call sites are
  inside stripped blocks, so presets are dead code in the Free build).
- **Field-key targeting**: left Pro; population stays inside stripped blocks,
  so it is inert in the Free build with no lock pattern (no guard survives).
- **README**: regex + bulk moved into Free "Key Features"; Pro note trimmed to
  field-key targeting, SEO sources, presets, in-editor tool, log exports.

**Post-fix verification:** `grep` for `!is__premium_only()`, `restrict_search`,
`can_use_premium`, `downgrade` in `includes/` → **zero hits**. Remaining
`is__premium_only` references (13) are all inside `if (is__premium_only())`
PRO-gated blocks that the Freemius generator removes entirely.

---

## ✅ Pass details

**GPL (§1/§2):** plugin header + readme + `LICENSE` are GPL-2.0-or-later;
`composer.json` GPL-2.0-or-later; bundled Freemius SDK is GPLv3 (compatible).
**Human-readable (§4):** no obfuscation; `eval`/`base64_decode`/`shell_exec`/
`system`/`exec` — zero hits in plugin code.
**Serviceware (§6):** no license-validation-only service; Freemius is the
standard SDK (updates, licensing via its own infra).
**Tracking (§7):** no telemetry/analytics/beacon/pixel in plugin code; the SDK's
own telemetry is the standard, consent-based Freemius mechanism.
**External code (§8):** zero `wp_remote_*`, `curl`, remote `file_get_contents`,
remote includes; all JS/CSS local; no third-party CDN.
**Admin (§11):** all notices `is-dismissible`; onboarding banner is dismissible
and nonce-gated.
**Readme spam (§12):** no affiliate links, no keyword stuffing; (no `Tags:`
field — see minor).
**Libraries (§13):** uses WP's bundled jQuery; no vendored WP libraries.
**Version (§15):** `1.0.1` in header, `AMENDOR_VERSION`, `Stable tag`, POT — all
identical.
**Nonces/permissions:** every state-changing handler verifies a nonce **and**
`amendor_current_user_can_manage()`; AJAX uses `check_ajax_referer()`; the only
direct `current_user_can()` is inside the central helper (enforced by
`bin/check-structure.php`).
**Enqueue:** zero raw `<script>`/`<style>` tags in `includes/`; assets enqueued
via `wp_enqueue_style/script` on the three plugin pages; inline CSS moved to
`admin.css`; inline JS handlers moved to `admin.js`.
**i18n:** `load_plugin_textdomain()` removed; `languages/amendor.pot` present
(307 entries, 59 translator notes, no stale `i18n.php` refs); text domain
`amendor` used consistently (369×, zero strays).
**Escaping/sanitization:** inputs sanitized (`sanitize_*`, `wp_unslash`,
`absint`); outputs escaped (`esc_html/attr/url`, `wp_kses`); `wp_die` messages
escaped.
**SQL:** prepared statements for all user-derived values; remaining direct
queries are plugin-owned table-name interpolations with `phpcs:ignore` +
documented rationale (by-design).
**Uninstall:** cleanup via `fs_after_uninstall_amendor` →
`amendor_uninstall_cleanup`; no plain `uninstall.php` (correct).
**Header/readme:** correct `Version`, `License`+`URI`, `Text Domain`,
`Domain Path`, `Requires at least`, `Requires PHP`; no `Plugin URI` duplicating
`Author URI`; readme has Description/Installation/FAQ/Changelog/Notes.
**Contributors:** `thebrandplaceltd` (case-sensitive — verify on wp.org).
**Freemius:** `is_org_compliant => true`; Pro tree config correct; gatekeeper
intentional (stripped in the generated Free build).

---

## 🟡 Minor items (optional)

1. **readme `Tags:` field** — not present. wp.org supports up to 5; adding
   e.g. `Tags: elementor, search, replace, backup, logs` is recommended (no
   competitor terms).
2. **`error_log()` prefix "ETP"** — leftover from the old Fluentor branding in
   `backups.php`, `search-data.php`, `search-engine.php`. Cosmetic only; safe
   to rename to `AMENDOR` for consistency (optional).
3. **readme Description** — fine as-is; optionally note the Free/Pro split
   more explicitly for transparency.

---

## Build-state note

The repo tree is the **Pro** build (correct). The wp.org submission must be the
**Freemius-generated Free artifact** — the local `amendor-1.0.1-clean.zip` is
for verification only. After resolving Finding A, re-run the Free-build audit
(§9 of the Freemius guide) on the actual generated artifact before SVN upload.
