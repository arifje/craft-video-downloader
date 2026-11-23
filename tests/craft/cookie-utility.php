<?php
/** Included by cookies.php, sharing its isolated storage and project-config sink. */

use arifje\craftvideodownloader\controllers\CookiesController;
use arifje\craftvideodownloader\utilities\CookieUtility;
use craft\elements\User;
use craft\web\Response;
use yii\web\HttpException;

$app->getConfig()->getGeneral()->allowAdminChanges = false;
$writesBefore = $app->getProjectConfig()->writes;
$utilities = $app->getUtilities();
$types = method_exists($utilities, 'getAllUtilities') ? $utilities->getAllUtilities() : $utilities->getAllUtilityTypes();
$check('cookie utility registered on this Craft major', in_array(CookieUtility::class, $types, true));
$check('admin sees cookie utility with admin changes disabled', CookieUtility::isSelectable());
$html = CookieUtility::contentHtml();
$check('production utility renders empty credential field and CSRF input', str_contains($html, 'settings[cookiePassword]') && str_contains($html, 'video-downloader/cookies/save') && str_contains($html, $app->getRequest()->csrfParam) && !str_contains($html, $secret));

$utilitySave = static function (mixed $input, bool $clear = false, bool $csrf = true, string $method = 'POST', mixed $domains = []) use ($app, $submit): ?Response {
    $_SERVER['REQUEST_METHOD'] = $method;
    $plugin = $submit($input, $clear, $csrf, $domains);
    $app->getRequest()->cp = true;
    $app->getRequest()->getHeaders()->set('Accept', 'application/json');
    $app->set('response', new Response());
    $controller = new CookiesController('cookies', $plugin);
    $app->controller = $controller;
    return $controller->runAction('save');
};
$readCookies = static function () use ($store): ?string {
    $path = $store->snapshot();
    if ($path === null) {
        return null;
    }
    try {
        return file_get_contents($path);
    } finally {
        unlink($path);
    }
};
$replacement = str_replace($secret, 'replacement-utility-fixture', $content);
$response = $utilitySave($replacement);
$check('utility replaces cookies on locked production', $response->getStatusCode() === 200 && $readCookies() === $replacement);
$check('utility response contains no credentials', !str_contains(json_encode($response->data), 'replacement-utility-fixture'));
$response = $utilitySave('');
$check('utility blank input preserves saved cookies', $response->getStatusCode() === 200 && $readCookies() === $replacement);
$response = $utilitySave('invalid ' . $secret);
$check('utility validation error is safe and preserves cookies', $response->getStatusCode() === 400 && !str_contains(json_encode($response->data), $secret) && $readCookies() === $replacement);
$response = $utilitySave(['unexpected' => $secret]);
$check('utility rejects non-text cookie input safely', $response->getStatusCode() === 400 && !str_contains(json_encode($response->data), $secret));
foreach (['csrf', 'non-admin', 'anonymous', 'get'] as $case) {
    if ($case === 'non-admin') {
        $app->getUser()->setIdentity(new User(['id' => 1234568, 'admin' => false]));
        $check('non-admin cannot see utility', !CookieUtility::isSelectable());
        try { CookieUtility::contentHtml(); $denied = false; } catch (HttpException) { $denied = true; }
        $check('direct utility render denies non-admin', $denied);
    } elseif ($case === 'anonymous') {
        $app->getUser()->setIdentity(null);
    }
    try {
        $utilitySave($content, false, $case !== 'csrf', $case === 'get' ? 'GET' : 'POST');
        $denied = false;
    } catch (HttpException $e) {
        $denied = in_array($e->statusCode, [400, 403, 405], true);
    }
    $check('utility denies ' . $case . ' and preserves credentials', $denied && $readCookies() === $replacement);
    $app->getUser()->setIdentity(new User(['id' => 1234567, 'admin' => true]));
}
$store->save($replacement . "other.test\tFALSE\t/\tFALSE\t0\tother\tprivate-value\n");
$html = CookieUtility::contentHtml();
$check('domain removal controls render without cookie values', str_contains($html, 'settings[removeCookieDomains][]') && str_contains($html, 'aria-label="Remove cookies for other.test"') && !str_contains($html, 'private-value'));
$response = $utilitySave('', false, true, 'POST', ['other.test']);
$check('utility removes selected domains on locked production', $response->getStatusCode() === 200 && $readCookies() === $replacement);
$check('domain selections stripped before settings persistence', $app->getRequest()->getBodyParam('settings.removeCookieDomains') === null && !isset($_POST['settings']['removeCookieDomains']));
$response = $utilitySave('', false, true, 'POST', 'invalid');
$check('utility rejects malformed selection safely', $response->getStatusCode() === 400 && $readCookies() === $replacement);
foreach (['csrf', 'non-admin'] as $case) {
    if ($case === 'non-admin') {
        $app->getUser()->setIdentity(new User(['id' => 1234568, 'admin' => false]));
    }
    try {
        $utilitySave('', false, $case !== 'csrf', 'POST', ['example.test']);
        $denied = false;
    } catch (HttpException) {
        $denied = true;
    }
    $check('domain removal denies ' . $case, $denied && $readCookies() === $replacement);
    $app->getUser()->setIdentity(new User(['id' => 1234567, 'admin' => true]));
}
$response = $utilitySave('', true);
$check('utility explicit clear works on locked production', $response->getStatusCode() === 200 && !$store->configured());
$check('utility never writes project config', $app->getProjectConfig()->writes === $writesBefore);
$_SERVER['REQUEST_METHOD'] = 'POST';
$app->getConfig()->getGeneral()->allowAdminChanges = true;
