<?php

namespace arifje\craftvideodownloader\services;

use arifje\craftvideodownloader\models\Settings;
use Craft;
use mikehaertl\shellcommand\Command;

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
 * Each download gets its own empty temp directory under Craft's temp path (so the
 * resulting file passes Asset::_validateTempFilePath()), and the produced file is
 * located by scanning that directory afterwards — robust across yt-dlp versions.
 */
final class Downloader
{
    /** Prefix that tags our machine-readable progress lines on stdout. */
    private const PROGRESS_MARKER = '__VDLP__';

    public function __construct(
        private readonly string $ytDlpPath,
        private readonly string $format,
        private readonly int $maxFilesizeMb,
        private readonly int $timeout,
        /** @var string[] */
        private readonly array $allowedHosts = [],
    ) {
    }

    public static function fromSettings(Settings $settings): self
    {
        return new self(
            $settings->getResolvedYtDlpPath(),
            $settings->format,
            (int) $settings->maxFilesizeMb,
            (int) $settings->timeout,
            $settings->getAllowedHostsList(),
        );
    }

    /**
     * Validate and normalise a source URL. Throws on anything that isn't a plain
     * http(s) URL, or whose host isn't in the configured allow-list (when set).
     *
     * @param string[] $allowedHosts
     * @throws \InvalidArgumentException
     */
    public static function normalizeUrl(string $url, array $allowedHosts = []): string
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Please enter a valid URL.');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Only http and https URLs are supported.');
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            throw new \InvalidArgumentException('The URL is missing a host.');
        }

        if (!empty($allowedHosts)) {
            $ok = false;
            foreach ($allowedHosts as $allowed) {
                $allowed = strtolower(trim($allowed));
                if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.' . $allowed))) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                throw new \InvalidArgumentException("Downloads from \"{$host}\" are not allowed.");
            }
        }

        return $url;
    }

    /**
     * Fetch lightweight metadata for a URL without downloading the media.
     * Best-effort: returns null if extraction fails or times out.
     *
     * @return array{title?:string,uploader?:string,extractor?:string,duration?:int,width?:int,height?:int,ext?:string,thumbnail?:string,filesizeApprox?:int}|null
     */
    public function probe(string $url): ?array
    {
        try {
            $url = self::normalizeUrl($url, $this->allowedHosts);
        } catch (\Throwable $e) {
            return null;
        }

        // Cap the probe so a slow site can't eat the whole job timeout.
        $probeTimeout = $this->timeout > 0 ? min(60, $this->timeout) : 60;

        $command = new Command(['command' => $this->ytDlpPath, 'timeout' => $probeTimeout]);
        $command->addArg('--dump-single-json');
        $command->addArg('--no-playlist');
        $command->addArg('--no-warnings');
        $command->addArg('--no-cache-dir');
        $command->addArg($url);

        if (!$command->execute()) {
            return null;
        }

        $json = json_decode($command->getOutput(), true);
        if (!is_array($json)) {
            return null;
        }

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

    /**
     * Download the video at $url with yt-dlp, reporting live progress.
     *
     * @param callable|null $onProgress  Called with each progress update:
     *        ['percent'=>?float, 'downloaded'=>?int, 'total'=>?int, 'speed'=>?float, 'eta'=>?int]
     * @return array{path:string,filename:string,dir:string,stderr:string}
     * @throws \RuntimeException on any failure.
     */
    public function download(string $url, ?callable $onProgress = null): array
    {
        $url = self::normalizeUrl($url, $this->allowedHosts);
        $dir = $this->makeTempDir();

        // %(title).150B caps the title at 150 bytes so long captions don't blow up
        // the filename. --restrict-filenames keeps it ASCII/filesystem-safe.
        $outputTemplate = $dir . '/%(title).150B [%(id)s].%(ext)s';

        $cmd = [
            $this->ytDlpPath,
            '-f', $this->format,
            '-o', $outputTemplate,
            '--merge-output-format', 'mp4',
            '--no-playlist',
            '--no-part',
            '--no-cache-dir',
            '--restrict-filenames',
            '--no-warnings',
            '--newline',
            // Emit one machine-readable progress line per update, pipe-delimited.
            '--progress-template',
            self::PROGRESS_MARKER . '|%(progress._percent_str)s|%(progress.downloaded_bytes)s'
                . '|%(progress.total_bytes)s|%(progress.total_bytes_estimate)s'
                . '|%(progress.speed)s|%(progress.eta)s',
        ];
        if ($this->maxFilesizeMb > 0) {
            $cmd[] = '--max-filesize';
            $cmd[] = $this->maxFilesizeMb . 'M';
        }
        $cmd[] = $url;

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes, $dir);
        if (!is_resource($proc)) {
            $this->removeDir($dir);
            throw new \RuntimeException(
                "Could not run yt-dlp at \"{$this->ytDlpPath}\". Check it's installed and the path is correct in the plugin settings."
            );
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stderr   = '';
        $buffers  = [1 => '', 2 => ''];
        $start    = microtime(true);
        $timedOut = false;
        $exitCode = null;

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
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }
                    if ($idx === 2) {
                        $stderr .= $chunk;
                    }
                    $buffers[$idx] .= $chunk;
                    while (($nl = strpos($buffers[$idx], "\n")) !== false) {
                        $line = rtrim(substr($buffers[$idx], 0, $nl), "\r");
                        $buffers[$idx] = substr($buffers[$idx], $nl + 1);
                        $this->handleLine($line, $onProgress);
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
                    if ($rest !== false && $rest !== '') {
                        if ($idx === 2) {
                            $stderr .= $rest;
                        }
                        $this->handleLine(rtrim($buffers[$idx] . $rest, "\r\n"), $onProgress);
                    }
                }
                break;
            }

            if ($this->timeout > 0 && (microtime(true) - $start) > $this->timeout) {
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
        if ($exitCode === null) {
            $exitCode = 0;
        }

        if ($timedOut) {
            $this->removeDir($dir);
            throw new \RuntimeException("yt-dlp timed out after {$this->timeout}s.");
        }

        if ($exitCode !== 0) {
            $this->removeDir($dir);
            $stderr = trim($stderr);
            if ($exitCode === 127 || stripos($stderr, 'not found') !== false || stripos($stderr, 'No such file') !== false) {
                throw new \RuntimeException(
                    "Could not run yt-dlp at \"{$this->ytDlpPath}\". Check it's installed and the path is correct in the plugin settings."
                );
            }
            throw new \RuntimeException($stderr !== '' ? "yt-dlp failed: {$stderr}" : 'yt-dlp failed with no output.');
        }

        $file = $this->findDownloadedFile($dir);
        if ($file === null) {
            $stderr = trim($stderr);
            $this->removeDir($dir);
            $hint = $stderr !== '' ? " ({$stderr})" : '';
            throw new \RuntimeException("yt-dlp reported success but produced no file{$hint}. The post may be private, region-locked, or larger than the size limit.");
        }

        return [
            'path'     => $file,
            'filename' => basename($file),
            'dir'      => $dir,
            'stderr'   => trim($stderr),
        ];
    }

    /** Parse a marker line and fire the progress callback. */
    private function handleLine(string $line, ?callable $onProgress): void
    {
        if ($onProgress === null || strncmp($line, self::PROGRESS_MARKER, strlen(self::PROGRESS_MARKER)) !== 0) {
            return;
        }
        $parts = explode('|', $line);
        if (count($parts) < 7) {
            return;
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

        $onProgress([
            'percent'    => $percent,
            'downloaded' => $downloaded,
            'total'      => $total,
            'speed'      => $speed,
            'eta'        => $eta,
        ]);
    }

    /** Create a fresh, empty temp dir under Craft's temp path. */
    private function makeTempDir(): string
    {
        $base = Craft::$app->getPath()->getTempPath() . '/video-downloader';
        $dir  = $base . '/' . bin2hex(random_bytes(8));
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

    public function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
