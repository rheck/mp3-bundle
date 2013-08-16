<?php

namespace Rheck\Mp3Bundle\Registry;

class BitRate
{
    public static function get($arg0, $arg1, $arg2)
    {
        $bitrates = array(
            '0000' => array(array('~', '~', '~')      , array('~', '~', '~')),
            '0001' => array(array('32', '32', '32')   , array('32', '8', '8')),
            '0010' => array(array('64', '48', '40')   , array('48', '16', '16')),
            '0011' => array(array('96', '56', '48')   , array('56', '24', '24')),
            '0100' => array(array('128', '64', '56')  , array('64', '32', '32')),
            '0101' => array(array('160', '80', '64')  , array('80', '40', '40')),
            '0110' => array(array('192', '96', '80')  , array('96', '48', '48')),
            '0111' => array(array('224', '112', '96') , array('112', '56', '56')),
            '1000' => array(array('256', '128', '112'), array('128', '64', '64')),
            '1001' => array(array('288', '160', '128'), array('144', '80', '80')),
            '1010' => array(array('320', '192', '160'), array('160', '96', '96')),
            '1011' => array(array('352', '224', '192'), array('176', '112', '112')),
            '1100' => array(array('384', '256', '224'), array('192', '128', '128')),
            '1101' => array(array('416', '320', '256'), array('224', '144', '144')),
            '1110' => array(array('448', '384', '320'), array('256', '160', '160'))
        );

        if (!isset($bitrates[$arg0][$arg1][$arg2])) {
            return false;
        }

        return $bitrates[$arg0][$arg1][$arg2];
    }
}
