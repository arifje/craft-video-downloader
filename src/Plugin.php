<?php

namespace arifje\craftvideodownloader;

use arifje\craftvideodownloader\assets\VideoDownloaderAsset;
use arifje\craftvideodownloader\models\Settings;
use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\TemplateEvent;
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
 * Default action endpoints (Craft's standard plugin routing):
 *   POST /actions/video-downloader/download/create   enqueue a download
 *   GET  /actions/video-downloader/download/status    poll a job
 *
 * @property-read Settings $settings
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();

        // The control-panel integration is the whole plugin — only wire it up for
        // CP web requests. The instanceof check keeps us clear of console context
        // (the queue worker), where getIsCpRequest() doesn't exist.
        $request = Craft::$app->getRequest();
        if ($request instanceof \craft\web\Request && $request->getIsCpRequest()) {
            $this->attachCpAssets();
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        // Offer the site's Assets fields so an admin can pick which ones get the
        // button when the mode is "list". Passing `false` to getFieldsByType()
        // includes fields nested in Matrix/Neo/Super Table blocks, not just the
        // global context. De-dupe by handle (matching is handle-based) and flag
        // the block-nested ones.
        $assetFields = [];
        $seen = [];
        foreach (Craft::$app->getFields()->getFieldsByType(\craft\fields\Assets::class, false) as $field) {
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

        return Craft::$app->getView()->renderTemplate('video-downloader/settings', [
            'plugin'      => $this,
            'settings'    => $this->getSettings(),
            'assetFields' => $assetFields,
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
                    'mode'    => $settings->mode,
                    'handles' => array_values($settings->fieldHandles),
                ]);
            }
        );
    }
}
