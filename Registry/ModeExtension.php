<?php

namespace Rheck\Mp3Bundle\Registry;

class ModeExtension
{
    public static function get($name)
    {
        $modeExtensions = array(
            '00' => array(0, 0),
            '01' => array(1, 0),
            '10' => array(0, 1),
            '11' => array(1, 1)
        );

        if (!isset($modeExtensions[$name])) {
            return false;
        }

        return $modeExtensions[$name];
    }
}
