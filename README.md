# Craft Video Downloader

A Craft CMS plugin that adds a **“Scrape URL”** button to your Assets fields. Paste a link to a social-media video, and the server downloads it with [**yt-dlp**](https://github.com/yt-dlp/yt-dlp) on Craft's queue and attaches the resulting video to the field — right next to **Add** / **Upload files**.

Compatible with **Craft CMS 4 and Craft CMS 5** from a single codebase (`^4.0 || ^5.0`).

![The Scrape URL button sits next to Add / Upload files on an Assets field.](#)

---

## How it works

1. The plugin adds a **Scrape URL** button to Assets fields in the control panel (all of them, or a chosen list) — including fields nested inside **Matrix / Neo / Super Table** blocks.
2. Clicking it opens a modal: paste a video URL and click **Show options**. The modal lists every available resolution (dimensions, fps, estimated size; anything above your limits is shown but disabled) and lets you choose **MP4** (H.264) or **Best quality**. The highest allowed resolution is pre-selected; click **Download**.
3. On submit the plugin authorizes the request (see [Permissions & security](#permissions--security)), resolves the field's upload folder exactly like a manual upload would, and queues a job that runs `yt-dlp` and creates an Asset from the result.
4. While it runs, the modal shows the video's **title, uploader, duration, resolution and thumbnail** plus a **live progress bar** (percent, speed, ETA, downloaded / total).
5. When the download finishes, the new video is dropped into the field automatically.
6. **Save** the entry as usual to keep the relation.

The asset is created in exactly the folder/volume the field's **Upload files** button would use (it honours the field's *Restrict / Default Upload Location* settings, including the user's temporary-uploads folder for unsaved elements). The destination is resolved and authorized **at request time**, in the editor's own web session — so console queue workers never guess, and there is no fallback to "some volume": if the destination can't be resolved you get a clear error instead.

---

## Download tool (CP nav item)

Besides the button on Assets fields, the plugin adds a **Video Downloader** page to the control-panel navigation for downloading a video straight to your own device (nothing is added to your Assets).

1. Paste a URL (on a phone, the **Paste** button reads your clipboard) and tap **Show options**. While yt-dlp reads the post (a few seconds, up to a minute on slow sites) a status row with a spinner and elapsed time is shown.
2. The plugin asks yt-dlp what the post offers and lists **every available resolution**, with dimensions, frame rate, an estimated file size and whether an H.264 stream exists. Resolutions above your **Max resolution** ceiling or over the **Max file size** are listed but disabled, with the reason.
3. Pick a format:
   - **MP4** (default): H.264 video + AAC audio, the combination iPhone and Android photo libraries import.
   - **Best quality**: highest quality in any codec (VP9, AV1), merged to MP4 (or MKV). May not import into Photos.
   - **Audio only**: the soundtrack, M4A when available.
4. Tap **Download**. The download runs on the queue with the same live progress bar as the field button.
5. When it's ready:
   - **On a phone**: tap **Save Video**. This opens the native share sheet with the video; choose **Save Video** there to put it in your photo library. (Browsers can't write to the photo library directly; the share sheet is the supported route. It needs iOS 15+ Safari or a current Android Chrome.)
   - **On a desktop**: the file downloads automatically, and **Download file** is there as a fallback.

Finished files are kept under `storage/video-downloader/files/` (never web-accessible), can only be fetched by the user who started the download, and are deleted after 24 hours.

**Access.** Admins always have it. For other users, grant Craft's built-in **Access Video Downloader** permission (Settings, Users, user group or user, under *Access the control panel*). The tool can be switched off entirely with the **Download tool** setting; the nav item then disappears and the page returns 404. The same safeguards as the field button apply: SSRF guard, allowed hosts, resolution ceiling, size cap and timeout.

---

## Requirements

- Craft CMS 4 or Craft CMS 5 (PHP 8.0.2+ for Craft 4; Craft 5 itself requires PHP 8.2+)
- [**yt-dlp**](https://github.com/yt-dlp/yt-dlp) installed on the server
- **ffmpeg** recommended on the same server (only needed when yt-dlp has to merge separate video + audio streams)
- PHP's `proc_open` must not be disabled (check `disable_functions` in `php.ini`)

---

## Installation

### From Packagist

```bash
composer require arifje/craft-video-downloader
php craft plugin/install video-downloader
```

### From the GitHub repo (before it's on Packagist)

Add the repo to your project's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/arifje/craft-video-downloader" }
]
```

Then:

```bash
composer require arifje/craft-video-downloader:^2.0
php craft plugin/install video-downloader
```

---

## Configuration

Settings live at **Settings → Plugins → Video Downloader**:

| Setting | Default | Notes |
|---|---|---|
| **Enabled** | on | Master switch for the button. |
| **Download tool** | on | Shows the **Video Downloader** CP page. Non-admins also need the **Access Video Downloader** permission. |
| **Apply to** | All Assets fields | Or limit to a chosen list of fields. |
| **Fields** | – | The Assets fields that get the button when “Apply to” is set to a list. |
| **Video-capable fields only** | on | Only show the button on fields that accept video (no file-type restriction, or *Video* among the allowed types). Leave on to skip image-only fields. |
| **yt-dlp path** | `yt-dlp` | Absolute path, or an env var like `$VIDEO_DOWNLOADER_YTDLP`. |
| **JS runtime (YouTube)** | *(auto-detect Deno)* | Runtime yt-dlp uses to solve YouTube's challenge. Full path (e.g. `/home/deploy/.deno/bin/deno`), a bare name, or an env var like `$VIDEO_DOWNLOADER_JS_RUNTIME`. The settings page shows which runtime is in use. |
| **Cookies file** | *(none)* | Optional cookies.txt from a logged-in browser, for sign-in or bot-check gated videos. Env var like `$VIDEO_DOWNLOADER_COOKIE_FILE`. |
| **Max resolution** | `1080` | Resolution ceiling as a profile (short side): 1080 allows up to 1920x1080 landscape and 1080x1920 portrait while blocking 4K. Accepts an env var like `$VIDEO_DOWNLOADER_MAX_RESOLUTION`. Empty or `0` = no limit. |
| **Format** | `mp4/bestvideo*+bestaudio/best` | yt-dlp `-f` selector, only used when **Max resolution** is empty/0 (a ceiling builds its own capped selector). The default avoids needing ffmpeg unless a merge is unavoidable. |
| **Max file size** | 500 MB | Hard cap (`--max-filesize`). |
| **Timeout** | 300 s | Wall-clock limit for the yt-dlp process. |
| **Allowed hosts** | *(any)* | Optional allow-list, one hostname per line. Sub-domains match too. |

### YouTube: install Deno

YouTube only hands out video streams after a JavaScript challenge is solved, and yt-dlp needs a JS runtime for that. Without one, the options load fine but the download fails with **"HTTP Error 403: Forbidden"**. **Deno** is the runtime that works reliably.

```bash
# as the user php-fpm and the queue worker run as (or system-wide)
curl -fsSL https://deno.land/install.sh | sh     # installs ~/.deno/bin/deno
yt-dlp -U                                        # keep yt-dlp current; YouTube changes often
```

The plugin auto-detects Deno on PATH, in `~/.deno/bin`, `/home/*/.deno/bin`, `/usr/local/bin` and `/usr/bin`. php-fpm's PATH usually doesn't include `~/.deno/bin`, so it's best to set it explicitly:

```bash
# .env
VIDEO_DOWNLOADER_JS_RUNTIME=/home/deploy/.deno/bin/deno
```

and set **JS runtime (YouTube)** to `$VIDEO_DOWNLOADER_JS_RUNTIME`. The settings page then shows "Using: deno:/home/deploy/.deno/bin/deno". For YouTube URLs the plugin passes `--js-runtimes deno:<path>` and `--remote-components ejs:github` (yt-dlp fetches its challenge solver from GitHub). If YouTube still refuses ("Sign in to confirm you're not a bot"), the server IP is being challenged: export a cookies.txt from a logged-in browser and set **Cookies file**.

Failed downloads now explain the likely fix (install Deno, update yt-dlp, add cookies) next to the yt-dlp error.

### Capping resolution from `.env`

Out of the box downloads are capped at the **1080 profile** (no 4K). The cap is orientation-aware: it admits landscape up to 1920x1080 *and* portrait up to 1080x1920 (Reels, TikTok, Shorts), instead of a naive height cap that would reject portrait HD. While a ceiling is active the plugin builds a hard-capped yt-dlp selector in which **every** fallback carries the cap, so a video only available above the limit fails cleanly rather than silently exceeding it; the **Format** setting applies only when the ceiling is off.

To drive the value from `.env`, set the **Max resolution** field to the env var once:

```
$VIDEO_DOWNLOADER_MAX_RESOLUTION
```

and per environment:

```bash
# .env
VIDEO_DOWNLOADER_MAX_RESOLUTION=1080   # 720 / 1440 also sensible; 0 = no limit
```

If the referenced env var is missing, the cap falls back to 1080 (fail-closed).

### Overriding from a config file

Like any Craft plugin, these can be overridden per-environment with a `config/video-downloader.php` file (handy for keeping the binary path and limits out of the database):

```php
<?php

use craft\helpers\App;

return [
    'ytDlpPath'     => App::env('VIDEO_DOWNLOADER_YTDLP') ?? '/usr/local/bin/yt-dlp',
    'maxResolution' => App::env('VIDEO_DOWNLOADER_MAX_RESOLUTION') ?? '1080',
    'maxFilesizeMb' => 750,
    'timeout'       => 600,
    'allowedHosts'  => "youtube.com\nyoutu.be\ntiktok.com\ninstagram.com",
];
```

---

## The queue

Downloads run on Craft's queue. If you run a dedicated worker it'll feel instant:

```bash
php craft queue/listen
```

Without a worker, Craft drains the queue during later control-panel requests, so the download still completes — the modal just keeps polling until it does.

> The upload folder is resolved and authorized when you click **Download** — in your own web session — and travels with the job, so a console worker never needs a logged-in user and never falls back to an unintended volume. Before doing any work, the job re-checks that the plugin is still enabled, the field is still allowed, the folder still exists, and you are still permitted to upload to it.

---

## Permissions & security

**Who can use it.** Requests require a logged-in user with **control-panel access**; per download the user must also be allowed to **save the element being edited** (when one is given, its field layout must actually contain the field — directly or nested), and must hold Craft's own **`Save assets`** permission on the destination volume (the user's temporary-uploads folder for unsaved elements is exempt, exactly like Craft's native upload). All of this is re-checked when the queued job executes, so revoking access between enqueue and execution takes effect. Job status is **owner-scoped**: only the user who started a download can poll it.

**Download hygiene.**

- URLs must be http(s), ≤ 2048 chars, without embedded credentials.
- Hosts that are — or resolve to — **private, loopback, link-local or reserved addresses** (cloud metadata endpoints included) are rejected. Adding a host to **Allowed hosts** is treated as informed consent and bypasses this check for that host.
- yt-dlp is invoked with an argv array (no shell) and the URL always follows a `--` separator so it can never be parsed as an option; socket and wall-clock timeouts plus `--max-filesize` bound every run.
- Downloaded output is validated before import (non-empty, within the size cap, a recognised AV container) and checked against the field's **allowed file kinds**; filenames are sanitised by yt-dlp (`--restrict-filenames`) and Craft.
- Temp files live in per-job directories under Craft's temp path and are always cleaned up — the cleaner refuses to touch anything outside the plugin's own temp base.
- Editor-facing errors are truncated and stripped of server paths; full details go to Craft's logs.

> ⚠️ **Deployment-level controls still matter.** Host validation happens before yt-dlp runs; yt-dlp itself follows redirects and fetches extractor-discovered media URLs that cannot be re-validated from PHP (redirects, DNS rebinding, multi-URL extractors). If your threat model includes malicious editors, run the queue worker with **egress filtering** (block RFC1918/link-local/metadata ranges at the network level) and keep **Allowed hosts** configured. Also note the plugin can't prevent a user from downloading content they shouldn't — it downloads what an authorized editor asks for.

---

## Notes & limitations

- **Nested fields:** Assets fields inside **Matrix (Craft 4 blocks and Craft 5 nested entries), Neo, Super Table** and slideout editors are supported — the server identifies the field by id, so sub-field handles don't need to be unique. On Craft 5, per-layout **handle overrides** are what the "only these fields" list matches against in the editor.
- **Large/slow posts:** bounded by **Max file size** and **Timeout**; tune both for your sources.
- **Field limits:** the modal refuses to start (and to attach) when the field is already at its element limit.

---

## Tests

Two executable suites live in `tests/` and run with **no Craft installation and no network** (downloads are mocked with a stub yt-dlp):

```bash
php tests/php/run.php                      # URL/SSRF guard, download pipeline, cleanup, JobStore, tool formats + storage
cd tests/js && npm install && npm test     # CP JS: field button + modal options step + download tool
```

`tests/live/selectors.sh` (needs Docker and network; simulate only, nothing is downloaded) runs the plugin's real format selectors through a current yt-dlp and prints which format each resolution and preset picks, for a portrait and a landscape video. Re-run it when a platform changes its formats.

An end-to-end script drives a real Craft install over HTTP (logins, CP page, nav item, permissions, inspect, queued download, owner-only file delivery, the disable switch) with the stub yt-dlp:

```bash
tests/craft/e2e.sh ~/Development/Claude/general-craft-4-test-container
tests/craft/e2e.sh ~/Development/Claude/general-craft-5-test-container
```

Checks that require a real Craft 4 **and** Craft 5 installation (permissions, folder resolution, asset creation, end-to-end) are documented as a checklist in [`tests/craft/INTEGRATION.md`](tests/craft/INTEGRATION.md).

---

## Local development

Point a test Craft 4 or Craft 5 site at a local clone:

```json
"repositories": [
    { "type": "path", "url": "../craft-video-downloader" }
]
```

```bash
composer require arifje/craft-video-downloader:@dev
php craft plugin/install video-downloader
```

Then enable it, set a field handle (or leave “Apply to” on *All*), make sure `yt-dlp` is on `PATH` (or set the path), run `php craft queue/listen`, open an entry, and try **Scrape URL** with a real video URL.

---

## License

MIT © arifje
