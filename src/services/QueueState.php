<?php

namespace arifje\craftvideodownloader\services;

use Craft;
use craft\queue\Queue;
use craft\queue\QueueInterface;
use yii\base\InvalidArgumentException;

/**
 * What Craft's queue itself says about a download job.
 *
 * A job reports its progress through its record in the JobStore. When the job
 * cannot write that record (the queue worker runs as another system user than
 * the website, for one) or dies before it gets to, the record stays "queued"
 * for ever while the queue shows the job as failed. The queue is asked as a
 * second opinion for every record that is not finished yet.
 */
final class QueueState
{
    /** How long a record may lag behind a job that has left the queue, in seconds. */
    private const GRACE_SECONDS = 15;

    /**
     * A record as its owner should see it: unchanged while the queue has the
     * job waiting or running, and failed, with the reason, when the queue says
     * the job failed or no longer has it without a result being recorded.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public static function settle(array $record): array
    {
        $status = (string) ($record['status'] ?? '');
        if ($status === 'done' || $status === 'failed') {
            return $record;
        }
        $state = self::of($record['queueId'] ?? null);
        if ($state === null) {
            return $record;
        }
        if ($state['state'] === 'failed') {
            return array_merge($record, [
                'status' => 'failed',
                'stage'  => 'failed',
                'error'  => self::reason($state['error']),
            ]);
        }
        // Gone from the queue without a result: released by hand, or finished
        // while its record could not be written. A job that just finished gets
        // a moment for its last write to land.
        if ($state['state'] === 'gone' && (time() - (int) ($record['updated_at'] ?? 0)) > self::GRACE_SECONDS) {
            return array_merge($record, [
                'status' => 'failed',
                'stage'  => 'failed',
                'error'  => 'The download job is no longer in the queue and left no result. It was removed, or it could not record its result. Start the download again.',
            ]);
        }

        return $record;
    }

    /**
     * @return array{state: string, error: string|null}|null  state is waiting,
     *         running, failed or gone; null when the queue cannot tell
     */
    public static function of(mixed $queueId): ?array
    {
        if ((!is_string($queueId) && !is_int($queueId)) || (string) $queueId === '') {
            return null;
        }
        $queue = Craft::$app->getQueue();
        if (!$queue instanceof QueueInterface) {
            return null;
        }
        try {
            $details = $queue->getJobDetails((string) $queueId);
        } catch (InvalidArgumentException $e) {
            // Craft's way of saying the queue has no job with this id.
            return ['state' => 'gone', 'error' => null];
        } catch (\Throwable $e) {
            return null;
        }

        return match ((int) ($details['status'] ?? 0)) {
            Queue::STATUS_FAILED   => ['state' => 'failed', 'error' => (string) ($details['error'] ?? '')],
            Queue::STATUS_RESERVED => ['state' => 'running', 'error' => null],
            Queue::STATUS_WAITING  => ['state' => 'waiting', 'error' => null],
            default                => null,
        };
    }

    /**
     * The queue's error as a sentence for the editor or the agent. Paths on
     * the server are left out; the queue manager shows the full error.
     */
    public static function reason(?string $error): string
    {
        $error = trim((string) $error);
        if ($error !== '' && stripos($error, 'permission denied') !== false && str_contains($error, 'video-downloader')) {
            return 'The download job failed: the queue worker may not write in storage/video-downloader. '
                . 'The queue runs as another system user than the website; make that folder writable for both, then start the download again.';
        }
        if ($error === '') {
            return 'The download job failed. Craft\'s queue manager (Utilities → Queue Manager) shows why.';
        }
        // Absolute paths say where the site lives on the server.
        $error = (string) preg_replace('#(?<![\w.:/-])/(?:[\w.@+-]+/)+[\w.@+-]*#', '[path]', $error);

        return 'The download job failed: ' . mb_substr($error, 0, 300);
    }
}
