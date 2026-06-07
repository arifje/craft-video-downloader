<?php

namespace arifje\craftvideodownloader\services;

use arifje\craftvideodownloader\models\Settings;
use Craft;
use mikehaertl\shellcommand\Command;

/**
 * Thin wrapper around the yt-dlp binary.
 *
 * The single shell-out goes through {@see Command} with one {@see Command::addArg()}
 * call per token, so yt-dlp never sees a shell — the user-supplied URL and option
 * values can't be interpreted as shell metacharacters.
 *
 * Each download gets its own empty temp directory under Craft's temp path (so the
 * resulting file passes Asset::_validateTempFilePath()), and the produced file is
 * located by scanning that directory afterwards — robust across yt-dlp versions.
 */
final class Downloader
{
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
     * Download the video at $url with yt-dlp.
     *
     * @return array{path:string,filename:string,dir:string,stderr:string}
     *         path     absolute path to the downloaded file (caller cleans up the dir)
     *         filename the file's basename, used as the Asset filename
     *         dir      the temp directory created for this download
     *         stderr   yt-dlp's stderr (kept for diagnostics)
     * @throws \RuntimeException on any failure.
     */
    public function download(string $url): array
    {
        $url = self::normalizeUrl($url, $this->allowedHosts);
        $dir = $this->makeTempDir();

        // Output template: a human filename plus the source id to keep it unique.
        // %(title).150B caps the title at 150 bytes so long captions don't blow up
        // the filename. --restrict-filenames keeps it ASCII/filesystem-safe.
        $outputTemplate = $dir . '/%(title).150B [%(id)s].%(ext)s';

        $command = new Command([
            'command' => $this->ytDlpPath,
            'timeout' => $this->timeout > 0 ? $this->timeout : null,
        ]);

        $command->addArg('-f', $this->format);
        $command->addArg('-o', $outputTemplate);
        $command->addArg('--merge-output-format', 'mp4');
        $command->addArg('--no-playlist');
        $command->addArg('--no-part');
        $command->addArg('--no-cache-dir');
        $command->addArg('--restrict-filenames');
        $command->addArg('--no-progress');
        $command->addArg('--no-warnings');
        if ($this->maxFilesizeMb > 0) {
            $command->addArg('--max-filesize', $this->maxFilesizeMb . 'M');
        }
        $command->addArg($url);

        $ok = $command->execute();

        if (!$ok) {
            $this->removeDir($dir);
            $stderr = trim($command->getStdErr() . ' ' . $command->getError());
            // A missing/non-executable binary surfaces as a launch failure.
            if ($command->getExitCode() === 127 || stripos($stderr, 'not found') !== false || stripos($stderr, 'No such file') !== false) {
                throw new \RuntimeException(
                    "Could not run yt-dlp at \"{$this->ytDlpPath}\". Check it's installed and the path is correct in the plugin settings."
                );
            }
            throw new \RuntimeException($stderr !== '' ? "yt-dlp failed: {$stderr}" : 'yt-dlp failed with no output.');
        }

        $file = $this->findDownloadedFile($dir);
        if ($file === null) {
            $stderr = trim($command->getStdErr());
            $this->removeDir($dir);
            $hint = $stderr !== '' ? " ({$stderr})" : '';
            throw new \RuntimeException("yt-dlp reported success but produced no file{$hint}. The post may be private, region-locked, or larger than the size limit.");
        }

        return [
            'path'     => $file,
            'filename' => basename($file),
            'dir'      => $dir,
            'stderr'   => trim($command->getStdErr()),
        ];
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
