<?php

namespace arifje\craftvideodownloader\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * CP asset bundle: the JS that injects the "Scrape URL" button and drives the
 * download modal, plus its styling. Depends on CpAsset so Craft/Garnish are
 * loaded first.
 */
class VideoDownloaderAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
    ];

    public $js = [
        'js/video-downloader.js',
    ];

    public $css = [
        'css/video-downloader.css',
    ];
}
