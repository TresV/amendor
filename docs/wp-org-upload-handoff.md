# Handoff: Prepare a WordPress plugin for WordPress.org upload

> Generic, self-contained brief. Read it fully before acting. It applies to any
> plugin repo — substitute the placeholders (`{plugin-slug}`, `{version}`,
> `{main-plugin-file}`, `{VERSION_CONST}`, `{repo-root}`) with the actual values
> for the project you're working on. You do not need prior knowledge of the
> project's history.

## 1. Goal

Produce a **clean, upload-ready ZIP** of the plugin for submission to
WordPress.org and verify it passes the acceptance checklist in §5.

If the plugin has a **Free/Pro split** (e.g. via the Freemius SDK), upload the
**Free** build to wp.org — never the Pro build (see §3.2).

## 2. Pre-flight checks (do these first)

- **Version sync** — confirm the release version is identical in all of:
  - `{main-plugin-file}` header `Version:`
  - the version constant (e.g. `{VERSION_CONST}`)
  - `readme.txt` `Stable tag:`
  - the POT file `Project-Id-Version:`
  - the git tag you will push
- **License** — the plugin must be GPL-compatible. Verify consistency across the
  `LICENSE` file, plugin header `License:` / `License URI:`, `readme.txt`, and
  `composer.json`.
- **Plugin URI** — wp.org flags "same plugin/author URI". Either omit `Plugin URI`
  from the main file header, or use a URL that genuinely differs from `Author URI`.
- **Free/Pro split** — if the SDK bootstrap sets `is_premium => true` and a
  `wp_org_gatekeeper` value, that is the **Pro** build. The wp.org build must be
  the SDK's **auto-generated Free** build.

## 3. Build the ZIP

### 3.1 Clean staging + zip (never use Finder "Compress")

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

### 3.2 Free/Pro (Freemius-style) plugins only

- **Preferred source:** the deploy workflow's generated **Free** artifact
  (it strips Pro gating + the `wp_org_gatekeeper` line automatically).
- **Fallback (local build):** take the Pro tree and remove the
  `wp_org_gatekeeper` line from the SDK bootstrap, then set `is_premium => false`
  (and related premium flags) to match what the SDK's generator produces.
- Do **not** hand-edit Pro feature gating — the SDK controls it.

## 4. Verification commands

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
  **no ERROR-level findings**. WARNINGs that are by-design / false positives are
  acceptable — document them so a reviewer understands why.

## 5. Acceptance checklist (check all before upload)

**Archive structure**
- [ ] Single top-level folder `{plugin-slug}/` (files are NOT at zip root)
- [ ] No `__MACOSX/` entries
- [ ] No `.DS_Store` files
- [ ] No dev-only files: `composer.json`, `composer.lock`, phpcs configs, `bin/`, `docs/`, `tests/`, `.github/`, `.git/`

**Required contents inside `{plugin-slug}/`**
- [ ] Main plugin file with correct header: `Version`, GPL-compatible `License` +
      `License URI`, `Text Domain`, `Domain Path`, `Requires at least`, `Requires PHP`;
      no `Plugin URI` duplicating `Author URI`
- [ ] `readme.txt`: Contributors, `Stable tag`, `Tested up to` (current WP),
      `Requires PHP`, `License`, FAQ, Changelog
- [ ] `LICENSE` (GPL-compatible)
- [ ] All runtime code + assets
- [ ] `languages/*.pot`
- [ ] Bundled runtime dependencies (SDK etc.)

**Code / build health**
- [ ] `php -l` clean on all PHP files
- [ ] `node --check` passes on all bundled JS
- [ ] Structural checks pass (if the repo has one)
- [ ] Version in sync everywhere (header == constant == Stable tag == pot == git tag)
- [ ] Free/Pro: the wp.org build has no `wp_org_gatekeeper` line and `is_premium => false`
- [ ] Plugin Check: no ERROR-level findings

**Housekeeping**
- [ ] Remove or replace any stale zip sitting at the repo root
- [ ] Do not commit the zip (ensure `*.zip` is gitignored)

## 6. Known gotchas (learned the hard way — do not repeat)

1. **Zip root folder**: if files sit at the archive root (no plugin-slug folder),
   the wp.org upload form rejects the zip. Always stage into a folder named after
   the slug before zipping.
2. **macOS junk**: Finder-created zips inject `__MACOSX/` entries plus `.DS_Store`
   files. Use `zip -qr -X` or `ditto --sequesterRsrc`.
3. **Pro vs Free build**: uploading a Pro build to wp.org is wrong — the
   gatekeeper line and premium gating must be stripped (the SDK does this
   automatically in the generated Free build).
4. **Uninstall cleanup location**: if cleanup moved into an SDK/lifecycle hook
   (e.g. Freemius `after_uninstall`), do not re-add a plain `uninstall.php`.
5. **Version bumps**: bump the header + version constant + `Stable tag` + pot in
   **one commit**, then tag `vX.Y.Z` so the tag matches what the plugin/SDK reports
   (deploy workflows resolve the version from the tag).
6. **readme.txt format**: it must use the wp.org header format exactly, and
   `Tested up to` should reflect the current WordPress release.

## 7. Files typically involved

| Path | Why |
| --- | --- |
| `{main-plugin-file}` | header (version, license, text domain), version constant, SDK bootstrap |
| `readme.txt` | wp.org metadata + changelog |
| `LICENSE` | GPL license text |
| `.github/workflows/deploy-*.yml` | source of the official Free/Pro artifacts |
| `docs/wp-org-upload-handoff.md` | this document |
