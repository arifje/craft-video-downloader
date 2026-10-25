<?php

namespace arifje\craftvideodownloader\services;

use arifje\craftvideodownloader\jobs\DownloadJob;
use arifje\craftvideodownloader\models\Settings;
use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\User;
use craft\fields\Assets as AssetsField;
use craft\models\VolumeFolder;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * The "Scrape URL" flow for an Assets field, shared by the control panel
 * endpoints (DownloadController) and the MCP tools: field and element checks,
 * URL validation, the format choice, the upload folder and its permission,
 * enqueueing the download, and reading a job back for its owner.
 *
 * Authorization mirrors Craft's own assets/upload action:
 *  - the field must be an Assets field the plugin settings allow;
 *  - with an element, the user must be allowed to save it and the field must
 *    be reachable from its field layout (directly, or nested via
 *    Matrix/Neo/Super Table/CKEditor-style container fields);
 *  - the user needs `saveAssets:<volumeUid>` on the upload folder's volume,
 *    or it must be their own temporary-uploads folder.
 */
final class FieldDownloads
{
    /** Format presets an Assets field download may request (no audio-only). */
    public const FIELD_PRESETS = [Downloader::PRESET_COMPATIBLE, Downloader::PRESET_BEST];

    public function __construct(private Settings $settings)
    {
    }

    public function assertEnabled(): void
    {
        if (!$this->settings->enabled) {
            throw new ForbiddenHttpException('Video Downloader is disabled.');
        }
    }

    /**
     * The target Assets field, by id or handle. Ids find a field in any
     * context; a handle resolves through the element's field layout when one
     * is given (so a field inside a Matrix block is found from the block),
     * otherwise among global fields.
     */
    public function field(int|string $field, ?ElementInterface $element = null): AssetsField
    {
        if (is_int($field) || ctype_digit($field)) {
            $resolved = (int) $field > 0 ? Craft::$app->getFields()->getFieldById((int) $field) : null;
        } else {
            $resolved = $element?->getFieldLayout()?->getFieldByHandle($field)
                ?? Craft::$app->getFields()->getFieldByHandle($field);
        }

        if (!$resolved instanceof AssetsField) {
            throw new BadRequestHttpException('Unknown or non-Assets field.');
        }
        if (!$this->settings->allowsField($resolved)) {
            throw new ForbiddenHttpException('Video Downloader is not enabled for this field.');
        }

        return $resolved;
    }

    public function assertSite(?int $siteId): void
    {
        if ($siteId !== null && Craft::$app->getSites()->getSiteById($siteId) === null) {
            throw new BadRequestHttpException('Unknown site.');
        }
    }

    /**
     * The element being edited, which $user must be allowed to save.
     */
    public function element(int $elementId, ?int $siteId, User $user): ElementInterface
    {
        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);
        if ($element === null) {
            throw new BadRequestHttpException('Unknown element.');
        }
        if (!$element->canSave($user)) {
            throw new ForbiddenHttpException('You are not allowed to edit this element.');
        }

        return $element;
    }

    public function assertFieldOnElement(AssetsField $field, ElementInterface $element): void
    {
        if (!self::fieldIsAvailableToElement($field, $element)) {
            Craft::warning("Video Downloader: field {$field->id} is not reachable from the layout of element {$element->id}.", __METHOD__);
            throw new BadRequestHttpException('That field is not available on this element.');
        }
    }

    public function url(string $url): string
    {
        try {
            return Downloader::normalizeUrl($url, $this->settings->getAllowedHostsList());
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }
    }

    /**
     * Validate the options-step choice. Without a preset the job uses the
     * default (capped) format selector.
     *
     * @return array{preset: ?string, resolution: int}
     */
    public function format(mixed $preset, mixed $resolution): array
    {
        $preset = ($preset === null || $preset === '') ? null : (string) $preset;
        if ($preset !== null && !in_array($preset, self::FIELD_PRESETS, true)) {
            throw new BadRequestHttpException('Unknown format preset.');
        }
        if (!is_numeric($resolution ?? 0) || (int) $resolution < 0) {
            throw new BadRequestHttpException('Invalid resolution.');
        }

        return [
            'preset'     => $preset,
            'resolution' => Downloader::clampToCeiling((int) $resolution, Downloader::fromSettings($this->settings)->maxResolution()),
        ];
    }

    /**
     * The folder the download lands in, which $user must be allowed to upload
     * to. Null when the field's upload location cannot be resolved.
     */
    public function folder(AssetsField $field, ?ElementInterface $element, User $user): ?VolumeFolder
    {
        // resolveDynamicPathToFolderId() honours the field's restrict/default
        // upload location settings and, for unsaved elements, may return the
        // user's temporary-uploads folder — the same behavior as "Upload files".
        try {
            $folder = Craft::$app->getAssets()->getFolderById($field->resolveDynamicPathToFolderId($element));
        } catch (\Throwable $e) {
            Craft::warning('Video Downloader: could not resolve the upload folder: ' . $e->getMessage(), __METHOD__);
            return null;
        }
        if (!$folder instanceof VolumeFolder) {
            return null;
        }

        if (!$folder->volumeId) {
            $temporaryFolder = Craft::$app->getAssets()->getUserTemporaryUploadFolder($user);
            if ($temporaryFolder->id != $folder->id) {
                throw new ForbiddenHttpException('You are not allowed to upload to this folder.');
            }
        } elseif (!$user->can('saveAssets:' . $folder->getVolume()->uid)) {
            throw new ForbiddenHttpException('User is not authorized to perform this action.');
        }

        return $folder;
    }

    /**
     * Record and queue a download. Returns the job id, or null when the job
     * record cannot be written.
     */
    public function enqueue(string $url, AssetsField $field, VolumeFolder $folder, ?int $elementId, ?int $siteId, User $user, ?string $preset, int $resolution): ?string
    {
        $record = (new JobStore())->create([
            'userId'    => (int) $user->id,
            'fieldId'   => (int) $field->id,
            'folderId'  => (int) $folder->id,
            'elementId' => $elementId,
            'siteId'    => $siteId,
        ]);
        if ($record === null) {
            return null;
        }

        $job = new DownloadJob();
        $job->jobId      = $record['id'];
        $job->url        = $url;
        $job->fieldId    = (int) $field->id;
        $job->folderId   = (int) $folder->id;
        $job->siteId     = $siteId;
        $job->uploaderId = (int) $user->id;
        $job->preset     = $preset;
        $job->resolution = $resolution;
        Craft::$app->getQueue()->push($job);

        return $record['id'];
    }

    /**
     * A URL's metadata and available resolutions; nothing is downloaded.
     *
     * @return array{url: string, meta: array, options: array, maxResolution: int, maxFilesizeMb: int}
     */
    public function inspect(string $url): array
    {
        @set_time_limit(120);
        $downloader = Downloader::fromSettings($this->settings);
        $info = $downloader->inspect($url);
        $summary = Downloader::summarizeFormats($info, $downloader->maxResolution(), $downloader->maxFilesizeBytes());

        return [
            'url'           => $url,
            'meta'          => Downloader::metaFromInfo($info),
            'options'       => $summary['options'],
            'maxResolution' => $downloader->maxResolution(),
            'maxFilesizeMb' => (int) $this->settings->maxFilesizeMb,
        ];
    }

    /**
     * A job's status for its owner. Unknown and not-owned are deliberately
     * indistinguishable, so job ids don't leak.
     */
    public function status(string $jobId, int $userId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            throw new BadRequestHttpException('Invalid jobId.');
        }

        $record = (new JobStore())->get($jobId);
        if ($record === null || (int) ($record['userId'] ?? 0) !== $userId) {
            throw new NotFoundHttpException('Unknown or expired jobId.');
        }

        $status   = (string) ($record['status'] ?? 'unknown');
        $response = [
            'jobId'    => $record['id'] ?? $jobId,
            'status'   => $status,
            'stage'    => $record['stage'] ?? null,
            'progress' => $record['progress'] ?? 0,
            'meta'     => $record['meta'] ?? null,
            'download' => $record['download'] ?? null,
        ];
        if ($status === 'done') {
            $response['result'] = $record['result'] ?? null;
        } elseif ($status === 'failed') {
            $response['error'] = $record['error'] ?? 'The download failed.';
        }

        return $response;
    }

    /* ------------------------------------------------------------ layout walk */

    /**
     * Whether a field is reachable from an element's field layout — directly,
     * or nested inside container fields (Craft 4 Matrix block types, Craft 5
     * Matrix/CKEditor entry types, Neo/Super Table block types), detected by
     * duck-typing so no version- or plugin-specific classes are referenced.
     */
    public static function fieldIsAvailableToElement(FieldInterface $field, ElementInterface $element): bool
    {
        $layout = $element->getFieldLayout();
        if ($layout === null) {
            return true; // nothing to validate against
        }
        $seen = [];
        return self::layoutContainsField($layout, (int) $field->id, 3, $seen);
    }

    /**
     * @param array<int,bool> $seen
     */
    private static function layoutContainsField(mixed $layout, int $fieldId, int $depth, array &$seen): bool
    {
        if ($layout === null || !method_exists($layout, 'getCustomFields')) {
            return false;
        }
        foreach ($layout->getCustomFields() as $layoutField) {
            $id = (int) ($layoutField->id ?? 0);
            if ($id === $fieldId) {
                return true;
            }
            if ($depth < 1 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $containers = [];
            if (method_exists($layoutField, 'getEntryTypes')) {
                $containers = $layoutField->getEntryTypes(); // Craft 5 Matrix, CKEditor
            } elseif (method_exists($layoutField, 'getBlockTypes')) {
                $containers = $layoutField->getBlockTypes(); // Craft 4 Matrix, Neo, Super Table
            }
            foreach ($containers as $container) {
                if (!is_object($container) || !method_exists($container, 'getFieldLayout')) {
                    continue;
                }
                try {
                    if (self::layoutContainsField($container->getFieldLayout(), $fieldId, $depth - 1, $seen)) {
                        return true;
                    }
                } catch (\Throwable $e) {
                    // A container that can't produce a layout shouldn't veto the walk.
                }
            }
        }
        return false;
    }
}
