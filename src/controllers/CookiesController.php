<?php

namespace arifje\craftvideodownloader\controllers;

use arifje\craftvideodownloader\Plugin;
use arifje\craftvideodownloader\utilities\CookieUtility;
use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\Response;

/** Private credential updates remain available when project config is locked. */
class CookiesController extends Controller
{
    /** Keep Craft's authentication/CSRF checks and require an administrator. */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireCpRequest();
        $this->requireAdmin(false);
        return true;
    }

    /** Save/replace/clear credentials without saving the plugin's settings model. */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(false);
        try {
            Plugin::getInstance()->savePostedCookies();
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->asFailure(Craft::t('app', $e->getMessage()));
        }
        return $this->asSuccess(
            Craft::t('app', 'Video Downloader cookies saved.'),
            redirect: UrlHelper::cpUrl('utilities/' . CookieUtility::id()),
        );
    }
}
