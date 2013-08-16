<?php

namespace Rheck\Mp3Bundle\Handler;

use Rheck\Mp3Bundle\Registry\Genres;

class Idv1Handler
{
    public function handle($fileSource, $fileSize)
    {
        $tagSize  = 128;
        $tagStart = $fileSize - $tagSize;

        fseek($fileSource, $tagStart);

        $tagData = fread($fileSource, $tagSize);
        $tag     = unpack('a3header/a30title/a30artist/a30album/a4year/a28comment/Creserve/Ctrack/Cgenre', $tagData);

        if($tag['header'] != 'TAG') {
            return array(
                'posAudioEnd' => $fileSize
            );
        }

        $posAudioEnd = $fileSize - $tagSize;

        $tag['genre'] = Genres::get($tag['genre']);
        $tag['genre'] = $tag['genre'] ? $tag['genre'] : 'Unknown';

        unset($tag['header']);

        return array(
            'tag'         => $tag,
            'posAudioEnd' => $posAudioEnd
        );
    }
}
