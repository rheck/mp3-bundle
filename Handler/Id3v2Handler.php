<?php

namespace Rheck\Mp3Bundle\Handler;

class Id3v2Handler
{
    public function handle($fileSource)
    {
        $mp3Handler = HandlerFactory::get('mp3');

        $pos_call = ftell($fileSource);
        $tag      = array();

        $tagHeaderData = fread($fileSource, 10);
        $tagHeader     = unpack('a3identifier/Cversion/Crevision/Cflag/Csize0/Csize1/Csize2/Csize3', $tagHeaderData);

        if(!$tagHeader || $tagHeader['identifier'] != 'ID3') {
            fseek($fileSource, $pos_call);
            return false;
        }

        $tag['version']  = $tagHeader['version'];
        $tag['revision'] = $tagHeader['revision'];

        $tagFlag = $mp3Handler->convFlag($tagHeader['flag']);

        $tag['flag'] = array
        (
            'unsynchronisation' => $tagFlag{0},
            'extra'             => $tagFlag{1},
            'istest'            => $tagFlag{2}
        );

        $tagSize = ($tagHeader['size0'] & 0x7F) << 21
            | ($tagHeader['size1'] & 0x7F) << 14
            | ($tagHeader['size2'] & 0x7F) << 7
            | ($tagHeader['size3']);

        if(($tagSize = intval($tagSize)) < 1) {
            return false;
        }

        $tag['size']   = $tagSize;
        $tag['frames'] = array();

        $pos_start = ftell($fileSource);
        $pos_end   = $pos_start + $tagSize - 10;

        while(true) {
            if(ftell($fileSource) >= $pos_end) {
                break;
            }

            $frameHeaderData = fread($fileSource, 10);
            $frameHeader     = unpack('a4frameid/Nsize/Cflag0/Cflag1', $frameHeaderData);

            if(!$frameHeader || !$frameHeader['frameid']) {
                continue;
            }

            $frameId          = $frameHeader['frameid'];
            $frameDescription = 'Unknown';

            if(Frame::get($frameId)) {
                $frameDescription = Frame::get($frameId);
            } else {
                switch(strtoupper($frameId{0})) {
                    case 'T':
                        $frameDescription = 'User defined text information frame';
                        break;
                    case 'W':
                        $frameDescription = 'User defined URL link frame';
                        break;
                }
            }

            if(($frameSize = $frameHeader['size']) < 1 || (ftell($fileSource) + $frameSize) > $pos_end) {
                continue;
            }

            $frameFlag = array
            (
                $mp3Handler->convFlag($frameHeader['flag0']),
                $mp3Handler->convFlag($frameHeader['flag1'])
            );

            $frameCharsetData = unpack('c', fread($fileSource, 1));
            $frameCharset     = '';

            switch($frameCharsetData) {
                case 0:
                    $frameCharset = 'ISO-8859-1';
                    break;
                case 1:
                    $frameCharset = 'UTF-16';
                    break;
                case 2:
                    $frameCharset = 'UTF-16BE';
                    break;
                case 3:
                    $frameCharset = 'UTF-8';
                    break;
            }

            if($frameCharset) {
                $frameDataSize = $frameSize - 1;
            } else {
                $frameDataSize = $frameSize;
                fseek($fileSource, ftell($fileSource) - 1);
            }

            $frameData = unpack("a{$frameDataSize}data", fread($fileSource, $frameDataSize));
            $frameData = $frameData['data'];

            if($frameId == 'COMM') {
                $frameLang = substr($frameData, 0, 3);
                $frameData = substr($frameData, 3 + ($frameData{3} == "\x00" ? 1 : 0));
            } else {
                $frameLang = '';
            }

            $frame = array
            (
                'frameid'     => $frameId,
                'description' => $frameDescription,
                'flag'        => array
                (
                    'tag_protect'  => $frameFlag[0]{0},
                    'file_protect' => $frameFlag[0]{1},
                    'readonly'     => $frameFlag[0]{2},
                    'compressed'   => $frameFlag[1]{0},
                    'encrypted'    => $frameFlag[1]{1},
                    'group'        => $frameFlag[1]{2}
                ),
                'size' => $frameSize,
                'data' => $frameData
            );

            $frameCharset && $frame['charset']  = $frameCharset;
            $frameLang    && $frame['language'] = $frameLang;

            $tag['frames'][$frameId][] = $frame;

        }

        $id3v2 = array();
        if($id3v2) {
            if(!isset($id3v2[0])) {
                $id3v2 = array($id3v2);
            }
            $id3v2[] = $tag;
        } else {
            $id3v2 = $tag;
        }

        return array(
            'posAudioStart' => $pos_end,
            'id3v2'         => $id3v2
        );
    }
}
