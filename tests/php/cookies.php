<?php

use arifje\craftvideodownloader\services\Cookies;

mkdir($scratch . '/web');
$store = new Cookies($scratch . '/credentials', $scratch . '/web');
$original = "# Netscape HTTP Cookie File\r\n#HttpOnly_.example.test\tTRUE\t/\tTRUE\t0\tsession\t secret=value  \r\n";
$normalized = str_replace("\r\n", "\n", $original);
$read = static function (Cookies $store, string $fallback = ''): ?string {
    $file = $store->snapshot($fallback);
    if ($file === null) {
        return null;
    }
    try {
        return file_get_contents($file);
    } finally {
        unlink($file);
    }
};
check('no cookies configured', $read($store) === null);
$store->save($original);
check('save preserves HttpOnly, whitespace and values; normalizes CRLF', $read($store) === $normalized);
check('private directory and saved file permissions', (fileperms($scratch . '/credentials') & 0777) === 0700 && (fileperms($scratch . '/credentials/cookies.txt') & 0777) === 0600);
$store->save('  ');
check('blank input preserves cookies', $read($store) === $normalized);
$replacement = str_replace('secret=value', 'replacement-value', $normalized);
$oldSnapshot = $store->snapshot();
$store->save($replacement);
check('replacement takes effect', $read($store) === $replacement);
check('in-flight snapshot survives replacement', file_get_contents($oldSnapshot) === $normalized);
$store->save('', true);
check('clear removes saved cookies', !$store->configured() && $read($store) === null);
check('in-flight snapshot survives clearing', file_get_contents($oldSnapshot) === $normalized);
unlink($oldSnapshot);
$legacy = $scratch . '/legacy.txt';
file_put_contents($legacy, $normalized);
check('legacy file fallback', $read($store, $legacy) === $normalized);
$store->save($replacement);
check('pasted cookies take precedence', $read($store, $legacy) === $replacement);
check('bad legacy path ignored when managed credentials exist', $read($store, '/missing') === $replacement);
foreach (['invalid', "# Netscape HTTP Cookie File\n", str_replace("\tTRUE\t", "\tyes\t", $normalized), str_replace('replacement-value', "bad\0value", $replacement), str_repeat('x', Cookies::MAX_BYTES + 1)] as $invalid) {
    check('invalid input rejected without replacing saved credentials', throws(fn() => $store->save($invalid)) && $read($store) === $replacement);
}
check('clear and replacement rejected', throws(fn() => $store->save($original, true)) && $store->configured());
$store->save('', true);
check('missing legacy file is a clear error', throws(fn() => $store->snapshot('/missing'), 'unreadable'));
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    chmod($legacy, 0000);
    check('unreadable legacy file reports safe error', throws(fn() => $store->snapshot($legacy), 'unreadable'));
    chmod($legacy, 0600);
}
file_put_contents($legacy, 'invalid');
check('invalid legacy file rejected', throws(fn() => $store->snapshot($legacy), 'Netscape'));
$public = new Cookies($scratch . '/web/credentials', $scratch . '/web');
check('public storage rejected', throws(fn() => $public->save($original), 'outside the web root'));
file_put_contents($scratch . '/blocked', 'file');
$blocked = new Cookies($scratch . '/blocked/credentials', $scratch . '/web');
check('storage failure is actionable', throws(fn() => $blocked->save($original), 'storage'));
$store->save($normalized);
putenv('VD_EXPECT_COOKIE=secret=value');
$downloader = makeDownloader($scratch, ['cookieStore' => $store]);
putenv('VD_FAKE_MODE=probe');
check('inspection receives configured cookies', $downloader->inspect('https://videos.example-cdn.test/clip')['title'] === 'Fake Video');
putenv('VD_FAKE_MODE=success');
$result = $downloader->download('https://videos.example-cdn.test/clip');
check('download receives configured cookies', is_file($result['path']));
check('yt-dlp cannot overwrite saved credentials', $read($store) === $normalized);
check('process snapshots cleaned', glob($scratch . '/credentials/jar-*') === []);
putenv('VD_COOKIE_ERROR=1');
check('authenticated diagnostics never disclose cookies', throws(fn() => $downloader->inspect('https://videos.example-cdn.test/clip'), 'Authenticated extraction'));
try {
    $downloader->inspect('https://videos.example-cdn.test/clip');
} catch (Throwable $e) {
    check('error response contains no cookie value', !str_contains($e->getMessage(), 'secret=value'));
}
check('failed extraction cleans snapshots', glob($scratch . '/credentials/jar-*') === []);
putenv('VD_COOKIE_ERROR');
putenv('VD_COOKIE_ECHO=1');
$store->save(str_replace(' secret=value  ', 'secret=value', $normalized) . ".example.test\tTRUE\t/\tFALSE\t0\tflag\t1\n");
$info = $downloader->inspect('https://videos.example-cdn.test/clip');
check('credential metadata redacted recursively without corrupting numbers', !str_contains(json_encode($info), 'secret=value') && !isset($info['cookies']) && !isset($info['formats'][0]['http_headers']) && $info['duration'] === 1);
putenv('VD_COOKIE_ECHO');
putenv('VD_EXPECT_COOKIE');
$store->save($normalized);

// Independent PHP processes exercise atomic rename without requiring pcntl.
$children = [];
for ($worker = 0; $worker < 3; $worker++) {
    $children[] = proc_open([PHP_BINARY, __DIR__ . '/cookie-worker.php', $scratch, (string) $worker], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
}
$ok = true;
foreach ($children as $child) {
    $ok = proc_close($child) === 0 && $ok;
}
check('concurrent readers never see partial cookies', $ok);

// Pasted exports in the shapes people actually copy (2.5.5).
$lenient = new Cookies($scratch . '/credentials-lenient', $scratch . '/web');
$tabbed = "# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t1893456000\tSID\tabc def\n";
$lenient->save(str_replace("\t", '   ', $tabbed));
check('spaces instead of tabs are saved as tabs, value keeps its own space', $read($lenient) === $tabbed);
$lenient->save("\xEF\xBB\xBF\n\n.youtube.com\tTRUE\t/\tTRUE\t1893456000.75\tSID\tabc def\n");
check('BOM, blank lines, missing header and decimal expiry accepted', $read($lenient) === $tabbed);
$json = json_encode([
    ['domain' => '.youtube.com', 'hostOnly' => false, 'httpOnly' => true, 'name' => 'SID', 'path' => '/', 'secure' => true, 'expirationDate' => 1893456000.5, 'value' => 'abc'],
    ['domain' => 'www.tiktok.com', 'hostOnly' => true, 'name' => 'tt', 'path' => '/', 'secure' => false, 'session' => true, 'value' => 'x'],
]);
$lenient->save($json);
check('Cookie-Editor JSON converted to Netscape', $read($lenient) === "# Netscape HTTP Cookie File\n#HttpOnly_.youtube.com\tTRUE\t/\tTRUE\t1893456000\tSID\tabc\nwww.tiktok.com\tFALSE\t/\tFALSE\t0\ttt\tx\n");
$lenient->save(json_encode(['cookies' => [['domain' => '.x.com', 'name' => 'auth', 'value' => 'v', 'path' => '/', 'expires' => -1, 'httpOnly' => false, 'secure' => true]]]));
check('Playwright storage state converted', $read($lenient) === "# Netscape HTTP Cookie File\n.x.com\tTRUE\t/\tTRUE\t0\tauth\tv\n");
check('broken JSON rejected with a clear message', throws(fn() => $lenient->save('[{"domain":'), 'JSON'));
check('JSON cookie without a name rejected', throws(fn() => $lenient->save('[{"domain":".a.test","value":"v"}]'), 'JSON cookie 1'));
check('invalid record names its line', throws(fn() => $lenient->save("# Netscape HTTP Cookie File\n.a.test\tTRUE\t/\tFALSE\t0\tok\tv\nnot a cookie\n"), 'Line 3'));
$lenient->save($json);
$summary = $lenient->summary();
check('summary lists domains, counts and first expiry', $summary === [
    ['domain' => 'www.tiktok.com', 'count' => 1, 'expires' => null],
    ['domain' => 'youtube.com', 'count' => 1, 'expires' => 1893456000],
]);
check('summary never contains names or values', !str_contains(json_encode($summary), 'SID') && !str_contains(json_encode($summary), 'abc'));
check('saved time reported', abs(($lenient->savedAt() ?? 0) - time()) < 5);
$lenient->save('', true);
check('no summary or saved time after clearing', $lenient->summary() === [] && $lenient->savedAt() === null);

// Domain removals preserve unrelated records and private snapshots.
file_put_contents($legacy, $normalized);
$multi = $normalized . "example.test\tFALSE\t/\tFALSE\t0\thost\thost-value\nsub.example.test\tFALSE\t/\tFALSE\t0\tsub\tsub-value\nother.test\tFALSE\t/\tFALSE\t0\tother\tother-value\n";
$store->save($multi);
$inFlight = $store->snapshot();
$store->save('', false, ['example.test']);
$remaining = "# Netscape HTTP Cookie File\nsub.example.test\tFALSE\t/\tFALSE\t0\tsub\tsub-value\nother.test\tFALSE\t/\tFALSE\t0\tother\tother-value\n";
check('domain removal includes HttpOnly and leading-dot variants but not subdomains', $read($store) === $remaining);
check('in-flight snapshot survives domain removal', file_get_contents($inFlight) === $multi);
unlink($inFlight);
$store->save('', false, ['missing.test']);
check('unknown domain is a harmless no-op', $read($store) === $remaining);
foreach ([[['bad']], ["bad\nvalue"], ['']] as $badDomains) {
    check('invalid domain selection preserves saved cookies', throws(fn() => $store->save('', false, $badDomains)) && $read($store) === $remaining);
}
check('removal and replacement cannot be combined', throws(fn() => $store->save($normalized, false, ['other.test'])) && $read($store) === $remaining);
check('removal and clear cannot be combined', throws(fn() => $store->save('', true, ['other.test'])) && $read($store) === $remaining);
$store->save('', false, ['sub.example.test', 'other.test']);
check('removing final domains clears managed file and enables legacy fallback', !$store->configured() && $read($store, $legacy) === $normalized);
check('domain removal never edits legacy file', file_get_contents($legacy) === $normalized);
// Multiple simultaneous removals must not overwrite each other.
for ($round = 0; $round < 10; $round++) {
    $store->save($multi);
    $children = [];
    foreach (['example.test', 'other.test'] as $domain) {
        $children[] = proc_open([PHP_BINARY, __DIR__ . '/cookie-worker.php', $scratch, 'remove', $domain], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    }
    $ok = true;
    foreach ($children as $child) {
        $ok = proc_close($child) === 0 && $ok;
    }
    check('concurrent domain removals preserve both changes', $ok && $read($store) === "# Netscape HTTP Cookie File\nsub.example.test\tFALSE\t/\tFALSE\t0\tsub\tsub-value\n");
}
