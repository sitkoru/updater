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
            $this->runAssets();
            $this->clearCaches();
        }
    }

    /**
     * @return bool
     */
    protected function upgrade()
    {
        Console::output("Starting upgrade");
        $this->execCommand("git fetch");
        $branches = $this->getBranches();
        $select = Console::select("Choose branch: ", $branches);
        $version = $branches[$select];
        Console::output("Selected version: " . $version);
        $filesUpdated = $this->updateFiles($version);
        if (!$filesUpdated) {
            return false;
        }
        $this->runComposer();
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
        list($return_var, $result) = $this->execCommand(
            "./yii updater/migrations/migrate 0 " . $version . " --interactive=0"
        );
        return $return_var == 0;
    }

    /**
     * @param $version
     *
     * @return bool
     */
    protected function migrateDown($version)
    {
        list($return_var, $result) = $this->execCommand(
            "./yii updater/migrations/migrateToAppVersion " . $version . " --interactive=0"
        );
        return $return_var == 0;
    }

    /**
     * @param string $dir
     *
     * @return array
     */
    protected function getBranches($dir = "up")
    {
        $branches = [];

        list($return_var, $result) = $this->execCommand("git branch -r --no-color");
        if ($return_var != 0) {
            return false;
        }
        foreach ($result as $branch) {
            $branch = trim($branch);
            if (stripos($branch, $this->module->releasePrefix) === 0) {
                $version = trim($branch, $this->module->releasePrefix);
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
        Console::output("Process Git");
        list($return_var, $result) = $this->execCommand(
            "git init && git stash && git fetch --all && git reset --hard " . $this->module->releasePrefix . $version
        );
        if ($return_var == 0) {
            Console::output("Files updated");
            return true;
        }
        Console::output("Error while updating files");
        var_dump($result);
        return false;
    }

    /**
     * @param $command
     *
     * @return array
     */
    protected function execCommand($command)
    {
        $result = [];
        $return_var = 0;
        $command = "cd " . $this->module->path . " && " . $command;
        exec($command, $result, $return_var);
        return [$return_var, $result];
    }

    private function downgrade()
    {
        return false;
    }

    private function saveVersion($version)
    {
        Console::output("Save new version to " . $this->module->versionFilePath);
        $php = str_ireplace("%constant%", $this->module->versionConstant, $this->module->versionFileTemplate);
        $php = str_ireplace("%version%", $version, $php);
        file_put_contents($this->module->versionFilePath, $php);
    }

    private function runAssets()
    {
        Console::output("Run assets commands");
        foreach ($this->module->assetsCommands as $command) {
            Console::output("Exec " . $command);
            $this->execCommand($command);
        }
        //$this->execCommand("lessc cgweb/web/less/cg_all.less cgweb/web/css/cg_all.css");
        //$this->execCommand("./yii asset/compress cgweb/config/main.assets.php cgweb/config/bundles.php");
    }

    private function runComposer()
    {
        Console::output("Run composer commands");
        foreach ($this->module->composerCommands as $command) {
            Console::output("Exec " . $command);
            $this->execCommand($command);
        }
        //$this->execCommand("curl -sS https://getcomposer.org/installer | php");
        //$this->execCommand("php composer.phar update --no-dev");
    }

    private function clearCaches()
    {
        if ($this->module->clearCache) {
            Console::output("Flush cache");
            $this->execCommand("./yii cache/flush");
        }
    }
}
