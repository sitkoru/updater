<?php

namespace sitkoru\updater\components;

/**
 * Class Console
 *
 * @package console\components
 */
class Console extends \yii\helpers\Console
{
    /**
     * @inheritdoc
     */
    public static function select($prompt, $options = [])
    {
        top:
        static::stdout("$prompt\n");
        foreach ($options as $key => $value) {
            static::stdout($key . " - " . $value . "\n");
        }
        static::stdout("Select:");
        $input = static::stdin();
        if (!array_key_exists($input, $options)) {
            goto top;
        }

        return $input;
    }

    /**
     * @param $cwd
     * @param $command
     *
     * @return array
     */
    public static function exec($command, $cwd = null)
    {
        $cwd = $cwd ?: __DIR__;
        $descriptorspec = array(
            0 => array('pipe', 'r'),  // stdin
            1 => array('pipe', 'w'),  // stdout
            2 => array('pipe', 'w'),  // stderr
        );
        $process = proc_open($command, $descriptorspec, $pipes, $cwd,
            null);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [$code, $output, $errors];
    }
}
