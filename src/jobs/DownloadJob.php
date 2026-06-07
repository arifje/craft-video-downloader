<?php

namespace arifje\craftvideodownloader\jobs;

use arifje\craftvideodownloader\Plugin;
use arifje\craftvideodownloader\services\Downloader;
use arifje\craftvideodownloader\services\JobStore;
use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\fields\Assets as AssetsField;
use craft\models\Volume;
use craft\models\VolumeFolder;
use craft\queue\BaseJob;

/**
 * Downloads a video with yt-dlp and creates an Asset from it.
 *
 * Runs on Craft's queue. The new asset's id is written to the {@see JobStore} so
 * the polling endpoint ({@see \arifje\craftvideodownloader\controllers\DownloadController::actionStatus()})
 * can hand it back to the browser, which attaches it to the open field.
 *
 * Note on context: when Craft runs the queue inline during a web request (the
 * default, with no `queue/listen` worker) the requesting user is present, so the
 * field's upload-folder resolution works exactly like a manual upload. Under a
 * console worker there's no user, so a temp-folder fallback resolves to the
 * field's configured volume instead.
 */
class DownloadJob extends BaseJob
{
    public string $jobId = '';
    public string $url = '';
    public ?int $elementId = null;
    public ?int $siteId = null;
    public int $fieldId = 0;
    public ?int $uploaderId = null;

    public function execute($queue): void
    {
        $store = new JobStore();
        $store->update($this->jobId, ['status' => 'running', 'stage' => 'starting', 'progress' => 0.05]);

        $dir = null;
        try {
            $settings = Plugin::getInstance()->getSettings();

            $field = Craft::$app->getFields()->getFieldById($this->fieldId);
            if (!$field instanceof AssetsField) {
                throw new \RuntimeException('The target field is not an Assets field (or no longer exists).');
            }

            $element = $this->elementId
                ? Craft::$app->getElements()->getElementById($this->elementId, null, $this->siteId)
                : null;

            $folder = $this->resolveFolder($field, $element);

            $store->update($this->jobId, ['stage' => 'downloading', 'progress' => 0.15]);
            $this->setProgress($queue, 0.15, 'Downloading');

            $download = Downloader::fromSettings($settings)->download($this->url);
            $dir = $download['dir'];

            $store->update($this->jobId, ['stage' => 'saving', 'progress' => 0.85]);
            $this->setProgress($queue, 0.85, 'Saving asset');

            $asset = new Asset();
            $asset->tempFilePath = $download['path'];
            $asset->setFilename($download['filename']);
            $asset->newFolderId = $folder->id;
            $asset->setVolumeId($folder->volumeId);
            $asset->avoidFilenameConflicts = true;
            if ($this->uploaderId) {
                $asset->uploaderId = $this->uploaderId;
            }
            $asset->setScenario(Asset::SCENARIO_CREATE);

            if (!Craft::$app->getElements()->saveElement($asset)) {
                $errors = implode(' ', $asset->getFirstErrors());
                throw new \RuntimeException('Could not save the downloaded asset.' . ($errors !== '' ? " {$errors}" : ''));
            }

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
            // Re-throw so the failure is visible in Craft's queue too (and retried if configured).
            throw $e;
        } finally {
            if ($dir !== null) {
                (new Downloader($settings->getResolvedYtDlpPath(), $settings->format, 0, 0))->removeDir($dir);
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'Video Downloader: fetch ' . $this->url;
    }

    /**
     * Resolve the folder the field would upload to, honoring its restrict-to /
     * default-upload-location settings. Falls back to the root folder of the
     * field's configured volume when the dynamic resolution lands on a temp
     * folder (e.g. a console worker with no logged-in user).
     */
    private function resolveFolder(AssetsField $field, ?ElementInterface $element): VolumeFolder
    {
        $assets = Craft::$app->getAssets();

        try {
            $folderId = $field->resolveDynamicPathToFolderId($element);
            $folder = $assets->getFolderById($folderId);
            if ($folder !== null && $folder->volumeId !== null) {
                return $folder;
            }
        } catch (\Throwable $e) {
            // fall through to the volume-root fallback
        }

        $volume = $this->fieldVolume($field);
        if ($volume === null) {
            throw new \RuntimeException(
                'Could not determine a target volume for this field. Set a "Default Upload Location" on the Assets field, '
                . 'or run the queue inline (no queue/listen worker) so the upload folder resolves against the logged-in user.'
            );
        }

        $root = $assets->getRootFolderByVolumeId($volume->id);
        if ($root === null) {
            throw new \RuntimeException("Could not find the root folder for volume \"{$volume->name}\".");
        }
        return $root;
    }

    /** Best-effort: the volume a field points at, from its source settings. */
    private function fieldVolume(AssetsField $field): ?Volume
    {
        $volumes = Craft::$app->getVolumes();

        $candidates = [];
        $candidates[] = $field->restrictLocation
            ? ($field->restrictedLocationSource ?? null)
            : ($field->defaultUploadLocationSource ?? null);

        $sources = $field->sources;
        if (is_array($sources)) {
            $candidates = array_merge($candidates, $sources);
        }

        foreach ($candidates as $source) {
            if (is_string($source) && str_starts_with($source, 'volume:')) {
                $volume = $volumes->getVolumeByUid(substr($source, 7));
                if ($volume !== null) {
                    return $volume;
                }
            }
        }

        $all = $volumes->getAllVolumes();
        return $all[0] ?? null;
    }
}
