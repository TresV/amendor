# Freemius Setup & Distribution Guide

> How to integrate Freemius into a Free/Pro WordPress plugin, configure it for
> WordPress.org compliance, and run the release pipeline so **Free** users
> update from wp.org and **Pro** users update in-place from their Freemius
> licenses (no re-downloading).
>
> Generic and self-contained — substitute the placeholders
> (`{PLUGIN_SLUG}`, `{PREFIX}`, `{PLUGIN_ID}`, `{PUBLIC_KEY}`, `{SECRET_KEY}`,
> `{DEV_ID}`, `{VERSION}`, `{SDK_VERSION}`, `{repo-root}`) with your project's
> values. See Appendix A for a real worked example (Amendor).
>
> Companion to the *WordPress.org Plugin Launch Playbook* (§4). Read that first
> for the overall submission rules; this guide is the Freemius deep-dive.

---

## 1. Prerequisites

- A Freemius developer account (<https://dashboard.freemius.com>).
- The plugin already works standalone (no Freemius) — **Free features are the
  default**; Pro adds to them.
- A git repo with CI (GitHub Actions) — strongly recommended for the release
  pipeline in §7.
- Your wp.org submission brief (see the Launch Playbook).

---

## 2. Freemius dashboard setup (one-time)

1. **Create the product** — "Add New Product":
   - Product slug = your wp.org slug for the Free version (`{PLUGIN_SLUG}`).
   - One paid plan (e.g. "Pro") with your pricing.
2. **Note the credentials** (Settings → *Integration* / *API*):
   - `{PLUGIN_ID}` — the numeric product ID.
   - `{PUBLIC_KEY}` — e.g. `pk_…` (public; embedded in the plugin).
   - `{SECRET_KEY}` + `{DEV_ID}` — for the API / GitHub Action (keep private).
3. **Store GitHub secrets** for the deploy workflow (§7):
   `FREEMIUS_PUBLIC_KEY`, `FREEMIUS_DEV_ID`, `FREEMIUS_SECRET_KEY`.
4. Decide the **premium slug** — typically `{PLUGIN_SLUG}-pro`.

---

## 3. SDK integration

### 3.1 Add the SDK

```bash
cd "{repo-root}"
# Pin a specific SDK version; fetch it in CI rather than committing it.
curl -fsSL https://github.com/Freemius/wordpress-sdk/archive/refs/tags/{SDK_VERSION}.zip -o /tmp/fs-sdk.zip
unzip -q /tmp/fs-sdk.zip -d /tmp/fs-sdk
mkdir -p vendor/freemius
cp -R "/tmp/fs-sdk/wordpress-sdk-{SDK_VERSION}/." vendor/freemius/
```

- `start.php` must live at `vendor/freemius/start.php`.
- Add `/vendor/` to `.gitignore` (CI re-fetches the pinned version; the built
  zip **includes** `vendor/`).

### 3.2 Bootstrap (top of the main plugin file)

```php
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( '{PREFIX}_fs' ) ) {
    // SDK already initialized (e.g. Free build active): let Pro take over.
    {PREFIX}_fs()->set_basename( true, __FILE__ );
} else {
    function {PREFIX}_fs() {
        global ${PREFIX}_fs;
        if ( ! isset( ${PREFIX}_fs ) ) {
            require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';
            ${PREFIX}_fs = fs_dynamic_init( array(
                'id'                  => '{PLUGIN_ID}',
                'slug'                => '{PLUGIN_SLUG}',
                'premium_slug'        => '{PLUGIN_SLUG}-pro',
                'type'                => 'plugin',
                'public_key'          => '{PUBLIC_KEY}',
                'is_premium'          => true,          // Pro build
                'premium_suffix'      => '{PLUGIN_SLUG} Pro',
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'is_org_compliant'    => true,          // REQUIRED for wp.org
                // 'wp_org_gatekeeper' => '...',        // Pro build only; stripped in Free
                'menu'                => array(
                    'slug'        => '{PLUGIN_SLUG}',
                    'first-path'  => 'admin.php?page={PLUGIN_SLUG}', // plain path, no query args
                    'support'     => false,
                ),
            ) );
        }
        return ${PREFIX}_fs;
    }
    {PREFIX}_fs();
    do_action( '{PREFIX}_fs_loaded' );
}
```

### 3.3 Config reference

| Parameter | Free build (wp.org) | Pro build (your site) | Why |
|---|---|---|---|
| `id` | `{PLUGIN_ID}` | `{PLUGIN_ID}` | same product |
| `slug` | `{PLUGIN_SLUG}` | `{PLUGIN_SLUG}` | same slug |
| `premium_slug` | `{PLUGIN_SLUG}-pro` | `{PLUGIN_SLUG}-pro` | premium identifier |
| `is_premium` | **`false`** (generator sets it) | `true` | build flag |
| `has_premium_version` | `false` | `true` | free build has no premium |
| `has_paid_plans` | `false` (or `true` w/o locks) | `true` | paid plans on Pro |
| `is_org_compliant` | **`true`** | **`true`** | wp.org-compliant mode |
| `wp_org_gatekeeper` | **absent** (generator strips) | present | enables compliant Free build |

> **You never hand-edit the Free build's config.** You keep `is_premium => true`
> + `wp_org_gatekeeper` on `main` (the Pro tree) and upload the **Pro zip** to
> Freemius; Freemius generates the Free build (strips premium code + the
> gatekeeper, sets `is_premium => false`).

---

## 4. Free/Pro build model

- **One dev branch** (`main`) = the **Pro tree** (`is_premium => true` +
  gatekeeper). All development happens here.
- Freemius **auto-generates the Free build** from every Pro zip you upload.
- **wp.org receives ONLY the generated Free build.**
- **Never** zip the repo manually and upload it to wp.org — that ships the Pro
  tree (the #1 compliance mistake).
- **Never** maintain a hand-written Free branch — it drifts from the generated
  build and risks reintroducing gating.

---

## 5. Gating code so the Free build strips cleanly

Freemius's generator removes **code inside `is__premium_only()` blocks**. Use
that pattern directly; avoid indirection.

### ✅ Do — wrap premium functionality directly

```php
// Pro-only: saved search presets
if ( {PREFIX}_fs()->is__premium_only() ) {
    // ... entire feature implementation ...
}
```

In the Free build the whole block is removed — the feature simply does not
exist (not "locked", absent).

### ❌ Don't — helper functions named `*_premium*`

```php
// BAD: survives the stripper as `return false;` with a greppable name.
function {PREFIX}_can_use_premium_features() {
    return {PREFIX}_fs()->can_use_premium_code();
}
```

The stripper replaces `can_use_premium_code()` with `false` but **keeps the
function** — the Free build ends up containing a `premium`-named function, which
review AI tools re-flag. Delete such helpers and inline the check.

### ❌ Don't — "available in Pro" strings in the free path

```php
// BAD: survives in the Free build's dead code.
if ( ! {PREFIX}_fs()->is__premium_only() ) {
    $messages[] = __( 'This feature is available in Pro.', 'text-domain' );
    return;
}
```

Just `return;` silently — don't leave user-facing "Pro" strings in the free
path.

### Regex / mode guards (defense-in-depth)

```php
// Pro-only search mode: downgrade crafted requests in the Free build.
if ( 'regex' === $mode && ! {PREFIX}_fs()->is__premium_only() ) {
    $mode = 'partial';
}
```

UI options for Pro features should also be inside `is__premium_only()` blocks so
they disappear from the Free build.

---

## 6. Distribution mechanics

| User | Installs | Receives updates via |
|---|---|---|
| **Free** | wp.org directory | wp.org SVN — you push the generated Free zip each release → normal WP plugin update |
| **Pro** | Freemius account zip (or SDK auto-install) after purchase | Freemius **SDK updater** (`class-fs-plugin-updater.php`) → normal WP plugin update in the admin |

```mermaid
flowchart LR
    A[main<br/>Pro tree<br/>is_premium=true] -->|tag v{VERSION}| B[deploy workflow]
    B -->|{PLUGIN_SLUG}-pro.zip| C[Freemius]
    C -->|released| D[Pro users: WP update via SDK updater]
    C -->|generates Free zip| E[download + grep audit]
    E --> F[wp.org SVN trunk + tags/{VERSION}]
    F --> G[Free users: WP update via wp.org]
```

**Feature vs fix semantics:**
- **Pro-only feature** → written in `main` inside `is__premium_only()`; in the
  Pro zip, stripped from the Free zip.
- **Bug fix (shared code)** → fixed once in `main`; flows into **both** builds
  on the next release.
- One tag = one release = both builds. You cannot (and don't need to) ship
  free-only or pro-only versions.

---

## 7. Release pipeline (per version)

1. **Bump version in lockstep**: plugin header `Version:`, version constant,
   readme `Stable tag:`, POT `Project-Id-Version:` — one commit.
2. **Tag** `v{VERSION}` and push. (Deploy workflow triggers on `v*` tags.)
3. Workflow **builds `{PLUGIN_SLUG}-pro.zip`** (rsync staging: exclude
   `.git`, `.github`, `bin`, `docs`, tests, `composer.*`, phpcs configs,
   `*.zip`, `*.log`, `.DS_Store`; **include** `vendor/`, `LICENSE`,
   `readme.txt`, `languages/*.pot`).
4. Workflow **uploads the Pro zip to Freemius** (e.g. `buttonizer/freemius-deploy`
   with `PUBLIC_KEY`/`DEV_ID`/`SECRET_KEY`/`PLUGIN_ID`/`PLUGIN_SLUG`).
   Freemius **auto-generates the Free zip**.
5. **Promote the release to `released`** (not `pending`/`beta`) so Pro license
   holders receive the update via the SDK updater.
6. **Download the Free artifact** from the workflow run.
7. **Audit the Free build** (§9) — zero forbidden patterns.
8. **Push to wp.org SVN** (`trunk` + `tags/{VERSION}`) — this is manual unless
   you automate it; Free users then get the update from wp.org.

---

## 8. Pro updates "in-place" (the ASE-style experience)

- `{PREFIX}_fs()->set_basename( true, __FILE__ )` in the bootstrap makes the
  SDK **auto-deactivate the Free plugin** when Pro is active — the "disable and
  delete the free version" step users expect.
- The SDK's built-in updater checks your Freemius release feed while a license
  is active → WP admin shows the update like any other plugin. **No
  re-downloading from the account.**
- **Operational requirement:** the Freemius release must be `released`
  (pending/beta releases are not pushed to license holders by default).

---

## 9. Compliance audit before wp.org upload (run on the Free zip)

```bash
# Unzip the generated Free build first, then:
grep -rEni 'wp_org_gatekeeper|can_use_premium_code|is__premium_only|premium|restrict|unlock|trial' .   # expect ZERO
grep -rEni 'is_premium[^_].*=>\s*true' .   # expect ZERO (Free build is is_premium => false)
grep -rEn '<script|<style' .              # expect ZERO
```

Allowed to remain: readme/FAQ mentions that "an extended Pro plugin is available
separately" (explicitly permitted by wp.org guidelines).

---

## 10. Common pitfalls

1. **Uploading the Pro build to wp.org** — the review sees `is_premium => true`
   + gatekeeper + gated features → trialware pend. Always submit the generated
   **Free** artifact.
2. **Helper functions named `*_premium*` / `*_restrict*`** — survive the
   stripper as inert code; delete and inline `is__premium_only()`.
3. **`load_plugin_textdomain()`** — not needed for wp.org plugins (WP ≥ 4.6
   auto-loads from `languages/`). Remove it.
4. **Leaving the release `pending`** — Pro users never see the update. Promote
   to `released`.
5. **Version drift** — Free and Pro share one version; keep header + constant +
   `Stable tag` + POT + git tag identical.
6. **`first-path` with query args** — the SDK doesn't convert `@` to `&`; use a
   plain path (`admin.php?page={PLUGIN_SLUG}`) or drop query args.
7. **Uninstall cleanup** — if you moved cleanup to the Freemius
   `after_uninstall` hook, don't re-add a plain `uninstall.php`.
8. **Zip hygiene** — single top-level `{PLUGIN_SLUG}/` folder, no
   `__MACOSX`/`.DS_Store`, exclude dev files.

---

## 11. Release checklist

- [ ] Version bumped in all 4 places + tag created
- [ ] CI built the Pro zip and uploaded to Freemius
- [ ] Freemius release promoted to **`released`**
- [ ] Free artifact downloaded and **grep-audited** (§9) — clean
- [ ] `php -l` + `node --check` + structural checks pass
- [ ] Free zip pushed to wp.org SVN (`trunk` + `tags/{VERSION}`)
- [ ] Smoke-tested: activate Free (wp.org), upgrade path to Pro, Pro in-place update

---

## Appendix A — Worked example (Amendor)

- Product: `id => '37047'`, `slug => 'amendor'`, `premium_slug => 'amendor-pro'`
- SDK: `vendor/freemius/start.php` (SDK 2.13.4, re-fetched in CI)
- SDK function: `ame_fs()`
- Deploy: `.github/workflows/deploy-freemius.yml` — tag-triggered, builds
  `amendor-pro.zip`, uses `buttonizer/freemius-deploy`, uploads generated
  Free + Pro artifacts (`free_version` / `pro_version` outputs)
- wp.org: only the generated **Free** artifact is pushed to SVN (see
  `docs/wp-org-plugin-launch-playbook.md` for the audit + upload steps)
