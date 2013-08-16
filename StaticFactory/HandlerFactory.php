<?php

namespace Rheck\Mp3Bundle\StaticFactory;

class HandlerFactory
{
    public static function get($handlerName)
    {
        $handlers = array(
            'id3v1' => 'Rheck\Mp3Bundle\Handler\Id3v1Handler',
            'id3v2' => 'Rheck\Mp3Bundle\Handler\Id3v2Handler',
            'mp3'   => 'Rheck\Mp3Bundle\Handler\Mp3Handler'
        );

        if (!isset($handler[$handlerName])) {
            return false;
        }

        return new $handlers[$handlerName];
    }
}
