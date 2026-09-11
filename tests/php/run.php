<?php
/**
 * Executable unit tests — zero dependencies, no Craft installation required.
 *
 *   php tests/php/run.php
 *
 * These cover the Craft-free parts of the plugin: URL/SSRF validation,
 * progress parsing, the whole mocked download pipeline (via tests/php/fake-yt-dlp
 * — no network, no live videos), temp-dir cleanup containment, error
 * sanitisation, and the JobStore (ownership metadata, expiry).
 *
 * Anything that needs a running Craft app (permissions, folder resolution,
 * asset creation, controller auth) is covered by tests/craft/INTEGRATION.md
 * against a disposable Craft install instead.
 */

declare(strict_types=1);

error_reporting(E_ALL);

require __DIR__ . '/../../src/services/Downloader.php';
require __DIR__ . '/../../src/services/JobStore.php';

use arifje\craftvideodownloader\services\Downloader;
use arifje\craftvideodownloader\services\JobStore;

$pass = 0;
$fail = 0;
$failures = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        echo "  ✓ {$name}\n";
    } else {
        $fail++;
        $failures[] = $name . ($detail !== '' ? " — {$detail}" : '');
        echo "  ✗ {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function throws(callable $fn, string $needle = ''): bool
{
    try {
        $fn();
        return false;
    } catch (\Throwable $e) {
        return $needle === '' || stripos($e->getMessage(), $needle) !== false;
    }
}

$scratch = sys_get_temp_dir() . '/vd-tests-' . bin2hex(random_bytes(4));
mkdir($scratch, 0777, true);
$fake = __DIR__ . '/fake-yt-dlp';
chmod($fake, 0755);

function makeDownloader(string $scratch, array $overrides = []): Downloader
{
    return new Downloader(
        $overrides['bin'] ?? __DIR__ . '/fake-yt-dlp',
        'mp4/best',
        $overrides['maxMb'] ?? 500,
        $overrides['timeout'] ?? 30,
        // Allow-listed so normalizeUrl never touches DNS — tests stay offline.
        $overrides['allowedHosts'] ?? ['example-cdn.test'],
        $scratch,
    );
}

/* ------------------------------------------------------------------ URLs */
echo "URL validation / SSRF guard\n";

check('rejects empty', throws(fn() => Downloader::normalizeUrl('')));
check('rejects ftp scheme', throws(fn() => Downloader::normalizeUrl('ftp://example.com/a.mp4'), 'http'));
check('rejects file scheme', throws(fn() => Downloader::normalizeUrl('file:///etc/passwd')));
check('rejects javascript scheme', throws(fn() => Downloader::normalizeUrl('javascript:alert(1)')));
check('rejects embedded credentials', throws(fn() => Downloader::normalizeUrl('https://user:pw@example.com/v'), 'credentials'));
check('rejects over-long URL', throws(fn() => Downloader::normalizeUrl('https://example.com/' . str_repeat('a', 3000))));
check('rejects loopback IPv4', throws(fn() => Downloader::normalizeUrl('http://127.0.0.1/x.mp4'), 'private'));
check('rejects RFC1918 10/8', throws(fn() => Downloader::normalizeUrl('http://10.0.0.5/x.mp4'), 'private'));
check('rejects RFC1918 192.168/16', throws(fn() => Downloader::normalizeUrl('http://192.168.1.10/x'), 'private'));
check('rejects RFC1918 172.16/12', throws(fn() => Downloader::normalizeUrl('http://172.20.1.1/x'), 'private'));
check('rejects cloud metadata IP', throws(fn() => Downloader::normalizeUrl('http://169.254.169.254/latest/meta-data'), 'private'));
check('rejects IPv6 loopback', throws(fn() => Downloader::normalizeUrl('http://[::1]/x'), 'private'));
check('rejects IPv6 ULA', throws(fn() => Downloader::normalizeUrl('http://[fc00::1]/x'), 'private'));
check('rejects v4-mapped private IPv6', throws(fn() => Downloader::normalizeUrl('http://[::ffff:10.0.0.1]/x'), 'private'));
check('rejects localhost name', throws(fn() => Downloader::normalizeUrl('http://localhost/x'), 'private'));
check('rejects .local name', throws(fn() => Downloader::normalizeUrl('http://nas.local/x'), 'private'));
check('accepts public IP literal', Downloader::normalizeUrl('http://93.184.216.34/x.mp4') === 'http://93.184.216.34/x.mp4');
check('allow-list: listed host passes without DNS', Downloader::normalizeUrl('https://x.com/user/status/1', ['x.com']) === 'https://x.com/user/status/1');
check('allow-list: subdomain matches', Downloader::normalizeUrl('https://www.youtube.com/watch?v=1', ['youtube.com']) !== '');
check('allow-list: unlisted host rejected', throws(fn() => Downloader::normalizeUrl('https://evil.test/v', ['youtube.com']), 'not allowed'));
check('allow-list: no suffix trickery', throws(fn() => Downloader::normalizeUrl('https://notyoutube.com/v', ['youtube.com'])));

echo "isPrivateIp\n";
check('public v4 not private', !Downloader::isPrivateIp('93.184.216.34'));
check('public v6 not private', !Downloader::isPrivateIp('2606:4700:4700::1111'));
check('link-local v4 private', Downloader::isPrivateIp('169.254.10.10'));
check('link-local v6 private', Downloader::isPrivateIp('fe80::1'));
check('0.0.0.0 private', Downloader::isPrivateIp('0.0.0.0'));
check('garbage fails closed', Downloader::isPrivateIp('not-an-ip'));

/* -------------------------------------------------------------- progress */
echo "Progress line parsing\n";

$p = Downloader::parseProgressLine('__VDLP__| 45.2%|1234|5000|NA|2500.5|3');
check('parses percent', $p !== null && abs($p['percent'] - 45.2) < 0.001);
check('parses bytes', $p['downloaded'] === 1234 && $p['total'] === 5000);
check('parses speed/eta', abs($p['speed'] - 2500.5) < 0.001 && $p['eta'] === 3);

$p = Downloader::parseProgressLine('__VDLP__|NA|500|NA|2000.0|NA|NA');
check('falls back to estimate + computes percent', $p['total'] === 2000 && abs($p['percent'] - 25.0) < 0.001);

check('rejects malformed line', Downloader::parseProgressLine('__VDLP__|oops') === null);

/* ------------------------------------------------- mocked download runs */
echo "Mocked download pipeline (fake yt-dlp, no network)\n";

$d = makeDownloader($scratch);
$updates = [];
$res = $d->download('https://videos.example-cdn.test/clip/1', function (array $u) use (&$updates) { $updates[] = $u; });
// note: the hostname is never resolved here because the fake never dials out —
// but normalizeUrl would try DNS. Use the allow-list to keep the test offline.
check('download() returned a file', is_file($res['path']));
check('filename from stub', $res['filename'] === 'Fake_Video [abc123].mp4');
check('progress callback fired', count($updates) >= 2);
check('progress reached 100', abs(end($updates)['percent'] - 100.0) < 0.001);
check('job dir inside plugin temp base', str_starts_with($res['dir'], $scratch . '/video-downloader/'));
check('cleanup removes job dir', $d->removeDir($res['dir']) && !is_dir($res['dir']));

putenv('VD_FAKE_MODE=fail');
$err = '';
try {
    makeDownloader($scratch)->download('https://videos.example-cdn.test/clip/2');
    check('failure throws', false);
} catch (\RuntimeException $e) {
    $err = $e->getMessage();
    check('failure throws', true);
}
check('failure message keeps ERROR line', str_contains($err, 'This video is unavailable'));
check('failure message drops benign noise', !str_contains($err, 'benign'));
check('failed job dir cleaned up', count(glob($scratch . '/video-downloader/*') ?: []) === 0);

putenv('VD_FAKE_MODE=nofile');
check('no-file run throws', throws(fn() => makeDownloader($scratch)->download('https://videos.example-cdn.test/3'), 'produced no file'));
check('no-file dir cleaned', count(glob($scratch . '/video-downloader/*') ?: []) === 0);

putenv('VD_FAKE_MODE=sleep');
$t0 = microtime(true);
check('timeout enforced', throws(fn() => makeDownloader($scratch, ['timeout' => 1])->download('https://videos.example-cdn.test/4'), 'timed out'));
check('timeout fired promptly', (microtime(true) - $t0) < 6);
check('timeout dir cleaned', count(glob($scratch . '/video-downloader/*') ?: []) === 0);

putenv('VD_FAKE_MODE=big');
check('size cap enforced post-download', throws(fn() => makeDownloader($scratch, ['maxMb' => 1])->download('https://videos.example-cdn.test/5'), 'size limit'));
check('oversize dir cleaned', count(glob($scratch . '/video-downloader/*') ?: []) === 0);

putenv('VD_FAKE_MODE=badext');
check('non-media extension rejected', throws(fn() => makeDownloader($scratch)->download('https://videos.example-cdn.test/6'), 'not an accepted media container'));

putenv('VD_FAKE_MODE=probe');
$meta = makeDownloader($scratch)->probe('https://videos.example-cdn.test/7');
check('probe returns metadata', is_array($meta) && $meta['title'] === 'Fake Video' && $meta['duration'] === 12);

putenv('VD_FAKE_MODE=success');
check('missing binary reported clearly', throws(fn() => makeDownloader($scratch, ['bin' => '/nonexistent/yt-dlp-xyz'])->download('https://videos.example-cdn.test/8'), 'Could not run yt-dlp'));

/* --------------------------------------------------- cleanup containment */
echo "Cleanup containment\n";

$outside = $scratch . '/outside-dir';
mkdir($outside);
file_put_contents($outside . '/keep.txt', 'important');
$d = makeDownloader($scratch);
check('refuses dir outside temp base', $d->removeDir($outside) === false && is_file($outside . '/keep.txt'));
check('refuses the temp base itself', $d->removeDir($scratch . '/video-downloader') === false);
check('refuses nonexistent path', $d->removeDir($scratch . '/video-downloader/nope') === false);

/* -------------------------------------------------------------- JobStore */
echo "JobStore\n";

$storeDir = $scratch . '/jobs';
$store = new JobStore($storeDir, 1);
$rec = $store->create(['userId' => 42, 'fieldId' => 7]);
check('create returns record', is_array($rec) && strlen($rec['id']) === 32);
check('meta persists (ownership)', $store->get($rec['id'])['userId'] === 42);
check('status starts queued', $rec['status'] === 'queued');

$store->update($rec['id'], ['status' => 'running', 'progress' => 0.5]);
check('update merges', $store->get($rec['id'])['status'] === 'running' && $store->get($rec['id'])['userId'] === 42);

check('unknown id → null', $store->get(str_repeat('a', 32)) === null);
check('invalid id → null', $store->get('../../../etc/passwd') === null);
check('update unknown id → null', $store->update(str_repeat('b', 32), ['x' => 1]) === null);

// Expiry: age a record past the TTL, then trigger cleanup via a new create.
touch($storeDir . '/' . $rec['id'] . '.json', time() - 3 * 86400);
$store->create([]);
check('expired records purged on create', $store->get($rec['id']) === null);

/* ------------------------------------------------------------- sanitiser */
echo "Error sanitisation\n";

$d = makeDownloader($scratch);
$raw = "line1\n" . $d->tempBase() . "/abcd/file.mp4 failed\nERROR: token=secret at " . $d->tempBase() . "/abcd\n";
$clean = $d->sanitizeOutput($raw);
check('temp paths stripped', !str_contains($clean, $scratch));
check('ERROR line kept', str_contains($clean, 'ERROR:'));
check('long output capped', strlen($d->sanitizeOutput(str_repeat('ERROR: x', 500))) <= 401 + 3);

/* ------------------------------------------------------------------ done */

// scrub scratch
exec('rm -rf ' . escapeshellarg($scratch));

echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    foreach ($failures as $f) {
        echo "FAIL: {$f}\n";
    }
    exit(1);
}
