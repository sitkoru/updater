<?php

namespace sitkoru\updater\controllers;

use sitkoru\updater\components\Console;
use sitkoru\updater\components\UpdateStopper;
use yii\base\Exception;
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

    const PREVENT_WAIT = 1;
    const PREVENT_CANCEL = 2;

    /**
     * @var \sitkoru\updater\Module
     */
    public $module;

    private $scenario = [];

    private $afterCommands = [];
    private $newVersion;
    private $branch;
    private $releasePath;

    public function init()
    {
        parent::init();

        if ($this->module->versionFilePath === '') {
            throw new Exception('You should set path to version file');
        }
        if ($this->module->currentVersion === 0.0) {
            Console::output('Maybe you forget to set current version. Trying to get from version file');
            if (file_exists($this->module->versionFilePath)) {
                require_once($this->module->versionFilePath);
                if (defined($this->module->versionConstant)) {
                    $this->module->currentVersion = constant($this->module->versionConstant);
                }
            }
        }
        if (!$this->module->steps) {
            throw new Exception('You should define steps');
        }
        if (!$this->module->scenarios) {
            throw new Exception('You should define scenarios');
        }
        Console::output('Starting process. Current version is ' . $this->module->currentVersion);
    }

    private function setNewVersion($version, $withPrefix = true)
    {
        $this->newVersion = $version;
        if ($withPrefix) {
            $prefix = str_ireplace('origin/', '', $this->module->releasePrefix);
            $this->branch = $prefix . $this->newVersion;
        } else {
            $this->branch = $version;
        }
    }

    public function actionIndex($branch = null)
    {
        if ($branch) {
            $this->setNewVersion($branch, false);
        }
        if (count($this->module->scenarios) > 1) {

            $scenarios = array_keys($this->module->scenarios);
            $scenario = Console::select('Choose scenario: ', $scenarios);
            $this->scenario = $this->module->scenarios[$scenarios[$scenario]];
        } else {
            $this->scenario = reset($this->module->scenarios);
        }
        //ask for mode
        $modes = [
            self::MODE_UPGRADE   => 'Upgrade',
            self::MODE_DOWNGRADE => 'Downgrade'
        ];

        $mode = (int)Console::select('Choose mode: ', $modes);
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

    private function getSteps($mode)
    {
        $steps = [];
        foreach ($this->scenario as $scenarioStep) {
            $stepNames = [];
            if (is_array($scenarioStep)) {
                if (array_key_exists('upgrade', $scenarioStep) && $mode === self::MODE_UPGRADE) {
                    $stepNames = $scenarioStep['upgrade'];
                }
                if (array_key_exists('downgrade', $scenarioStep) && $mode === self::MODE_DOWNGRADE) {
                    $stepNames = $scenarioStep['downgrade'];
                }
            } else {
                $stepNames = (array)$scenarioStep;
            }
            foreach ($stepNames as $stepName) {
                if (array_key_exists($stepName, $this->module->steps)) {
                    $steps[] = $this->module->steps[$stepName];
                } elseif (in_array($stepName, $this->module->systemSteps, true)) {
                    $steps[] = $stepName;
                } else {
                    $this->deleteLock();
                    throw new Exception('Unknown step: ' . $stepName);
                }
            }
        }

        return $steps;
    }

    private function runSystemCommand($commandName, $mode)
    {
        switch ($commandName) {
            case 'files':
                return $this->runFiles();
                break;
            case 'migrations':
                $this->runMigrations($mode);
                break;
            case 'nginx':
                return $this->runNginx();
                break;
        }

        return false;
    }

    private function runNginx()
    {
        foreach ($this->module->nginx as $file => $string) {
            if (file_exists($file)) {
                file_put_contents($file, str_ireplace('%release_dir%', $this->releasePath, $string));
            } else {
                Console::output('File ' . $file . ' doens\'t exists. Skip');
            }
        }

        return true;
    }

    private function runFiles()
    {
        $this->releasePath = $this->module->releasesDir . DIRECTORY_SEPARATOR . $this->newVersion;

        if (!is_dir($this->releasePath)) {
            mkdir($this->releasePath);
        } else {
            $this->execCommand('ls -a | xargs rm -rf', $this->releasePath);
            //array_map('unlink', glob($path . '/*'));
        }
        $filesUpdated = $this->updateFiles();
        if (!$filesUpdated) {
            return false;
        }
        if ($this->module->environment) {
            $this->runEnvironment($this->module->environment);
        }


        return true;
    }

    private function runEnvironment($environment)
    {
        $this->execCommand('./init --env=' . $environment . ' --overwrite=n', $this->releasePath);
    }

    private function runMigrations($mode)
    {
        $migrated = false;
        switch ($mode) {
            case self::MODE_UPGRADE:
                $migrated = $this->migrateUp($this->newVersion);
                break;
            case self::MODE_DOWNGRADE:
                $migrated = $this->migrateDown($this->newVersion);
                break;
        }

        return $migrated;
    }


    protected function process($mode = null)
    {
        if (!$this->checkLock()) {
            return false;
        }
        $this->createLock();
        if ($this->checkAppPreventUpdate()) {


            Console::output('Starting upgrade');
            if (!$this->newVersion) {
                $this->execCommand('git fetch');
                Console::output('Git fetched');
                $branches = $this->getBranches($mode);
                Console::output('Branches loaded');
                if (!$branches) {
                    Console::output('There is no new releases');

                    return false;
                }
                $select = Console::select('Choose branch: ', $branches);
                $version = $branches[$select];
                Console::output('Selected version: ' . $version);
                $this->setNewVersion($version);
            }
            Console::output(($mode === self::MODE_DOWNGRADE ? 'Downgrade' : 'Upgrade') . ' to version: ' . $this->newVersion);

            $steps = $this->getSteps($mode);
            foreach ($steps as $stepName => $commands) {
                if (is_string($commands) && in_array($commands, $this->module->systemSteps, true)) {
                    Console::output('Run step : ' . $commands);
                    $this->runSystemCommand($commands, $mode);
                } else {
                    Console::output('Run step : ' . $stepName);
                    $this->runUserCommands($stepName, $commands);
                }
            }
            /* $this->runBefore();
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
             $this->finalize();*/
        }

        return true;
    }


    /**
     * @return bool
     */
    protected function upgrade()
    {
        Console::output('Starting upgrade');
        $this->execCommand("git fetch");
        $branches = $this->getBranches();
        if (!$branches) {
            Console::output("There is no new releases");

            return false;
        }
        $select = Console::select("Choose branch: ", $branches);
        $version = $branches[$select];
        Console::output("Selected version: " . $version);
        for ($i = 0; $i <= $select; $i++) {
            $tmpVersion = $branches[$i];
            Console::output("Upgrade to version: " . $tmpVersion);
            $filesUpdated = $this->updateFiles($tmpVersion);
            if (!$filesUpdated) {
                return false;
            }
            $migrated = $this->migrateUp($tmpVersion);
            if (!$migrated) {
                return false;
            }
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
        Console::output('Migrate up');
        list($return_var, $result) = $this->execCommand(
            './yii updater/migrations/up 0 ' . $version . ' --interactive=0',
            $this->releasePath
        );
        if ($return_var === 0) {
            Console::output('Migrate complete');

            return true;
        }
        Console::output('Error while migrate process');
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
     * @param integer $mode
     *
     * @return array
     */
    protected function getBranches($mode)
    {
        $branches = [];

        list($return_var, $result) = $this->execCommand('git branch -r --no-color');
        if ($return_var !== 0) {
            return false;
        }
        foreach ($result as $branch) {
            $branch = trim($branch);
            if (stripos($branch, $this->module->releasePrefix) === 0) {
                $version = trim($branch, $this->module->releasePrefix);
                switch ($mode) {
                    case self::MODE_UPGRADE:
                        if ($this->compareVersions($version, $this->module->currentVersion) === 1) {
                            $branches[] = $version;
                        }
                        break;
                    case self::MODE_DOWNGRADE:
                        if ($this->compareVersions($version, $this->module->currentVersion) === -1) {
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
        if ($a === $b) {
            //var_dump("Equal!");
            return 0;
        }
        $partsA = explode('.', $a);
        //var_dump($partsA);
        $partsB = explode('.', $b);
        $more = null;
        //var_dump($partsB);
        foreach ($partsA as $key => $partA) {
            //Console::output("Process " . $partA);
            if ($more !== null) {
                //Console::output("Already found");
                continue;
            }
            if (array_key_exists($key, $partsB)) {
                if ((int)$partA > (int)$partsB[$key]) {
                    //Console::output($partA . " more than " . $partsB[$key]);
                    $more = true;
                } elseif ((int)$partA < (int)$partsB[$key]) {
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

    protected function updateFiles()
    {

        Console::output('Process Git');
        $fullPath = $this->getRelativePath($this->module->path, $this->releasePath);
        $command = 'git clone ' . $this->module->gitUrl . ' --branch ' . $this->branch . ' --single-branch --depth=1 ' . $fullPath;
        Console::output('Run ' . $command);
        list($return_var, $result) = $this->execCommand($command);
        if ($return_var === 0) {
            Console::output('Files updated');

            return true;
        }
        Console::output('Error while updating files');
        var_dump($result);

        return false;
    }

    /**
     * @param string $command
     * @param string $path
     *
     * @return array
     */
    protected function execCommand($command, $path = null)
    {
        $result = [];
        $return_var = 0;
        if (!$path) {
            $path = $this->module->path;
        }
        $command = 'cd ' . $path . ' && ' . $command;
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
        $start = count($branches) - 1;
        for ($i = $start; $i >= $select; $i--) {
            $tmpVersion = $branches[$i];
            Console::output("Downgrade to version: " . $tmpVersion);
            $migrated = $this->migrateDown($version);
            if (!$migrated) {
                return false;
            }
            $filesUpdated = $this->updateFiles($version);
            if (!$filesUpdated) {
                return false;
            }
        }

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
            $this->execCommand($command . " >> /dev/null");
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
            foreach ($this->module->cacheCommands as $command) {
                Console::output("Exec " . $command);
                $this->execCommand($command);
            }
        }
    }

    private function runAfter()
    {
        Console::output("Run after-update commands");
        foreach ($this->module->afterCommands as $command) {
            Console::output("Exec " . $command);
            $this->execCommand($command);
        }
        foreach ($this->afterCommands as $command) {
            Console::output("Exec " . $command);
            $this->execCommand($command);
        }
    }

    private function registerAfterCommand($key, $command)
    {
        $this->afterCommands[$key] = $command;
    }

    private function createLock()
    {
        file_put_contents($this->module->path . "/updater.lock", "1");
    }

    private function deleteLock()
    {
        unlink($this->module->path . "/updater.lock");
    }

    private function checkLock()
    {
        if (file_exists($this->module->path . "/updater.lock")) {
            Console::output("Update already in progress. File update.lock exists in your path");

            return false;
        }

        return true;
    }

    private function runBefore()
    {
        Console::output("Run before-update commands");
        foreach ($this->module->beforeCommands as $key => $command) {
            if (is_array($command)) {
                $answer = Console::select($key, ['0' => 'No', '1' => 'Yes']);
                if (!isset($command[$answer]) || $command[$answer] == false) {
                    continue;
                } else {
                    if (is_array($command[$answer])) {
                        if (!isset($command[$answer]['before'])) {
                            continue;
                        } else {
                            Console::output("Exec " . $command[$answer]['before']);
                            $this->execCommand($command[$answer]['before']);
                            if (isset($command[$answer]['after'])) {
                                $this->registerAfterCommand($key, $command[$answer]['after']);
                            }
                        }
                    } else {
                        Console::output("Exec " . $command[$answer]);
                        $this->execCommand($command[$answer]);
                    }
                }
            } else {
                Console::output("Exec " . $command);
                $this->execCommand($command);
            }
        }
    }

    private function checkAppPreventUpdate()
    {
        if (count($this->module->appUpdateStoppers)) {
            foreach ($this->module->appUpdateStoppers as $stopperClass) {
                $stopper = new $stopperClass();
                if ($stopper instanceof UpdateStopper) {
                    $canProcess = $stopper->check();
                    if ($canProcess !== true) {
                        Console::output(
                            $stopperClass . " prevent update process with message '" . $canProcess['message'] . "'"
                        );
                        $answer = Console::select(
                            "What should we do?",
                            [
                                self::PREVENT_WAIT   => 'Wait. Updater would ask for permission every 5 sec and proceed after positive answer',
                                self::PREVENT_CANCEL => 'Cancel update',
                            ]
                        );
                        if ($answer == self::PREVENT_CANCEL) {
                            $this->deleteLock();

                            return false;
                        } else {
                            while (true) {
                                $canProcess = $stopper->check();
                                if ($canProcess !== true) {
                                    Console::output(
                                        "Still waiting: " . $canProcess['message'] . ". Sleep for 5 seconds"
                                    );
                                    sleep(5);
                                } else {
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        return true;
    }

    private function finalize()
    {
        $this->runAfter();
        $this->deleteLock();
    }

    private function runUserCommands($stepName, array $commands)
    {
        Console::output('Run: ' . $stepName);
        foreach ($commands as $key => $command) {
            if (is_array($command)) {
                $answer = Console::select($key, ['0' => 'No', '1' => 'Yes']);
                if (!isset($command[$answer]) || $command[$answer] === false) {
                    continue;
                } else {
                    if (is_array($command[$answer])) {
                        if (!isset($command[$answer]['before'])) {
                            continue;
                        } else {
                            Console::output('Exec ' . $command[$answer]['before']);
                            $this->execCommand($command[$answer]['before'], $this->releasePath);
                            if (isset($command[$answer]['after'])) {
                                $this->registerAfterCommand($key, $command[$answer]['after']);
                            }
                        }
                    } else {
                        Console::output('Exec ' . $command[$answer]);
                        $this->execCommand($command[$answer], $this->releasePath);
                    }
                }
            } else {
                Console::output('Exec ' . $command);
                $this->execCommand($command, $this->releasePath);
            }
        }
    }

    private function getRelativePath($from, $to)
    {
        // some compatibility fixes for Windows paths
        $from = is_dir($from) ? rtrim($from, '\/') . '/' : $from;
        $to = is_dir($to) ? rtrim($to, '\/') . '/' : $to;
        $from = str_replace('\\', '/', $from);
        $to = str_replace('\\', '/', $to);

        $from = explode('/', $from);
        $to = explode('/', $to);
        $relPath = $to;

        foreach ($from as $depth => $dir) {
            // find first non-matching dir
            if ($dir === $to[$depth]) {
                // ignore this directory
                array_shift($relPath);
            } else {
                // get number of remaining dirs to $from
                $remaining = count($from) - $depth;
                if ($remaining > 1) {
                    // add traversals up to first matching dir
                    $padLength = (count($relPath) + $remaining - 1) * -1;
                    $relPath = array_pad($relPath, $padLength, '..');
                    break;
                } else {
                    $relPath[0] = './' . $relPath[0];
                }
            }
        }

        return implode('/', $relPath);
    }
}
