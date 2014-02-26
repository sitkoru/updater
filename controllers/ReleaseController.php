<?php
/**
 *
 * PHP version 5
 *
 * Created by PhpStorm.
 * User: Георгий
 * Date: 21.02.14
 * Time: 14:11
 */

namespace sitkoru\updater\controllers;

use sitkoru\updater\components\Console;
use yii\console\Controller;

/**
 * Class ReleaseController
 *
 * @package console\controllers
 *
 */
class ReleaseController extends Controller
{
    const MODE_UPGRADE = 1;
    const MODE_DOWNGRADE = 2;

    /**
     * @var \sitkoru\updater\Module
     */
    public $module;

    public function actionUpdate()
    {
        //ask for mode
        $modes = [
            self::MODE_UPGRADE   => 'Upgrade',
            self::MODE_DOWNGRADE => 'Downgrade'
        ];

        $mode = Console::select("Choose mode: ", $modes);

        $version = false;
        switch ($mode) {
            case self::MODE_UPGRADE:
                $version = $this->upgrade();
                break;
            case self::MODE_DOWNGRADE:
                $version = $this->downgrade();
                break;
        }
        if ($version) {
            $this->saveVersion($version);
            $this->runComposer();
            $this->runAssets();
            $this->clearCaches();
        }
    }

    /**
     * @return bool
     */
    protected function upgrade()
    {
        //$this->execGitCommand("git fetch --all");
        $branches = $this->getBranches();
        $select = Console::select("Choose branch: ", $branches);
        $version = $branches[$select];
        $filesUpdated = $this->updateFiles($version);
        if (!$filesUpdated) {
            return false;
        }
        $migrated = $this->migrateUp($version);
        if (!$migrated) {
            return false;
        }
        return $version;
    }

    /**
     * @param $version
     *
     * @return bool
     */
    protected function migrateUp($version)
    {
        $this->execCommand("./yii updater/migrations/migrate --version=" . $version . " --interactive=0");
        return true;
    }

    /**
     * @param $version
     *
     * @return bool
     */
    protected function migrateDown($version)
    {
        $this->execCommand("./yii updater/migrations/migrateToAppVersion --version=" . $version . " --interactive=0");
        return true;
    }

    /**
     * @param string $dir
     *
     * @return array
     */
    protected function getBranches($dir = "up")
    {
        $branches = [];

        $result = $this->execCommand("git branch -r --no-color");
        foreach ($result as $branch) {
            $branch = trim($branch);
            if (stripos($branch, $this->module->releasePrefix) === 0) {
                $version = floatval(trim($branch, $this->module->releasePrefix));
                switch ($dir) {
                    case "up":
                        if ($version > $this->module->currentVersion) {
                            $branches[] = $version;
                        }
                        break;
                    case "down":
                        if ($version < $this->module->currentVersion) {
                            $branches[] = $version;
                        }
                        break;
                }
            }
        }
        return $branches;
    }

    protected function updateFiles($version)
    {
        $result = $this->execCommand(
            "git init && git stash && git pull origin " . $this->module->currentVersion . $version
        );
        var_dump($result);
        return true;
    }

    /**
     * @param $command
     *
     * @return array
     */
    protected function execCommand($command)
    {
        $result = [];
        $command = "cd " . $this->module->path . " && " . $command;
        exec($command, $result);
        return $result;
    }

    private function downgrade()
    {
        return false;
    }

    private function saveVersion($version)
    {
        $php = str_ireplace("%constant%", $this->module->versionConstant, $this->module->versionFileTemplate);
        $php = str_ireplace("%version%", $version, $php);
        file_put_contents($this->module->versionFilePath, $php);
    }

    private function runAssets()
    {
        foreach ($this->module->assetsCommands as $command) {
            $this->execCommand($command);
        }
        //$this->execCommand("lessc cgweb/web/less/cg_all.less cgweb/web/css/cg_all.css");
        //$this->execCommand("./yii asset/compress cgweb/config/main.assets.php cgweb/config/bundles.php");
    }

    private function runComposer()
    {
        foreach ($this->module->composerCommands as $command) {
            $this->execCommand($command);
        }
        //$this->execCommand("curl -sS https://getcomposer.org/installer | php");
        //$this->execCommand("php composer.phar update --no-dev");
    }

    private function clearCaches()
    {
        if ($this->module->clearCache) {
            $this->execCommand("./yii cache/flush");
        }
    }
}
