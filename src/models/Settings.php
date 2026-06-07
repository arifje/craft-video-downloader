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
     * Which Assets fields get the button:
     *  - 'all'  : every Assets field in the CP
     *  - 'list' : only the handles in {@see $fieldHandles}
     */
    public string $mode = self::MODE_ALL;

    /** Assets field handles that get the button when mode is 'list'. @var string[] */
    public array $fieldHandles = [];

    /**
     * Path to the yt-dlp binary. Supports Craft's env syntax, e.g.
     * `$VIDEO_DOWNLOADER_YTDLP` or an absolute path like `/usr/local/bin/yt-dlp`.
     */
    public string $ytDlpPath = 'yt-dlp';

    /**
     * yt-dlp `-f` format selector. The default prefers a single progressive mp4
     * (no ffmpeg merge needed) and only falls back to a separate video+audio
     * merge — which requires ffmpeg — when that's all that's on offer.
     */
    public string $format = 'mp4/bestvideo*+bestaudio/best';

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
            [['enabled'], 'boolean'],
            [['mode'], 'in', 'range' => [self::MODE_ALL, self::MODE_LIST]],
            [['fieldHandles'], 'each', 'rule' => ['string']],
            [['ytDlpPath', 'format', 'allowedHosts'], 'string'],
            [['ytDlpPath', 'format'], 'required'],
            [['maxFilesizeMb', 'timeout'], 'integer', 'min' => 1],
        ];
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

    /** Bytes form of {@see $maxFilesizeMb}, or 0 when no limit is desired. */
    public function getMaxFilesizeBytes(): int
    {
        return max(0, $this->maxFilesizeMb) * 1024 * 1024;
    }
}
