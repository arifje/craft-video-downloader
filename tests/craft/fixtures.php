<?php
/**
 * Fixtures for the end-to-end tool check against a disposable Craft install
 * (the shared general-craft-4/5-test-container harnesses). Never run this
 * against a real project.
 *
 *   docker compose exec -T -e VD_PW=... php php /workspace/.../fixtures.php setup
 *   ... fixtures.php tool on|off
 *   ... fixtures.php teardown
 *
 * setup     plugin settings → stub yt-dlp + offline allow-list; users
 *           vd-editor (accessCp + accessPlugin-video-downloader) and vd-noaccess (accessCp only)
 * tool      toggle the "Download tool" setting
 * teardown  delete the test users, restore default settings, purge tool files
 */

use arifje\craftvideodownloader\controllers\ToolController;
use craft\elements\User;
use yii\base\Application;

require '/var/www/html/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

$action = $argv[1] ?? '';
$plugins = Craft::$app->getPlugins();
$plugin = $plugins->getPlugin('video-downloader');
if ($plugin === null) {
    fwrite(STDERR, "plugin not installed\n");
    exit(1);
}

// Craft 5.11 stores only the keys passed — always merge with current settings.
$save = function (array $changes) use ($plugins, $plugin): void {
    $plugins->savePluginSettings($plugin, array_merge($plugin->getSettings()->toArray(), $changes));
};

switch ($action) {
    case 'setup':
        $pw = getenv('VD_PW') ?: '';
        if (strlen($pw) < 12) {
            fwrite(STDERR, "VD_PW missing\n");
            exit(1);
        }
        $save([
            'enabled' => true,
            'toolEnabled' => true,
            'ytDlpPath' => '/workspace/Claude/craft-video-downloader/tests/php/fake-yt-dlp',
            'allowedHosts' => 'example-cdn.test',
            'maxResolution' => '1080',
            'maxFilesizeMb' => 500,
        ]);
        $users = [
            'vd-editor' => ['accesscp', strtolower(ToolController::PERMISSION_USE_TOOL)],
            'vd-noaccess' => ['accesscp'],
        ];
        foreach ($users as $name => $perms) {
            $u = User::find()->username($name)->status(null)->one() ?? new User();
            $u->username = $name;
            $u->email = $name . '@example.test';
            $u->newPassword = $pw;
            Craft::$app->getElements()->saveElement($u, false);
            Craft::$app->getUsers()->activateUser($u);
            Craft::$app->getUserPermissions()->saveUserPermissions($u->id, $perms);
        }
        echo 'edition=' . Craft::$app->getEditionName() . " setup ok\n";
        break;

    case 'field':
        // Disposable Assets field `vdVideos` uploading to the first volume.
        $fields = Craft::$app->getFields();
        $field = $fields->getFieldByHandle('vdVideos');
        if (!$field) {
            $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
            if ($volume === null) {
                fwrite(STDERR, "no volume\n");
                exit(1);
            }
            $field = new \craft\fields\Assets([
                'name' => 'VD Videos',
                'handle' => 'vdVideos',
                'sources' => '*',
                'defaultUploadLocationSource' => 'volume:' . $volume->uid,
            ]);
            if (method_exists($fields, 'getAllGroups') && property_exists($field, 'groupId')) {
                $field->groupId = $fields->getAllGroups()[0]->id ?? null; // Craft 4
            }
            if (!$fields->saveField($field)) {
                fwrite(STDERR, json_encode($field->getErrors()) . "\n");
                exit(1);
            }
        }
        echo $field->id . "\n";
        break;

    case 'delete-asset':
        $asset = \craft\elements\Asset::find()->id((int) ($argv[2] ?? 0))->status(null)->one();
        if ($asset) {
            Craft::$app->getElements()->deleteElement($asset, true);
        }
        echo "deleted\n";
        break;

    case 'tool':
        $save(['toolEnabled' => ($argv[2] ?? 'on') === 'on']);
        echo 'toolEnabled=' . var_export($plugin->getSettings()->toolEnabled, true) . "\n";
        break;

    case 'teardown':
        if ($f = Craft::$app->getFields()->getFieldByHandle('vdVideos')) {
            Craft::$app->getFields()->deleteField($f);
        }
        foreach (['vd-editor', 'vd-noaccess'] as $name) {
            $u = User::find()->username($name)->status(null)->one();
            if ($u) {
                Craft::$app->getElements()->deleteElement($u, true);
            }
        }
        $save([
            'toolEnabled' => true,
            'ytDlpPath' => 'yt-dlp',
            'allowedHosts' => '',
            'maxResolution' => '1080',
        ]);
        $files = Craft::getAlias('@storage') . '/video-downloader/files';
        exec('rm -rf ' . escapeshellarg($files));
        echo "teardown ok\n";
        break;

    default:
        fwrite(STDERR, "usage: fixtures.php setup|tool on|off|teardown\n");
        exit(1);
}

// Persist project-config changes (plugin settings) like a real request would.
$app->trigger(Application::EVENT_AFTER_REQUEST);
