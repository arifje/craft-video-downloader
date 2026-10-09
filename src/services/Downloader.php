<?php

namespace arifje\craftvideodownloader\services;

use arifje\craftvideodownloader\models\Settings;
use Craft;

/**
 * Thin wrapper around the yt-dlp binary.
 *
 * {@see probe()} fetches lightweight metadata (title, uploader, duration,
 * resolution, thumbnail) so the UI can show what's being fetched.
 *
 * {@see download()} streams yt-dlp through proc_open — an argv array, so no shell
 * is involved and user input can't be interpreted as shell metacharacters — and
 * parses its --progress-template output line by line to report live progress
 * (percent, bytes, speed, ETA) through a callback.
 *
 * Security notes:
 *  - {@see normalizeUrl()} rejects non-http(s) schemes, credentials embedded in
 *    the URL, and hosts that are (or resolve to) private, loopback, link-local
 *    or otherwise reserved addresses (cloud metadata endpoints included) unless
 *    the host is explicitly allow-listed. This is a first line of defense only:
 *    yt-dlp follows redirects and fetches extractor-discovered URLs that cannot
 *    be re-validated from here — see the README for the deployment-level
 *    controls (egress filtering) that complete the story.
 *  - The URL is always passed after a `--` argument separator so it can never
 *    be interpreted as a yt-dlp option.
 *  - Downloads land in a fresh per-job directory under the plugin's own temp
 *    base, produced files are validated (size, media extension) before being
 *    imported, and {@see removeDir()} refuses to delete anything outside that
 *    base.
 *
 * The class is intentionally Craft-free when a $tempBasePath is injected, so
 * the whole download pipeline is unit-testable with a stub yt-dlp binary.
 */
final class Downloader
{
    /** Prefix that tags our machine-readable progress lines on stdout. */
    private const PROGRESS_MARKER = '__VDLP__';

    /** Subdirectory of the temp base that all job directories live under. */
    private const TEMP_SUBDIR = 'video-downloader';

    /** Longest URL we accept. */
    private const MAX_URL_LENGTH = 2048;

    /** Network timeout passed to yt-dlp for individual socket operations. */
    private const SOCKET_TIMEOUT = 15;

    /** Extensions we accept as downloaded output (common AV containers). */
    private const ALLOWED_EXTENSIONS = [
        'mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'flv', '3gp', '3g2',
        'ts', 'm2ts', 'mts', 'ogv', 'ogg', 'mp3', 'm4a', 'aac', 'wav', 'opus',
    ];

    /** Default resolution profile (short side, px): ~1080p, blocks 4K. */
    public const DEFAULT_MAX_RESOLUTION = 1080;

    /** Smallest accepted resolution profile; lower values are clamped up. */
    public const MIN_RESOLUTION = 144;

    /** Largest accepted resolution profile (8K); larger values are clamped. */
    public const MAX_RESOLUTION_LIMIT = 4320;

    public function __construct(
        private readonly string $ytDlpPath,
        private readonly string $format,
        private readonly int $maxFilesizeMb,
        private readonly int $timeout,
        /**
         * Resolution ceiling as a short-side profile (0 = no cap, use $format
         * verbatim). See {@see buildFormatSelector()} for the semantics.
         */
        private readonly int $maxResolution = 0,
        /** @var string[] */
        private readonly array $allowedHosts = [],
        /** Overrides Craft's temp path; lets tests run without a Craft app. */
        private readonly ?string $tempBasePath = null,
    ) {
    }

    public static function fromSettings(Settings $settings): self
    {
        return new self(
            $settings->getResolvedYtDlpPath(),
            $settings->format,
            (int) $settings->maxFilesizeMb,
            (int) $settings->timeout,
            $settings->getResolvedMaxResolution(),
            $settings->getAllowedHostsList(),
        );
    }

    /**
     * Clamp a resolution profile to a sane range. 0 (and negative values)
     * means "no cap"; anything else lands between MIN_RESOLUTION and
     * MAX_RESOLUTION_LIMIT.
     */
    public static function normalizeResolution(int $resolution): int
    {
        if ($resolution <= 0) {
            return 0;
        }
        return max(self::MIN_RESOLUTION, min(self::MAX_RESOLUTION_LIMIT, $resolution));
    }

    /**
     * Build a hard-capped yt-dlp format selector for a resolution profile.
     *
     * The profile is orientation-aware: 1080 admits landscape up to 1920x1080
     * AND portrait up to 1080x1920 (Reels/TikTok/Shorts), instead of a naive
     * height<=1080 that would reject portrait HD. Each tier therefore has a
     * landscape-capped branch followed by a portrait-capped one; the
     * wrong-orientation branch simply matches nothing and falls through.
     *
     * Tier order mirrors this plugin's default format (pre-merged mp4 first so
     * ffmpeg merging stays a fallback, not a requirement): pre-merged mp4 →
     * split video+audio → any pre-merged. Every branch carries the cap, so
     * yt-dlp fails ("Requested format is not available") rather than silently
     * downloading a larger stream when nothing fits. The `<=?` operator passes
     * formats that don't expose width/height, so platforms serving a single
     * dimensionless format keep working. Audio is never capped.
     */
    public static function buildFormatSelector(int $maxResolution): string
    {
        $short = self::normalizeResolution($maxResolution);
        $long  = (int) round($short * 16 / 9);

        $caps = [
            "[height<=?{$short}][width<=?{$long}]", // landscape (and square)
            "[width<=?{$short}][height<=?{$long}]", // portrait
        ];

        $branches = [];
        foreach ($caps as $cap) {
            $branches[] = "b[ext=mp4]{$cap}";   // pre-merged mp4, no ffmpeg needed
        }
        foreach ($caps as $cap) {
            $branches[] = "bv*{$cap}+ba";       // split streams, merged to mp4
        }
        foreach ($caps as $cap) {
            $branches[] = "b{$cap}";            // any pre-merged format
        }

        return implode('/', $branches);
    }

    /**
     * The format selector a download will actually use: the hard-capped
     * resolution-profile selector when a cap is set, the configured format
     * string otherwise.
     */
    public function effectiveFormat(): string
    {
        if ($this->maxResolution > 0) {
            return self::buildFormatSelector($this->maxResolution);
        }
        return $this->format;
    }

    /** The server-wide resolution ceiling (0 = none). */
    public function maxResolution(): int
    {
        return self::normalizeResolution($this->maxResolution);
    }

    /** The server-wide file-size cap in bytes (0 = none). */
    public function maxFilesizeBytes(): int
    {
        return max(0, $this->maxFilesizeMb) * 1024 * 1024;
    }

    /* ------------------------------------------------- download tool (CP) */

    /** Tool preset: H.264 MP4 that imports straight into iOS/Android photo apps. */
    public const PRESET_COMPATIBLE = 'compatible';

    /** Tool preset: best quality in any codec, merged to MP4 (or MKV). */
    public const PRESET_BEST = 'best';

    /** Tool preset: audio only. */
    public const PRESET_AUDIO = 'audio';

    /** @var string[] */
    public const PRESETS = [self::PRESET_COMPATIBLE, self::PRESET_BEST, self::PRESET_AUDIO];

    /**
     * Summarise a yt-dlp info dict into the resolution choices the download
     * tool offers: one option per distinct short-side resolution, highest
     * first, each with an estimated file size and whether it is allowed under
     * the server ceiling and size cap.
     *
     * @param array<string,mixed> $info yt-dlp info dict (from {@see inspect()})
     * @param int $ceiling server resolution ceiling (0 = none)
     * @param int $maxBytes server file-size cap in bytes (0 = none)
     * @return array{options: list<array<string,mixed>>, audio: array<string,mixed>|null}
     */
    public static function summarizeFormats(array $info, int $ceiling = 0, int $maxBytes = 0): array
    {
        $formats  = is_array($info['formats'] ?? null) ? $info['formats'] : [];
        $duration = isset($info['duration']) ? (float) $info['duration'] : 0.0;

        $sizeOf = static function (array $f) use ($duration): ?int {
            if (!empty($f['filesize'])) {
                return (int) $f['filesize'];
            }
            if (!empty($f['filesize_approx'])) {
                return (int) $f['filesize_approx'];
            }
            if (!empty($f['tbr']) && $duration > 0) {
                return (int) round((float) $f['tbr'] * 125 * $duration); // kbit/s → bytes
            }
            return null;
        };

        // Best audio-only stream (added to video-only sizes for an estimate).
        $audio = null;
        foreach ($formats as $f) {
            $vcodec = (string) ($f['vcodec'] ?? '');
            $acodec = (string) ($f['acodec'] ?? '');
            if ($vcodec === 'none' && $acodec !== '' && $acodec !== 'none') {
                $tbr = (float) ($f['tbr'] ?? $f['abr'] ?? 0);
                if ($audio === null || $tbr > $audio['tbr']) {
                    $audio = ['tbr' => $tbr, 'size' => $sizeOf($f), 'ext' => (string) ($f['ext'] ?? '')];
                }
            }
        }

        $groups = [];
        foreach ($formats as $f) {
            $vcodec = (string) ($f['vcodec'] ?? '');
            if ($vcodec === 'none') {
                continue; // audio-only
            }
            $w = (int) ($f['width'] ?? 0);
            $h = (int) ($f['height'] ?? 0);
            if ($w <= 0 && $h <= 0) {
                continue; // dimensionless (e.g. storyboards, manifests)
            }
            if ((string) ($f['ext'] ?? '') === 'mhtml') {
                continue; // storyboard images
            }
            $short = ($w > 0 && $h > 0) ? min($w, $h) : max($w, $h);

            $hasAudio = ($f['acodec'] ?? 'none') !== 'none' && ($f['acodec'] ?? '') !== '';
            $size = $sizeOf($f);
            if ($size !== null && !$hasAudio && $audio !== null && $audio['size'] !== null) {
                $size += $audio['size'];
            }
            $isH264Mp4 = str_starts_with($vcodec, 'avc1') && ($f['ext'] ?? '') === 'mp4';

            $g = $groups[$short] ?? [
                'resolution'     => $short,
                'width'          => $w ?: null,
                'height'         => $h ?: null,
                'fps'            => null,
                'estimatedBytes' => null,
                'h264'           => false,
            ];
            // Keep the largest-size estimate in the group (the "best" stream).
            if ($size !== null && ($g['estimatedBytes'] === null || $size > $g['estimatedBytes'])) {
                $g['estimatedBytes'] = $size;
                $g['width'] = $w ?: $g['width'];
                $g['height'] = $h ?: $g['height'];
            }
            if (!empty($f['fps'])) {
                $g['fps'] = max((int) $g['fps'], (int) round((float) $f['fps']));
            }
            $g['h264'] = $g['h264'] || $isH264Mp4;
            $groups[$short] = $g;
        }

        krsort($groups, SORT_NUMERIC);

        $options = [];
        foreach ($groups as $g) {
            $reason = null;
            if ($ceiling > 0 && $g['resolution'] > $ceiling) {
                $reason = 'ceiling';
            } elseif ($maxBytes > 0 && $g['estimatedBytes'] !== null && $g['estimatedBytes'] > $maxBytes) {
                $reason = 'size';
            }
            $options[] = $g + [
                'label'   => self::resolutionLabel($g['resolution']),
                'allowed' => $reason === null,
                'reason'  => $reason,
            ];
        }

        $audioOption = null;
        if ($audio !== null) {
            $audioOption = [
                'estimatedBytes' => $audio['size'],
                'ext'            => $audio['ext'],
                'allowed'        => !($maxBytes > 0 && $audio['size'] !== null && $audio['size'] > $maxBytes),
            ];
        }

        return ['options' => $options, 'audio' => $audioOption];
    }

    /** Human label for a short-side resolution: 2160 → "4K", 1080 → "1080p". */
    public static function resolutionLabel(int $short): string
    {
        return match (true) {
            $short >= 4320 => '8K',
            $short >= 2160 => '4K',
            default => $short . 'p',
        };
    }

    /**
     * Build the yt-dlp format selector for a download-tool request.
     *
     * $resolution is an orientation-aware short-side profile (see
     * {@see buildFormatSelector()}); 0 means "best available". The caller is
     * responsible for having already clamped it to the server ceiling.
     *
     *  - compatible: H.264 video in MP4 with AAC audio first (what iOS/Android
     *    photo libraries import), then any MP4, then anything (merged to MP4).
     *  - best: best video + best audio in any codec, then best pre-merged.
     *  - audio: best audio, M4A preferred. Never resolution-capped.
     */
    public static function buildToolSelector(int $resolution, string $preset): string
    {
        if ($preset === self::PRESET_AUDIO) {
            return 'ba[ext=m4a]/ba';
        }

        $short = self::normalizeResolution($resolution);
        if ($short > 0) {
            $long = (int) round($short * 16 / 9);
            $caps = [
                "[height<=?{$short}][width<=?{$long}]", // landscape (and square)
                "[width<=?{$short}][height<=?{$long}]", // portrait
            ];
        } else {
            $caps = [''];
        }

        $branches = [];
        if ($preset === self::PRESET_COMPATIBLE) {
            foreach ($caps as $cap) {
                $branches[] = "bv*[vcodec^=avc1][ext=mp4]{$cap}+ba[ext=m4a]";
            }
            foreach ($caps as $cap) {
                $branches[] = "b[ext=mp4][vcodec^=avc1]{$cap}";
            }
            foreach ($caps as $cap) {
                $branches[] = "b[ext=mp4]{$cap}";
            }
        }
        foreach ($caps as $cap) {
            $branches[] = "bv*{$cap}+ba";
        }
        foreach ($caps as $cap) {
            $branches[] = "b{$cap}";
        }

        return implode('/', $branches);
    }

    /**
     * Clamp a requested short-side resolution to the server ceiling. 0 means
     * "best available", which becomes the ceiling itself when one is set; a
     * request can lower the effective cap but never raise it.
     */
    public static function clampToCeiling(int $requested, int $ceiling): int
    {
        $requested = self::normalizeResolution($requested);
        if ($ceiling <= 0) {
            return $requested;
        }
        if ($requested === 0) {
            return $ceiling;
        }
        return min($requested, $ceiling);
    }

    /** yt-dlp --merge-output-format for a tool preset (null = don't pass it). */
    public static function mergeFormatForPreset(string $preset): ?string
    {
        return match ($preset) {
            self::PRESET_COMPATIBLE => 'mp4',
            self::PRESET_BEST => 'mp4/mkv',
            default => null,
        };
    }

    /* --------------------------------------------------------------- URLs */

    /**
     * Validate and normalise a source URL.
     *
     * Throws on: non-http(s) schemes, over-long URLs, credentials embedded in
     * the URL, hosts outside the allow-list (when one is configured), and —
     * unless the host is explicitly allow-listed — hosts that are or resolve
     * to private/loopback/link-local/reserved addresses.
     *
     * @param string[] $allowedHosts
     * @throws \InvalidArgumentException
     */
    public static function normalizeUrl(string $url, array $allowedHosts = []): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Please enter a valid URL.');
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \InvalidArgumentException('Please enter a valid URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Only http and https URLs are supported.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('URLs with embedded credentials are not supported.');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            throw new \InvalidArgumentException('The URL is missing a host.');
        }

        $allowListed = self::hostIsAllowListed($host, $allowedHosts);

        if (!empty($allowedHosts) && !$allowListed) {
            throw new \InvalidArgumentException("Downloads from \"{$host}\" are not allowed.");
        }

        // Block requests aimed at internal infrastructure. An explicit entry in
        // the allow-list is treated as informed consent and skips this check.
        if (!$allowListed && self::hostTargetsPrivateNetwork($host)) {
            throw new \InvalidArgumentException('Downloads from private or internal addresses are not allowed.');
        }

        return $url;
    }

    /** @param string[] $allowedHosts */
    private static function hostIsAllowListed(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower(trim($allowed));
            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.' . $allowed))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a URL host is — or resolves to — a private, loopback, link-local
     * or otherwise reserved address (which covers cloud metadata services like
     * 169.254.169.254). Unresolvable hostnames are treated as private: yt-dlp
     * could not fetch them anyway, and failing closed is the safe default.
     */
    public static function hostTargetsPrivateNetwork(string $host): bool
    {
        $host = strtolower(trim($host, "[]\t "));

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return true;
        }

        if (self::isIpAddress($host)) {
            return self::isPrivateIp($host);
        }

        // Hostname: resolve and check every address it can point at.
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }

        if (empty($ips)) {
            return true; // fail closed on unresolvable hosts
        }

        foreach ($ips as $ip) {
            if (self::isPrivateIp($ip)) {
                return true;
            }
        }

        return false;
    }

    private static function isIpAddress(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /** Loopback, RFC1918/ULA, link-local and reserved ranges — v4 and v6. */
    public static function isPrivateIp(string $ip): bool
    {
        $ip = strtolower(trim($ip, "[] \t"));

        // Unwrap IPv4-mapped IPv6 (::ffff:10.0.0.1) so the v4 ranges apply.
        if (str_starts_with($ip, '::ffff:') && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = substr($ip, 7);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true; // not a parseable address — fail closed
        }

        // NO_PRIV_RANGE: RFC1918 + IPv6 ULA. NO_RES_RANGE: loopback, link-local
        // (incl. 169.254.169.254 metadata), 0.0.0.0/8, 240/4, ::1, etc.
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /* -------------------------------------------------------------- probe */

    /**
     * Fetch lightweight metadata for a URL without downloading the media.
     * Best-effort: returns null if extraction fails or times out.
     *
     * @return array{title?:string,uploader?:string,extractor?:string,duration?:int,width?:int,height?:int,ext?:string,thumbnail?:string,filesizeApprox?:int}|null
     */
    public function probe(string $url): ?array
    {
        try {
            return self::metaFromInfo($this->inspect($url));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Ask yt-dlp for the full info JSON of a URL (metadata AND the list of
     * available formats) without downloading any media.
     *
     * @return array<string,mixed> yt-dlp's info dict
     * @throws \InvalidArgumentException when the URL is rejected
     * @throws \RuntimeException when yt-dlp can't be run or can't read the URL
     */
    public function inspect(string $url): array
    {
        $url = self::normalizeUrl($url, $this->allowedHosts);

        $cmd = [
            $this->ytDlpPath,
            '--dump-single-json',
            '--no-playlist',
            '--no-warnings',
            '--no-cache-dir',
            '--socket-timeout', (string) self::SOCKET_TIMEOUT,
            '--',
            $url,
        ];

        // Cap extraction so a slow site can't eat the whole job/request timeout.
        $probeTimeout = $this->timeout > 0 ? min(60, $this->timeout) : 60;

        $result = $this->runProcess($cmd, null, $probeTimeout, null);
        if ($result === null) {
            throw new \RuntimeException(
                "Could not run yt-dlp at \"{$this->ytDlpPath}\". Check it's installed and the path is correct in the plugin settings."
            );
        }
        if ($result['timedOut']) {
            throw new \RuntimeException('Reading the video information timed out.');
        }
        if ($result['exitCode'] !== 0) {
            $stderr = $this->sanitizeOutput($result['stderr']);
            throw new \RuntimeException($stderr !== '' ? "yt-dlp could not read this URL: {$stderr}" : 'yt-dlp could not read this URL.');
        }

        $json = json_decode($result['stdout'], true);
        if (!is_array($json)) {
            throw new \RuntimeException('yt-dlp returned unreadable video information.');
        }
        return $json;
    }

    /**
     * Reduce a yt-dlp info dict to the lightweight metadata the UI shows.
     *
     * @param array<string,mixed> $json
     * @return array<string,mixed>
     */
    public static function metaFromInfo(array $json): array
    {
        $width  = isset($json['width']) ? (int) $json['width'] : null;
        $height = isset($json['height']) ? (int) $json['height'] : null;

        $meta = [
            'title'          => isset($json['title']) ? (string) $json['title'] : null,
            'uploader'       => $json['uploader'] ?? $json['channel'] ?? $json['uploader_id'] ?? null,
            'extractor'      => $json['extractor_key'] ?? $json['extractor'] ?? null,
            'duration'       => isset($json['duration']) ? (int) round((float) $json['duration']) : null,
            'width'          => $width ?: null,
            'height'         => $height ?: null,
            'ext'            => $json['ext'] ?? null,
            'thumbnail'      => $json['thumbnail'] ?? null,
            'filesizeApprox' => isset($json['filesize_approx']) ? (int) $json['filesize_approx']
                : (isset($json['filesize']) ? (int) $json['filesize'] : null),
        ];

        return array_filter($meta, static fn($v) => $v !== null && $v !== '');
    }

    /* ----------------------------------------------------------- download */

    /**
     * Download the video at $url with yt-dlp, reporting live progress.
     *
     * @param callable|null $onProgress  Called with each progress update:
     *        ['percent'=>?float, 'downloaded'=>?int, 'total'=>?int, 'speed'=>?float, 'eta'=>?int]
     * @param string|null $format  yt-dlp `-f` selector override (the download
     *        tool's per-request choice); null = the configured/capped default.
     * @param string|null $mergeOutputFormat  `--merge-output-format` value; null
     *        omits the flag (audio-only downloads). Ignored unless $format is set.
     * @return array{path:string,filename:string,dir:string,stderr:string}
     * @throws \RuntimeException on any failure.
     */
    public function download(
        string $url,
        ?callable $onProgress = null,
        ?string $format = null,
        ?string $mergeOutputFormat = 'mp4',
    ): array {
        $url = self::normalizeUrl($url, $this->allowedHosts);
        $dir = $this->makeTempDir();

        // %(title).150B caps the title at 150 bytes so long captions don't blow up
        // the filename. --restrict-filenames keeps it ASCII/filesystem-safe.
        $outputTemplate = $dir . '/%(title).150B [%(id)s].%(ext)s';

        $cmd = [
            $this->ytDlpPath,
            '-f', $format ?? $this->effectiveFormat(),
            '-o', $outputTemplate,
            '--no-playlist',
            '--no-part',
            '--no-cache-dir',
            '--restrict-filenames',
            '--no-warnings',
            '--newline',
            '--socket-timeout', (string) self::SOCKET_TIMEOUT,
            // Emit one machine-readable progress line per update, pipe-delimited.
            '--progress-template',
            self::PROGRESS_MARKER . '|%(progress._percent_str)s|%(progress.downloaded_bytes)s'
                . '|%(progress.total_bytes)s|%(progress.total_bytes_estimate)s'
                . '|%(progress.speed)s|%(progress.eta)s',
        ];
        $merge = $format === null ? 'mp4' : $mergeOutputFormat;
        if ($merge !== null) {
            $cmd[] = '--merge-output-format';
            $cmd[] = $merge;
        }
        if ($this->maxFilesizeMb > 0) {
            $cmd[] = '--max-filesize';
            $cmd[] = $this->maxFilesizeMb . 'M';
        }
        // `--` so the (user-supplied) URL can never be read as an option.
        $cmd[] = '--';
        $cmd[] = $url;

        $onLine = function (string $line) use ($onProgress): void {
            $this->handleLine($line, $onProgress);
        };

        $result = $this->runProcess($cmd, $onLine, $this->timeout, $dir);

        if ($result === null) {
            $this->removeDir($dir);
            throw new \RuntimeException(
                "Could not run yt-dlp at \"{$this->ytDlpPath}\". Check it's installed and the path is correct in the plugin settings."
            );
        }

        if ($result['timedOut']) {
            $this->removeDir($dir);
            throw new \RuntimeException("yt-dlp timed out after {$this->timeout}s.");
        }

        if ($result['exitCode'] !== 0) {
            $stderr = $this->sanitizeOutput($result['stderr']);
            $this->removeDir($dir);
            if ($result['exitCode'] === 127 || stripos($stderr, 'not found') !== false || stripos($stderr, 'No such file') !== false) {
                throw new \RuntimeException(
                    "Could not run yt-dlp at \"{$this->ytDlpPath}\". Check it's installed and the path is correct in the plugin settings."
                );
            }
            throw new \RuntimeException($stderr !== '' ? "yt-dlp failed: {$stderr}" : 'yt-dlp failed with no output.');
        }

        $file = $this->findDownloadedFile($dir);
        if ($file === null) {
            $stderr = $this->sanitizeOutput($result['stderr']);
            $this->removeDir($dir);
            $hint = $stderr !== '' ? " ({$stderr})" : '';
            throw new \RuntimeException("yt-dlp reported success but produced no file{$hint}. The post may be private, region-locked, or larger than the size limit.");
        }

        try {
            $this->validateDownloadedFile($file);
        } catch (\Throwable $e) {
            $this->removeDir($dir);
            throw $e;
        }

        return [
            'path'     => $file,
            'filename' => basename($file),
            'dir'      => $dir,
            'stderr'   => $this->sanitizeOutput($result['stderr']),
        ];
    }

    /**
     * Sanity-check a produced file before it is imported as an asset: it must
     * be non-empty, within the configured size cap, and carry a recognised
     * audio/video container extension.
     *
     * @throws \RuntimeException
     */
    public function validateDownloadedFile(string $path): void
    {
        $size = is_file($path) ? (int) (filesize($path) ?: 0) : 0;
        if ($size <= 0) {
            throw new \RuntimeException('The downloaded file is empty.');
        }
        if ($this->maxFilesizeMb > 0 && $size > $this->maxFilesizeMb * 1024 * 1024) {
            throw new \RuntimeException('The downloaded file exceeds the configured size limit.');
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new \RuntimeException("The downloaded file type \".{$ext}\" is not an accepted media container.");
        }
    }

    /* ------------------------------------------------------ proc plumbing */

    /**
     * Run a command (argv array, no shell) with a wall-clock timeout, streaming
     * stdout/stderr. Complete stdout lines are passed to $onLine as they arrive.
     *
     * @param string[] $cmd
     * @return array{exitCode:int,stdout:string,stderr:string,timedOut:bool}|null
     *         null when the process could not be started at all.
     */
    private function runProcess(array $cmd, ?callable $onLine, int $timeout, ?string $cwd): ?array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout   = '';
        $stderr   = '';
        $lineBuf  = '';
        $start    = microtime(true);
        $timedOut = false;
        $exitCode = null;

        $consume = function (int $idx, string $chunk) use (&$stdout, &$stderr, &$lineBuf, $onLine): void {
            if ($chunk === '') {
                return;
            }
            if ($idx === 2) {
                // Keep the tail — the useful ERROR lines come last.
                $stderr = substr($stderr . $chunk, -65536);
                return;
            }
            $stdout = strlen($stdout) < 4194304 ? $stdout . $chunk : $stdout;
            if ($onLine !== null) {
                $lineBuf .= $chunk;
                while (($nl = strpos($lineBuf, "\n")) !== false) {
                    $onLine(rtrim(substr($lineBuf, 0, $nl), "\r"));
                    $lineBuf = substr($lineBuf, $nl + 1);
                }
            }
        };

        while (true) {
            $read   = [$pipes[1], $pipes[2]];
            $write  = null;
            $except = null;
            $n = @stream_select($read, $write, $except, 1);

            if ($n === false) {
                break;
            }
            if ($n > 0) {
                foreach ($read as $stream) {
                    $idx   = ($stream === $pipes[2]) ? 2 : 1;
                    $chunk = fread($stream, 16384);
                    if ($chunk !== false) {
                        $consume($idx, $chunk);
                    }
                }
            }

            $status = proc_get_status($proc);
            if (!$status['running']) {
                // First status read after exit carries the real code; proc_close()
                // would return -1 because the child has already been reaped.
                $exitCode = $status['exitcode'];
                foreach ([1, 2] as $idx) {
                    $rest = stream_get_contents($pipes[$idx]);
                    if ($rest !== false) {
                        $consume($idx, $rest);
                    }
                }
                if ($onLine !== null && $lineBuf !== '') {
                    $onLine(rtrim($lineBuf, "\r\n"));
                    $lineBuf = '';
                }
                break;
            }

            if ($timeout > 0 && (microtime(true) - $start) > $timeout) {
                $timedOut = true;
                proc_terminate($proc, 9);
                break;
            }
        }

        foreach ([1, 2] as $idx) {
            if (isset($pipes[$idx]) && is_resource($pipes[$idx])) {
                fclose($pipes[$idx]);
            }
        }
        proc_close($proc); // return value is unreliable post-status; use $exitCode

        return [
            'exitCode' => $exitCode ?? 0,
            'stdout'   => $stdout,
            'stderr'   => $stderr,
            'timedOut' => $timedOut,
        ];
    }

    /** Parse a marker line and fire the progress callback. */
    private function handleLine(string $line, ?callable $onProgress): void
    {
        if ($onProgress === null || strncmp($line, self::PROGRESS_MARKER, strlen(self::PROGRESS_MARKER)) !== 0) {
            return;
        }
        $parsed = self::parseProgressLine($line);
        if ($parsed !== null) {
            $onProgress($parsed);
        }
    }

    /**
     * Parse one machine-readable progress line into a normalised array.
     *
     * @return array{percent:?float,downloaded:?int,total:?int,speed:?float,eta:?int}|null
     */
    public static function parseProgressLine(string $line): ?array
    {
        $parts = explode('|', $line);
        if (count($parts) < 7) {
            return null;
        }

        $percentStr = trim(str_replace('%', '', $parts[1]));
        $percent    = is_numeric($percentStr) ? (float) $percentStr : null;
        $downloaded = is_numeric($parts[2]) ? (int) $parts[2] : null;
        $total      = is_numeric($parts[3]) ? (int) $parts[3] : (is_numeric($parts[4]) ? (int) $parts[4] : null);
        $speed      = is_numeric($parts[5]) ? (float) $parts[5] : null;
        $eta        = is_numeric($parts[6]) ? (int) $parts[6] : null;

        if ($percent === null && $downloaded !== null && $total) {
            $percent = round($downloaded / $total * 100, 1);
        }

        return [
            'percent'    => $percent,
            'downloaded' => $downloaded,
            'total'      => $total,
            'speed'      => $speed,
            'eta'        => $eta,
        ];
    }

    /* -------------------------------------------------------- temp + misc */

    /** The directory all of this plugin's job directories live under. */
    public function tempBase(): string
    {
        $base = $this->tempBasePath ?? Craft::$app->getPath()->getTempPath();
        return rtrim($base, '/') . '/' . self::TEMP_SUBDIR;
    }

    /** Create a fresh, empty temp dir under the plugin temp base. */
    private function makeTempDir(): string
    {
        $dir = $this->tempBase() . '/' . bin2hex(random_bytes(8));
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create a temp directory for the download: {$dir}");
        }
        return $dir;
    }

    /** Pick the largest non-temporary file produced in the download dir. */
    private function findDownloadedFile(string $dir): ?string
    {
        $best     = null;
        $bestSize = -1;
        foreach (glob($dir . '/*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, ['part', 'ytdl', 'tmp', 'temp'], true)) {
                continue;
            }
            $size = (int) (filesize($path) ?: 0);
            if ($size > $bestSize) {
                $best     = $path;
                $bestSize = $size;
            }
        }
        return $best;
    }

    /**
     * Delete a job's temp directory. Refuses anything that does not resolve to
     * a direct child of the plugin's own temp base, so a corrupted or forged
     * path can never delete files elsewhere.
     */
    public function removeDir(string $dir): bool
    {
        $base = realpath($this->tempBase());
        $real = realpath($dir);
        if ($base === false || $real === false) {
            return false;
        }
        if (dirname($real) !== $base) {
            return false;
        }
        foreach (glob($real . '/*') ?: [] as $path) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }
        @rmdir($real);
        return true;
    }

    /**
     * Reduce raw yt-dlp output to something safe to show an editor: prefer the
     * ERROR lines, strip local temp paths, collapse whitespace, cap the length.
     */
    public function sanitizeOutput(string $output): string
    {
        $output = str_replace($this->tempBase(), '…', $output);

        $lines = preg_split('/\r?\n/', trim($output)) ?: [];
        $errors = array_values(array_filter($lines, static fn($l) => stripos($l, 'ERROR') !== false));
        $keep = $errors !== [] ? $errors : array_slice($lines, -3);

        $text = trim(preg_replace('/\s+/', ' ', implode(' ', $keep)) ?? '');
        if (strlen($text) > 400) {
            $text = substr($text, 0, 400) . '…';
        }
        return $text;
    }
}
