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

    public function actionIndex()
    {
        //ask for mode
        $modes = [
            self::MODE_UPGRADE   => 'Upgrade',
            self::MODE_DOWNGRADE => 'Downgrade'
        ];

        $mode = Console::select("Choose mode: ", $modes);
        $this->process($mode);
    }

    public function actionUpgrade()
    {
        $this->process(self::MODE_UPGRADE);
    }

    public function actionDowngrade()
    {
        $this->process(self::MODE_DOWNGRADE);
    }

    protected function process($mode = null)
    {
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
        if (!$branches) {
            Console::output("There is no new releases");
            return false;
        }
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
        Console::output("Migrate up");
        list($return_var, $result) = $this->execCommand(
            "./yii updater/migrations/up 0 " . $version . " --interactive=0"
        );
        if ($return_var == 0) {
            Console::output("Migrate complete");
            return true;
        }
        Console::output("Error while migrate process");
        var_dump($result);
        return false;
    }

    /**
     * @param $version
     *
     * @return bool
     */
    protected function migrateDown($version)
    {
        Console::output("Migrate down");
        list($return_var, $result) = $this->execCommand(
            "./yii updater/migrations/to-app-version " . $version . " --interactive=0"
        );
        if ($return_var == 0) {
            Console::output("Migrate complete");
            return true;
        }
        Console::output("Error while migrate process");
        var_dump($result);
        return false;
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
                        if ($this->compareVersions($version, $this->module->currentVersion) == 1) {
                            $branches[] = $version;
                        }
                        break;
                    case "down":
                        if ($this->compareVersions($version, $this->module->currentVersion) == -1) {
                            $branches[] = $version;
                        }
                        break;
                }
            }
        }
        return $branches;
    }

    private function compareVersions($a, $b)
    {
        //Console::output("Compare " . $a . " & " . $b);
        if ($a == $b) {
            //var_dump("Equal!");
            return 0;
        }
        $partsA = explode(".", $a);
        //var_dump($partsA);
        $partsB = explode(".", $b);
        $more = null;
        //var_dump($partsB);
        foreach ($partsA as $key => $partA) {
            //Console::output("Process " . $partA);
            if ($more !== null) {
                //Console::output("Already found");
                continue;
            }
            if (isset($partsB[$key])) {
                if ($partA > $partsB[$key]) {
                    //Console::output($partA . " more than " . $partsB[$key]);
                    $more = true;
                } elseif ($partA < $partsB[$key]) {
                    //Console::output($partA . " less than " . $partsB[$key]);
                    $more = false;
                }
                //Console::output($partA . " equal " . $partsB[$key]);
            } else {
                //Console::output($key . " doesn't exist in partsB");
                $more = true;
            }
        }
        //Console::output("Result " . ($more) ? "more" : "less");
        return $more ? 1 : -1;
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
        Console::output("Starting downgrade");
        $this->execCommand("git fetch");
        $branches = $this->getBranches('down');
        if (!$branches) {
            Console::output("There is no older releases");
            return false;
        }
        $select = Console::select("Choose branch: ", $branches);
        $version = $branches[$select];
        Console::output("Selected version: " . $version);
        $migrated = $this->migrateDown($version);
        if (!$migrated) {
            return false;
        }
        $filesUpdated = $this->updateFiles($version);
        if (!$filesUpdated) {
            return false;
        }
        $this->runComposer();
        return $version;
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
    }

    private function runComposer()
    {
        Console::output("Run composer commands");
        foreach ($this->module->composerCommands as $command) {
            Console::output("Exec " . $command);
            $this->execCommand($command);
        }
    }

    private function clearCaches()
    {
        if ($this->module->clearCache) {
            Console::output("Flush cache");
            $this->execCommand("./yii cache/flush");
        }
    }
}
