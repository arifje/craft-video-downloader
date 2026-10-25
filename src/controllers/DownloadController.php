<?php

namespace arifje\craftvideodownloader\controllers;

use arifje\craftvideodownloader\jobs\DownloadJob;
use arifje\craftvideodownloader\Plugin;
use arifje\craftvideodownloader\services\FieldDownloads;
use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Endpoints behind the "Scrape URL" button. Both require a logged-in user with
 * control-panel access and the standard CSRF token (Craft's JS sends it
 * automatically).
 *
 *   POST video-downloader/download/inspect  list a URL's resolutions (options step)
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
    /** Format presets an Assets field download may request (no audio-only). */
    public const FIELD_PRESETS = FieldDownloads::FIELD_PRESETS;

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

        $request   = Craft::$app->getRequest();
        $user      = Craft::$app->getUser()->getIdentity();
        $downloads = new FieldDownloads(Plugin::getInstance()->getSettings());
        $downloads->assertEnabled();

        // Resolved by id, not handle: the field may live inside a nested
        // context where handles aren't globally unique. The browser sends the
        // id from the field's own element-select settings.
        $field = $downloads->field((int) $request->getRequiredBodyParam('fieldId'));

        $siteId = self::intParam($request->getBodyParam('siteId'));
        $downloads->assertSite($siteId);

        $elementId = self::intParam($request->getBodyParam('elementId'));
        $element   = null;
        if ($elementId !== null) {
            $element = $downloads->element($elementId, $siteId, $user);
            $downloads->assertFieldOnElement($field, $element);
        }

        $url    = $downloads->url((string) $request->getRequiredBodyParam('url'));
        $format = $downloads->format($request->getBodyParam('preset'), $request->getBodyParam('resolution', 0));

        $folder = $downloads->folder($field, $element, $user);
        if ($folder === null) {
            Craft::$app->getResponse()->setStatusCode(409);
            return $this->asJson([
                'success' => false,
                'error'   => 'Could not resolve this field\'s upload location. Set a Default Upload Location on the Assets field.',
            ]);
        }

        $jobId = $downloads->enqueue($url, $field, $folder, $elementId, $siteId, $user, $format['preset'], $format['resolution']);
        if ($jobId === null) {
            Craft::$app->getResponse()->setStatusCode(500);
            return $this->asJson(['success' => false, 'error' => 'Could not create the job record. Check that @storage is writable.']);
        }

        Craft::$app->getResponse()->setStatusCode(202);
        return $this->asJson([
            'success' => true,
            'jobId'   => $jobId,
        ]);
    }

    /**
     * Read a URL's metadata and available resolutions for the field modal's
     * options step (no media is downloaded). Gated like create: CP access,
     * plugin enabled, and the field must be an Assets field the settings allow.
     *
     * @throws BadRequestHttpException for an invalid field or URL
     * @throws ForbiddenHttpException when the plugin or field is not enabled
     */
    public function actionInspect(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request   = Craft::$app->getRequest();
        $downloads = new FieldDownloads(Plugin::getInstance()->getSettings());
        $downloads->assertEnabled();
        $downloads->field((int) $request->getRequiredBodyParam('fieldId'));
        $url = $downloads->url((string) $request->getRequiredBodyParam('url'));

        try {
            $result = $downloads->inspect($url);
        } catch (\Throwable $e) {
            Craft::warning('Video Downloader: inspect failed: ' . $e->getMessage(), __METHOD__);
            Craft::$app->getResponse()->setStatusCode(422);
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->asJson(['success' => true] + $result);
    }

    /**
     * Report a job's status to its owner. Returns the new asset id once done so
     * the browser can attach it to the field.
     */
    public function actionStatus(): Response
    {
        $this->requireAcceptsJson();

        $downloads = new FieldDownloads(Plugin::getInstance()->getSettings());
        $status    = $downloads->status(
            (string) Craft::$app->getRequest()->getRequiredParam('jobId'),
            (int) Craft::$app->getUser()->getId(),
        );

        return $this->asJson(['success' => true] + $status);
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * Kept for callers of the 2.x API; the walk lives in FieldDownloads.
     */
    public static function fieldIsAvailableToElement(FieldInterface $field, ElementInterface $element): bool
    {
        return FieldDownloads::fieldIsAvailableToElement($field, $element);
    }

    private static function intParam(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }
}
