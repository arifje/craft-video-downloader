<?php

namespace arifje\craftvideodownloader\services;

use Craft;

/**
 * Holds files produced by the CP download tool until the requesting user has
 * fetched them.
 *
 * Each job gets its own directory, `<base>/<jobId>/`, under `@storage` (never
 * web-accessible; files are only ever served through the owner-checked
 * `video-downloader/tool/file` action). Every path is derived from a validated
 * 32-hex job id and resolved with realpath() before use, so nothing outside
 * the base can be read or deleted. Directories older than the TTL are swept
 * whenever a new tool download starts.
 */
final class ToolStorage
{
    /** How long a finished download stays fetchable. */
    public const TTL_SECONDS = 86400;

    private string $base;

    public function __construct(?string $base = null)
    {
        if ($base === null || $base === '') {
            // Only touch Craft when no explicit base is given (keeps tests Craft-free).
            $storage = Craft::getAlias('@storage', false);
            $base = ($storage !== false ? $storage : sys_get_temp_dir()) . '/video-downloader/files';
        }
        $this->base = rtrim($base, '/');
    }

    public function base(): string
    {
        return $this->base;
    }

    /**
     * Move a downloaded file into the job's directory and return its new path.
     *
     * @throws \InvalidArgumentException for an invalid job id
     * @throws \RuntimeException when the file can't be stored
     */
    public function store(string $jobId, string $sourcePath): string
    {
        $dir = $this->dirFor($jobId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the download storage directory.');
        }

        $name = self::safeFilename(basename($sourcePath));
        $target = $dir . '/' . $name;

        if (!@rename($sourcePath, $target)) {
            // Cross-device move (temp and storage on different filesystems).
            if (!@copy($sourcePath, $target)) {
                throw new \RuntimeException('Could not store the downloaded file.');
            }
            @unlink($sourcePath);
        }

        return $target;
    }

    /**
     * The stored file for a job, or null if there is none (or it expired).
     *
     * @throws \InvalidArgumentException for an invalid job id
     */
    public function find(string $jobId): ?string
    {
        $dir = realpath($this->dirFor($jobId));
        $base = realpath($this->base);
        if ($dir === false || $base === false || dirname($dir) !== $base) {
            return null;
        }
        foreach (glob($dir . '/*') ?: [] as $path) {
            $real = realpath($path);
            if ($real !== false && is_file($real) && dirname($real) === $dir) {
                return $real;
            }
        }
        return null;
    }

    /** Delete a job's stored file + directory. Contained to the base. */
    public function delete(string $jobId): bool
    {
        if (!self::isValidId($jobId)) {
            return false;
        }
        $dir = realpath($this->dirFor($jobId));
        $base = realpath($this->base);
        if ($dir === false || $base === false || dirname($dir) !== $base) {
            return false;
        }
        foreach (glob($dir . '/*') ?: [] as $path) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
        return true;
    }

    /** Remove job directories older than the TTL. */
    public function cleanup(int $ttlSeconds = self::TTL_SECONDS): int
    {
        $removed = 0;
        $cutoff = time() - max(60, $ttlSeconds);
        foreach (glob($this->base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (self::isValidId($id) && filemtime($dir) < $cutoff && $this->delete($id)) {
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * @throws \InvalidArgumentException for an invalid job id
     */
    private function dirFor(string $jobId): string
    {
        if (!self::isValidId($jobId)) {
            throw new \InvalidArgumentException('Invalid job id.');
        }
        return $this->base . '/' . $jobId;
    }

    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', $id);
    }

    /**
     * A filesystem- and header-safe filename: ASCII letters, digits, dot,
     * dash, underscore, space and brackets only; never empty, never hidden.
     */
    public static function safeFilename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._\-\[\] ]+/', '_', $name) ?? '';
        $name = ltrim(trim($name), '.');
        if ($name === '' || !str_contains($name, '.')) {
            $name = 'video' . ($name !== '' ? '_' . $name : '') . '.mp4';
        }
        if (strlen($name) > 180) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = substr(pathinfo($name, PATHINFO_FILENAME), 0, 170) . '.' . $ext;
        }
        return $name;
    }
}
