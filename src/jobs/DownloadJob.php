<?php

namespace arifje\craftvideodownloader\jobs;

use arifje\craftvideodownloader\Plugin;
use arifje\craftvideodownloader\services\Downloader;
use arifje\craftvideodownloader\services\JobStore;
use Craft;
use craft\elements\Asset;
use craft\fields\Assets as AssetsField;
use craft\helpers\Assets as AssetsHelper;
use craft\queue\BaseJob;
use yii\queue\RetryableJobInterface;

/**
 * Downloads a video with yt-dlp and creates an Asset from it.
 *
 * The destination folder was resolved and authorized at request time (in web
 * context, exactly like a manual upload) and travels with the job — a console
 * queue worker never needs a logged-in session to resolve it. Everything that
 * can change between enqueue and execution is re-checked here: plugin enabled,
 * field still allowed by the settings, folder still present, and the requesting
 * user still permitted to upload to the destination volume.
 *
 * Retry safety: the job implements {@see RetryableJobInterface} with a TTR
 * sized to the configured download timeout so a long download is never handed
 * to a second worker mid-run, and execute() is idempotent — if a previous
 * attempt already produced the asset, it is never created twice.
 */
class DownloadJob extends BaseJob implements RetryableJobInterface
{
    public string $jobId = '';
    public string $url = '';
    public int $fieldId = 0;
    public int $folderId = 0;
    public ?int $elementId = null;
    public ?int $siteId = null;
    public ?int $uploaderId = null;

    public function getTtr(): int
    {
        $timeout = 300;
        try {
            $timeout = (int) Plugin::getInstance()->getSettings()->timeout;
        } catch (\Throwable $e) {
            // settings unavailable — fall back to the default
        }
        // download timeout + probe cap + asset-save headroom
        return max(300, $timeout) + 240;
    }

    public function canRetry($attempt, $error): bool
    {
        // Single attempt: the editor already sees the failure (and can just
        // try again), and a silent retry would report "failed" while still
        // running — or, for field downloads, create an asset nobody attaches.
        return false;
    }

    public function execute($queue): void
    {
        $store  = new JobStore();
        $record = $store->get($this->jobId);

        // Idempotency for retries/redeliveries: never create a second asset.
        if ($record !== null) {
            if (($record['status'] ?? '') === 'done') {
                return;
            }
            $existingId = (int) ($record['result']['assetId'] ?? 0);
            if ($existingId > 0) {
                $store->update($this->jobId, ['status' => 'done', 'stage' => 'done', 'progress' => 1.0]);
                return;
            }
        }

        $store->update($this->jobId, ['status' => 'running', 'stage' => 'starting', 'progress' => 0.05]);

        $dir = null;
        /** @var Downloader|null $downloader */
        $downloader = null;

        try {
            $settings = Plugin::getInstance()->getSettings();

            // --- re-check everything that may have changed since enqueue ----
            if (!$settings->enabled) {
                throw new \RuntimeException('Video Downloader has been disabled.');
            }

            $field = Craft::$app->getFields()->getFieldById($this->fieldId);
            if (!$field instanceof AssetsField) {
                throw new \RuntimeException('The target field no longer exists (or is not an Assets field).');
            }
            if (!$settings->allowsField($field)) {
                throw new \RuntimeException('Video Downloader is no longer enabled for this field.');
            }

            $folder = $this->folderId > 0 ? Craft::$app->getAssets()->getFolderById($this->folderId) : null;
            if ($folder === null) {
                throw new \RuntimeException('The destination folder no longer exists.');
            }

            $user = $this->uploaderId ? Craft::$app->getUsers()->getUserById($this->uploaderId) : null;
            if ($user === null) {
                throw new \RuntimeException('The requesting user no longer exists.');
            }
            if ($folder->volumeId) {
                $volume = $folder->getVolume();
                if (!$user->can('saveAssets:' . $volume->uid)) {
                    throw new \RuntimeException('The requesting user is no longer allowed to upload to this volume.');
                }
            } else {
                // Only the requester's own temporary-uploads folder is acceptable
                // as a volume-less destination (unsaved-element uploads).
                $temporaryFolder = Craft::$app->getAssets()->getUserTemporaryUploadFolder($user);
                if ($temporaryFolder->id != $folder->id) {
                    throw new \RuntimeException('The destination folder is not available.');
                }
            }

            $downloader = Downloader::fromSettings($settings);

            // --- metadata first, so the UI can show what's being fetched ----
            $store->update($this->jobId, ['stage' => 'extracting', 'progress' => 0.1]);
            $this->setProgress($queue, 0.1, 'Reading video info');
            $meta = $downloader->probe($this->url);
            if ($meta) {
                $store->update($this->jobId, ['meta' => $meta]);
            }

            $store->update($this->jobId, ['stage' => 'downloading', 'progress' => 0.15]);
            $this->setProgress($queue, 0.15, 'Downloading');

            // Map yt-dlp's 0–100% onto the 0.15–0.85 band of overall job progress,
            // throttling disk writes so frequent updates don't hammer the store.
            $lastWrite = 0.0;
            $onProgress = function (array $p) use ($store, $queue, &$lastWrite): void {
                $pct = $p['percent'];
                $overall = $pct !== null ? 0.15 + 0.70 * (max(0.0, min(100.0, $pct)) / 100) : 0.15;
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

            $download = $downloader->download($this->url, $onProgress);
            $dir = $download['dir'];

            // --- honour the field's accepted file kinds ---------------------
            if ($field->restrictFiles && !empty($field->allowedKinds)) {
                $kind = AssetsHelper::getFileKindByExtension($download['filename']);
                if (!in_array($kind, (array) $field->allowedKinds, true)) {
                    throw new \RuntimeException("The downloaded file is a \"{$kind}\" file, which this field does not accept.");
                }
            }

            $store->update($this->jobId, ['stage' => 'saving', 'progress' => 0.9]);
            $this->setProgress($queue, 0.9, 'Saving asset');

            $asset = new Asset();
            $asset->tempFilePath = $download['path'];
            $asset->setFilename(AssetsHelper::prepareAssetName($download['filename']));
            $asset->newFolderId = $folder->id;
            if ($folder->volumeId) {
                $asset->setVolumeId($folder->volumeId);
            }
            $asset->uploaderId = $this->uploaderId;
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);

            if (!Craft::$app->getElements()->saveElement($asset)) {
                $errors = implode(' ', $asset->getFirstErrors());
                throw new \RuntimeException('Could not save the downloaded asset.' . ($errors !== '' ? " {$errors}" : ''));
            }

            // Persist the result immediately after the save so a crash between
            // here and "done" can be recovered idempotently on retry.
            $store->update($this->jobId, [
                'status'   => 'done',
                'stage'    => 'done',
                'progress' => 1.0,
                'result'   => [
                    'assetId'  => (int) $asset->id,
                    'siteId'   => (int) $asset->siteId,
                    'filename' => $asset->filename,
                ],
            ]);
        } catch (\Throwable $e) {
            $store->update($this->jobId, [
                'status' => 'failed',
                'stage'  => 'failed',
                'error'  => $e->getMessage(),
            ]);
            Craft::error('[video-downloader/job ' . $this->jobId . '] ' . $e->getMessage(), __METHOD__);
            // Re-throw so the failure is visible in Craft's queue (and retried
            // once, per canRetry(); execute() is idempotent for the asset).
            throw $e;
        } finally {
            if ($dir !== null && $downloader !== null) {
                $downloader->removeDir($dir);
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'Video Downloader: fetch ' . $this->url;
    }
}
