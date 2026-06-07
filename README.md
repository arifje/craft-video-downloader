# Craft Video Downloader

A Craft CMS plugin that adds a **“Scrape URL”** button to your Assets fields. Paste a link to a social-media video, and the server downloads it with [**yt-dlp**](https://github.com/yt-dlp/yt-dlp) on Craft's queue and attaches the resulting video to the field — right next to **Add** / **Upload files**.

Compatible with **Craft 4** (this branch). A **Craft 5** branch will follow.

![The Scrape URL button sits next to Add / Upload files on an Assets field.](#)

---

## How it works

1. The plugin adds a **Scrape URL** button to Assets fields in the control panel (all of them, or a chosen list).
2. Clicking it opens a small modal where you paste a video URL.
3. On submit, a queue job runs `yt-dlp` to download the video into the field's normal upload folder and creates an Asset from it.
4. The modal polls for completion; when the download finishes, the new video is dropped into the field automatically.
5. **Save** the entry as usual to keep the relation.

The asset is created in exactly the folder/volume the field's **Upload files** button would use (it honours the field's *Restrict / Default Upload Location* settings).

---

## Requirements

- Craft CMS 4 (PHP 8.0.2+)
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
composer require arifje/craft-video-downloader:^1.0
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

> **Tip:** with a console worker (`queue/listen`) there's no logged-in user, so set a **Default Upload Location** on the Assets field. The plugin falls back to the field's volume root if it can't resolve a user-specific folder, but an explicit upload location is cleaner.

---

## Notes & limitations

- **Authenticated, server-side fetch (SSRF):** the download runs server-side from a URL a logged-in CP user supplies. Restrict it with the **Allowed hosts** setting if your editors aren't fully trusted.
- **Scope:** this version targets top-level Assets fields on entry edit pages. Assets fields nested inside Matrix blocks are a planned follow-up.
- **Large/slow posts:** bounded by **Max file size** and **Timeout**; tune both for your sources.

---

## Local development

Point a test Craft 4 site at a local clone:

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
