<?php
/**
 *
 * PHP version 5
 *
 * Created by PhpStorm.
 * User: Георгий
 * Date: 21.02.14
 * Time: 17:25
 */

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
        if (!in_array($input, array_keys($options))) {
            goto top;
        }
        return $input;
    }
}
