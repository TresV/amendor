# Amendor — WordPress.org Review Feedback Analysis

> Task A: Compare the Plugin Review Team's feedback against the official
> [WordPress.org Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/),
> and verify each claim against the actual codebase.
>
> Date: 2026-08-17 · Status: **Feedback is aligned with the guidelines and
> verified in the code.** All 🔴 issues are real and must be fixed before
> resubmission.

---

## 1. Verdict (TL;DR)

| # | Review finding | Maps to guideline | Verified in code? | Severity |
|---|---|---|---|---|
| 1 | **Trialware / locked features** | §5 Trialware, §1 GPL, §9 dishonesty, §6 serviceware | ✅ Yes — extensive Freemius gating | 🔴 **BLOCKER** |
| 2 | **Use `wp_enqueue` for JS/CSS** | Developer expectations / WP best practice (review checklist) | ✅ Yes — raw `<style>` block at `log-debug-page.php:468` | 🔴 Fix |
| 3 | **Nonces & user permissions** | Developer expectations ("made as secure as possible") | ✅ **Already compliant** — all handlers verified | 🟢 No action |
| 4 | **Contributors list** | readme metadata rule (Contributors = wp.org usernames) | ✅ Yes — `readme.txt:2` uses non-matching name | 🟠 Fix |
| 5 | **Freemius not org-compliant** | §5 Trialware + Freemius `is_org_compliant` doc | ⚠️ Partially — `is_org_compliant` is `true`, but the **wrong build (Pro) was uploaded** | 🔴 **BLOCKER** |
| 6 | **`load_plugin_textdomain()` unnecessary** | WP ≥4.6 auto-loads translations (make/core i18n) | ✅ Yes — `i18n.php:17` | 🟡 Fix |

**Bottom line:** The feedback aligns with the official guidelines. Every 🔴
finding is confirmed in the repo. The single root cause of the two blockers is
the **Free/Pro build mix-up**: a build containing Freemius Pro gating was
submitted to wp.org instead of the Freemius-generated Free build (or a
fully-free build). This must be resolved before replying.

---

## 2. Issue-by-issue analysis

### 2.1 🔴 Trialware / locked features — CONFIRMED (Blocking)

**Guideline reference:**
- **§5 Trialware:** *"Plugins may not contain functionality that is restricted
  or locked, only to be made available by payment or upgrade… Even if the locked
  feature is present in the code 'just in case the user upgrades,' it's still
  not allowed. All plugin code hosted on WordPress.org must be free and fully
  functional."*
- **§1 GPL:** all hosted code must convey the four freedoms; license-gated code
  does not.
- **§9:** *"Implying users must pay to unlock included features"* is listed as a
  dishonest practice.
- **§6:** a service whose *"sole purpose [is] validating licenses or keys while
  all functional aspects of the plugin are included locally is not permitted."*

**What the review team flagged (verbatim, ✨ AI):**

> Code includes locally implemented features that are intentionally disabled
> behind Freemius license checks, including regex search downgraded in
> `includes/plugin-context.php::amendor_restrict_search_mode()`, bulk
> replace/field-key targeting stripped in `includes/action-handlers.php`, SEO
> sources hidden in `includes/search-data.php`, presets gated in
> `includes/presets.php`, log export gated in `includes/log-debug-page.php` and
> `includes/log-history-page.php`, and the Elementor editor tool blocked in
> `includes/elementor-editor.php`.

**Verified in the code — every claim checks out.** The gating is implemented via
two helpers and scattered `is__premium_only()` checks:

| File | Location | What is gated |
|---|---|---|
| `includes/plugin-context.php` | `:68` `amendor_can_use_premium_features()` (`ame_fs()->can_use_premium_code()`) | Central license check |
| `includes/plugin-context.php` | `:82` `amendor_restrict_search_mode()` | Downgrades `regex` → `partial` for non-Pro |
| `includes/action-handlers.php` | `:142`, `:288`, `:421` | Strips `$allowed_fields` (field-key targeting) + `$bulk_search`/`$bulk_replace` for non-Pro |
| `includes/admin-main-page.php` | `:60-66`, `:87`, `:93`, `:264`, `:269`, `:280`, `:344`, `:425`, `:509` | Strips bulk-replace UI, regex UI + tips, presets UI, field-key UI |
| `includes/ajax-handlers.php` | `:71-77` | Strips `field_keys` from AJAX payloads for non-Pro |
| `includes/search-data.php` | `:52-57` | Hides `seo_title` / `seo_description` content sources |
| `includes/presets.php` | `:257`, `:316` | Blocks preset save/delete/export/import + hides presets box |
| `includes/log-debug-page.php` | `:170-171`, `:283` | Blocks CSV/JSON/TXT export + hides export buttons |
| `includes/log-history-page.php` | `:143-144`, `:211` | Blocks export + hides export buttons |
| `includes/elementor-editor.php` | `:33-36` | Blocks the in-editor tool entirely |

**Interpretation:** This is precisely the "even if the locked feature is present
'just in case the user upgrades'" scenario the guidelines prohibit. Whether the
plugin *intends* to strip these via Freemius's free-build generator is
irrelevant — **the code that was reviewed contained the locked features**, so the
submission is non-compliant as-is.

---

### 2.2 🔴 Use `wp_enqueue` for JS/CSS — CONFIRMED

**Guideline reference:** not a numbered guideline; part of the review team's
technical checklist and the Plugin Check tool ("enqueued assets"). WordPress
best practice: register/enqueue scripts & styles via `wp_enqueue_script()` /
`wp_enqueue_style()` on the correct hook (`admin_enqueue_scripts` / `wp_enqueue_scripts`),
and inline JS/CSS via `wp_add_inline_script()` / `wp_add_inline_style()`.

**Verified:** `includes/log-debug-page.php:468` opens a raw `<style>` block in
the middle of page output containing `.log-level-badge*`, `tr.log-level-*`, and
`.debug_log__options` rules, closing at `:508`.

Note: the plugin already enqueues `assets/css/admin.css` + `assets/js/admin.js`
on all three plugin pages via `amendor_admin_enqueue_scripts()`
(`includes/admin-chrome.php:148-223`). The fix is simply to move this CSS into
`admin.css` (it is not currently there — verified by grep) and delete the
inline block.

Minor related item (not explicitly flagged): inline `onclick="return
confirm(...)"` handlers at `log-debug-page.php:278` and `presets.php:351`. Not a
hard violation, but moving them into `admin.js` is cleaner.

---

### 2.3 🔴 Nonces and user permissions — ALREADY COMPLIANT 🟢

**Guideline reference:** Developer Expectations — *"All code in the directory
should be made as secure as possible."* The review email is the standard generic
template; **no specific finding was cited for Amendor.**

**Verified — every data-mutating entry point checks both nonce and capability:**

| Entry point | Nonce | Capability |
|---|---|---|
| Restore action | `amendor_restore_nonce` (`action-handlers.php:33`) | `amendor_current_user_can_manage()` (`:26`) |
| Undo action | `amendor_undo_nonce` (`:72`) | `:66` |
| Search action | `amendor_search_nonce` (`:169`) | `:162` |
| Preview action | `amendor_preview_nonce` (`:231`) | `:219` |
| Replace action | `amendor_replace_nonce` (`:441`) | `:428` |
| Presets (save/delete/export/import) | `amendor_presets_nonce` (`presets.php:264`) | `:262` |
| Clear debug log | `_wpnonce` → `amendor_clear_debug_log_nonce` (`log-debug-page.php:154`) | `:149` |
| Debug log export | `_wpnonce` → `amendor_export_debug_log` (`:177`) | page-level |
| History export | `_wpnonce` → `amendor_export_history_log` (`log-history-page.php:149`) | page-level |
| Backup download (`admin_init`) | `amendor_download_nonce` (`backups.php:332`) | `:340` |
| Onboarding dismiss | `_wpnonce` (`admin-main-page.php:35`) | page-level |
| AJAX: backup check | `check_ajax_referer('amendor_check_backup')` (`ajax-handlers.php:15`) | `:20` |
| AJAX: search batch | `check_ajax_referer('amendor_run_search_batch')` (`:46`) | `:50` |
| AJAX: search results | `check_ajax_referer('amendor_get_search_results')` (`:91`) | `:95` |

All route through the central `amendor_current_user_can_manage()` helper
(`plugin-context.php:54`, `manage_options`), which the repo's structural check
(`bin/check-structure.php`) enforces as the only allowed `current_user_can()`
call site.

**Action:** none required. Keep this pattern intact during the trialware
refactor — do not weaken it.

---

### 2.4 🟠 Contributors list — CONFIRMED

**Guideline reference:** readme `Contributors` parameter is a **case-sensitive,
comma-separated list of WordPress.org usernames** that contributed to the code.

**Verified:** `readme.txt:2` → `Contributors: TheBrandPlace`. The review AI
reports the wp.org username of the plugin owner (account "thebrandplaceltd") is
not "TheBrandPlace". So the contributor entry does not match any real wp.org
username.

**Action:** set `Contributors:` to the actual wp.org username(s) with exact
case (confirm: `thebrandplaceltd`). If unsure, log into wp.org and check the
profile URL slug. Leaving it empty is also acceptable.

---

### 2.5 🔴 Freemius org-compliance — CONFIRMED (as a build problem)

**Guideline reference:** §5 Trialware + Freemius's `is_org_compliant` mode,
which makes the SDK strip premium code and behave per wp.org rules.

**Verified in `amendor.php:42-66` (`fs_dynamic_init`):**
- `is_org_compliant => true` ✅ (already set — the email's claim that the
  compatibility flag is "not enabled" is technically inaccurate)
- `is_premium => true` ⚠️ (Pro-build flag)
- `has_premium_version => true`, `has_paid_plans => true` ⚠️
- `wp_org_gatekeeper => 'OA7#...'` ⚠️ (this is the Pro-build gatekeeper token,
  commented "Automatically removed in the free version")

**Root cause analysis:** The **Pro build was submitted to wp.org** instead of the
Freemius auto-generated **Free** build. The repo's own docs confirm the intended
flow:
- `docs/wp-org-upload-handoff.md:§3.2` — *"upload the **Free** build to wp.org —
  never the Pro build."*
- `.github/workflows/deploy-freemius.yml` — builds `amendor-pro.zip`, uploads to
  Freemius, and Freemius auto-generates the Free zip (artifact
  `amendor-freemius-builds`); the comment says the Free one goes to wp.org SVN.

The reviewed code (with `is_premium => true` + gatekeeper + all the gating from
§2.1) matches the **Pro** tree, i.e. the wrong artifact was uploaded (or the
repo source was zipped manually). This is both a compliance failure and a
process failure.

**Fix direction (choose one — see the fix-plan doc):**
- **Path A (recommended):** the wp.org plugin becomes a fully-free, fully-
  functional plugin with **all gating removed**; any paid "Pro" lives in a
  separate plugin hosted off wp.org.
- **Path B:** keep the Freemius split, but submit **only** the auto-generated
  Free build (`is_premium => false`, no gatekeeper, all premium code stripped),
  and verify by grep that no gating patterns survive.

---

### 2.6 🟡 `load_plugin_textdomain()` — CONFIRMED

**Guideline reference:** not a numbered guideline; a WordPress 4.6+ behavior
([i18n improvements](https://make.wordpress.org/core/2024/10/21/i18n-improvements-6-7/)).
For wp.org-hosted plugins, WordPress automatically loads translations from the
plugin's `languages/` folder; the explicit call is unnecessary and can even load
translations too early.

**Verified:** `includes/i18n.php:17` calls
`load_plugin_textdomain(AMENDOR_TEXT_DOMAIN, false, ...'/languages/')`, hooked on
`plugins_loaded` from `amendor.php`.

**Action:** remove the function and the hook. The plugin requires WP ≥ 6.4, so
auto-loading is guaranteed. Keep `languages/amendor.pot`. (If the same code is
also shipped outside wp.org and you want to keep the call, it must be moved to
`init` — but for wp.org, removal is the correct fix.)

---

## 3. Additional findings (not in the review, found during audit)

These are not required fixes but should be considered to avoid a second round:

1. **README advertises stripped features.** `readme.txt` "Key Features" lists
   regex matching, bulk replace, CSV/JSON/TXT export, SEO sources, presets and
   the Elementor editor tool. Under **Path B** (free build strips all of these),
   the README would advertise functionality that does not exist in the hosted
   plugin — misleading and a §12/§9 risk. Must be reconciled with whichever path
   is chosen.
2. **Residual "premium" identifiers.** Even if the Freemius stripper neutralizes
   `amendor_can_use_premium_features()` / `amendor_restrict_search_mode()`, the
   function *names* still contain "premium"/"restrict" and an AI reviewer could
   re-flag them. Under Path B, prefer removing/renaming these helpers in the
   free build.
3. **Inline JS handlers** (`onclick="return confirm(...)"` in
   `log-debug-page.php:278`, `presets.php:351`) — move to `admin.js` for
   cleanliness.
4. **Version bump needed** (Guideline §15): the resubmission must bump
   `Version:` / `AMENDOR_VERSION` / `Stable tag:` / POT consistently (e.g.
   `1.0.1`), and every changed file must be in the SVN commit.
5. **Zip hygiene** (from repo memory): wp.org zip must have a single top-level
   `amendor/` folder, no `__MACOSX/`, no `.DS_Store`, exclude `bin/`, `docs/`,
   `.github/`, `composer.json`, `phpcs.xml*` — and the whole `vendor/freemius`
   SDK **is** included (GPL-compatible, verified earlier).
6. **GPL** (§1): already fine — plugin is GPL-2.0-or-later and the bundled
   Freemius SDK is GPL-compatible. No action.

---

## 4. Overall assessment

The review team's feedback is **consistent with the official guidelines** and
every flagged item is **reproducible in this codebase**. Two items are
out-and-out blockers (trialware gating + Pro build on wp.org) and share one root
cause. One item (nonces/permissions) is already fully handled — no changes
there. The remaining items are small, mechanical fixes.

Proceed to `docs/wp-org-compliance-fix-plan.md` for the implementation plan and
`docs/wp-org-ai-dev-agent-guide.md` for the reusable agent guidance.
