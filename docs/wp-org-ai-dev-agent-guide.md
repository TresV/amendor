# WordPress.org Compliance — Guide for AI Dev Agents

> Reusable checklist for building WordPress plugins that pass the wp.org
> review **on the first submission**. Written from the Amendor review cycle —
> every item below is a real reason plugins get pended.
>
> Applies to: any plugin destined for wordpress.org. Read this before writing a
> single line of plugin code, and re-run the §8 scan before every upload.

---

## 1. The #1 reason plugins get pended: Trialware (Guideline 5)

**Do NOT** ship wp.org-hosted code with any of these:

- ❌ A license/API-key check that gates a locally-implemented feature.
- ❌ A feature that is present in the code but disabled for "free" users
  (trial, quota, time limit, "pro" flag). *"Even if the locked feature is
  present in the code 'just in case the user upgrades,' it's still not
  allowed."*
- ❌ `if ( $sdk->is__premium_only() ) { … }` / `can_use_premium_code()` gates
  around real functionality.
- ❌ A remote license-validation service whose only job is to unlock local code
  (this is also an illegal **serviceware** pattern — Guideline 6).
- ❌ README text implying users must pay to unlock features that exist in the
  hosted code (Guideline 9).

**The compliant patterns:**

- ✅ **Free = fully functional.** All features in the wp.org plugin work for
  everyone. If you monetize, put *different/extra* features in a **separate
  plugin hosted on your own site** (Guideline 5 explicitly recommends add-on
  plugins hosted outside wp.org).
- ✅ **If you keep a free/pro split via Freemius:** the wp.org submission must
  be the **auto-generated Free build** (see §3), never the Pro build.

**AI-reviewer reality check:** reviewers' AI tools grep for `premium`, `pro`,
`license`, `unlock`, `restrict`, `trial`, `is__premium_only`,
`can_use_premium_code`. Even inert helper functions named `…_premium…` can get
re-flagged. If a string matches, it *will* be looked at. Remove the patterns,
don't just hope they're ignored.

---

## 2. Nonces and permissions — mandatory, no exceptions

Every request handler that reads `$_GET` / `$_POST` / `$_REQUEST` and performs a
state change **must**:

1. Verify a nonce.
2. Verify the user's capability.

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
- Create the nonce server-side (`wp_nonce_field()` in forms, `wp_create_nonce()`
  + `wp_localize_script()` for JS, `wp_nonce_url()` for GET downloads).
- Route capability checks through **one** helper
  (e.g. `my_plugin_current_user_can_manage()`) so reviewers see a single
  `current_user_can()` call site.
- `admin_init` / `admin_post` handlers and download endpoints need nonces too —
  don't forget them.
- Use `check_ajax_referer()` for AJAX, `wp_verify_nonce()` elsewhere.

---

## 3. Freemius on wp.org — the playbook

If you use Freemius, the SDK must run in **org-compliant mode** and the correct
build must be uploaded:

| Parameter | wp.org (Free) build | Pro build (your site) |
|---|---|---|
| `is_premium` | `false` | `true` |
| `has_premium_version` | `false` | `true` |
| `has_paid_plans` | `false` (or `true` only if no local locks) | `true` |
| `is_org_compliant` | `true` | `true` |
| `wp_org_gatekeeper` | **absent** (Freemius strips it) | present |

Rules:
- **Never manually zip the repo** and upload it to wp.org if the repo is the Pro
  tree — that is exactly how Amendor got pended. Upload the **Freemius-generated
  Free artifact** (your CI should produce it and label it `free_version`).
- **Audit the Free zip before upload** (see §8). Verify `is_premium` is `false`,
  no `wp_org_gatekeeper`, and no `is__premium_only` / `can_use_premium_code`
  strings survive.
- Indirect gating via helper functions (e.g. `my_plugin_can_use_premium()`)
  may **survive** the stripper as `return false;` — rename/delete such helpers
  so the free build contains zero "premium" identifiers.
- Freemius's own Account/Contact menus are fine when no local feature is locked.

---

## 4. Enqueue assets — never print raw `<script>` / `<style>`

- Static JS/CSS → `wp_enqueue_script()` / `wp_enqueue_style()` on
  `admin_enqueue_scripts` (admin) or `wp_enqueue_scripts` (front end), with a
  version (use `filemtime()` in dev, the plugin version in release).
- Inline JS/CSS → `wp_add_inline_script()` / `wp_add_inline_style()`.
- Pass data to JS → `wp_localize_script()`.
- Only load on the pages that need the asset (check `$hook_suffix` /
  `get_current_screen()`).
- Avoid `onclick="..."` / inline `style=` handlers where practical; move them
  to the enqueued JS/CSS.

```php
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( 'toplevel_page_my-plugin' !== $hook ) { return; }
    wp_enqueue_style( 'my-plugin-admin', plugins_url( 'assets/admin.css', __FILE__ ), [], '1.0.1' );
    wp_enqueue_script( 'my-plugin-admin', plugins_url( 'assets/admin.js', __FILE__ ), [ 'jquery' ], '1.0.1', true );
    wp_localize_script( 'my-plugin-admin', 'my_plugin_vars', [ 'nonce' => wp_create_nonce( 'my_plugin_action' ) ] );
} );
```

---

## 5. i18n & textdomain

- **Do NOT call `load_plugin_textdomain()`** in a wp.org plugin (WP ≥ 4.6
  auto-loads translations from `languages/`). If you must keep it for
  non-wp.org distribution, hook it to `init`, never `plugins_loaded`.
- Include `languages/<slug>.pot` in the zip.
- Use `__()`, `esc_html__()`, `esc_attr__()`, `esc_html_e()`, `sprintf()` with
  `/* translators: … */` comments on every placeholder string.
- Use ordered placeholders (`%1$s`, `%2$d`) when a string has multiple args.
- Use `esc_html()`/`esc_attr()`/`esc_url()` on **every** output.

---

## 6. readme.txt correctness

- **`Contributors:`** — exact, case-sensitive **wp.org usernames** (not display
  names, not company names). Missing or mismatched names get flagged.
- **≤ 5 tags**; no competitor/affiliate tags; no keyword stuffing (§12).
- `Stable tag:` must equal the plugin header `Version:` (Guideline §15).
- `Requires at least` / `Tested up to` / `Requires PHP` must match reality.
- Do **not** advertise features in the wp.org plugin's readme that the wp.org
  build doesn't actually contain (e.g. features stripped from a free build).
  Mentioning a separate premium plugin is fine.
- Readmes are for people, not bots.

---

## 7. GPL & license (Guidelines 1–2)

- Plugin + **every bundled file** (SDKs, libraries, images, fonts, icons) must
  be GPL-compatible. Verify licenses before bundling.
- Keep a `LICENSE` file and consistent `License:` / `License URI:` in the header
  and readme.
- Code must be human-readable — no obfuscation, no minified-only PHP, no
  `eval`, no base64-hidden payloads (Guideline 4).
- Use WordPress's bundled libraries instead of shipping your own (jQuery,
  PHPMailer, SimplePie, etc.) (Guideline 13).
- Don't load executable code from third-party servers; keep JS/CSS local
  (Guideline 8). No tracking without explicit opt-in (Guideline 7).
- No forced "Powered by" credits on the front end (Guideline 10); no admin
  hijacking / non-dismissible nags (Guideline 11).

---

## 8. Pre-submission scan (run on the EXACT zip you will upload)

```bash
# 1. Pro/trialware patterns — expect ZERO hits (or only documented inert ones)
grep -rEni 'is__premium_only|can_use_premium_code|wp_org_gatekeeper|gatekeeper|premium|unlock|trial' .

# 2. Raw script/style tags — expect ZERO
grep -rEn '<script|<style' .

# 3. License-check / remote service calls
grep -rEni 'license|api[_-]?key|activation[_-]?key|remote|curl|wp_remote_(get|post)' .

# 4. Security scan — expect ZERO unguarded mutations
grep -rEn '\$_POST|\$_GET|\$_REQUEST' .   # every hit must have a nearby nonce + capability check
grep -rEn '\beval\s*\(|base64_decode|system\s*\(|exec\s*\(' .

# 5. Sanity
php -l <every php file>
node --check assets/*.js
```

Also verify:
- Zip has exactly **one top-level folder** named after the slug.
- No `__MACOSX/`, `.DS_Store`, `.git/`, `bin/`, `docs/`, `.github/`,
  `composer.json`, `phpcs.xml*` in the zip.
- Version bumped (header + constant + `Stable tag` + POT) since the last upload.
- **Plugin Check** (the official wp.org checker plugin) passes at ERROR level.
- Activation on a clean install produces no fatal errors.

---

## 9. The 18 guidelines — quick reference

| # | Guideline (short) | Watch out for |
|---|---|---|
| 1 | GPL-compatible | every bundled file's license |
| 2 | Dev responsible for contents | know your licenses & 3rd-party ToS |
| 3 | Stable version on wp.org | don't distribute elsewhere as primary |
| 4 | Human-readable code | no obfuscation/minified PHP |
| 5 | **No trialware** | **no locked/limited local features** |
| 6 | Serviceware OK if real service | no license-validation-only services |
| 7 | No tracking w/o consent | opt-in, documented in readme |
| 8 | No executable code via 3rd parties | local JS/CSS, no remote updates |
| 9 | No dishonest behavior | no "pay to unlock" implications, no fake reviews |
| 10 | No forced external credits | opt-in only, default off |
| 11 | Don't hijack the admin | dismissible notices, sparse upsells |
| 12 | Readmes must not spam | ≤5 tags, no keyword stuffing |
| 13 | Use WP's default libraries | don't bundle jQuery etc. |
| 14 | Avoid frequent commits | SVN = releases only |
| 15 | Bump version each release | header + Stable tag + tag |
| 16 | Complete plugin at submission | real zip, no name-reserving |
| 17 | Respect trademarks | no other brands in the slug |
| 18 | Directory can enforce | exceptions exist; don't argue |

---

## 10. Decoding the review email

Common phrases and what they mean:

| Review phrase | What they actually found | Fix |
|---|---|---|
| "locked or restricted built-in functionality" | license/trial gating in the code | §1 |
| "Even if the locked feature is present 'just in case the user upgrades'" | gating code shipped to wp.org | §1, §3 |
| "Use wp_enqueue commands" | raw `<script>`/`<style>` in output | §4 |
| "Nonces and user permissions before processing" | missing nonce/cap check (or generic template) | §2 |
| "You haven't added yourself to Contributors" | readme Contributor isn't a real wp.org username | §6 |
| "Freemius is not set as compliant" | wrong Freemius config or Pro build uploaded | §3 |
| "load_plugin_textdomain() is not needed" | explicit textdomain call in a wp.org plugin | §5 |
| "Code must be (mostly) human readable" | obfuscated/minified code | §7 |
| "You may not restrict or lock functionality" | Guideline 5 blocker | §1 |

---

## 11. Golden rules (tl;dr)

1. **wp.org ships fully-free, fully-functional code.** Locked local features =
   rejection. Premium = separate plugin hosted elsewhere.
2. **Freemius is a trap:** `is_org_compliant=true`, upload the **Free** build,
   audit it with grep, and never zip the Pro tree for wp.org.
3. **Every handler:** nonce + capability, or don't ship it.
4. **Enqueue everything**; no raw `<script>`/`<style>` tags.
5. **No `load_plugin_textdomain()`** in wp.org plugins.
6. **README must match the actual shipped build** — including `Contributors`
   (real wp.org usernames) and the feature list.
7. **Scan the exact zip** (not the repo) before every upload, then run Plugin
   Check and a clean-install smoke test.
