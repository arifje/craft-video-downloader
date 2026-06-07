<?php

namespace arifje\craftvideodownloader\services;

use Craft;

/**
 * File-based persistence for download jobs. Each job is a single JSON file named
 * <id>.json in a non-web-public directory under @storage.
 *
 * Craft removes queue jobs from its table once they succeed, so the queue itself
 * can't report a finished job's result — this store holds the status + result
 * (the new asset id) for the polling endpoint to read. Writes are atomic
 * (temp file + rename). Ported from the user's craft-video-tools JobStore.
 */
final class JobStore
{
    private string $dir;
    private int $ttlDays;

    public function __construct(?string $dir = null, int $ttlDays = 1)
    {
        $storage = Craft::getAlias('@storage', false);
        $base    = $dir ?: (($storage !== false ? $storage : sys_get_temp_dir()) . '/video-downloader/jobs');
        $this->dir     = rtrim($base, '/');
        $this->ttlDays = max(1, $ttlDays);
    }

    /**
     * Create a new job record with a random, unguessable id.
     *
     * @param array<string,mixed> $meta  Extra fields to persist with the record.
     * @return array<string,mixed>|null  The record, or null if it couldn't be written.
     */
    public function create(array $meta = []): ?array
    {
        $id  = bin2hex(random_bytes(16)); // 32 hex chars
        $now = time();
        $record = array_merge([
            'id'         => $id,
            'status'     => 'queued',
            'stage'      => 'queued',
            'progress'   => 0.0,
            'created_at' => $now,
            'updated_at' => $now,
            'result'     => null,
            'error'      => null,
        ], $meta);

        if (!$this->write($id, $record)) {
            return null;
        }

        $this->cleanup();

        return $record;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $id): ?array
    {
        if (!$this->isValidId($id)) {
            return null;
        }
        $path = $this->path($id);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Merge $changes into an existing record and persist it.
     *
     * @param array<string,mixed> $changes
     * @return array<string,mixed>|null
     */
    public function update(string $id, array $changes): ?array
    {
        $record = $this->get($id);
        if ($record === null) {
            return null;
        }
        $record = array_merge($record, $changes, ['updated_at' => time()]);
        return $this->write($id, $record) ? $record : null;
    }

    private function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', $id);
    }

    private function path(string $id): string
    {
        return $this->dir . '/' . $id . '.json';
    }

    /**
     * @param array<string,mixed> $record
     */
    private function write(string $id, array $record): bool
    {
        if (!$this->isValidId($id)) {
            return false;
        }
        if (!is_dir($this->dir) && !mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            return false;
        }
        $path = $this->path($id);
        $tmp  = $path . '.' . uniqid('', true) . '.tmp';
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($tmp, $json) === false) {
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** Delete job files older than the TTL. */
    private function cleanup(): void
    {
        $cutoff = time() - ($this->ttlDays * 86400);
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
