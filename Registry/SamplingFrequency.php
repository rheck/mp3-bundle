<?php

namespace Rheck\Mp3Bundle\Registry;

class SamplingFrequency
{
    public static function get($arg0, $arg1)
    {
        $frequencies = array(
            '00' => array('44100', '22050', '11025'),
            '01' => array('48000', '24000', '12000'),
            '10' => array('32000', '16000', '8000')
        );

        if (isset($frequencies[$arg0][$arg1])) {
            return false;
        }

        return $frequencies[$arg0][$arg1];
    }
}
