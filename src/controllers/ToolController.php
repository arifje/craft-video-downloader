<?php

namespace arifje\craftvideodownloader\controllers;

use arifje\craftvideodownloader\assets\ToolAsset;
use arifje\craftvideodownloader\jobs\ToolDownloadJob;
use arifje\craftvideodownloader\Plugin;
use arifje\craftvideodownloader\services\Downloader;
use arifje\craftvideodownloader\services\JobStore;
use arifje\craftvideodownloader\services\ToolStorage;
use Craft;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The standalone download tool (CP nav item "Video Downloader").
 *
 *   GET  video-downloader            the tool page (CP URL rule → actionIndex)
 *   POST video-downloader/tool/inspect   read a URL's metadata + resolutions
 *   POST video-downloader/tool/create    queue a download → { jobId }
 *   GET  video-downloader/tool/file      fetch a finished download (owner only)
 *
 * Progress is polled via the existing owner-scoped
 * `video-downloader/download/status` action.
 *
 * Every action passes the same gate ({@see self::requireToolAccess()}): CP
 * access, Craft's "Access Video Downloader" plugin permission, and the
 * "Download tool" setting. The queue job re-checks the permission and the
 * setting at execution time.
 */
class ToolController extends Controller
{
    /**
     * The permission that grants access to the download tool: Craft's own
     * per-plugin CP-section permission ("Access Video Downloader"), which Craft
     * registers automatically and already enforces on the /admin/video-downloader
     * page. Reusing it means editors need exactly one permission, and the
     * action endpoints (which Craft does not gate this way) enforce the same.
     */
    public const PERMISSION_USE_TOOL = 'accessPlugin-video-downloader';

    /** Job-record kind marker, so tool files can't be fetched for field jobs. */
    public const RECORD_KIND = 'tool';

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        self::requireToolAccess($this);
        return true;
    }

    /**
     * The single gate shared by every tool action.
     *
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException when the tool is switched off
     */
    public static function requireToolAccess(Controller $controller): void
    {
        $controller->requirePermission('accessCp');
        if (!Plugin::getInstance()->getSettings()->toolEnabled) {
            throw new NotFoundHttpException('The video download tool is disabled.');
        }
        $controller->requirePermission(self::PERMISSION_USE_TOOL);
    }

    /** Render the tool page. */
    public function actionIndex(): Response
    {
        $downloader = Downloader::fromSettings(Plugin::getInstance()->getSettings());
        $this->getView()->registerAssetBundle(ToolAsset::class);

        return $this->renderTemplate('video-downloader/tool/index', [
            'maxResolution' => $downloader->maxResolution(),
            'maxFilesizeMb' => (int) Plugin::getInstance()->getSettings()->maxFilesizeMb,
        ]);
    }

    /**
     * Read a URL's metadata and available resolutions (no media downloaded).
     *
     * @throws BadRequestHttpException for a missing or rejected URL
     */
    public function actionInspect(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $settings = Plugin::getInstance()->getSettings();
        $downloader = Downloader::fromSettings($settings);

        try {
            $url = Downloader::normalizeUrl(
                (string) $this->request->getRequiredBodyParam('url'),
                $settings->getAllowedHostsList(),
            );
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        // Extraction is capped at 60 s inside the downloader; leave headroom.
        @set_time_limit(120);

        try {
            $info = $downloader->inspect($url);
        } catch (\Throwable $e) {
            Craft::warning('Video Downloader tool: inspect failed: ' . $e->getMessage(), __METHOD__);
            $this->response->setStatusCode(422);
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        $summary = Downloader::summarizeFormats($info, $downloader->maxResolution(), $downloader->maxFilesizeBytes());

        return $this->asJson([
            'success'       => true,
            'url'           => $url,
            'meta'          => Downloader::metaFromInfo($info),
            'options'       => $summary['options'],
            'audio'         => $summary['audio'],
            'maxResolution' => $downloader->maxResolution(),
            'maxFilesizeMb' => (int) $settings->maxFilesizeMb,
        ]);
    }

    /**
     * Queue a download with the chosen resolution and preset.
     *
     * @throws BadRequestHttpException for invalid parameters
     */
    public function actionCreate(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $settings = Plugin::getInstance()->getSettings();
        $downloader = Downloader::fromSettings($settings);
        $user = Craft::$app->getUser()->getIdentity();

        try {
            $url = Downloader::normalizeUrl(
                (string) $this->request->getRequiredBodyParam('url'),
                $settings->getAllowedHostsList(),
            );
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $preset = (string) $this->request->getBodyParam('preset', Downloader::PRESET_COMPATIBLE);
        if (!in_array($preset, Downloader::PRESETS, true)) {
            throw new BadRequestHttpException('Unknown format preset.');
        }

        $requested = $this->request->getBodyParam('resolution', 0);
        if (!is_numeric($requested) || (int) $requested < 0) {
            throw new BadRequestHttpException('Invalid resolution.');
        }
        $resolution = Downloader::clampToCeiling((int) $requested, $downloader->maxResolution());

        $store = new JobStore();
        (new ToolStorage())->cleanup();
        $record = $store->create([
            'kind'       => self::RECORD_KIND,
            'userId'     => (int) $user->id,
            'preset'     => $preset,
            'resolution' => $resolution,
        ]);
        if ($record === null) {
            $this->response->setStatusCode(500);
            return $this->asJson(['success' => false, 'error' => 'Could not create the job record. Check that @storage is writable.']);
        }

        $job = new ToolDownloadJob();
        $job->jobId = $record['id'];
        $job->url = $url;
        $job->preset = $preset;
        $job->resolution = $resolution;
        $job->userId = (int) $user->id;
        Craft::$app->getQueue()->push($job);

        $this->response->setStatusCode(202);
        return $this->asJson(['success' => true, 'jobId' => $record['id']]);
    }

    /**
     * Send a finished download to its owner as an attachment.
     *
     * @throws BadRequestHttpException for a malformed job id
     * @throws NotFoundHttpException when the job is unknown, not owned, not a
     *         tool job, not finished, or its file has expired
     */
    public function actionFile(): Response
    {
        $jobId = (string) $this->request->getRequiredParam('jobId');
        if (!ToolStorage::isValidId($jobId)) {
            throw new BadRequestHttpException('Invalid jobId.');
        }

        $record = (new JobStore())->get($jobId);
        $userId = (int) Craft::$app->getUser()->getId();
        if (
            $record === null
            || ($record['kind'] ?? null) !== self::RECORD_KIND
            || (int) ($record['userId'] ?? 0) !== $userId
            || ($record['status'] ?? null) !== 'done'
        ) {
            throw new NotFoundHttpException('Unknown or expired download.');
        }

        $path = (new ToolStorage())->find($jobId);
        if ($path === null) {
            throw new NotFoundHttpException('This download has expired. Please download it again.');
        }

        return $this->response->sendFile($path, ToolStorage::safeFilename(basename($path)), [
            'inline' => false,
        ]);
    }
}
