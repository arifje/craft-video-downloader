<?php

namespace arifje\craftvideodownloader\jobs;

use arifje\craftvideodownloader\controllers\ToolController;
use arifje\craftvideodownloader\Plugin;
use arifje\craftvideodownloader\services\Downloader;
use arifje\craftvideodownloader\services\JobStore;
use arifje\craftvideodownloader\services\ToolStorage;
use Craft;
use craft\queue\BaseJob;
use yii\queue\RetryableJobInterface;

/**
 * Downloads a video for the CP download tool and parks it in {@see ToolStorage}
 * so the requesting user can fetch it to their device. No Asset is created.
 *
 * Re-checks at execution time that the tool is still enabled and the user
 * still holds the tool permission; the resolution was clamped to the server
 * ceiling at request time and is clamped again here in case the ceiling was
 * lowered in between. Idempotent across retries (a finished job is a no-op),
 * with a TTR sized to the configured download timeout.
 */
class ToolDownloadJob extends BaseJob implements RetryableJobInterface
{
    /** @var string Job-store record id (32 hex). */
    public string $jobId = '';

    /** @var string Source URL (already normalized + validated). */
    public string $url = '';

    /** @var string One of Downloader::PRESETS. */
    public string $preset = Downloader::PRESET_COMPATIBLE;

    /** @var int Short-side resolution profile (0 = best available). */
    public int $resolution = 0;

    /** @var int Requesting user id. */
    public int $userId = 0;

    /** @inheritdoc */
    public function getTtr(): int
    {
        $timeout = 300;
        try {
            $timeout = (int) Plugin::getInstance()->getSettings()->timeout;
        } catch (\Throwable $e) {
            // settings unavailable, keep the default
        }
        return max(300, $timeout) + 120;
    }

    /** @inheritdoc */
    public function canRetry($attempt, $error): bool
    {
        return $attempt < 2;
    }

    /**
     * @inheritdoc
     * @throws \Throwable re-thrown after the failure is recorded
     */
    public function execute($queue): void
    {
        $store = new JobStore();
        $record = $store->get($this->jobId);
        if ($record !== null && ($record['status'] ?? '') === 'done') {
            return; // retry/redelivery of a finished job
        }

        $store->update($this->jobId, ['status' => 'running', 'stage' => 'starting', 'progress' => 0.05]);

        $dir = null;
        $downloader = null;

        try {
            $settings = Plugin::getInstance()->getSettings();
            if (!$settings->toolEnabled) {
                throw new \RuntimeException('The video download tool has been disabled.');
            }

            $user = $this->userId ? Craft::$app->getUsers()->getUserById($this->userId) : null;
            if ($user === null || !$user->can(ToolController::PERMISSION_USE_TOOL)) {
                throw new \RuntimeException('You are no longer allowed to use the download tool.');
            }

            if (!in_array($this->preset, Downloader::PRESETS, true)) {
                throw new \RuntimeException('Unknown format preset.');
            }

            $downloader = Downloader::fromSettings($settings);
            $resolution = Downloader::clampToCeiling($this->resolution, $downloader->maxResolution());

            $store->update($this->jobId, ['stage' => 'downloading', 'progress' => 0.1]);
            $this->setProgress($queue, 0.1, 'Downloading');

            $lastWrite = 0.0;
            $onProgress = function (array $p) use ($store, $queue, &$lastWrite): void {
                $pct = $p['percent'];
                $overall = $pct !== null ? 0.1 + 0.8 * (max(0.0, min(100.0, $pct)) / 100) : 0.1;
                $now = microtime(true);
                if (($now - $lastWrite) < 0.4 && !($pct !== null && $pct >= 100)) {
                    return;
                }
                $lastWrite = $now;
                $store->update($this->jobId, [
                    'stage'    => 'downloading',
                    'progress' => round($overall, 4),
                    'download' => [
                        'percent'    => $pct,
                        'downloaded' => $p['downloaded'],
                        'total'      => $p['total'],
                        'speed'      => $p['speed'],
                        'eta'        => $p['eta'],
                    ],
                ]);
                $this->setProgress($queue, $overall, 'Downloading');
            };

            $download = $downloader->download(
                $this->url,
                $onProgress,
                Downloader::buildToolSelector($resolution, $this->preset),
                Downloader::mergeFormatForPreset($this->preset),
            );
            $dir = $download['dir'];

            $store->update($this->jobId, ['stage' => 'saving', 'progress' => 0.95]);
            $path = (new ToolStorage())->store($this->jobId, $download['path']);

            $store->update($this->jobId, [
                'status'   => 'done',
                'stage'    => 'done',
                'progress' => 1.0,
                'result'   => [
                    'filename' => basename($path),
                    'size'     => (int) (filesize($path) ?: 0),
                    'ext'      => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                ],
            ]);
        } catch (\Throwable $e) {
            $store->update($this->jobId, [
                'status' => 'failed',
                'stage'  => 'failed',
                'error'  => $e->getMessage(),
            ]);
            Craft::error('[video-downloader/tool ' . $this->jobId . '] ' . $e->getMessage(), __METHOD__);
            throw $e;
        } finally {
            if ($dir !== null && $downloader !== null) {
                $downloader->removeDir($dir);
            }
        }
    }

    /** @inheritdoc */
    protected function defaultDescription(): ?string
    {
        return 'Video Downloader (tool): fetch ' . $this->url;
    }
}
