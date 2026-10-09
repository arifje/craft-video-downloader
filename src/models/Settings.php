<?php

namespace arifje\craftvideodownloader\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Plugin settings.
 *
 * Editable in the control panel (Settings → Plugins → Video Downloader) and, as
 * with any Craft plugin, overridable from a `config/video-downloader.php` file —
 * handy for keeping the server-specific values (binary path, limits) out of the
 * database and in version control / per-environment .env.
 */
class Settings extends Model
{
    public const MODE_ALL = 'all';
    public const MODE_LIST = 'list';

    /** Master on/off switch for the "Scrape URL" button. */
    public bool $enabled = true;

    /**
     * Whether the standalone download tool (CP nav item "Video Downloader")
     * is available. Access additionally requires Craft's built-in
     * "Access Video Downloader" plugin permission (admins always have it).
     */
    public bool $toolEnabled = true;

    /**
     * Which Assets fields get the button:
     *  - 'all'  : every Assets field in the CP
     *  - 'list' : only the handles in {@see $fieldHandles}
     */
    public string $mode = self::MODE_ALL;

    /** Assets field handles that get the button when mode is 'list'. @var string[] */
    public array $fieldHandles = [];

    /**
     * Only show the button on Assets fields that accept video — i.e. fields with
     * no file-type restriction, or whose "Restrict allowed file types" list
     * includes Video. On by default so image-only fields are left alone.
     */
    public bool $videoFieldsOnly = true;

    /**
     * Path to the yt-dlp binary. Supports Craft's env syntax, e.g.
     * `$VIDEO_DOWNLOADER_YTDLP` or an absolute path like `/usr/local/bin/yt-dlp`.
     */
    public string $ytDlpPath = 'yt-dlp';

    /**
     * JavaScript runtime yt-dlp uses to solve YouTube's n-challenge (without
     * one, YouTube downloads fail with 403). Empty = auto-detect Deno; or a
     * bare name (deno, node, bun, quickjs); or a full path such as
     * /home/deploy/.deno/bin/deno. Supports env syntax, e.g.
     * `$VIDEO_DOWNLOADER_JS_RUNTIME`.
     */
    public string $jsRuntime = '';

    /**
     * Optional Netscape-format cookies.txt exported from a logged-in browser,
     * for videos behind sign-in or bot checks. Supports env syntax, e.g.
     * `$VIDEO_DOWNLOADER_COOKIE_FILE`. Keep it outside the web root.
     */
    public string $cookieFile = '';

    /**
     * yt-dlp `-f` format selector. The default prefers a single progressive mp4
     * (no ffmpeg merge needed) and only falls back to a separate video+audio
     * merge — which requires ffmpeg — when that's all that's on offer.
     * Only applied when {@see $maxResolution} is empty/0; with a resolution
     * ceiling active the plugin builds a hard-capped selector instead.
     */
    public string $format = 'mp4/bestvideo*+bestaudio/best';

    /**
     * Resolution ceiling for downloads, as a short-side profile: 1080 admits
     * landscape up to 1920x1080 AND portrait up to 1080x1920 (Reels/TikTok/
     * Shorts), while excluding 4K. Supports Craft's env syntax, e.g.
     * `$VIDEO_DOWNLOADER_MAX_RESOLUTION`. Empty or 0 disables the cap (the
     * raw {@see $format} selector applies instead); an unresolvable value
     * falls back to 1080 so the cap fails closed. Sensible values: 720, 1080,
     * 1440. Read it via {@see getResolvedMaxResolution()}.
     */
    public string $maxResolution = '1080';

    /** Hard ceiling on the downloaded file size, in megabytes (yt-dlp --max-filesize). */
    public int $maxFilesizeMb = 500;

    /** Wall-clock timeout for the yt-dlp process, in seconds. */
    public int $timeout = 300;

    /**
     * Optional allow-list of hostnames the downloader may fetch from, one per
     * line (e.g. "youtube.com"). Empty = allow any http(s) host. Matching is
     * host-suffix based, so "tiktok.com" also allows "www.tiktok.com".
     * Stored as a string for the CP textarea; read it via {@see getAllowedHostsList()}.
     */
    public string $allowedHosts = '';

    public function defineRules(): array
    {
        return [
            [['enabled', 'toolEnabled', 'videoFieldsOnly'], 'boolean'],
            [['mode'], 'in', 'range' => [self::MODE_ALL, self::MODE_LIST]],
            [['fieldHandles'], 'each', 'rule' => ['string']],
            [['ytDlpPath', 'format', 'maxResolution', 'allowedHosts', 'jsRuntime', 'cookieFile'], 'string'],
            [['ytDlpPath', 'format'], 'required'],
            [['maxFilesizeMb', 'timeout'], 'integer', 'min' => 1],
        ];
    }

    /**
     * Whether this plugin is allowed to operate on the given Assets field under
     * the current settings (enabled + mode/list + video-capable filter).
     *
     * Shared by the controller (request time) and the queue job (execution
     * time), so a settings change between enqueue and execution is honoured.
     */
    public function allowsField(\craft\fields\Assets $field): bool
    {
        if (!$this->enabled) {
            return false;
        }
        if ($this->mode === self::MODE_LIST && !in_array($field->handle, $this->fieldHandles, true)) {
            return false;
        }
        if ($this->videoFieldsOnly && $field->restrictFiles
            && !in_array('video', (array) ($field->allowedKinds ?? []), true)
        ) {
            return false;
        }
        return true;
    }

    /**
     * The allowed-hosts setting parsed into a list of trimmed, lower-cased
     * hostnames (blank lines dropped).
     *
     * @return string[]
     */
    public function getAllowedHostsList(): array
    {
        $hosts = preg_split('/[\r\n,]+/', strtolower($this->allowedHosts)) ?: [];
        return array_values(array_filter(array_map('trim', $hosts), static fn($h) => $h !== ''));
    }

    /** Resolve the yt-dlp path with any `$ENV_VAR` / alias syntax expanded. */
    public function getResolvedYtDlpPath(): string
    {
        return App::parseEnv($this->ytDlpPath) ?: 'yt-dlp';
    }

    /** The JS runtime setting with `$ENV_VAR` / alias syntax expanded. */
    public function getResolvedJsRuntime(): string
    {
        return trim((string) App::parseEnv($this->jsRuntime));
    }

    /** The cookies-file setting with `$ENV_VAR` / alias syntax expanded. */
    public function getResolvedCookieFile(): string
    {
        return trim((string) App::parseEnv($this->cookieFile));
    }

    /**
     * The resolution ceiling with any `$ENV_VAR` syntax expanded and the value
     * clamped to a sane range. Returns 0 when the cap is explicitly disabled
     * (empty or "0"); a non-numeric value (e.g. an unset env var reference)
     * falls back to the default profile so the cap fails closed.
     */
    public function getResolvedMaxResolution(): int
    {
        $raw = trim((string) App::parseEnv($this->maxResolution));
        if ($raw === '' || $raw === '0') {
            return 0;
        }
        if (!is_numeric($raw)) {
            return \arifje\craftvideodownloader\services\Downloader::DEFAULT_MAX_RESOLUTION;
        }
        return \arifje\craftvideodownloader\services\Downloader::normalizeResolution((int) $raw);
    }

    /** Bytes form of {@see $maxFilesizeMb}, or 0 when no limit is desired. */
    public function getMaxFilesizeBytes(): int
    {
        return max(0, $this->maxFilesizeMb) * 1024 * 1024;
    }
}
