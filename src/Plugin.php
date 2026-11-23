<?php

namespace arifje\craftvideodownloader;

use arifje\craftvideodownloader\assets\VideoDownloaderAsset;
use arifje\craftvideodownloader\controllers\ToolController;
use arifje\craftvideodownloader\mcp\VideoTools;
use arifje\craftvideodownloader\models\Settings;
use arifje\craftvideodownloader\services\Cookies;
use arifje\craftvideodownloader\utilities\CookieUtility;
use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\TemplateEvent;
use craft\services\Utilities;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;

/**
 * Video Downloader plugin.
 *
 * Adds a "Scrape URL" button to Assets fields in the control panel. Pasting a
 * social-media video URL enqueues a {@see jobs\DownloadJob} that shells out to
 * yt-dlp, creates an Asset in the field's normal upload folder, and the finished
 * video is attached to the open field client-side (the editor then Saves).
 *
 * It also provides a standalone download tool (CP nav item "Video
 * Downloader", {@see controllers\ToolController}) that inspects a URL, offers
 * the available resolutions and formats, and downloads the result to the
 * user's device (with a share-sheet "Save Video" path on mobile).
 *
 * Default action endpoints (Craft's standard plugin routing):
 *   POST /actions/video-downloader/download/create   enqueue a field download
 *   POST /actions/video-downloader/download/status   poll a job (owner only)
 *   POST /actions/video-downloader/tool/inspect      list a URL's resolutions
 *   POST /actions/video-downloader/tool/create       enqueue a tool download
 *   GET  /actions/video-downloader/tool/file         fetch a finished download
 *
 * @property-read Settings $settings
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** Request-only credentials, never part of the settings model. */
    private mixed $_cookiePassword = '';
    /** Explicit request-only deletion intent. */
    private bool $_clearCookies = false;

    /** Request-only domains selected for removal. */
    private mixed $_removeCookieDomains = [];

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();

        // Remove credential input before validation, error rendering or debug collection.
        $request = Craft::$app->getRequest();
        if ($request instanceof \craft\web\Request && $request->getIsPost()
            && $request->getBodyParam('pluginHandle') === $this->handle
            && is_array($request->getBodyParam('settings'))) {
            $body = $request->getBodyParams();
            $this->_cookiePassword = $body['settings']['cookiePassword'] ?? '';
            $this->_removeCookieDomains = $body['settings']['removeCookieDomains'] ?? [];
            $this->_clearCookies = ($body['settings']['clearCookies'] ?? '') === '1';
            unset($body['settings']['cookiePassword'], $body['settings']['clearCookies'], $body['settings']['removeCookieDomains']);
            unset($_POST['settings']['cookiePassword'], $_POST['settings']['clearCookies'], $_POST['settings']['removeCookieDomains']);
            unset($_REQUEST['settings']['cookiePassword'], $_REQUEST['settings']['clearCookies'], $_REQUEST['settings']['removeCookieDomains']);
            if ($this->_cookiePassword !== '') {
                $request->setRawBody('');
            }
            $request->setBodyParams($body);
        }

        // CP routing for the tool page ("/admin/video-downloader"). Access to
        // that URL is gated by Craft's built-in accessPlugin-video-downloader
        // permission, which Craft registers itself for plugins with a CP section.
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules['video-downloader'] = 'video-downloader/tool/index';
            }
        );

        // The control-panel integration is the whole plugin — only wire it up for
        // CP web requests. The instanceof check keeps us clear of console context
        // (the queue worker), where getIsCpRequest() doesn't exist.
        $request = Craft::$app->getRequest();
        if ($request instanceof \craft\web\Request && $request->getIsCpRequest()) {
            $this->attachCpAssets();
        }

        // Craft 4 and 5 use different names for the utility registration event.
        Event::on(
            Utilities::class,
            defined(Utilities::class . '::EVENT_REGISTER_UTILITIES')
                ? Utilities::EVENT_REGISTER_UTILITIES
                : Utilities::EVENT_REGISTER_UTILITY_TYPES,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = CookieUtility::class;
            }
        );

        $this->registerMcpTools();
    }

    /**
     * Offer the Scrape URL flow as MCP tools when Craft MCP Tools is
     * installed. The class check keeps this plugin working without it.
     */
    private function registerMcpTools(): void
    {
        $mcp = 'arifje\\mcptools\\Mcp';
        if (!class_exists($mcp)) {
            return;
        }

        Event::on($mcp, $mcp::EVENT_REGISTER_TOOLS, static function ($event): void {
            $event->addTool(VideoTools::class, 'video-downloader');
        });
    }

    /**
     * The "Video Downloader" nav item, shown only when the tool is enabled and
     * the current user may use it (a nav item to a 403 helps nobody).
     */
    public function getCpNavItem(): ?array
    {
        if (!$this->getSettings()->toolEnabled) {
            return null;
        }
        $user = Craft::$app->getUser();
        if (!$user->checkPermission('accessCp') || !$user->checkPermission(ToolController::PERMISSION_USE_TOOL)) {
            return null;
        }

        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('app', 'Video Downloader');
        $item['url'] = 'video-downloader';
        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /** Save credentials through the native admin/CSRF-protected settings lifecycle. */
    public function beforeSaveSettings(): bool
    {
        if (!parent::beforeSaveSettings()) {
            return false;
        }
        if ($this->_cookiePassword === '' && !$this->_clearCookies && $this->_removeCookieDomains === []) {
            return true;
        }
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new \yii\web\ForbiddenHttpException('Administrative changes are disallowed in this environment.');
        }
        try {
            $this->savePostedCookies();
            return true;
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            // The pasted text is never echoed back, so say plainly that nothing was kept.
            $this->getSettings()->addError('cookieFile', Craft::t('app', $e->getMessage()) . ' ' . Craft::t('app', 'Nothing was saved. Fix the export and paste it again.'));
            return false;
        }
    }

    /**
     * Save request-only credentials without writing project config.
     * Utilities intentionally permit this operational change on locked production sites.
     *
     * @throws \yii\web\ForbiddenHttpException
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function savePostedCookies(): void
    {
        try {
            $request = Craft::$app->getRequest();
            if (!$request instanceof \craft\web\Request || !$request->getIsPost()
                || !Craft::$app->getUser()->getIsAdmin() || !$request->validateCsrfToken()) {
                throw new \yii\web\ForbiddenHttpException('Cookie changes require an authorized request.');
            }
            if (!is_string($this->_cookiePassword)) {
                throw new \InvalidArgumentException('Cookies must be pasted as text.');
            }
            if (!is_array($this->_removeCookieDomains)) {
                throw new \InvalidArgumentException('Select valid cookie domains to remove.');
            }
            Cookies::forCraft()->save($this->_cookiePassword, $this->_clearCookies, $this->_removeCookieDomains);
        } finally {
            $this->_cookiePassword = '';
            $this->_clearCookies = false;
            $this->_removeCookieDomains = [];
        }
    }

    protected function settingsHtml(): ?string
    {
        // Offer the site's Assets fields so an admin can pick which ones get the
        // button when the mode is "list". getAllFields(false) enumerates every
        // context — on Craft 4 that includes fields nested in Matrix/Neo/Super
        // Table block types; on Craft 5 all fields are global anyway. (We avoid
        // getFieldsByType(), which only exists since Craft 4.4.) De-dupe by
        // handle (matching is handle-based) and flag the block-nested ones.
        $assetFields = [];
        $seen = [];
        foreach (Craft::$app->getFields()->getAllFields(false) as $field) {
            if (!$field instanceof \craft\fields\Assets) {
                continue;
            }
            if (isset($seen[$field->handle])) {
                continue;
            }
            $seen[$field->handle] = true;
            $nested = ($field->context ?? 'global') !== 'global';
            $assetFields[] = [
                'label' => $field->name . ' (' . $field->handle . ')' . ($nested ? ' — in a block' : ''),
                'value' => $field->handle,
            ];
        }

        $runtime = \arifje\craftvideodownloader\services\Downloader::fromSettings($this->getSettings())->jsRuntimeArg();
        $jsRuntimeStatus = $runtime !== null
            ? Craft::t('app', 'Using: {runtime}', ['runtime' => $runtime])
            : Craft::t('app', 'No JS runtime found. YouTube downloads will fail until Deno is installed and configured here.');

        return Craft::$app->getView()->renderTemplate('video-downloader/settings', [
            'plugin'          => $this,
            'settings'        => $this->getSettings(),
            'assetFields'     => $assetFields,
            'jsRuntimeStatus' => $jsRuntimeStatus,
            'cookiesConfigured' => Cookies::forCraft()->configured(),
            'cookiesSavedAt'    => Cookies::forCraft()->savedAt(),
            'cookiesSummary'    => Cookies::forCraft()->summary(),
        ]);
    }

    /**
     * Register the JS/CSS bundle (and the settings it needs) on CP page renders.
     * The bundle is harmless on pages without Assets fields — its JS simply finds
     * nothing to enhance.
     */
    private function attachCpAssets(): void
    {
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            function (TemplateEvent $event): void {
                $settings = $this->getSettings();
                if (!$settings->enabled || Craft::$app->getUser()->getIdentity() === null) {
                    return;
                }

                $view = Craft::$app->getView();
                $view->registerAssetBundle(VideoDownloaderAsset::class);
                $view->registerJsVar('videoDownloaderSettings', [
                    'mode'            => $settings->mode,
                    'handles'         => array_values($settings->fieldHandles),
                    'videoFieldsOnly' => $settings->videoFieldsOnly,
                    // Lets the JS pick the right render endpoint (Craft 5 replaced
                    // elements/get-element-html with app/render-elements).
                    'craft'           => Craft::$app->getVersion(),
                ]);
            }
        );
    }
}
