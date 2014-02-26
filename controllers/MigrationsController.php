<?php
/**
 *
 * PHP version 5
 *
 * Created by PhpStorm.
 * User: Георгий
 * Date: 26.02.14
 * Time: 10:48
 */

namespace sitkoru\updater\controllers;

use yii\console\controllers\MigrateController;
use yii\db\Query;
use yii\helpers\ArrayHelper;

/**
 * Class MigrationsController
 *
 * @package sitkoru\updater\controllers
 */
class MigrationsController extends MigrateController
{

    public $appVersion;

    protected function createMigrationHistoryTable()
    {
        $tableName = $this->db->schema->getRawTableName($this->migrationTable);
        echo "Creating migration history table \"$tableName\"...";
        $this->db->createCommand()->createTable(
            $this->migrationTable,
            [
                'version'     => 'varchar(180) NOT NULL PRIMARY KEY',
                'apply_time'  => 'integer',
                'app_version' => 'string'
            ]
        )->execute();
        $this->db->createCommand()->insert(
            $this->migrationTable,
            [
                'version'    => self::BASE_MIGRATION,
                'apply_time' => time(),
            ]
        )->execute();
        echo "done.\n";
    }

    public function actionUp($limit = 0, $version = null)
    {
        $this->appVersion = $version;
        parent::actionUp($limit);
    }

    public function actionToAppVersion($version)
    {
        $this->appVersion = $version;
        $history = $this->getMigrationHistory(1, $version);
        if ($history) {
            $mVersion = array_keys($history)[0];
            $this->migrateToVersion($mVersion);
        }
    }

    /**
     * @param int  $limit
     *
     * @param null $version
     *
     * @return array
     */
    protected function getMigrationHistory($limit, $version = null)
    {
        if ($this->db->schema->getTableSchema($this->migrationTable, true) === null) {
            $this->createMigrationHistoryTable();
        }
        $query = new Query;
        $query->select(['version', 'apply_time'])
            ->from($this->migrationTable)
            ->orderBy('version DESC')
            ->limit($limit);
        if ($version) {
            $query->where(['app_version' => $version]);
        }
        $rows = $query->createCommand($this->db)
            ->queryAll();
        $history = ArrayHelper::map($rows, 'version', 'apply_time');
        unset($history[self::BASE_MIGRATION]);
        return $history;
    }

    /**
     * Upgrades with the specified migration class.
     *
     * @param string $class the migration class name
     *
     * @return boolean whether the migration is successful
     */
    protected function migrateUp($class)
    {
        if ($class === self::BASE_MIGRATION) {
            return true;
        }

        echo "*** applying $class\n";
        $start = microtime(true);
        $migration = $this->createMigration($class);
        if ($migration->up() !== false) {
            $this->db->createCommand()->insert(
                $this->migrationTable,
                [
                    'version'     => $class,
                    'apply_time'  => time(),
                    'app_version' => $this->appVersion
                ]
            )->execute();
            $time = microtime(true) - $start;
            echo "*** applied $class (time: " . sprintf("%.3f", $time) . "s)\n\n";
            return true;
        } else {
            $time = microtime(true) - $start;
            echo "*** failed to apply $class (time: " . sprintf("%.3f", $time) . "s)\n\n";
            return false;
        }
    }
} 