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

namespace console\controllers;

use console\components\Console;
use yii\console\Controller;

/**
 * Class ReleaseController
 *
 * @package console\controllers
 */
class ReleaseController extends Controller
{
    const MODE_UPGRADE = 1;
    const MODE_DOWNGRADE = 2;

    const ENV_DEV = 0;
    const ENV_PROD = 1;

    public $releasePrefix = "origin/release-";
    public $currentVersion = 0.3;

    private $env;

    public function actionUpdate()
    {
        $environments = [
            self::MODE_UPGRADE   => 'Upgrade',
            self::MODE_DOWNGRADE => 'Downgrade'
        ];

        $this->env = Console::select("Choose environment: ", $environments);

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
            $this->runInit($this->env);
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
        $branch = $branches[$select];
        $filesUpdated = $this->updateFiles($branch);
        if (!$filesUpdated) {
            return false;
        }
        $migrated = $this->migrateUp();
        if (!$migrated) {
            return false;
        }
        return true;
    }

    /**
     * @return bool
     */
    protected function migrateUp()
    {
        $this->execCommand("./yii migrate --interactive=0");
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
            if (stripos($branch, $this->releasePrefix) === 0) {
                $version = floatval(trim($branch, $this->releasePrefix));
                switch ($dir) {
                    case "up":
                        if ($version > $this->currentVersion) {
                            $branches[] = $version;
                        }
                        break;
                    case "down":
                        if ($version < $this->currentVersion) {
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
        $result = $this->execCommand("git init && git stash && git pull origin " . $this->releasePrefix . $version);
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
        exec($command, $result);
        return $result;
    }

    private function downgrade()
    {
        return false;
    }

    private function saveVersion($version)
    {
        $php = <<<EOF
        <?php
        define("CG_VERSION", "{$version}");
        ?>
EOF;
        file_put_contents("version.php", $php);
    }

    private function runInit($env)
    {
        $this->execCommand("./init " . $env);
    }

    private function runAssets()
    {
        $this->execCommand("lessc cgweb/web/less/cg_all.less cgweb/web/css/cg_all.css");
        $this->execCommand("./yii asset/compress cgweb/config/main.assets.php cgweb/config/bundles.php");
    }

    private function runComposer()
    {
        $this->execCommand("curl -sS https://getcomposer.org/installer | php");
        $this->execCommand("php composer.phar update --no-dev");
    }

    private function clearCaches()
    {
        $this->execCommand("./yii run/cacheClean");
    }
}
