# Amendor — WordPress.org Compliance Fix Plan

> Task B: Implementation plan to resolve the review team's feedback.
>
> ⚠️ **No code changes have been applied.** This is a plan awaiting your
> approval. The single most important decision is **P0 / Path A vs Path B**
> (below) — everything else is mechanical.

---

## 0. Executive summary

| Priority | Issue | Effort | Risk if skipped |
|---|---|---|---|
| **P0** | Trialware / Pro build on wp.org (choose Path A or B) | Large | **Rejection** |
| P1 | Move inline `<style>` to enqueued CSS | Tiny | Re-review comment |
| P2 | Nonces/permissions | **None needed** (already compliant) | — |
| P3 | Fix `Contributors` in readme | Tiny | Minor comment |
| P4 | Align README "Key Features" with what ships | Small | §9/§12 comment |
| P5 | Remove `load_plugin_textdomain()` | Tiny | Minor comment |
| P6 | Build & upload the correct artifact + version bump | Small | **Rejection** |

**Decision:** **Path B** (chosen 2026-08-17) — keep the Freemius free/pro
split; submit the auto-generated Free build to wp.org. Path A was originally
recommended as lowest-risk, but the freemium funnel is the business model.
Execute Path B's steps rigorously; the residual re-review risk is addressed in
§P0b step 6.

---

## P0. 🔴 Trialware — DECISION REQUIRED (Path A or Path B)

### Path A — Make the wp.org plugin fully free and fully functional *(recommended)*

**Concept:** Remove **all** license gating from the plugin that ships on wp.org.
Every feature (regex, bulk replace, field-key targeting, SEO sources, presets,
log export, Elementor editor) works for every user. Monetization moves to a
**separate "Amendor Pro" plugin hosted on your own site** (Guideline §5
explicitly recommends: *"the use of add-on plugins, hosted outside of
WordPress.org, in order to exclude the premium code"*).

**Code changes (when approved):**

1. **`includes/plugin-context.php`** — delete `amendor_can_use_premium_features()`
   and `amendor_restrict_search_mode()`.
2. **Remove every `is__premium_only()` / `can_use_premium_features()` gate:**
   - `includes/action-handlers.php:144, 288, 421` — delete the `if
     (!amendor_can_use_premium_features()) { … }` blocks that zero out
     `$allowed_fields` / `$bulk_search` / `$bulk_replace`.
   - `includes/admin-main-page.php:60-66, 87, 93, 264, 269, 280, 344, 425, 509` —
     delete the gating `if` blocks; keep the feature code/UI unconditionally.
     Replace `ame_fs()->is__premium_only()` conditions with their inner content.
   - `includes/ajax-handlers.php:77` — always parse `field_keys`.
   - `includes/search-data.php:52-57` — always include SEO sources.
   - `includes/presets.php:257, 316` — remove the premium early-returns.
   - `includes/log-debug-page.php:170-171, 283` and
     `includes/log-history-page.php:143-144, 211` — enable export unconditionally.
   - `includes/elementor-editor.php:33-36` — enqueue the editor tool
     unconditionally (keep the existing `amendor_enable_elementor_editor_integration`
     filter).
3. **`amendor.php` — decide Freemius's fate:**
   - **A1 (cleanest):** remove Freemius from the wp.org plugin entirely (delete
     the `ame_fs()` bootstrap + `vendor/freemius` + related hooks). The wp.org
     plugin is a plain, fully-free plugin.
   - **A2 (keep SDK for upsell only, no locks):** keep the bootstrap but set
     `is_premium => false`, `has_premium_version => false`,
     `has_paid_plans => false`, `is_org_compliant => true`, and **delete the
     `wp_org_gatekeeper` line**. Freemius then only renders Account/Contact
     menus and can point to a separate Pro plugin; it locks nothing. (This is
     the standard "Freemius on wp.org without trialware" config.)
4. **Update `docs/ARCHITECTURE.md` + `bin/check-structure.php`** expectations
   (function inventory changes).
5. **`languages/amendor.pot`** — regenerate after string changes.

**Pros:** guaranteed compliant; nothing for the reviewer to re-flag; README
matches code. **Cons:** loses the "free = teaser" model; if you monetize, the
Pro features must be genuinely new code in a separate plugin, not these ones.

### Path B — Keep the split; submit the Freemius auto-generated FREE build

**Concept:** Keep the current repo (Pro gating intact for the Pro product), but
ensure **only** the Freemius-generated Free build ever reaches wp.org. This is
the flow your `deploy-freemius.yml` + `docs/wp-org-upload-handoff.md` already
describe — it just wasn't followed for the submission.

**Process changes:**
1. **Never zip the repo manually.** The wp.org artifact must come from the
   `amendor-freemius-builds` GitHub Actions artifact (the `free_version` file).
2. **Before uploading, audit the Free build:**
   - `is_premium` must be `false`; `wp_org_gatekeeper` must be absent.
   - Grep the unzipped free tree — must contain **zero** hits for:
     `is__premium_only`, `can_use_premium_code`, `amendor_restrict_search_mode`,
     `amendor_can_use_premium_features`, `gatekeeper`, `Premium`, `Pro` (as a
     gating label).
3. **Reduce residual risk:** because AI reviewers scan for "premium"-looking
   identifiers even when inert, consider deleting `amendor_restrict_search_mode()`
   and `amendor_can_use_premium_features()` from the source before generating the
   free build (the stripper will not remove these helpers because the gating is
   indirect — the functions survive as `return false;`).
4. **Fix the README** (see P4): remove regex / bulk replace / SEO sources /
   presets / log export / Elementor editor from "Key Features" of the wp.org
   plugin (they will not exist in the free build). You may add one line: *"An
   extended Amendor Pro plugin with [features] is available separately."*

**Pros:** preserves the freemium funnel; matches Freemius's supported wp.org
pattern. **Cons:** the free build is significantly less capable; residual
"premium" patterns may draw a re-flag; the review team's warning makes this
path riskier.

> **Decision:** **Path B** (chosen 2026-08-17). Execute Path B steps 1–4
> rigorously and accept the residual re-review risk — the main residual risk
> (helper functions surviving the stripper as inert "premium"-named code) is
> addressed in §P0b step 6.

---

## P0b. Branch & release strategy (Path B — decided)

**Current git reality (verified 2026-08-17):** GitHub has **one** branch —
`main` (at `74a2938`, in sync with local) — and **no tags**. The pre-Freemius
code is **not a separate branch**: it is the earlier history of `main` (last
pre-Freemius commit `a799359`; Freemius integration starts at `c7c1437`).

**Recommended model — one dev branch, two derived builds (same pattern as
WP ASE Pro):**

```mermaid
flowchart LR
    A[main<br/>Pro tree<br/>is_premium=true] -->|tag v1.0.1| B[deploy-freemius.yml]
    B -->|amendor-pro.zip| C[Freemius]
    C --> D[Pro zip → Pro users via Freemius]
    C --> E[Free zip artifact]
    E -->|download + grep scan| F[wp.org SVN trunk + tags]
```

1. **Keep `main` as the single canonical dev branch (the Pro tree).** It holds
   `is_premium => true` + `wp_org_gatekeeper`. All development — including the
   Path B compliance fixes — lands here, because `main` feeds both builds.
2. **Do NOT create a hand-maintained Free branch.** The Free build is
   *generated* by Freemius from the Pro zip on each release
   (`deploy-freemius.yml` already does this). A manual Free branch would:
   - diverge from the generated build (two sources of truth),
   - require re-applying every change twice (cherry-picks/conflicts),
   - risk a missed strip → the exact trialware pend that just happened.
3. **The pre-Freemius commit is history, not a branch.** It predates the
   security round, modularization, backups/presets/SEO, Elementor editor,
   Plugin Check fixes, and the GPL relicense — reviving it as "the Free plugin"
   would regress everything. Optionally snapshot it:
   `git tag legacy/pre-freemius a799359 && git push origin legacy/pre-freemius`.
   Do not develop on it.
4. **Release flow:** merge to `main` → tag `v1.0.1` → workflow builds the Pro
   zip → Freemius generates Free → download the **Free** artifact → scan it
   (step 6) → push to wp.org SVN (`trunk` + `tags/1.0.1`).
5. **GitHub hygiene:** protect `main` (require PRs); short-lived feature
   branches (`fix/wp-org-compliance`); keep `ci.yml` green; no release branches
   needed (tags drive releases).
6. **Path B gating structure (so the generated Free build is clean):**
   - Direct `is__premium_only()` blocks → **stripped cleanly** by Freemius.
     Keep that pattern for UI/feature blocks.
   - Helper functions `amendor_can_use_premium_features()` and
     `amendor_restrict_search_mode()` → **not stripped** (they survive in the
     Free build as `return false;`, and their names grep as
     "premium"/"restrict"). Refactor call sites to direct `is__premium_only()`
     checks and **delete the helpers** so the Free build has zero suspicious
     identifiers.
   - Before every SVN push, grep the generated Free zip:
     `is__premium_only|can_use_premium_code|wp_org_gatekeeper|premium|restrict`
     → expect **zero** hits (P6 step 3).

---

## P0c. Distribution & release mechanics (how Free/Pro updates reach users)

Goal (matches the WP ASE Pro experience): Free installs/updates from wp.org;
Pro installs once from the Freemius account, then updates **in-place as normal
WP plugin updates**. One `main` branch supports all of this — no Free branch.

**Who gets what, from where:**

| User | Installs | Receives updates via |
|---|---|---|
| Free (wp.org) | WP directory | wp.org SVN (you push the Freemius-generated Free zip) |
| Pro (paid) | Freemius account zip (or SDK auto-install) | Freemius SDK updater (`class-fs-plugin-updater.php`) → normal WP update flow |

**How a single release serves both channels (the one-tag flow):**

```mermaid
flowchart LR
    A[main] -->|tag v1.0.1| B[deploy-freemius.yml]
    B --> C[Freemius release id 37047]
    C -->|released| D[Pro users: WP update via SDK updater]
    C -->|generates Free zip| E[download + grep scan]
    E --> F[wp.org SVN trunk + tags/1.0.1]
    F --> G[Free users: WP update via wp.org]
```

**Feature/fix semantics with one branch:**
- **Pro-only feature** → written in `main`, wrapped in `is__premium_only()`;
  included in the Pro zip, stripped by Freemius from the Free zip.
- **Bug fix (shared code)** → fixed once in `main`; flows into **both** builds on
  the next release.
- You cannot ship free-only or pro-only releases independently (one tag = both),
  and you don't need to — that is how every Freemius plugin (incl. ASE Pro)
  works.

**Operational requirements for the "Pro updates in-place" experience:**
1. The Freemius release must be **`released`** (not `pending`) — the workflow
   currently uses `release_mode: pending` (comment: "upload but don't release
   to license holders yet"). Switch to `released` for shipping.
2. Version bump must be identical across `amendor.php` header + `AMENDOR_VERSION`
   + `readme.txt` `Stable tag:` + POT, since both builds share the version.
3. Free artifact push to wp.org SVN is a **manual** step (the workflow only
   uploads artifacts) — do it every release after the grep scan.
4. The SDK already implements the free→Pro handover: `set_basename(true,
   __FILE__)` in `amendor.php` auto-deactivates the Free plugin when Pro is
   active (the "disable/delete free" step you saw in ASE Pro).

---

## P1. Enqueue assets properly (small)

1. **`includes/log-debug-page.php`** — delete the inline `<style>…</style>`
   block at `:468-508` (`.log-level-badge*`, `tr.log-level-*`,
   `.debug_log__options`).
2. **`assets/css/admin.css`** — append the same rules (namespaced under
   `.amendor-*` or the existing pattern; the file is already enqueued on all
   three plugin pages by `amendor_admin_enqueue_scripts()` in
   `admin-chrome.php:148-223`, so the styles will load on the debug-log page).
3. *(Optional, recommended)* move the inline `onclick="return confirm(...)"`
   handlers in `log-debug-page.php:278` and `presets.php:351` into
   `assets/js/admin.js` (data attributes + delegated listeners), using the
   already-localized `amendor_admin_vars` strings.

---

## P2. Nonces & permissions — no changes needed

Verified compliant (see analysis §2.3). During the P0 refactor, **preserve**:
- every form handler verifies its own nonce then calls
  `amendor_current_user_can_manage()`;
- every AJAX handler calls `check_ajax_referer()` then
  `amendor_current_user_can_manage()`;
- `current_user_can()` only inside `amendor_current_user_can_manage()`
  (enforced by `bin/check-structure.php`).

Do a final grep before upload for any handler touching `$_POST`/`$_GET`/`$_REQUEST`
that mutates data without a nonce.

---

## P3. Fix `Contributors` in `readme.txt`

- Change `readme.txt:2` `Contributors: TheBrandPlace` → the exact wp.org
  username(s) of the contributors (review AI indicates the owner account is
  `thebrandplaceltd` — confirm the exact casing on wordpress.org before
  editing). Case-sensitive; comma-separated if multiple.
- Optionally also fix the `Author:` header in `amendor.php` if it doesn't match
  a real wp.org identity (only if the reviewer flagged it — currently only the
  readme was flagged).

---

## P4. Align README with the shipped build

- Under **Path A**: "Key Features" may keep all features (they now all ship).
  Remove any "Pro" / "upgrade to unlock" phrasing.
- Under **Path B**: trim "Key Features" to what exists in the Free build; the
  removed items may be referenced only as *"available in the separate Amendor
  Pro plugin"* (no claim that wp.org users can use them).
- Ensure ≤ 5 tags, no affiliate/competitor tags, no keyword stuffing (§12).

---

## P5. Remove `load_plugin_textdomain()`

- **`includes/i18n.php`** — remove `amendor_load_textdomain()` (and the
  `// Hooked in main plugin file` comment).
- **`amendor.php`** — remove
  `add_action('plugins_loaded', 'amendor_load_textdomain');` and (under A1) the
  `require_once … i18n.php` line.
- Keep `languages/amendor.pot` (WP auto-loads translations for wp.org plugins).
- Re-run the pot generator if any strings change (P0/P1 touch strings).

---

## P6. Build, version, verify, resubmit

1. **Version bump (Guideline §15):** `1.0.0` → `1.0.1` (or `1.1.0`) in all four
   places: `amendor.php` header + `AMENDOR_VERSION` + `readme.txt` `Stable tag:`
   + POT `Project-Id-Version:`. Git tag must match.
2. **Build** per `docs/wp-org-upload-handoff.md` (or the Actions artifact under
   Path B). Single top-level `amendor/` folder, no `__MACOSX`/`.DS_Store`,
   exclude `bin/`, `docs/`, `.github/`, `composer.json`, `phpcs.xml*`.
3. **Pre-upload scan (on the exact zip that will be uploaded):**
   - `grep -rEni 'is__premium_only|can_use_premium_code|restrict_search_mode|gatekeeper|wp_org_gatekeeper|premium' …` → expect **no** hits (Path A) or only known-inert hits (Path B).
   - `grep -rEn '<script|<style' includes/` → no hits (P1 done).
   - `php -l` every PHP file; `node --check` both JS files; run
     `composer run check-structure` if composer is available.
   - Run **Plugin Check** (wp.org checker plugin) → clean at ERROR level.
4. **Manual smoke test on a clean WP 6.4+ install:** activate, run a regex
   search, bulk replace, preset save/apply, log export, Elementor editor tool,
   restore/undo, backup download. Confirm no fatal errors.
5. **Upload** the correct build to wp.org SVN (`trunk` + `tags/1.0.1`).

---

## Suggested reply to the review team (brief, per their instructions)

> Thank you for the review. All issues have been addressed: the wp.org build is
> now fully free and fully functional with all locked/premium-gated features
> removed (no license checks, no trialware); scripts/styles are enqueued
> properly; the Contributors field is corrected; the unnecessary
> `load_plugin_textdomain()` call was removed; and Freemius is configured in
> org-compliant mode with the correct Free build submitted. Version 1.0.1
> uploaded.

(Adjust the first clause if Path B was chosen: "the wp.org build is now the
Freemius-generated Free build with all premium code stripped and no license
gating; the README no longer advertises Pro features…")

---

## Sequencing

1. Get approval on **Path A vs Path B** (the only open question).
2. P0 → P5 code changes in one feature branch (`fix/wp-org-compliance`).
3. Run the structural check + lint + pot regen.
4. P6 build + scan + smoke test.
5. Push, tag `v1.0.1`, deploy Free artifact, upload to wp.org SVN, reply to the
   review thread.
