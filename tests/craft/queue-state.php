<?php
/**
 * Checks, against a disposable Craft install, that a download's status follows
 * Craft's queue when the job could not write its own record.
 *
 *   bin/run-php ../craft-video-downloader/tests/craft/queue-state.php   (shared test container)
 *
 * It pushes nothing that downloads: the queue rows are written and changed by
 * hand, and removed again.
 */

use arifje\craftvideodownloader\jobs\DownloadJob;
use arifje\craftvideodownloader\Plugin;
use arifje\craftvideodownloader\services\FieldDownloads;
use arifje\craftvideodownloader\services\JobStore;
use craft\db\Table;
use craft\helpers\Db;

require '/var/www/html/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

$failures = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($detail !== '' ? '  (' . $detail . ')' : '') . PHP_EOL;
    $failures += $ok ? 0 : 1;
};

$downloads = new FieldDownloads(Plugin::getInstance()->getSettings());
$store = new JobStore();
$queue = Craft::$app->getQueue();
$userId = 987654;

// A record and a queued job, as enqueue() leaves them.
$push = function () use ($store, $queue, $userId): array {
    $record = $store->create(['userId' => $userId]);
    $job = new DownloadJob();
    $job->jobId = $record['id'];
    $job->url = 'https://example.test/video';
    $queueId = (string) $queue->push($job);
    $store->update($record['id'], ['queueId' => $queueId]);

    return [$record['id'], $queueId];
};

[$jobId, $queueId] = $push();
$status = $downloads->status($jobId, $userId);
$check('a job that waits in the queue is queued', $status['status'] === 'queued', json_encode($status));

Db::update(Table::QUEUE, ['dateReserved' => Db::prepareDateForDb(new DateTime()), 'timeUpdated' => time(), 'attempt' => 1], ['id' => $queueId]);
$check('a job the worker has taken stays as its record says', $downloads->status($jobId, $userId)['status'] === 'queued');

// The worker could not write the record: the queue has the failure, the record still says queued.
Db::update(Table::QUEUE, [
    'fail' => true,
    'dateFailed' => Db::prepareDateForDb(new DateTime()),
    'error' => 'file_put_contents(/var/www/html/storage/video-downloader/jobs/' . $jobId . '.json.6af5.tmp): Failed to open stream: Permission denied',
], ['id' => $queueId]);
$status = $downloads->status($jobId, $userId);
$check('a job that failed in the queue is failed, whatever its record says', $status['status'] === 'failed' && $store->get($jobId)['status'] === 'queued', json_encode($status));
$check('the reason says what to fix, without server paths', str_contains((string) ($status['error'] ?? ''), 'writable for both') && !str_contains((string) ($status['error'] ?? ''), '/var/www'), (string) ($status['error'] ?? ''));

Db::update(Table::QUEUE, ['error' => 'The target field no longer exists (or is not an Assets field).'], ['id' => $queueId]);
$status = $downloads->status($jobId, $userId);
$check('another failure keeps its own reason', str_contains((string) ($status['error'] ?? ''), 'The target field no longer exists'), (string) ($status['error'] ?? ''));

// Released from the queue with nothing recorded.
Db::delete(Table::QUEUE, ['id' => $queueId]);
$check('a job that just left the queue gets a moment', $downloads->status($jobId, $userId)['status'] === 'queued');
$path = Craft::getAlias('@storage') . '/video-downloader/jobs/' . $jobId . '.json';
$record = json_decode((string) file_get_contents($path), true);
$record['updated_at'] = time() - 60;
file_put_contents($path, json_encode($record));
$status = $downloads->status($jobId, $userId);
$check('a job that is gone without a result is failed', $status['status'] === 'failed' && str_contains((string) ($status['error'] ?? ''), 'no longer in the queue'), json_encode($status));

// A finished record is never second-guessed.
$store->update($jobId, ['status' => 'done', 'stage' => 'done', 'result' => ['assetId' => 0]]);
$check('a finished record stays finished', $downloads->status($jobId, $userId)['status'] === 'done');
@unlink($path);

// A job that finds its record but may not write it stops with a reason of its own.
[$jobId, $queueId] = $push();
Db::delete(Table::QUEUE, ['id' => $queueId]);
$path = Craft::getAlias('@storage') . '/video-downloader/jobs/' . $jobId . '.json';
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    chmod(dirname($path), 0555);
    $job = new DownloadJob();
    $job->jobId = $jobId;
    $message = '';
    try {
        $job->execute($queue);
    } catch (Throwable $e) {
        $message = $e->getMessage();
    }
    chmod(dirname($path), 0775);
    $check('a job that may not write its record stops and says so', $message === JobStore::NOT_WRITABLE, $message);
} else {
    echo 'skip a job that may not write its record (running as root, which may write anywhere)' . PHP_EOL;
}
@unlink($path);

echo PHP_EOL . ($failures === 0 ? 'All checks passed.' : "{$failures} check(s) failed.") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
