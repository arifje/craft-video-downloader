<?php

namespace arifje\craftvideodownloader\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Asset bundle for the standalone download tool page (CP nav item "Video
 * Downloader"). Depends on CpAsset so Craft's JS helpers are available.
 */
class ToolAsset extends AssetBundle
{
    /** @inheritdoc */
    public $sourcePath = __DIR__ . '/dist';

    /** @inheritdoc */
    public $depends = [
        CpAsset::class,
    ];

    /** @inheritdoc */
    public $js = [
        'js/tool.js',
    ];

    /** @inheritdoc */
    public $css = [
        'css/tool.css',
    ];
}
