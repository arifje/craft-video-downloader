<?php
/**
 * Native Craft lifecycle regression, with an in-memory project config sink.
 * Run in the disposable Craft Docker harness; no database/config writes.
 */
require '/var/www/html/bootstrap.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'arifje\\craftvideodownloader\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
}, true, true);
$_SERVER['SCRIPT_FILENAME'] = '/var/www/html/web/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['CRAFT_DISABLED_PLUGINS'] = '*';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/web.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n"); exit(1); });

use arifje\craftvideodownloader\Plugin;
use arifje\craftvideodownloader\services\Cookies;
use arifje\craftvideodownloader\services\Downloader;
use arifje\craftvideodownloader\services\FieldDownloads;

class CookieTestRequest extends \craft\web\Request
{
    public bool $cp = false;
    public function getIsCpRequest(): bool { return $this->cp; }
    public function getCsrfToken($regenerate = false): string { return Craft::$app->getSecurity()->maskToken('cookie-test-csrf'); }
}
class CookieTestProjectConfig extends \craft\services\ProjectConfig
{
    public array $writes = [];
    public function set(string $path, mixed $value, ?string $message = null, bool $updateTimestamp = true, bool $force = false): bool
    {
        $this->writes[$path] = $value;
        return true;
    }
}
class CookieTestPath extends \craft\services\Path
{
    public string $scratch;
    public function getStoragePath(bool $create = true): string { return $this->scratch; }
}

\yii\base\Event::on(\craft\web\View::class, \craft\web\View::EVENT_REGISTER_CP_TEMPLATE_ROOTS, static function ($event): void {
    $event->roots['video-downloader'] = dirname(__DIR__, 2) . '/src/templates';
});
$failures = 0;
$check = static function (string $name, bool $ok) use (&$failures): void {
    echo ($ok ? 'ok ' : 'FAIL ') . $name . "\n";
    $failures += !$ok;
};
$scratch = sys_get_temp_dir() . '/vd-craft-cookies-' . bin2hex(random_bytes(5));
mkdir($scratch, 0700);
mkdir($scratch . '/web', 0700);
Craft::setAlias('@webroot', $scratch . '/web');
mkdir($scratch . '/web/cpresources', 0700);
$app->set('assetManager', new \craft\web\AssetManager(['basePath' => $scratch . '/web/cpresources', 'baseUrl' => '/cpresources']));
$app->set('path', new CookieTestPath(['scratch' => $scratch]));
$app->set('projectConfig', new CookieTestProjectConfig());
$app->set('user', new \craft\web\User(['enableSession' => false, 'identityClass' => \craft\elements\User::class]));
$app->getUser()->setIdentity(new \craft\elements\User(['id' => 1234567, 'admin' => true]));
$app->getConfig()->getGeneral()->allowAdminChanges = true;
$_SERVER['REQUEST_METHOD'] = 'POST';
$secret = 'fixture-session-secret-123';
$content = "# Netscape HTTP Cookie File\n#HttpOnly_.example.test\tTRUE\t/\tTRUE\t0\tsession\t$secret\n";
$submit = static function (mixed $input, bool $clear = false, bool $csrf = true) use ($app): Plugin {
    $request = new CookieTestRequest(['cookieValidationKey' => 'offline-test-key']);
    $body = ['settings' => ['cookiePassword' => $input, 'clearCookies' => $clear ? '1' : ''], 'pluginHandle' => 'video-downloader'];
    if ($csrf) {
        $body[$request->csrfParam] = $request->getCsrfToken();
    }
    $_POST = $body;
    $request->setBodyParams($body);
    $app->set('request', $request);
    return new Plugin('video-downloader', null, ['basePath' => dirname(__DIR__, 2) . '/src']);
};
try {
    $plugin = $submit($content);
    $check('credential removed from request before settings validation', !isset($_POST['settings']['cookiePassword']) && $app->getRequest()->getBodyParam('settings.cookiePassword') === null);
    $check('native save succeeds', $app->getPlugins()->savePluginSettings($plugin, $app->getRequest()->getBodyParam('settings')));
    $serialized = json_encode($app->getProjectConfig()->writes) . json_encode($plugin->getSettings()->toArray());
    $check('project config and model contain no credential or input fields', !str_contains($serialized, $secret) && !str_contains($serialized, 'cookiePassword'));
    $store = Cookies::forCraft();
    $check('native save writes private credentials', $store->configured());
    $plugin = $submit('');
    $check('native blank save preserves', $plugin->beforeSaveSettings() && $store->configured());
    $plugin = $submit('not a cookie ' . $secret);
    $check('invalid content has safe model errors', !$plugin->beforeSaveSettings() && !str_contains(json_encode($plugin->getSettings()->getErrors()), $secret));
    $plugin = $submit($content, false, false);
    try { $plugin->beforeSaveSettings(); $denied = false; } catch (\yii\web\ForbiddenHttpException) { $denied = true; }
    $check('missing CSRF denied by native validation', $denied);
    $plugin = $submit($content);
    $app->getUser()->setIdentity(new \craft\elements\User(['id' => 1234568, 'admin' => false]));
    try { $plugin->beforeSaveSettings(); $denied = false; } catch (\yii\web\ForbiddenHttpException) { $denied = true; }
    $check('non-admin denied', $denied);
    $app->getUser()->setIdentity(new \craft\elements\User(['id' => 1234567, 'admin' => true]));
    $plugin = $submit($content);
    $app->getConfig()->getGeneral()->allowAdminChanges = false;
    try { $plugin->beforeSaveSettings(); $denied = false; } catch (\yii\web\ForbiddenHttpException) { $denied = true; }
    $check('allowAdminChanges enforced', $denied);
    $app->getConfig()->getGeneral()->allowAdminChanges = true;
    $settings = $plugin->getSettings();
    $settings->ytDlpPath = __DIR__ . '/../php/fake-yt-dlp';
    $settings->allowedHosts = 'example-cdn.test';
    $_SERVER['VD_TEST_COOKIE_PATH'] = $scratch . '/legacy.txt';
    $settings->cookieFile = '$VD_TEST_COOKIE_PATH';
    file_put_contents($scratch . '/legacy.txt', str_replace($secret, 'legacy-fixture', $content));
    $check('legacy environment path resolves', $settings->getResolvedCookieFile() === $scratch . '/legacy.txt');
    putenv('VD_EXPECT_COOKIE=' . $secret);
    putenv('VD_FAKE_MODE=probe');
    $response = (new FieldDownloads($settings))->inspect('https://videos.example-cdn.test/clip');
    $check('MCP shared inspect service uses managed credentials without returning secrets', $response['meta']['title'] === 'Fake Video' && !str_contains(json_encode($response), $secret));
    putenv('VD_FAKE_MODE=success');
    $downloader = Downloader::fromSettings($settings);
    $result = $downloader->download('https://videos.example-cdn.test/clip');
    $check('queue downloader factory receives managed credentials', is_file($result['path']) && !str_contains(json_encode($result), $secret));
    $downloader->removeDir($result['dir']);
    $plugin = $submit('', true);
    $check('native explicit clear succeeds', $plugin->beforeSaveSettings() && !$store->configured());
    putenv('VD_EXPECT_COOKIE=legacy-fixture');
    putenv('VD_FAKE_MODE=probe');
    $check('factory falls back to environment legacy path after clear', Downloader::fromSettings($settings)->inspect('https://videos.example-cdn.test/clip')['title'] === 'Fake Video');
    $store->save($content);
    $app->controller = new \craft\controllers\PluginsController('plugins', $app);
    $reflection = new ReflectionMethod($plugin, 'settingsHtml');
    $html = $app->getView()->namespaceInputs(fn() => $reflection->invoke($plugin), 'settings');
    $check('native settings textarea renders empty', str_contains($html, 'textarea') && str_contains($html, 'settings[cookiePassword]') && str_contains($html, 'Saved cookies are configured.') && !str_contains($html, $secret));
    require __DIR__ . '/cookie-utility.php';
    echo 'Craft ' . $app->getVersion() . "\n";
} finally {
    putenv('VD_EXPECT_COOKIE');
    putenv('VD_FAKE_MODE');
    \craft\helpers\FileHelper::removeDirectory($scratch);
}
exit($failures > 0 ? 1 : 0);
