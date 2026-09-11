<?php

namespace arifje\craftvideodownloader\controllers;

use arifje\craftvideodownloader\jobs\DownloadJob;
use arifje\craftvideodownloader\Plugin;
use arifje\craftvideodownloader\services\Downloader;
use arifje\craftvideodownloader\services\JobStore;
use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\fields\Assets as AssetsField;
use craft\models\VolumeFolder;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Endpoints behind the "Scrape URL" button. Both require a logged-in user with
 * control-panel access and the standard CSRF token (Craft's JS sends it
 * automatically).
 *
 *   POST video-downloader/download/create   enqueue a download → { jobId }
 *   POST video-downloader/download/status    poll an owned job  → { status, … }
 *
 * Authorization model for create — mirrors Craft's own assets/upload action:
 *  - the field must be an Assets field the plugin settings allow;
 *  - when an element is given, the user must be allowed to save it and the
 *    field must be reachable from its field layout (directly, or nested via
 *    Matrix/Neo/Super Table/CKEditor-style container fields);
 *  - the upload folder is resolved from the field's own settings at request
 *    time (so the logged-in web context is available, exactly like a manual
 *    upload) and the user needs `saveAssets:<volumeUid>` on its volume — or it
 *    must be their own temporary-uploads folder (Craft's supported behavior
 *    for unsaved elements).
 *
 * Jobs are owned: the status endpoint only answers for the user who created
 * the job, and responds 404 (not 403) for anything else so job ids don't leak.
 */
class DownloadController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        // CP-only functionality: any CP user may exist without this permission.
        $this->requirePermission('accessCp');
        return true;
    }

    /**
     * Validate the request, resolve + authorize the upload destination, then
     * push a {@see DownloadJob} and return its id for the browser to poll.
     */
    public function actionCreate(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request  = Craft::$app->getRequest();
        $settings = Plugin::getInstance()->getSettings();
        $user     = Craft::$app->getUser()->getIdentity();

        if (!$settings->enabled) {
            throw new ForbiddenHttpException('Video Downloader is disabled.');
        }

        // --- field ---------------------------------------------------------
        // Resolved by id, not handle: the field may live inside a nested
        // context where handles aren't globally unique. getFieldById() finds a
        // field in any context; the browser sends the id from the field's own
        // element-select settings.
        $fieldId = (int) $request->getRequiredBodyParam('fieldId');
        $field   = $fieldId > 0 ? Craft::$app->getFields()->getFieldById($fieldId) : null;
        if (!$field instanceof AssetsField) {
            throw new BadRequestHttpException('Unknown or non-Assets field.');
        }
        if (!$settings->allowsField($field)) {
            throw new ForbiddenHttpException('Video Downloader is not enabled for this field.');
        }

        // --- site + element context ---------------------------------------
        $siteId = self::intParam($request->getBodyParam('siteId'));
        if ($siteId !== null && Craft::$app->getSites()->getSiteById($siteId) === null) {
            throw new BadRequestHttpException('Unknown site.');
        }

        $elementId = self::intParam($request->getBodyParam('elementId'));
        $element   = null;
        if ($elementId !== null) {
            $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);
            if ($element === null) {
                throw new BadRequestHttpException('Unknown element.');
            }
            if (!$element->canSave($user)) {
                throw new ForbiddenHttpException('You are not allowed to edit this element.');
            }
            if (!self::fieldIsAvailableToElement($field, $element)) {
                Craft::warning("Video Downloader: field {$field->id} is not reachable from the layout of element {$elementId}.", __METHOD__);
                throw new BadRequestHttpException('That field is not available on this element.');
            }
        }

        // --- URL -----------------------------------------------------------
        try {
            $url = Downloader::normalizeUrl((string) $request->getRequiredBodyParam('url'), $settings->getAllowedHostsList());
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        // --- destination folder (resolved now, in web context) -------------
        // resolveDynamicPathToFolderId() honours the field's restrict/default
        // upload location settings and, for unsaved elements, may return the
        // user's temporary-uploads folder — the same behavior as "Upload files".
        try {
            $folderId = $field->resolveDynamicPathToFolderId($element);
            $folder   = Craft::$app->getAssets()->getFolderById($folderId);
        } catch (\Throwable $e) {
            Craft::warning('Video Downloader: could not resolve the upload folder: ' . $e->getMessage(), __METHOD__);
            $folder = null;
        }
        if (!$folder instanceof VolumeFolder) {
            Craft::$app->getResponse()->setStatusCode(409);
            return $this->asJson([
                'success' => false,
                'error'   => 'Could not resolve this field\'s upload location. Set a Default Upload Location on the Assets field.',
            ]);
        }

        $this->requireSaveAssetsPermissionByFolder($folder);

        // --- enqueue -------------------------------------------------------
        $store  = new JobStore();
        $record = $store->create([
            'userId'    => (int) $user->id,
            'fieldId'   => (int) $field->id,
            'folderId'  => (int) $folder->id,
            'elementId' => $elementId,
            'siteId'    => $siteId,
        ]);
        if ($record === null) {
            Craft::$app->getResponse()->setStatusCode(500);
            return $this->asJson(['success' => false, 'error' => 'Could not create the job record. Check that @storage is writable.']);
        }

        $job = new DownloadJob();
        $job->jobId      = $record['id'];
        $job->url        = $url;
        $job->fieldId    = (int) $field->id;
        $job->folderId   = (int) $folder->id;
        $job->siteId     = $siteId;
        $job->uploaderId = (int) $user->id;
        Craft::$app->getQueue()->push($job);

        Craft::$app->getResponse()->setStatusCode(202);
        return $this->asJson([
            'success' => true,
            'jobId'   => $record['id'],
        ]);
    }

    /**
     * Report a job's status to its owner. Returns the new asset id once done so
     * the browser can attach it to the field.
     */
    public function actionStatus(): Response
    {
        $this->requireAcceptsJson();

        $id = (string) Craft::$app->getRequest()->getRequiredParam('jobId');
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new BadRequestHttpException('Invalid jobId.');
        }

        $record = (new JobStore())->get($id);
        $userId = (int) Craft::$app->getUser()->getId();

        // Unknown and not-owned are deliberately indistinguishable.
        if ($record === null || (int) ($record['userId'] ?? 0) !== $userId) {
            throw new NotFoundHttpException('Unknown or expired jobId.');
        }

        $status   = (string) ($record['status'] ?? 'unknown');
        $response = [
            'success'  => true,
            'jobId'    => $record['id'] ?? $id,
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

        return $this->asJson($response);
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * The same gate Craft's own assets/upload action applies: the user's own
     * temporary-uploads folder is always allowed; anything else requires
     * `saveAssets:` on the folder's volume.
     */
    private function requireSaveAssetsPermissionByFolder(VolumeFolder $folder): void
    {
        if (!$folder->volumeId) {
            $temporaryFolder = Craft::$app->getAssets()->getUserTemporaryUploadFolder();
            if ($temporaryFolder->id == $folder->id) {
                return;
            }
            throw new ForbiddenHttpException('You are not allowed to upload to this folder.');
        }

        $volume = $folder->getVolume();
        $this->requirePermission('saveAssets:' . $volume->uid);
    }

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

    private static function intParam(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }
}
