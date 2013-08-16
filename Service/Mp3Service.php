<?php

namespace Rheck\Mp3Bundle\Service;

use Rheck\Mp3Bundle\Registry\BitRate;
use Rheck\Mp3Bundle\Registry\Frame;
use Rheck\Mp3Bundle\Registry\Genres;
use Rheck\Mp3Bundle\Registry\Mode;
use Rheck\Mp3Bundle\Registry\ModeExtension;
use Rheck\Mp3Bundle\Registry\SamplingFrequency;
use Rheck\Mp3Bundle\StaticFactory\HandlerFactory;

class Mp3Service
{
    public function getMp3($filePath, $analysis = false, $getFramesIndex = false)
    {
        $getFramesIndex = $analysis ? $getFramesIndex : false;
        $fileAnalysis   = intval(!empty($analysis)) + intval(!empty($getFramesIndex));

        if(!$fileSource = fopen($filePath, 'rb')) {
            return false;
        }

        $fileSize = filesize($filePath);

        $id3v2Handle = HandlerFactory::get('id3v2')
            ->handle($fileSource);

        $id3v2         = $id3v2Handle['id3v2'];
        $posAudioStart = $id3v2Handle['posAudioStart'];


        $id3v1Handle = HandlerFactory::get('id3v1')
            ->handle($fileSource, $fileSize);

        $id3v1       = isset($id3v1Handle['tag']) ? $id3v1Handle['tag'] : false;
        $posAudioEnd = $id3v1Handle['posAudioEnd'];

        $dataHandle = HandlerFactory::get('mp3')
            ->getData(
                $fileSource,
                $fileSize,
                $posAudioStart,
                $posAudioEnd,
                $id3v2,
                $fileAnalysis
            );

        $data        = $dataHandle['data'];
        $audioFrames = $dataHandle['audioFrames'];

        $return = array
        (
            'data'          => $data,
            'id3v2'         => $id3v2,
            'id3v1'         => $id3v1,
            'frames'        => $getFramesIndex ? $audioFrames : false,
            'posAudioStart' => $posAudioStart,
            'posAudioEnd'   => $posAudioEnd,
        );

        foreach($return as $variable => $value) {
            if($value === false) {
                unset($return[$variable]);
            }
        }

        return $return;
    }

    public function setMp3($fileInput, $fileOutput, $id3v2 = array(), $id3v1 = array())
    {
        if(!$mp3 = $this->getMp3($fileInput)) {
            return false;
        }

        if(!$newFileSource = fopen($fileOutput, 'wb')) {
            return false;
        }

        if(!$fileSource = fopen($fileInput, 'rb')) {
            return false;
        }

        $id3v2 = is_array($id3v2) ? $id3v2 : array();
        $id3v1 = is_array($id3v1) ? $id3v1 : array();

        $id3v2_data = '';
        $id3v1_data = '';

        fseek($fileSource, $mp3['posAudioStart']);

        $audio_length = $mp3['posAudioEnd'] - $mp3['posAudioStart'];
        $audio_data   = fread($fileSource, $audio_length);

        foreach($id3v2 as $frameId => $frame) {
            if(strlen($frameId) != 4 || !is_array($frame)) {
                continue;
            }

            $frameId      = strtoupper($frameId);
            $frameCharset = 0;

            $frameFlag = array(
                0 => bindec(($frame['tag_protect'] ? '1' : '0').($frame['file_protect'] ? '1' : '0').($frame['readonly'] ? '1' : '0').'00000'),
                1 => bindec(($frame['compressed'] ? '1' : '0').($frame['encrypted'] ? '1' : '0').($frame['group'] ? '1' : '0').'00000'),
            );

            if($frame['charset'] = strtolower($frame['charset'])) {
                switch($frame['charset']) {
                    case 'UTF-16':
                        $frameCharset = 1;
                        break;
                    case 'UTF-16BE':
                        $frameCharset = 2;
                        break;
                    case 'UTF-8':
                        $frameCharset = 3;
                        break;
                }
            }

            $frameData = chr($frameCharset) . $frame['data'];
            $frameSize = strlen($frameData);

            $id3v2_data .= pack('a4NCCa' . $frameSize, $frameId, $frameSize, $frameFlag[0], $frameFlag[1], $frameData);
        }

        if($id3v2_data) {
            $id3v2_flag = bindec(($id3v2['unsynchronisation'] ? '1' : '0').($id3v2['extra'] ? '1' : '0').($id3v2['istest'] ? '1' : '0').'00000');
            $id3v2_size = strlen($id3v2_data) + 10;

            $id3v2_sizes = array(
                0 => ($id3v2_size >> 21) & 0x7F,
                1 => ($id3v2_size >> 14) & 0x7F,
                2 => ($id3v2_size >> 7) & 0x7F,
                3 => $id3v2_size & 0x7F
            );

            $id3v2_header  = pack('a3CCC', 'ID3', 3, 0, $id3v2_flag);
            $id3v2_header .= pack('CCCC', $id3v2_sizes[0], $id3v2_sizes[1], $id3v2_sizes[2], $id3v2_sizes[3]);

            $audio_data = $id3v2_header . $id3v2_data . $audio_data;
        }

        if($id3v1) {
            $id3v1_data  = pack('a3a30a30a30a4a28CCC', 'TAG', $id3v1['title'], $id3v1['artist'], $id3v1['album'], $id3v1['year'], $id3v1['comment'], intval($id3v1['reserve']), intval($id3v1['track']), intval($id3v1['genre']));
            $audio_data .= $id3v1_data;
        }

        fwrite($newFileSource, $audio_data);
        fclose($newFileSource);

        return true;
    }

    public function cutMp3($fileInput, $fileOutput, $startIndex = 0, $endIndex = -1, $indexType = 'frame', $cleanTags = false) {

        if(!in_array($indexType, array('frame', 'second', 'percent'))) {
            return false;
        }

        if(!$mp3 = $this->getMp3($fileInput, true, true)) {
            return false;
        }

        if(!$mp3['data'] || !$mp3['frames']) {
            return false;
        }

        if(!$newFileSource = fopen($fileOutput, 'wb')) {
            return false;
        }

        if(!$fileSource = fopen($fileInput, 'rb')) {
            return false;
        }

        $indexs     = $mp3['frames'];
        $indexTotal = count($mp3['frames']);

        $cutData = '';
        $maxEndIndex = $indexTotal - 1;

        if($indexType == 'second') {
            $startIndex = ceil($startIndex * (1 / 0.026));
            $endIndex   = $endIndex > 0 ? ceil($endIndex * (1 / 0.026)) : -1;
        } elseif ($indexType == 'percent') {
            $startIndex = round($maxEndIndex * $startIndex);
            $endIndex   = $endIndex > 0 ? round($maxEndIndex * $endIndex) : -1;
        }

        if($startIndex < 0 || $startIndex > $maxEndIndex) {
            return false;
        }

        $endIndex = $endIndex < 0 ? $maxEndIndex : $endIndex;
        $endIndex = min($endIndex, $maxEndIndex);

        if($endIndex <= $startIndex) {
            return false;
        }

        $pos_start = $indexs[$startIndex][0];
        $pos_end = $indexs[$endIndex][0] + $indexs[$endIndex][2];

        fseek($fileSource, $pos_start);
        $cutData = fread($fileSource, $pos_end - $pos_start);

        if($mp3['data']['type'] == 'VBR') {

            fseek($fileSource, $indexs[0][0]);
            $frame = fread($fileSource, $indexs[0][2]);

            if(strpos($frame, 'Xing')) {

                $cutData = substr($cutData, $indexs[0][2]);

                $newvbr = substr($frame, 0, 4);
                $newvbr_sign_padding = 0;

                if($mp3['data']['mpegver'] == 1) {
                    $newvbr_sign_padding = $mp3['data']['mode'] == Mode::get('11') ? 16 : 31;
                } else if($mp3['data']['mpegver'] == 2) {
                    $newvbr_sign_padding = $mp3['data']['mode'] == Mode::get('11') ? 8 : 16;
                }

                if($newvbr_sign_padding) {

                    $newvbr .= pack("a{$newvbr_sign_padding}a4", null, 'Xing');
                    $newvbr .= pack('a'.(32 - $newvbr_sign_padding), null);
                    $newvbr .= pack('NNNa100N', 1, $endIndex - $startIndex + 1, 0, null, 0);

                    $newvbr .= pack('a'.($indexs[0][2] - strlen($newvbr)), null);
                    $cutData = $newvbr . $cutData;
                }
            }
        }

        if(!$cleanTags) {
            rewind($fileSource);

            if($mp3['posAudioStart'] != 0) {
                $cutData = fread($fileSource, $mp3['posAudioStart']) . $cutData;
            }

            if($mp3['posAudioEnd'] != $mp3['data']['fileSize']) {
                fseek($fileSource, $mp3['posAudioEnd']);
                $cutData .= fread($fileSource, 128);
            }
        }

        fwrite($newFileSource, $cutData);
        fclose($newFileSource);

        return true;
    }
}
