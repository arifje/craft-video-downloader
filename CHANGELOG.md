# Changelog

## 2.0.1 — 2026-09-11

- Documentation: set the 2.0.0 release date in the changelog.

## 2.0.0 — 2026-09-11

Compatibility, security and reliability release. Craft 4 **and** Craft 5 are now
supported from this single codebase.

### Added
- **Craft 5 support** (`craftcms/cms: ^4.0 || ^5.0`): the asset chip is rendered
  via `app/render-elements` on Craft 5 (`elements/get-element-html` on Craft 4),
  nested Matrix *entries* (`…[entries][uid:…][fields][handle]`) and slideout
  namespaces are recognised, `selectElements()` is awaited (async in Craft 5),
  and static/read-only fields (`allowAdd: false`) are left alone.
- **Authorization** on `download/create`: control-panel access, permission to
  save the target element (when editing one), the field must be reachable from
  that element's field layout (directly or nested via Matrix / Neo / Super
  Table / CKEditor-style containers), and Craft's own `saveAssets:<volume>`
  permission on the destination — with the native exemption for the user's own
  temporary-uploads folder (unsaved elements).
- **Job ownership**: job records store the requesting user; `download/status`
  answers only for that user (404 otherwise, so ids don't leak).
- **Execution-time re-checks** in the queue job: plugin enabled, field still
  allowed by settings, folder still present, user still permitted.
- **SSRF guard**: URLs with embedded credentials are rejected; hosts that are or
  resolve to private/loopback/link-local/reserved addresses (cloud metadata
  included) are refused unless explicitly allow-listed. See the README for the
  deployment-level egress controls that complete this.
- **Output validation** before import: non-empty, within the size cap, a
  recognised AV container, and matching the field's allowed file kinds.
- **Retry safety**: the job implements a TTR sized to the configured timeout
  (no double delivery mid-download), retries at most once, and is idempotent —
  a retry can never create a duplicate asset.
- **Field-limit awareness**: the modal refuses to start or attach when the
  field is at its element limit.
- Executable test suites (`tests/php`, `tests/js`) using a stubbed yt-dlp and a
  mocked CP — no Craft install or network needed — plus a Craft 4/5 integration
  checklist in `tests/craft/INTEGRATION.md`.

### Changed
- **Upload-folder resolution moved to request time** (the editor's own web
  session), stored on the job. Console queue workers no longer depend on a
  logged-in user, and Craft's temporary-uploads behavior for unsaved elements
  is preserved.
- yt-dlp now runs with a `--` separator before the URL, `--socket-timeout`, and
  its stderr is sanitised (paths stripped, truncated) before reaching editors;
  full details go to the logs.
- The metadata probe uses the same shell-free process runner as the download
  (the `mikehaertl/php-shellcommand` dependency is no longer used).
- Status polling backs off after ~20 s and still stops as soon as the modal
  closes or the job finishes.
- Settings-screen field list uses a Craft-4.0-compatible enumeration
  (`getAllFields(false)`), so the plugin no longer relies on `getFieldsByType()`
  (Craft 4.4+).

### Fixed
- Temp-directory cleanup can no longer touch anything outside the plugin's own
  temp base, and failed/timed-out downloads always clean up after themselves.
- Duplicate "Scrape URL" modals from double-clicking the button.
- The button is inserted after the field's own buttons (not appended to the
  row), keeping it in place next to Craft 5.8's inline search input.

### Upgrade notes
- **Drain the queue before upgrading** (`php craft queue/run`): pending 1.x
  download jobs are not compatible with the 2.x job signature.
- **Removed fallback:** 1.x silently fell back to the field's volume root (or
  the first volume in the system) when the upload folder couldn't be resolved.
  2.0 fails with a clear error instead — give such fields a **Default Upload
  Location**.
- **New requirements for editors:** users now need CP access plus
  `Save assets` on the destination volume; downloads into other users' temp
  folders or unauthorized volumes are refused.
- **Private/internal URLs** are now rejected by default. If you intentionally
  scrape an internal host, add it to **Allowed hosts** (that skips the
  private-network check for that host).
- Settings are unchanged — nothing to migrate.

## 1.3.1 — 2026-06-08
- Settings UX: the per-field list is only shown when "Apply to" is set to
  "Only the fields selected below".

## 1.3.0 — 2026-06-08
- New **Video-capable fields only** setting (default on): the button skips
  fields whose allowed file types don't include video.

## 1.2.0 — 2026-06-08
- Support Assets fields nested in Matrix / Neo / Super Table blocks; fields are
  identified by id, and the settings list covers all field contexts.

## 1.1.0 — 2026-06-08
- Live download progress (percent, speed, ETA, bytes) and video metadata
  (title, uploader, duration, resolution, thumbnail) in the modal.

## 1.0.0 — 2026-06-07
- Initial release: "Scrape URL" button on Assets fields, queue-based yt-dlp
  download, client-side attach.
