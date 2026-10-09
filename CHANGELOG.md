# Changelog

## 2.3.0 - 2026-10-09

### Fixed
- **YouTube downloads failing with "HTTP Error 403: Forbidden"** while the
  options loaded fine. YouTube requires yt-dlp to solve a JavaScript
  challenge; the plugin now passes a JS runtime (`--js-runtimes deno:<path>`)
  and the EJS solver (`--remote-components ejs:github`) for YouTube URLs, the
  same approach craft-website-scraper uses.
- Failed jobs no longer retry silently. A retry kept the queue row "Reserved"
  after the editor had already been told the download failed (and for field
  downloads a successful retry created an asset nobody attached). Jobs are now
  single-attempt; just click Download again.

### Added
- **JS runtime (YouTube)** setting: empty auto-detects Deno (PATH,
  `~/.deno/bin`, `/home/*/.deno/bin`, `/usr/local/bin`, `/usr/bin`), or a full
  path / bare name / env var such as `$VIDEO_DOWNLOADER_JS_RUNTIME`. The
  settings page shows which runtime is in use, or warns when none is found.
- **Cookies file** setting (env-aware) for sign-in or bot-check gated videos.
- Failure hints: yt-dlp errors now come with the likely fix (install Deno,
  update yt-dlp, add a cookies file, resolution limit).

### Upgrade notes
- For YouTube, install Deno on the server and set
  `VIDEO_DOWNLOADER_JS_RUNTIME` to its full path (see README, "YouTube:
  install Deno"). Keep yt-dlp current with `yt-dlp -U`.

## 2.2.1 - 2026-10-09

### Changed
- Download tool: "Show options" now shows a visible working state (spinner,
  "Reading video information…", elapsed seconds, and a note when a site is
  slow) and locks the URL field until the options load.
- Download tool labels: "MP4 for Photos" is now "MP4" and the phone button
  "Save to Photos" is now "Save Video".

## 2.2.0 - 2026-10-09

### Added
- **Video Downloader CP page** (new nav item): paste a URL, see every
  resolution the post offers (dimensions, fps, estimated size, H.264
  availability), choose a format (MP4 for Photos, Best quality, Audio only)
  and download the result to your own device. On phones a **Save to Photos**
  button opens the native share sheet with the file; desktops download
  automatically. Resolutions above the Max resolution ceiling or the size cap
  are shown but disabled.
- **Download tool** setting (on by default) to switch the page off.
- Finished tool downloads are stored outside the web root, served only to the
  user who started them, and deleted after 24 hours.
- `tests/craft/e2e.sh`: end-to-end check of the tool against a real Craft
  install (verified on Craft 4.18.9 and 5.11.3), plus jsdom tests for the
  page and new offline PHP tests for format summarising, selectors, ceiling
  clamping and storage containment.

### Access
- Uses Craft's built-in **Access Video Downloader** permission (registered
  automatically for plugins with a CP section). Admins have it; grant it to
  other user groups to give them the tool. The action endpoints enforce the
  same permission, and the queue job re-checks it before downloading.


## 2.1.0 — 2026-10-05

### Added
- **Max resolution** setting (default **1080**): a resolution ceiling applied as
  an orientation-aware profile — 1080 allows up to 1920x1080 landscape AND
  1080x1920 portrait (Reels/TikTok/Shorts) while blocking 4K. Accepts an env
  var reference (e.g. `$VIDEO_DOWNLOADER_MAX_RESOLUTION`); empty or 0 disables
  the cap; an unresolvable value falls back to 1080 (fail-closed). While a
  ceiling is active the plugin builds a hard-capped yt-dlp selector (pre-merged
  mp4 first, every fallback capped — a video only available above the limit
  fails cleanly instead of silently exceeding it) and the **Format** setting is
  not used.

### Changed
- Downloads are now capped at the 1080 profile **by default**. Set Max
  resolution to 0 (or empty) to restore the previous unlimited behavior with
  your own Format selector.
- The README config-file example now uses `craft\helpers\App::env()` instead of
  `getenv()`.

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
