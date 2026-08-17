# WordPress.org Plugin Launch Playbook

> **The single source of truth** for shipping a WordPress plugin to wordpress.org:
> compliant on the first submission, with a sustainable Free/Pro distribution
> model and a repeatable upload process.
>
> Generic and self-contained — substitute the placeholders
> (`{plugin-slug}`, `{version}`, `{main-plugin-file}`, `{VERSION_CONST}`,
> `{TEXT_DOMAIN}`, `{repo-root}`) with your project's values. You do not need
> prior knowledge of the project's history.
>
> Read it **before writing plugin code**, and re-run **§5.4 (pre-submission
> scan)** before every upload.

---

## 1. How to use this playbook

| Phase | Section |
|---|---|
| Before writing code | §2 rules · §3 compliance must-dos · §4 Freemius |
| Every release | §4.2 distribution flow · §5 build & upload |
| Just before upload | §5.3 verification · §5.4 scan · §5.5 checklist |
| After a review email | §6 decoding the review |

---

## 2. The 18 guidelines in one page

| # | Guideline (short) | Watch out for |
|---|---|---|
| 1 | GPL-compatible | every bundled file's license |
| 2 | Dev responsible for contents | know your licenses & 3rd-party ToS |
| 3 | Stable version on wp.org | don't distribute elsewhere as primary |
| 4 | Human-readable code | no obfuscation / minified-only PHP |
| 5 | **No trialware** | **no locked/limited local features** |
| 6 | Serviceware OK if real service | no license-validation-only services |
| 7 | No tracking w/o consent | opt-in, documented in readme |
| 8 | No executable code via 3rd parties | local JS/CSS, no remote updates |
| 9 | No dishonest behavior | no "pay to unlock" implications, no fake reviews |
| 10 | No forced external credits | opt-in only, default off |
| 11 | Don't hijack the admin | dismissible notices, sparse upsells |
| 12 | Readmes must not spam | ≤ 5 tags, no keyword stuffing |
| 13 | Use WP's default libraries | don't bundle jQuery, etc. |
| 14 | Avoid frequent commits | SVN = releases only |
| 15 | Bump version each release | header + Stable tag + tag |
| 16 | Complete plugin at submission | real zip, no name-reserving |
| 17 | Respect trademarks | no other brands in the slug |
| 18 | Directory can enforce | exceptions exist; don't argue |

Full text: <https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/>

---

## 3. Compliance must-dos — why plugins get pended

### 3.1 Trialware — the #1 killer (Guideline 5)

**Do NOT ship wp.org-hosted code with any of these:**

- ❌ A license/API-key check that gates a locally-implemented feature.
- ❌ A feature present in the code but disabled for "free" users (trial, quota,
  time limit, "pro" flag). *"Even if the locked feature is present in the code
  'just in case the user upgrades,' it's still not allowed."*
- ❌ `if ( $sdk->is__premium_only() ) { … }` / `can_use_premium_code()` gates
  around real functionality.
- ❌ A remote license-validation service whose only job is to unlock local code
  (also an illegal **serviceware** pattern — Guideline 6).
- ❌ README text implying users must pay to unlock features that exist in the
  hosted code (Guideline 9).

**The compliant patterns:**

- ✅ **Free = fully functional.** All features in the wp.org plugin work for
  everyone. If you monetize, put *different/extra* features in a **separate
  plugin hosted on your own site** (Guideline 5 explicitly recommends add-on
  plugins hosted outside wp.org).
- ✅ **If you keep a free/pro split via Freemius:** the wp.org submission must
  be the **auto-generated Free build** (§4), never the Pro build.

**AI-reviewer reality check:** reviewers' AI tools grep for `premium`, `pro`,
`license`, `unlock`, `restrict`, `trial`, `is__premium_only`,
`can_use_premium_code`. Even inert helper functions named `…_premium…` can get
re-flagged. If a string matches, it *will* be looked at. Remove the patterns,
don't hope they're ignored.

### 3.2 Nonces & permissions — mandatory, no exceptions

Every request handler that reads `$_GET` / `$_POST` / `$_REQUEST` and performs a
state change **must** verify a nonce **and** the user's capability:

```php
// Form handler (POST):
function my_plugin_save() {
    if ( ! isset( $_POST['my_nonce'] )
        || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['my_nonce'] ) ), 'my_plugin_save_action' ) ) {
        wp_die( esc_html__( 'Invalid nonce.', 'my-plugin' ) );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'my-plugin' ) );
    }
    // ... do the work ...
}

// AJAX handler:
function my_plugin_ajax() {
    check_ajax_referer( 'my_plugin_ajax_action', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'my-plugin' ) ], 403 );
    }
    // ... do the work ...
    wp_die();
}
add_action( 'wp_ajax_my_plugin_ajax', 'my_plugin_ajax' );
```

Best practices:
- Create nonces server-side: `wp_nonce_field()` (forms), `wp_create_nonce()` +
  `wp_localize_script()` (JS), `wp_nonce_url()` (GET downloads).
- Route capability checks through **one** helper (e.g.
  `my_plugin_current_user_can_manage()`) so reviewers see a single
  `current_user_can()` call site.
- `admin_init` / `admin_post` handlers and download endpoints need nonces too.
- Use `check_ajax_referer()` for AJAX, `wp_verify_nonce()` elsewhere.
- Sanitize every input (`sanitize_text_field()`, `sanitize_key()`, `absint()`,
  `intval()`, `wp_unslash()` first), escape every output (`esc_html()`,
  `esc_attr()`, `esc_url()`).

### 3.3 Enqueue assets — never print raw `<script>` / `<style>`

- Static JS/CSS → `wp_enqueue_script()` / `wp_enqueue_style()` on
  `admin_enqueue_scripts` (admin) or `wp_enqueue_scripts` (front end), with a
  version (plugin version; `filemtime()` is fine in dev).
- Inline JS/CSS → `wp_add_inline_script()` / `wp_add_inline_style()`.
- Pass data to JS → `wp_localize_script()`.
- Only load on the pages that need the asset (`$hook_suffix` /
  `get_current_screen()`).
- Avoid inline `onclick="…"` / `style=` handlers where practical; move them to
  the enqueued JS/CSS.

```php
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( 'toplevel_page_my-plugin' !== $hook ) { return; }
    wp_enqueue_style( 'my-plugin-admin', plugins_url( 'assets/admin.css', __FILE__ ), [], '1.0.1' );
    wp_enqueue_script( 'my-plugin-admin', plugins_url( 'assets/admin.js', __FILE__ ), [ 'jquery' ], '1.0.1', true );
    wp_localize_script( 'my-plugin-admin', 'my_plugin_vars', [ 'nonce' => wp_create_nonce( 'my_plugin_action' ) ] );
} );
```

### 3.4 i18n & textdomain

- **Do NOT call `load_plugin_textdomain()`** in a wp.org plugin (WP ≥ 4.6
  auto-loads translations from `languages/`). If you must keep it for
  non-wp.org distribution, hook it to `init`, never `plugins_loaded`.
- Include `languages/{plugin-slug}.pot` in the zip.
- Use `__()`, `esc_html__()`, `esc_attr__()`, `esc_html_e()`, `sprintf()` with
  `/* translators: … */` comments on every placeholder string.
- Use ordered placeholders (`%1$s`, `%2$d`) when a string has multiple args.

### 3.5 readme.txt correctness

- **`Contributors:`** — exact, case-sensitive **wp.org usernames** (not display
  names, not company names). Mismatched names get flagged.
- **≤ 5 tags**; no competitor/affiliate tags; no keyword stuffing (§12).
- `Stable tag:` must equal the plugin header `Version:` (Guideline §15).
- `Requires at least` / `Tested up to` (current WP release) / `Requires PHP`
  must match reality.
- Do **not** advertise features in the readme that the wp.org build doesn't
  actually contain (e.g. features stripped from a Free build). Mentioning a
  separate premium plugin is fine.
- Readmes are written for people, not bots.

### 3.6 GPL & license (Guidelines 1–2)

- Plugin + **every bundled file** (SDKs, libraries, images, fonts, icons) must
  be GPL-compatible. Verify licenses before bundling.
- Keep a `LICENSE` file and consistent `License:` / `License URI:` in the
  header and readme.
- Code must be human-readable — no obfuscation, no `eval`, no base64-hidden
  payloads (Guideline 4).
- Use WordPress's bundled libraries instead of shipping your own (jQuery,
  PHPMailer, SimplePie, etc.) (Guideline 13).
- No executable code loaded from third-party servers; keep JS/CSS local
  (Guideline 8). No tracking without explicit opt-in (Guideline 7).

---

## 4. Freemius (Free/Pro) playbook

*Only if you use a Freemius free/pro split. If your plugin is fully free, skip
to §5.*

> Deep-dive setup guide (dashboard, SDK, config, release pipeline):
> **`docs/freemius-setup-and-distribution.md`**

### 4.1 SDK config that keeps you compliant

| Parameter | wp.org (Free) build | Pro build (your site) |
|---|---|---|
| `is_premium` | `false` | `true` |
| `has_premium_version` | `false` | `true` |
| `has_paid_plans` | `false` (or `true` only if no local locks) | `true` |
| `is_org_compliant` | `true` | `true` |
| `wp_org_gatekeeper` | **absent** (Freemius strips it) | present |

Rules:
- **Never manually zip the repo and upload it to wp.org if the repo is the Pro
  tree.** Upload the **Freemius-generated Free artifact** (your CI should
  produce it and label it `free_version`). This is the single most common
  compliance mistake.
- **Audit the Free zip before upload** (§5.4): verify `is_premium` is `false`,
  no `wp_org_gatekeeper`, and no `is__premium_only` / `can_use_premium_code`
  strings survive.
- Indirect gating via helper functions (e.g. `my_plugin_can_use_premium()`)
  may **survive** the stripper as `return false;` — rename/delete such helpers
  so the Free build contains zero "premium" identifiers.
- Direct `is__premium_only()` blocks **are stripped cleanly** by Freemius —
  prefer that pattern for gating.
- Freemius's own Account/Contact menus are fine when no local feature is locked.

### 4.2 Distribution mechanics — how Free/Pro updates reach users

**One dev branch, two derived builds** — this is how WP ASE Pro works and it is
the model to copy:

| User | Installs | Receives updates via |
|---|---|---|
| Free (wp.org) | WP directory | wp.org SVN (you push the Freemius-generated Free zip) |
| Pro (paid) | Freemius account zip (or SDK auto-install) | Freemius SDK updater (`class-fs-plugin-updater.php`) → normal WP update flow |

```mermaid
flowchart LR
    A[main<br/>Pro tree<br/>is_premium=true] -->|tag v{version}| B[deploy-freemius.yml]
    B -->|{plugin-slug}-pro.zip| C[Freemius]
    C -->|released| D[Pro users: WP update via SDK updater]
    C -->|generates Free zip| E[download + grep scan]
    E --> F[wp.org SVN trunk + tags/{version}]
    F --> G[Free users: WP update via wp.org]
```

### 4.3 Feature vs fix semantics with one branch

- **Pro-only feature** → written in `main`, wrapped in `is__premium_only()`;
  included in the Pro zip, stripped by Freemius from the Free zip.
- **Bug fix (shared code)** → fixed once in `main`; flows into **both** builds
  on the next release.
- One tag = one release = both builds. You cannot (and don't need to) ship
  free-only or pro-only releases independently.
- **Do not maintain a hand-written Free branch.** The Free build is generated
  by Freemius; a manual branch drifts from the generated build, needs every
  change applied twice, and risks reintroducing gating — the exact thing that
  gets plugins pended.
- The pre-Freemius state of your plugin is history on `main`, not a branch. If
  you want a snapshot, tag it (`legacy/pre-freemius`); don't develop on it.

### 4.4 Operational requirements for in-place Pro updates

1. The Freemius release must be **`released`** (not `pending`/`beta`) for
   license holders to receive updates.
2. Version must be in lockstep across `{main-plugin-file}` header +
   `{VERSION_CONST}` + readme `Stable tag:` + POT + git tag (both builds share
   the version).
3. Free artifact push to wp.org SVN is **manual** each release (download →
   scan → commit to `trunk` + `tags/{version}`).
4. The SDK bootstrap should include `set_basename(true, __FILE__)` so the Free
   plugin is auto-deactivated when Pro is active (the "disable/delete free"
   step Pro users expect).

---

## 5. Build & upload handoff

### 5.1 Pre-flight checks (do these first)

- **Version sync** — release version identical in all of: `{main-plugin-file}`
  header `Version:`, `{VERSION_CONST}`, `readme.txt` `Stable tag:`, the POT
  `Project-Id-Version:`, and the git tag you will push.
- **License** — GPL-compatible and consistent across `LICENSE`, plugin header
  `License:` / `License URI:`, `readme.txt`, and `composer.json`.
- **Plugin URI** — wp.org flags "same plugin/author URI". Either omit
  `Plugin URI` from the main file header, or use a URL that genuinely differs
  from `Author URI`.
- **Free/Pro split** — if the SDK bootstrap sets `is_premium => true` and a
  `wp_org_gatekeeper` value, that is the **Pro** build. The wp.org build must
  be the SDK's **auto-generated Free** build (§4).

### 5.2 Build the ZIP (never use Finder "Compress")

```bash
cd "{repo-root}"
STAGE="$(mktemp -d)"
rm -rf "$STAGE/{plugin-slug}"
mkdir -p "$STAGE/{plugin-slug}"
rsync -a \
  --exclude '.git' \
  --exclude '.github' \
  --exclude 'bin' \
  --exclude 'docs' \
  --exclude 'tests' \
  --exclude 'composer.json' \
  --exclude 'composer.lock' \
  --exclude 'phpcs.xml*' \
  --exclude '*.zip' \
  --exclude '*.log' \
  --exclude '.DS_Store' \
  ./ "$STAGE/{plugin-slug}/"
cd "$STAGE"
zip -qr -X {plugin-slug}.zip {plugin-slug}
mv "$STAGE/{plugin-slug}.zip" "{repo-root}/{plugin-slug}-{version}-clean.zip"
```

Notes:
- `zip -X` strips extra attributes; Finder-created archives inject `__MACOSX/`
  junk — avoid them. Alternative: `ditto -c -k --sequesterRsrc {plugin-slug} {plugin-slug}.zip`.
- The archive **must** contain exactly **one top-level folder** named
  `{plugin-slug}/`.
- **Keep** in the zip: the main plugin file, all runtime code (`includes/`,
  `assets/`, etc.), bundled runtime dependencies (e.g. an SDK under `vendor/`),
  `LICENSE`, `readme.txt`, and `languages/*.pot`.
- **Exclude** dev-only files: `composer.json`/lock, phpcs configs, `bin/`,
  `docs/`, `tests/`, `.github/`, `.git/`.

### 5.3 Verification commands

```bash
# PHP lint: main file + every included PHP file
php -l {main-plugin-file}
find includes -name '*.php' -exec php -l {} \;

# JS syntax (if any bundled JS)
node --check assets/js/admin.js
node --check assets/js/editor.js

# Any repo-specific structural check script
php bin/check-structure.php .   # if present

# Zip hygiene
unzip -l {plugin-slug}.zip | grep -c '__MACOSX'   # expect 0
unzip -l {plugin-slug}.zip | grep -c 'DS_Store'   # expect 0
unzip -l {plugin-slug}.zip | awk 'NR>3 {print $4}' | awk -F/ 'NF==2 && $2!="" {print $1}' | sort -u   # expect only {plugin-slug}
```

- Run the wp.org **Plugin Check** tool (or the Plugin Check plugin) → must have
  **no ERROR-level findings**. WARNINGs that are by-design / false positives
  are acceptable — document them so a reviewer understands why.

### 5.4 Pre-submission scan (run on the EXACT zip you will upload)

```bash
# 1. Pro/trialware patterns — expect ZERO hits (or only documented inert ones)
grep -rEni 'is__premium_only|can_use_premium_code|wp_org_gatekeeper|gatekeeper|premium|unlock|trial' .

# 2. Raw script/style tags — expect ZERO
grep -rEn '<script|<style' .

# 3. License-check / remote service calls
grep -rEni 'license|api[_-]?key|activation[_-]?key|remote|curl|wp_remote_(get|post)' .

# 4. Security scan — every $_POST/$_GET/$_REQUEST hit must have a nearby nonce
#    + capability check; no eval/base64/exec
grep -rEn '\$_POST|\$_GET|\$_REQUEST' .
grep -rEn '\beval\s*\(|base64_decode|system\s*\(|exec\s*\(' .

# 5. Sanity
php -l <every php file>
node --check assets/*.js
```

### 5.5 Acceptance checklist (check all before upload)

**Archive structure**
- [ ] Single top-level folder `{plugin-slug}/` (files are NOT at zip root)
- [ ] No `__MACOSX/` entries
- [ ] No `.DS_Store` files
- [ ] No dev-only files: `composer.json`, `composer.lock`, phpcs configs, `bin/`, `docs/`, `tests/`, `.github/`, `.git/`

**Required contents inside `{plugin-slug}/`**
- [ ] Main plugin file with correct header: `Version`, GPL-compatible `License` +
      `License URI`, `Text Domain`, `Domain Path`, `Requires at least`,
      `Requires PHP`; no `Plugin URI` duplicating `Author URI`
- [ ] `readme.txt`: Contributors (real wp.org usernames), `Stable tag`,
      `Tested up to` (current WP), `Requires PHP`, `License`, FAQ, Changelog
- [ ] `LICENSE` (GPL-compatible)
- [ ] All runtime code + assets
- [ ] `languages/*.pot`
- [ ] Bundled runtime dependencies (SDK etc.)

**Code / build health**
- [ ] `php -l` clean on all PHP files
- [ ] `node --check` passes on all bundled JS
- [ ] Structural checks pass (if the repo has one)
- [ ] Version in sync everywhere (header == constant == Stable tag == pot == git tag)
- [ ] Free/Pro: the wp.org build has no `wp_org_gatekeeper` line and
      `is_premium => false`
- [ ] Plugin Check: no ERROR-level findings

**Housekeeping**
- [ ] Remove or replace any stale zip sitting at the repo root
- [ ] Do not commit the zip (ensure `*.zip` is gitignored)

### 5.6 Known gotchas (learned the hard way — do not repeat)

1. **Zip root folder**: if files sit at the archive root (no plugin-slug
   folder), the wp.org upload form rejects the zip. Always stage into a folder
   named after the slug before zipping.
2. **macOS junk**: Finder-created zips inject `__MACOSX/` entries plus
   `.DS_Store` files. Use `zip -qr -X` or `ditto --sequesterRsrc`.
3. **Pro vs Free build**: uploading a Pro build to wp.org is wrong — the
   gatekeeper line and premium gating must be stripped (the SDK does this
   automatically in the generated Free build).
4. **Uninstall cleanup location**: if cleanup moved into an SDK/lifecycle hook
   (e.g. Freemius `after_uninstall`), do not re-add a plain `uninstall.php`.
5. **Version bumps**: bump the header + version constant + `Stable tag` + pot in
   **one commit**, then tag `vX.Y.Z` so the tag matches what the plugin/SDK
   reports (deploy workflows resolve the version from the tag).
6. **readme.txt format**: it must use the wp.org header format exactly, and
   `Tested up to` should reflect the current WordPress release.

---

## 6. Decoding the review email

| Review phrase | What they actually found | Fix |
|---|---|---|
| "locked or restricted built-in functionality" | license/trial gating in the code | §3.1, §4 |
| "Even if the locked feature is present 'just in case the user upgrades'" | gating code shipped to wp.org | §3.1, §4 |
| "Use wp_enqueue commands" | raw `<script>`/`<style>` in output | §3.3 |
| "Nonces and user permissions before processing" | missing nonce/cap check (or generic template) | §3.2 |
| "You haven't added yourself to Contributors" | readme Contributor isn't a real wp.org username | §3.5 |
| "Freemius is not set as compliant" | wrong Freemius config or Pro build uploaded | §4 |
| "`load_plugin_textdomain()` is not needed" | explicit textdomain call in a wp.org plugin | §3.4 |
| "Code must be (mostly) human readable" | obfuscated/minified code | §3.6 |
| "You may not restrict or lock functionality" | Guideline 5 blocker | §3.1 |

---

## 7. Files typically involved

| Path | Why |
| --- | --- |
| `{main-plugin-file}` | header (version, license, text domain), version constant, SDK bootstrap |
| `readme.txt` | wp.org metadata + changelog |
| `LICENSE` | GPL license text |
| `.github/workflows/deploy-*.yml` | source of the official Free/Pro artifacts |
| `languages/*.pot` | translation template |
| `docs/wp-org-upload-handoff.md` | this document (when kept in-repo) |

---

## 8. Golden rules (tl;dr)

1. **wp.org ships fully-free, fully-functional code.** Locked local features =
   rejection. Premium = separate plugin hosted elsewhere, or a clean
   Freemius-generated Free build.
2. **Freemius is a trap:** `is_org_compliant=true`, upload the **Free** build,
   audit it with grep, and never zip the Pro tree for wp.org.
3. **Every handler:** nonce + capability, or don't ship it.
4. **Enqueue everything**; no raw `<script>`/`<style>` tags.
5. **No `load_plugin_textdomain()`** in wp.org plugins.
6. **README must match the actual shipped build** — including `Contributors`
   (real wp.org usernames) and the feature list.
7. **Scan the exact zip** (not the repo) before every upload, then run Plugin
   Check and a clean-install smoke test.
8. **One `main` branch feeds both builds.** Free/Pro is a release artifact
   question, not a git-branch question.
