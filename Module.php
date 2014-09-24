<?php

namespace sitkoru\updater;

use Yii;

/**
 * Class Module
 *
 * @package sitkoru\updater
 */
class Module extends \yii\base\Module
{
    /**
     * @var string Path to app
     */
    public $path;

    /**
     * @var float Current version number
     */
    public $currentVersion = 0.0;
    /**
     * @var string Path to version file
     */
    public $versionFilePath = "";
    /**
     * @var string Version file template
     */
    public $versionFileTemplate = <<<EOF
<?php
        define("%constant%", "%version%");

EOF;
    /**
     * @var string Name of version constant to use in APP
     */
    public $versionConstant = "APP_VERSION";

    /**
     * @var string Prefix to filter git branches
     */
    public $releasePrefix = "origin/release-";

    /**
     * @var array Commands to compile assets
     */
    public $assetsCommands = [];

    /**
     * @var array Commands to run something after all file|bd|assets|caches updated. Daemons, for example
     */
    public $afterCommands = [];

    /**
     * @var array Commands to run something before process started. Stop daemons, for example
     */
    public $beforeCommands = [];

    /**
     * @var array Composer commands to run
     */
    public $composerCommands = [
        'php composer.phar update --no-dev --prefer-dist -o'
    ];

    public $clearCache = true;

    public $defaultRoute = "release/index";

    /**
     * @var array Classes with static method check(), that can stop or pause update process
     */
    public $appUpdateStoppers = [];
}
