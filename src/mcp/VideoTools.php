<?php

namespace arifje\craftvideodownloader\mcp;

use arifje\craftvideodownloader\Plugin;
use arifje\craftvideodownloader\services\FieldDownloads;
use arifje\mcptools\attributes\McpToolMeta;
use arifje\mcptools\enums\Capability;
use arifje\mcptools\enums\ToolCategory;
use arifje\mcptools\support\Authorization;
use Craft;
use craft\elements\Asset;
use craft\elements\User;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * MCP tools for the "Scrape URL" button: read a video URL's details, download
 * it into an Assets field's upload folder as a queued job, and follow that
 * job, through the same FieldDownloads service the control panel uses.
 * Registered only when Craft MCP Tools is installed (Plugin::init).
 *
 * The tools run as the token's Craft user, so the plugin's own checks apply
 * to that user. A download is owned by a user and checked again when the job
 * runs, so a connection without one (stdio) cannot start one.
 */
class VideoTools
{
    #[McpTool(
        name: 'video_inspect',
        title: 'Inspect a video URL',
        description: 'Read a video page URL (YouTube, TikTok, Instagram, X and anything else yt-dlp supports) without downloading it: title, uploader, duration, thumbnail and the resolutions that fit the size and resolution limits. Use before video_download to pick a resolution.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function inspect(
        #[Schema(description: 'The video page URL.')]
        string $url,
        #[Schema(description: 'Handle of the Assets field the video is for; checks Video Downloader is enabled for it.')]
        string $field,
    ): array {
        $downloads = $this->downloads();
        $this->run(fn () => $downloads->field($field));
        $normalized = $this->run(fn () => $downloads->url($url));

        return $this->run(fn () => $downloads->inspect($normalized));
    }

    #[McpTool(
        name: 'video_download',
        title: 'Download a video into an Assets field',
        description: 'Download a video from a URL into the upload folder of an Assets field, as the "Scrape URL" button does. Runs as a queued job and returns a jobId: follow it with video_download_status until status is done, which gives the asset id and natural key. The asset is not added to the entry: put the key in the field with update_entry. Needs Craft\'s queue to be running.',
        annotations: new ToolAnnotations(destructiveHint: false, openWorldHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true, capability: Capability::Upload)]
    public function download(
        #[Schema(description: 'The video page URL.')]
        string $url,
        #[Schema(description: 'Handle of the Assets field the video is for; its upload location decides the folder.')]
        string $field,
        #[Schema(description: 'The entry (or Matrix block entry) the video is for. Needed when the upload location uses the entry; the token\'s user must be allowed to edit it.')]
        ?int $entryId = null,
        #[Schema(description: 'Site of the entry. Omit for the primary site.')]
        ?int $siteId = null,
        #[Schema(description: '"compatible" (H.264 MP4 that plays everywhere) or "best" (highest quality). Omit for the plugin default.', enum: ['compatible', 'best'])]
        ?string $preset = null,
        #[Schema(description: 'Maximum height in pixels, such as 720 or 1080, capped by the plugin settings. 0 or omitted: the best allowed.')]
        int $resolution = 0,
    ): array {
        $downloads = $this->downloads();
        $user = $this->user();

        $this->run(fn () => $downloads->assertSite($siteId));
        $element = null;
        if ($entryId !== null) {
            $element = $this->run(fn () => $downloads->element($entryId, $siteId, $user));
            Authorization::assertCanSave($element);
        }

        $fieldModel = $this->run(fn () => $downloads->field($field, $element));
        if ($element !== null) {
            $this->run(fn () => $downloads->assertFieldOnElement($fieldModel, $element));
        }

        $normalized = $this->run(fn () => $downloads->url($url));
        $format = $this->run(fn () => $downloads->format($preset, $resolution));
        $folder = $this->run(fn () => $downloads->folder($fieldModel, $element, $user));
        if ($folder === null) {
            throw new ToolCallException('Could not resolve this field\'s upload location. Set a Default Upload Location on the Assets field, or pass entryId when it depends on the entry.');
        }

        $jobId = $downloads->enqueue($normalized, $fieldModel, $folder, $entryId, $siteId, $user, $format['preset'], $format['resolution']);
        if ($jobId === null) {
            throw new ToolCallException('Could not create the job record. Check that @storage is writable.');
        }

        return [
            'jobId' => $jobId,
            'status' => 'queued',
            'next' => 'Call video_download_status with this jobId until status is done or failed.',
        ];
    }

    #[McpTool(
        name: 'video_download_status',
        title: 'Follow a video download',
        description: 'Status of a download started with video_download: queued, running (with stage and progress 0 to 1), done (with the asset id and natural key {volume, path, filename}) or failed (with the reason). Only the user who started it can read it.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function status(
        #[Schema(description: 'The jobId from video_download.')]
        string $jobId,
    ): array {
        $downloads = $this->downloads();
        $status = $this->run(fn () => $downloads->status($jobId, (int) $this->user()->id));

        $assetId = (int) ($status['result']['assetId'] ?? 0);
        if ($assetId > 0) {
            $asset = Asset::find()->id($assetId)->status(null)->one();
            if ($asset instanceof Asset) {
                $key = ['volume' => $asset->getVolume()->handle, 'filename' => $asset->getFilename()];
                $path = $asset->getFolder()->path ?? '';
                if ($path !== '') {
                    $key['path'] = $path;
                }
                $status['result']['key'] = $key;
                $url = $asset->getUrl();
                if ($url !== null) {
                    $status['result']['url'] = $url;
                }
            }
        }

        if (($status['status'] ?? '') === 'queued') {
            $status['hint'] = 'Still queued. Downloads run on Craft\'s queue; if this stays queued, the site needs a queue worker (php craft queue/listen).';
        }

        return array_filter($status, static fn ($value) => $value !== null);
    }

    private function downloads(): FieldDownloads
    {
        $downloads = new FieldDownloads(Plugin::getInstance()->getSettings());
        $this->run(fn () => $downloads->assertEnabled());

        return $downloads;
    }

    private function user(): User
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user instanceof User) {
            throw new ToolCallException('Video downloads belong to a Craft user. Connect over HTTP with a token to use them.');
        }

        return $user;
    }

    /**
     * Run a service call, handing its refusal to the agent as a tool error
     * with the same message the control panel shows.
     */
    private function run(callable $call): mixed
    {
        try {
            return $call();
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Craft::warning('[video-downloader] MCP: ' . $e->getMessage(), __METHOD__);
            throw new ToolCallException($e->getMessage(), 0, $e);
        }
    }
}
