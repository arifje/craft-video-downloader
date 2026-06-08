<?php

namespace arifje\craftvideodownloader\controllers;

use arifje\craftvideodownloader\jobs\DownloadJob;
use arifje\craftvideodownloader\models\Settings;
use arifje\craftvideodownloader\Plugin;
use arifje\craftvideodownloader\services\Downloader;
use arifje\craftvideodownloader\services\JobStore;
use Craft;
use craft\fields\Assets as AssetsField;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Endpoints behind the "Scrape URL" button. Both require a logged-in CP user
 * (the controller default) and the standard CSRF token, which Craft's JS sends
 * automatically.
 *
 *   POST video-downloader/download/create   enqueue a download → { jobId }
 *   GET  video-downloader/download/status    poll a job        → { status, … }
 */
class DownloadController extends Controller
{
    /**
     * Accept the enqueue request, validate it, push a {@see DownloadJob} and
     * return its id for the browser to poll.
     */
    public function actionCreate(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request  = Craft::$app->getRequest();
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->enabled) {
            throw new ForbiddenHttpException('Video Downloader is disabled.');
        }

        $fieldHandle = (string) $request->getRequiredBodyParam('fieldHandle');
        $field       = Craft::$app->getFields()->getFieldByHandle($fieldHandle);
        if (!$field instanceof AssetsField) {
            throw new BadRequestHttpException('Unknown or non-Assets field.');
        }
        if ($settings->mode === Settings::MODE_LIST && !in_array($field->handle, $settings->fieldHandles, true)) {
            throw new ForbiddenHttpException('Video Downloader is not enabled for this field.');
        }

        try {
            $url = Downloader::normalizeUrl((string) $request->getRequiredBodyParam('url'), $settings->getAllowedHostsList());
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $store  = new JobStore();
        $record = $store->create();
        if ($record === null) {
            Craft::$app->getResponse()->setStatusCode(500);
            return $this->asJson(['success' => false, 'error' => 'Could not create the job record. Check that @storage is writable.']);
        }

        $job = new DownloadJob();
        $job->jobId      = $record['id'];
        $job->url        = $url;
        $job->fieldId    = (int) $field->id;
        $job->elementId  = ($v = $request->getBodyParam('elementId')) !== null && $v !== '' ? (int) $v : null;
        $job->siteId     = ($v = $request->getBodyParam('siteId')) !== null && $v !== '' ? (int) $v : null;
        $job->uploaderId = Craft::$app->getUser()->getId();
        Craft::$app->getQueue()->push($job);

        Craft::$app->getResponse()->setStatusCode(202);
        return $this->asJson([
            'success' => true,
            'jobId'   => $record['id'],
        ]);
    }

    /**
     * Report a job's status. Returns the new asset id once it's done so the
     * browser can attach it to the field.
     */
    public function actionStatus(): Response
    {
        $this->requireAcceptsJson();

        $id = (string) Craft::$app->getRequest()->getRequiredParam('jobId');
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new BadRequestHttpException('Invalid jobId.');
        }

        $record = (new JobStore())->get($id);
        if ($record === null) {
            Craft::$app->getResponse()->setStatusCode(404);
            return $this->asJson(['success' => false, 'error' => 'Unknown or expired jobId.']);
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
}
