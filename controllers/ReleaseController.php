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
    private $scenarioName;

    private $afterCommands = [];
    private $newVersion;
    private $branch;
    private $branches = [];
    private $releasePath;
    private $currentPath;

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
                    $this->module->currentVersion
                        = constant($this->module->versionConstant);
                }
            }
        }
        if (!$this->module->steps) {
            throw new Exception('You should define steps');
        }
        if (!$this->module->scenarios) {
            throw new Exception('You should define scenarios');
        }
        Console::output('Starting process. Current version is '
            . $this->module->currentVersion);
    }

    private function setNewVersion($version, $withPrefix = true)
    {
        $this->newVersion = $version;
        $this->releasePath = $this->module->releasesDir . DIRECTORY_SEPARATOR
            . $this->newVersion;
        if ($withPrefix) {
            $this->branch = $this->getFullBranchName($this->newVersion);
        } else {
            $this->branch = $version;
        }
    }

    private function getFullBranchName($branch)
    {
        $prefix = str_ireplace('origin/', '', $this->module->releasePrefix);

        return $prefix . $branch;
    }

    public function actionIndex()
    {
        if (count($this->module->scenarios) > 1) {

            $scenarios = array_keys($this->module->scenarios);
            $scenario = Console::select('Choose scenario: ', $scenarios);
            $this->scenarioName = $scenarios[$scenario];
            $this->scenario = $this->module->scenarios[$this->scenarioName];
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
        foreach ($this->scenario as $scenarioStepName => $scenarioStep) {
            switch (true) {
                case $scenarioStepName === 'upgrade':
                    echo $scenarioStepName . PHP_EOL;
                    if ($mode !== self::MODE_UPGRADE) {
                        continue;
                    }
                    $steps['main'] = $scenarioStep;
                    break;
                case $scenarioStepName === 'downgrade':
                    echo $scenarioStepName . PHP_EOL;
                    if ($mode !== self::MODE_DOWNGRADE) {
                        continue;
                    }
                    $steps['main'] = $scenarioStep;
                    break;
                default:
                    $stepNames = (array)$scenarioStep;

                    foreach ($stepNames as $stepName) {
                        if (array_key_exists($stepName, $this->module->steps)) {
                            $steps[$stepName] = $this->module->steps[$stepName];
                        } elseif (in_array($stepName,
                            $this->module->systemSteps, true)) {
                            $steps[$stepName] = $stepName;
                        } else {
                            $this->deleteLock();
                            throw new Exception('Unknown step: ' . $stepName);
                        }
                    }
                    break;
            }
        }

        return $steps;
    }

    private function runSystemCommand($stepName, $commands, $mode)
    {
        switch ($stepName) {
            case 'main':
                return $this->runMain($commands, $mode);
                break;
            case 'nginx':
                return $this->runNginx();
                break;
        }

        return false;
    }

    private function runMain(array $commands, $mode)
    {
        var_dump($commands);
        foreach ($this->branches as $branch) {
            foreach ($commands as $command) {
                switch ($command) {
                    case 'files':
                        $this->runFiles($branch);
                        break;
                    case 'composer':
                        $this->runComposer();
                        break;
                    case 'composerCopy':
                        $this->runComposerCopy();
                        break;
                    case 'migrations':
                        $this->runMigrations($branch, $mode);
                        break;
                }
            }
            if ($branch === $this->newVersion) {
                echo 'done';
                break;
            }
        }

        return true;
    }

    private function runComposer()
    {
        Console::output('Run composer');
        $this->runComposerCopy();
        foreach ($this->module->composer as $command) {
            list($returnVar, $result) = $this->execCommand($command,
                $this->currentPath);
            if ($returnVar !== 0) {
                Console::output('Composer error: ');
                var_dump($result);
            }
        }
        Console::output('Composer done');

        return true;
    }

    private function runComposerCopy()
    {
        list($returnVar, $result) = $this->execCommand('cp -a '
            . $this->module->path . DIRECTORY_SEPARATOR . 'vendor '
            . $this->currentPath);
        if ($returnVar !== 0) {
            var_dump($result);
        }
        list($returnVar, $result) = $this->execCommand('cp '
            . $this->module->path . DIRECTORY_SEPARATOR . 'composer.lock '
            . $this->currentPath);
        if ($returnVar !== 0) {
            var_dump($result);
        }
        Console::output('Old composer dir copied');
    }

    private function runNginx()
    {
        $changed = false;
        foreach ($this->module->nginx as $file => $string) {
            if (file_exists($file)) {
                file_put_contents($file,
                    str_ireplace('%release_dir%', $this->releasePath, $string));
                $changed = true;
            } else {
                Console::output('File ' . $file . ' doens\'t exists. Skip');
            }
        }
        if ($changed) {
            list($return_var, $result)
                = $this->execCommand('nginx -t && /etc/init.d/nginx reload');
            if ($return_var !== 0) {
                Console::output('Error on nginx reloading');
                var_dump($result);
            }
        }

        return true;
    }

    private function runFiles($branch)
    {
        $this->currentPath = $this->module->releasesDir . DIRECTORY_SEPARATOR
            . $branch;

        if (!is_dir($this->currentPath)) {
            mkdir($this->currentPath);
        } else {
            $this->execCommand('ls -a | xargs rm -rf', $this->currentPath);
            //array_map('unlink', glob($path . '/*'));
        }
        $filesUpdated = $this->updateFiles($branch);
        if (!$filesUpdated) {
            return false;
        }
        if ($this->module->environment) {
            $this->runEnvironment($this->module->environment,
                $this->currentPath);
        }


        return true;
    }

    private function runEnvironment($environment, $path)
    {
        $this->execCommand('./init --env=' . $environment . ' --overwrite=a',
            $path ?: $this->releasePath);
    }

    private function runMigrations($branch, $mode)
    {
        Console::output('Run migrations');
        $migrated = false;
        switch ($mode) {
            case self::MODE_UPGRADE:
                $migrated = $this->migrateUp($branch);
                break;
            case self::MODE_DOWNGRADE:
                $migrated = $this->migrateDown($branch);
                break;
        }
        Console::output('Migrations is done');

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
                $this->branches = $this->getBranches($mode);
                Console::output('Branches loaded');
                if (!$this->branches) {
                    Console::output('There is no new releases');
                    $this->deleteLock();

                    return false;
                }
                $select = Console::select('Choose branch: ', $this->branches);
                $version = $this->branches[$select];
                Console::output('Selected version: ' . $version);
                $this->setNewVersion($version);
            }
            Console::output(($mode === self::MODE_DOWNGRADE ? 'Downgrade'
                    : 'Upgrade') . ' to version: ' . $this->newVersion);

            $steps = $this->getSteps($mode);
            foreach ($steps as $stepName => $commands) {
                if (in_array($stepName, $this->module->systemSteps, true)) {
                    Console::output('Run step : ' . $stepName);
                    $this->runSystemCommand($stepName, $commands, $mode);
                } else {
                    Console::output('Run step : ' . $stepName);
                    $this->runUserCommands($stepName, $commands);
                }
            }

            $this->saveVersion();
            $this->deleteLock();
        }

        return true;
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
            $this->currentPath
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
            "./yii updater/migrations/to-app-version " . $version
            . " --interactive=0"
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
        $command = 'git ls-remote --heads origin | grep "'
            . $this->module->releasePrefix . '"';
        Console::output('Exec ' . $command);
        list($return_var, $result) = $this->execCommand($command);
        if ($return_var !== 0) {
            return false;
        }
        foreach ($result as $branch) {
            $branch = trim(explode("\t", $branch)[1]);
            $branch = str_ireplace('refs/heads/', '', $branch);
            if (stripos($branch, $this->module->releasePrefix) === 0) {
                $version = trim($branch, $this->module->releasePrefix);
                switch ($mode) {
                    case self::MODE_UPGRADE:
                        if ($this->compareVersions($version,
                                $this->module->currentVersion) === 1
                        ) {
                            $branches[] = $version;
                        }
                        break;
                    case self::MODE_DOWNGRADE:
                        if ($this->compareVersions($version,
                                $this->module->currentVersion) === -1
                        ) {
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

    protected function updateFiles($branch)
    {
        $fullBranchName = $this->getFullBranchName($branch);
        Console::output('Process Git');
        $fullPath = $this->getRelativePath($this->module->path,
            $this->currentPath);
        $command = 'git clone ' . $this->module->gitUrl . ' --branch '
            . $fullBranchName . ' --single-branch --depth=1 ' . $fullPath;
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
        if (!$path) {
            $path = $this->module->path;
        }
        $command = str_ireplace('%release_dir%', $this->releasePath, $command);

        list($exitCode, $output, $errors) = Console::exec($command, $path);
        if ($exitCode > 0) {
            Console::output('There some errors:');
            Console::output($output);
            Console::output($errors);
        }

        return [$exitCode, $output];
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

    private function saveVersion()
    {
        Console::output('Save new version to ' . $this->currentPath
            . DIRECTORY_SEPARATOR . $this->module->versionFilePath);
        $php = str_ireplace('%constant%', $this->module->versionConstant,
            $this->module->versionFileTemplate);
        $php = str_ireplace('%version%', $this->newVersion, $php);
        file_put_contents($this->currentPath . DIRECTORY_SEPARATOR
            . $this->module->versionFilePath, $php);
    }

    private function registerAfterCommand($key, $command)
    {
        $this->afterCommands[$key] = $command;
    }

    private function createLock()
    {
        file_put_contents($this->module->path . '/updater.lock', 1);
    }

    private function deleteLock()
    {
        unlink($this->module->path . '/updater.lock');
    }

    private function checkLock()
    {
        if (file_exists($this->module->path . '/updater.lock')) {
            Console::output('Update already in progress. File updater.lock exists in your path');

            return false;
        }

        return true;
    }

    private function checkAppPreventUpdate()
    {
        if (count($this->module->appUpdateStoppers[$this->scenarioName])) {
            foreach (
                $this->module->appUpdateStoppers[$this->scenarioName] as
                $stopperClass
            ) {
                $stopper = new $stopperClass();
                if ($stopper instanceof UpdateStopper) {
                    $canProcess = $stopper->check();
                    if ($canProcess !== true) {
                        Console::output(
                            $stopperClass
                            . ' prevent update process with message \''
                            . $canProcess['message'] . "'"
                        );
                        $answer = Console::select(
                            'What should we do?',
                            [
                                self::PREVENT_WAIT   => 'Wait. Updater would ask for permission every 5 sec and proceed after positive answer',
                                self::PREVENT_CANCEL => 'Cancel update',
                            ]
                        );
                        if ($answer === self::PREVENT_CANCEL) {
                            $this->deleteLock();

                            return false;
                        } else {
                            while (true) {
                                $canProcess = $stopper->check();
                                if ($canProcess !== true) {
                                    Console::output(
                                        'Still waiting: '
                                        . $canProcess['message']
                                        . '. Sleep for 5 seconds'
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
                        foreach ($command[$answer] as $answerCommand) {
                            Console::output('Exec ' . $answerCommand);
                            $this->execCommand($answerCommand,
                                $this->releasePath);
                        }
                    } else {
                        Console::output('Exec ' . $command[$answer]);
                        $this->execCommand($command[$answer],
                            $this->releasePath);
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
