<?php
require __DIR__ . '/../../src/services/Cookies.php';
use arifje\craftvideodownloader\services\Cookies;
$store = new Cookies($argv[1] . '/credentials', $argv[1] . '/web');
$original = "# Netscape HTTP Cookie File\n#HttpOnly_.example.test\tTRUE\t/\tTRUE\t0\tsession\t secret=value  \n";
$replacement = str_replace('secret=value', 'replacement-value', $original);
for ($iteration = 0; $iteration < 150; $iteration++) {
    if ($argv[2] === '0') {
        $store->save($iteration % 2 ? $original : $replacement);
    } else {
        $file = $store->snapshot();
        $content = file_get_contents($file);
        unlink($file);
        if (!in_array($content, [$original, $replacement], true)) {
            exit(1);
        }
    }
}
