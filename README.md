# Craft Video Downloader

A Craft CMS plugin that adds a **“Scrape URL”** button to your Assets fields. Paste a link to a social-media video, and the server downloads it with [**yt-dlp**](https://github.com/yt-dlp/yt-dlp) on Craft's queue and attaches the resulting video to the field — right next to **Add** / **Upload files**.

Compatible with **Craft CMS 4 and Craft CMS 5** from a single codebase (`^4.0 || ^5.0`).

![The Scrape URL button sits next to Add / Upload files on an Assets field.](#)

---

## How it works

1. The plugin adds a **Scrape URL** button to Assets fields in the control panel (all of them, or a chosen list) — including fields nested inside **Matrix / Neo / Super Table** blocks.
2. Clicking it opens a small modal where you paste a video URL.
3. On submit the plugin authorizes the request (see [Permissions & security](#permissions--security)), resolves the field's upload folder exactly like a manual upload would, and queues a job that runs `yt-dlp` and creates an Asset from the result.
4. While it runs, the modal shows the video's **title, uploader, duration, resolution and thumbnail** plus a **live progress bar** (percent, speed, ETA, downloaded / total).
5. When the download finishes, the new video is dropped into the field automatically.
6. **Save** the entry as usual to keep the relation.

The asset is created in exactly the folder/volume the field's **Upload files** button would use (it honours the field's *Restrict / Default Upload Location* settings, including the user's temporary-uploads folder for unsaved elements). The destination is resolved and authorized **at request time**, in the editor's own web session — so console queue workers never guess, and there is no fallback to "some volume": if the destination can't be resolved you get a clear error instead.

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
| **Apply to** | All Assets fields | Or limit to a chosen list of fields. |
| **Fields** | – | The Assets fields that get the button when “Apply to” is set to a list. |
| **Video-capable fields only** | on | Only show the button on fields that accept video (no file-type restriction, or *Video* among the allowed types). Leave on to skip image-only fields. |
| **yt-dlp path** | `yt-dlp` | Absolute path, or an env var like `$VIDEO_DOWNLOADER_YTDLP`. |
| **Format** | `mp4/bestvideo*+bestaudio/best` | yt-dlp `-f` selector. The default avoids needing ffmpeg unless a merge is unavoidable. |
| **Max file size** | 500 MB | Hard cap (`--max-filesize`). |
| **Timeout** | 300 s | Wall-clock limit for the yt-dlp process. |
| **Allowed hosts** | *(any)* | Optional allow-list, one hostname per line. Sub-domains match too. |

### Overriding from a config file

Like any Craft plugin, these can be overridden per-environment with a `config/video-downloader.php` file (handy for keeping the binary path and limits out of the database):

```php
<?php
return [
    'ytDlpPath'     => getenv('VIDEO_DOWNLOADER_YTDLP') ?: '/usr/local/bin/yt-dlp',
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
php tests/php/run.php                      # URL/SSRF guard, download pipeline, cleanup, JobStore
cd tests/js && npm install && npm test     # CP JS: filtering, Craft 4/5 name shapes, modal guard
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
