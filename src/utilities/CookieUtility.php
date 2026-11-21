<?php

namespace arifje\craftvideodownloader\utilities;

use arifje\craftvideodownloader\Plugin;
use arifje\craftvideodownloader\services\Cookies;
use Craft;
use craft\base\Utility;
use craft\web\Request;
use yii\web\ForbiddenHttpException;

/** Administrator-only credential maintenance, independent of project config. */
class CookieUtility extends Utility
{
    /** @inheritdoc */
    public static function displayName(): string
    {
        return Craft::t('app', 'Video Downloader Cookies');
    }

    /** @inheritdoc */
    public static function id(): string
    {
        return 'video-downloader-cookies';
    }

    /** Hide the utility from non-admin users, even with a utility permission. */
    public static function isSelectable(): bool
    {
        return Craft::$app->getRequest() instanceof Request && Craft::$app->getUser()->getIsAdmin();
    }

    /** Craft 5 utility icon. */
    public static function icon(): ?string
    {
        return dirname(__DIR__) . '/icon.svg';
    }

    /** Craft 4 utility icon. */
    public static function iconPath(): ?string
    {
        return self::icon();
    }

    /** Render status and write-only fields; never read credential contents. */
    public static function contentHtml(): string
    {
        if (!self::isSelectable()) {
            throw new ForbiddenHttpException('Only administrators can manage video cookies.');
        }
        return Craft::$app->getView()->renderTemplate('video-downloader/_utilities/cookies', [
            'settings' => Plugin::getInstance()->getSettings(),
            'cookiesConfigured' => Cookies::forCraft()->configured(),
            'cookiesSavedAt' => Cookies::forCraft()->savedAt(),
            'cookiesSummary' => Cookies::forCraft()->summary(),
        ]);
    }
}
