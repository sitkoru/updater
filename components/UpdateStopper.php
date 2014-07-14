<?php
/**
 * Created by PhpStorm.
 * User: Георгий
 * Date: 14.07.2014
 * Time: 16:55
 */

namespace sitkoru\updater\components;


interface UpdateStopper {
    /**
     * @return mixed
     */
    public function check();
} 