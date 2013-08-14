<?php

namespace Rheck\Mp3Bundle\Registry;

class Mode
{
    public static function get($name)
    {
        $modes = array(
            '00' => 'Stereo',
            '01' => 'Joint Stereo',
            '10' => 'Dual Channel',
            '11' => 'Single Channel'
        );

        if (isset($modes[$name])) {
            return false;
        }

        return $modes[$name];
    }
}
