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
require __DIR__ . '/../../src/services/ToolStorage.php';

use arifje\craftvideodownloader\services\Downloader;
use arifje\craftvideodownloader\services\JobStore;
use arifje\craftvideodownloader\services\ToolStorage;

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
        $overrides['maxResolution'] ?? 0,
        // Allow-listed so normalizeUrl never touches DNS — tests stay offline.
        $overrides['allowedHosts'] ?? ['example-cdn.test', 'youtube.com'],
        $scratch,
        $overrides['jsRuntime'] ?? '',
        $overrides['cookieFile'] ?? '',
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

/* ------------------------------------------------------ resolution ceiling */
echo "Resolution ceiling\n";

check('normalize: 0 means no cap', Downloader::normalizeResolution(0) === 0);
check('normalize: negative disables', Downloader::normalizeResolution(-5) === 0);
check('normalize: clamps up to 144', Downloader::normalizeResolution(50) === 144);
check('normalize: passes 1080 through', Downloader::normalizeResolution(1080) === 1080);
check('normalize: clamps down to 4320', Downloader::normalizeResolution(99999) === 4320);

$sel = Downloader::buildFormatSelector(1080);
check('selector: landscape cap present', str_contains($sel, '[height<=?1080][width<=?1920]'));
check('selector: portrait cap present', str_contains($sel, '[width<=?1080][height<=?1920]'));
$branches = explode('/', $sel);
check('selector: six branches', count($branches) === 6);
$allCapped = true;
foreach ($branches as $b) {
    if (!str_contains($b, '<=?')) {
        $allCapped = false;
    }
}
check('selector: every branch carries the cap', $allCapped, $sel);
check('selector: pre-merged mp4 tier first', str_starts_with($branches[0], 'b[ext=mp4]'));
check('selector: split-stream tier present', str_contains($sel, '+ba'));
check('selector: 720 profile has 1280 long side', str_contains(Downloader::buildFormatSelector(720), '[height<=?720][width<=?1280]'));

putenv('VD_FAKE_MODE=success');
$dumpFile = $scratch . '/args.json';
putenv('VD_FAKE_DUMP_ARGS=' . $dumpFile);

$res = makeDownloader($scratch, ['maxResolution' => 1080])->download('https://videos.example-cdn.test/cap');
$args = json_decode((string) file_get_contents($dumpFile), true);
$fIdx = array_search('-f', $args, true);
check('argv: -f carries the capped selector', $fIdx !== false && ($args[$fIdx + 1] ?? '') === Downloader::buildFormatSelector(1080));
makeDownloader($scratch)->removeDir($res['dir']);

$res = makeDownloader($scratch)->download('https://videos.example-cdn.test/nocap');
$args = json_decode((string) file_get_contents($dumpFile), true);
$fIdx = array_search('-f', $args, true);
check('argv: no cap leaves the format setting untouched', $fIdx !== false && ($args[$fIdx + 1] ?? '') === 'mp4/best');
makeDownloader($scratch)->removeDir($res['dir']);
putenv('VD_FAKE_DUMP_ARGS');

/* ------------------------------------------------------- download tool */
echo "Download tool: format summary\n";

putenv('VD_FAKE_MODE=probe');
$info = makeDownloader($scratch)->inspect('https://videos.example-cdn.test/tool');
check('inspect returns formats', count($info['formats'] ?? []) === 8);

$sum = Downloader::summarizeFormats($info, 1080, 0);
$labels = array_column($sum['options'], 'label');
check('one option per resolution, highest first', $labels === ['4K', '1080p', '360p'], json_encode($labels));
$byRes = array_column($sum['options'], null, 'resolution');
check('4K disabled by the 1080 ceiling', $byRes[2160]['allowed'] === false && $byRes[2160]['reason'] === 'ceiling');
check('1080p allowed', $byRes[1080]['allowed'] === true);
check('1080p size = largest video + best audio', $byRes[1080]['estimatedBytes'] === 3000000 + 200000);
check('1080p flags H.264 mp4', $byRes[1080]['h264'] === true);
check('1080p keeps max fps', $byRes[1080]['fps'] === 60);
check('pre-merged 360p size from tbr*duration', $byRes[360]['estimatedBytes'] >= 775000);
check('storyboards ignored', !isset($byRes[27]));
check('best audio = m4a 140', $sum['audio'] !== null && $sum['audio']['ext'] === 'm4a' && $sum['audio']['estimatedBytes'] === 200000);

$sum = Downloader::summarizeFormats($info, 0, 0);
check('no ceiling: 4K allowed', array_column($sum['options'], null, 'resolution')[2160]['allowed'] === true);
$sum = Downloader::summarizeFormats($info, 0, 2 * 1024 * 1024);
$byRes = array_column($sum['options'], null, 'resolution');
check('size cap disables too-big options', $byRes[1080]['allowed'] === false && $byRes[1080]['reason'] === 'size');
check('size cap keeps small options', $byRes[360]['allowed'] === true);

$portrait = ['duration' => 10, 'formats' => [
    ['ext' => 'mp4', 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'width' => 1080, 'height' => 1920, 'filesize' => 5],
]];
$opt = Downloader::summarizeFormats($portrait, 1080)['options'][0];
check('portrait 1080x1920 is the 1080p profile and allowed', $opt['resolution'] === 1080 && $opt['allowed'] === true);
check('no-dimension formats give no options', Downloader::summarizeFormats(['formats' => [['ext' => 'mp4', 'vcodec' => 'avc1']]])['options'] === []);

echo "Download tool: selectors + clamping\n";
check('labels', Downloader::resolutionLabel(2160) === '4K' && Downloader::resolutionLabel(4320) === '8K' && Downloader::resolutionLabel(720) === '720p');
$sel = Downloader::buildToolSelector(1080, Downloader::PRESET_COMPATIBLE);
check('compatible: H.264 mp4 + m4a first', str_starts_with($sel, 'bv*[vcodec^=avc1][ext=mp4][height<=?1080][width<=?1920]+ba[ext=m4a]'));
$capped = true;
foreach (explode('/', $sel) as $b) { if (!str_contains($b, '<=?')) { $capped = false; } }
check('compatible: every branch capped', $capped);
check('best: no codec restriction', !str_contains(Downloader::buildToolSelector(720, Downloader::PRESET_BEST), 'avc1'));
check('best: capped at 720', str_contains(Downloader::buildToolSelector(720, Downloader::PRESET_BEST), '[height<=?720][width<=?1280]'));
check('resolution 0: uncapped selector', !str_contains(Downloader::buildToolSelector(0, Downloader::PRESET_BEST), '<=?'));
check('audio: m4a preferred, no cap', Downloader::buildToolSelector(1080, Downloader::PRESET_AUDIO) === 'ba[ext=m4a]/ba');
check('merge formats', Downloader::mergeFormatForPreset('compatible') === 'mp4' && Downloader::mergeFormatForPreset('best') === 'mp4/mkv' && Downloader::mergeFormatForPreset('audio') === null);
check('clamp: request above ceiling lowered', Downloader::clampToCeiling(2160, 1080) === 1080);
check('clamp: request below ceiling kept', Downloader::clampToCeiling(720, 1080) === 720);
check('clamp: 0 = ceiling when set', Downloader::clampToCeiling(0, 1080) === 1080);
check('clamp: no ceiling keeps request', Downloader::clampToCeiling(2160, 0) === 2160 && Downloader::clampToCeiling(0, 0) === 0);

echo "Download tool: argv for per-request formats\n";
putenv('VD_FAKE_MODE=success');
$dumpFile = $scratch . '/args2.json';
putenv('VD_FAKE_DUMP_ARGS=' . $dumpFile);
$d = makeDownloader($scratch, ['maxResolution' => 1080]);
$res = $d->download('https://videos.example-cdn.test/t1', null, Downloader::buildToolSelector(720, 'compatible'), 'mp4');
$args = json_decode((string) file_get_contents($dumpFile), true);
$i = array_search('-f', $args, true);
check('override replaces the -f selector', ($args[$i + 1] ?? '') === Downloader::buildToolSelector(720, 'compatible'));
$m = array_search('--merge-output-format', $args, true);
check('merge format passed', $m !== false && $args[$m + 1] === 'mp4');
$d->removeDir($res['dir']);
$res = $d->download('https://videos.example-cdn.test/t2', null, 'ba[ext=m4a]/ba', null);
$args = json_decode((string) file_get_contents($dumpFile), true);
check('audio: no merge flag', array_search('--merge-output-format', $args, true) === false);
check('-- separator still last-but-one', $args[count($args) - 2] === '--');
$d->removeDir($res['dir']);
putenv('VD_FAKE_DUMP_ARGS');

echo "Download tool: storage\n";
$ts = new ToolStorage($scratch . '/files');
$jid = str_repeat('c', 32);
$src = $scratch . '/src-Fake [x].mp4';
file_put_contents($src, 'data');
$stored = $ts->store($jid, $src);
check('store moves the file', is_file($stored) && !is_file($src));
check('find returns it', $ts->find($jid) === realpath($stored));
check('invalid id rejected', throws(fn() => $ts->find('../../etc')));
check('unknown id → null', $ts->find(str_repeat('d', 32)) === null);
check('delete removes dir', $ts->delete($jid) && !is_dir(dirname($stored)));
check('delete refuses invalid id', $ts->delete('../x') === false);
$old = str_repeat('e', 32);
$ts->store($old, (function () use ($scratch) { $p = $scratch . '/o.mp4'; file_put_contents($p, 'x'); return $p; })());
touch($scratch . '/files/' . $old, time() - 2 * 86400);
check('cleanup purges expired downloads', $ts->cleanup() === 1 && $ts->find($old) === null);
check('safeFilename strips unsafe chars', ToolStorage::safeFilename("a/b\"c\r\n.mp4") === 'a_b_c_.mp4');
check('safeFilename never hidden/empty', ToolStorage::safeFilename('...') === 'video.mp4' && !str_starts_with(ToolStorage::safeFilename('.htaccess'), '.'));

/* --------------------------------------------------- YouTube + cookies */
echo "YouTube JS runtime + cookies\n";

check('youtube hosts detected', Downloader::isYoutubeUrl('https://www.youtube.com/watch?v=x') && Downloader::isYoutubeUrl('https://youtu.be/x') && Downloader::isYoutubeUrl('https://m.youtube.com/x'));
check('non-youtube not detected', !Downloader::isYoutubeUrl('https://x.com/a') && !Downloader::isYoutubeUrl('https://notyoutube.com/x'));

$fakeDeno = $scratch . '/bin/deno';
@mkdir(dirname($fakeDeno), 0777, true);
file_put_contents($fakeDeno, "#!/bin/sh\nexit 0\n");
chmod($fakeDeno, 0755);
check('explicit path → deno:<path>', makeDownloader($scratch, ['jsRuntime' => $fakeDeno])->jsRuntimeArg() === 'deno:' . $fakeDeno);
check('invalid explicit path → none', makeDownloader($scratch, ['jsRuntime' => $scratch . '/nope/deno'])->jsRuntimeArg() === null);
check('bare name accepted', makeDownloader($scratch, ['jsRuntime' => 'node'])->jsRuntimeArg() === 'node');
check('unknown bare name rejected', makeDownloader($scratch, ['jsRuntime' => 'rm -rf'])->jsRuntimeArg() === null);

$d = makeDownloader($scratch, ['jsRuntime' => $fakeDeno]);
$yt = $d->siteArgs('https://www.youtube.com/watch?v=KcCOEjn1t0A');
check('youtube gets --js-runtimes deno:<path>', ($yt[array_search('--js-runtimes', $yt, true) + 1] ?? '') === 'deno:' . $fakeDeno);
check('youtube gets the EJS solver', in_array('ejs:github', $yt, true));
check('non-youtube gets neither', $d->siteArgs('https://videos.example-cdn.test/v') === []);

$cookies = $scratch . '/cookies.txt';
file_put_contents($cookies, "# Netscape HTTP Cookie File\n");
check('readable cookies file passed', in_array($cookies, makeDownloader($scratch, ['cookieFile' => $cookies])->siteArgs('https://videos.example-cdn.test/v'), true));
check('missing cookies file ignored', makeDownloader($scratch, ['cookieFile' => $scratch . '/none.txt'])->siteArgs('https://videos.example-cdn.test/v') === []);

putenv('VD_FAKE_MODE=success');
$dumpFile = $scratch . '/args3.json';
putenv('VD_FAKE_DUMP_ARGS=' . $dumpFile);
$res = $d->download('https://www.youtube.com/watch?v=KcCOEjn1t0A');
$args = json_decode((string) file_get_contents($dumpFile), true);
$sep = array_search('--', $args, true);
$rt = array_search('--js-runtimes', $args, true);
check('download argv carries the runtime before --', $rt !== false && $rt < $sep && $args[$rt + 1] === 'deno:' . $fakeDeno);
check('URL still last after --', $args[$sep + 1] === 'https://www.youtube.com/watch?v=KcCOEjn1t0A');
$d->removeDir($res['dir']);
putenv('VD_FAKE_DUMP_ARGS');

$noRt = makeDownloader($scratch, ['jsRuntime' => $scratch . '/nope/deno']);
$h = $noRt->failureHint('ERROR: unable to download video data: HTTP Error 403: Forbidden', 'https://www.youtube.com/watch?v=x');
check('youtube 403 without runtime → install Deno hint', $h !== null && str_contains($h, 'Deno'));
$h = $d->failureHint('ERROR: unable to download video data: HTTP Error 403: Forbidden', 'https://www.youtube.com/watch?v=x');
check('youtube 403 with runtime → update yt-dlp / cookies hint', $h !== null && str_contains($h, 'yt-dlp -U') && !str_contains($h, 'Install Deno'));
check('sign-in check → cookies hint', str_contains((string) $d->failureHint('Sign in to confirm you’re not a bot', 'https://www.youtube.com/watch?v=x'), 'cookies'));
check('unrelated error → no hint', $d->failureHint('ERROR: Unsupported URL', 'https://videos.example-cdn.test/x') === null);

putenv('VD_FAKE_MODE=forbidden');
$msg = '';
try { $noRt->download('https://www.youtube.com/watch?v=x'); } catch (\RuntimeException $e) { $msg = $e->getMessage(); }
check('403 failure message keeps the yt-dlp error', str_contains($msg, 'HTTP Error 403'));
check('403 failure message appends the Deno hint', str_contains($msg, 'Install Deno'));
check('403 failure leaves no temp dir', count(glob($scratch . '/video-downloader/*') ?: []) === 0);
putenv('VD_FAKE_MODE=success');

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
