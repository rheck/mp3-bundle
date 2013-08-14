<?php

namespace Rheck\Mp3Bundle\Service;

use Rheck\Mp3Bundle\Registry\BitRate;
use Rheck\Mp3Bundle\Registry\Frame;
use Rheck\Mp3Bundle\Registry\Genres;
use Rheck\Mp3Bundle\Registry\Mode;
use Rheck\Mp3Bundle\Registry\ModeExtension;
use Rheck\Mp3Bundle\Registry\SamplingFrequency;

class Mp3Service
{
    private $fp, $fileSize, $fileAnalysis;
    private $id3v1, $id3v2, $data;

    private $audioFrames, $audioFramesTotal;
    private $posAudioStart, $posAudioEnd;

    private $bitRateMax, $bitRateMin, $bitRateSum;

    public function getMp3($filePath, $analysis = false, $getFramesIndex = false)
    {
        $getFramesIndex = $analysis ? $getFramesIndex : false;

        $this->fileAnalysis = intval(!empty($analysis)) + intval(!empty($getFramesIndex));

        if(!$fileSource = @fopen($filePath, 'rb')) {
            return false;
        }

        $this->fileSize = filesize($filePath);
        $this->id3v1    = array();
        $this->id3v2    = array();
        $this->data     = array();

        $this->audioFrames      = array();
        $this->audioFramesTotal = 0;

        $this->posAudioStart = 0;
        $this->posAudioEnd   = 0;
        $this->bitRateMax     = 0;
        $this->bitRateMin     = 0;
        $this->bitRateSum     = 0;

        $this->getId3v2();
        $this->getId3v1();
        $this->getData();

        $return = array
        (
            'data'   => $this->data,
            'id3v2'  => $this->id3v2,
            'id3v1'  => $this->id3v1,
            'frames' => $getFramesIndex ? $this->audioFrames : false
        );

        foreach($return as $variable => $value) {
            if(!$value) {
                unset($return[$variable]);
            }
        }

        return $return;
    }

    private function getId3v1()
    {
        $tagSize  = 128;
        $tagStart = $this->fileSize - $tagSize;

        fseek($this->fp, $tagStart);

        $tagData = fread($this->fp, $tagSize);
        $tag     = @unpack('a3header/a30title/a30artist/a30album/a4year/a28comment/Creserve/Ctrack/Cgenre', $tagData);

        if($tag['header'] == 'TAG') {
            $this->posAudioEnd = $this->fileSize - $tagSize;
        } else {
            $this->posAudioEnd = $this->fileSize;
            return false;
        }

        $tag['genre'] = Genres::get($tag['genre']);
        $tag['genre'] = $tag['genre'] ? $tag['genre'] : 'Unknown';

        unset($tag['header']);

        $this->id3v1 = $tag;

        return true;
    }

    private function getId3v2()
    {
        $pos_call = ftell($this->fp);
        $tag      = array();

        $tagHeaderData = fread($this->fp, 10);
        $tagHeader     = @unpack('a3identifier/Cversion/Crevision/Cflag/Csize0/Csize1/Csize2/Csize3', $tagHeaderData);

        if(!$tagHeader || $tagHeader['identifier'] != 'ID3') {
            fseek($this->fp, $pos_call);
            return false;
        }

        $tag['version']  = $tagHeader['version'];
        $tag['revision'] = $tagHeader['revision'];

        $tagFlag = $this->conv_flag($tagHeader['flag']);

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

        $pos_start = ftell($this->fp);
        $pos_end   = $pos_start + $tagSize - 10;

        while(true) {
            if(ftell($this->fp) >= $pos_end) {
                break;
            }

            $frameHeaderData = fread($this->fp, 10);
            $frameHeader     = @unpack('a4frameid/Nsize/Cflag0/Cflag1', $frameHeaderData);

            if(!$frameHeader || !$frameHeader['frameid']) {
                continue;
            }

            $frameId          = $frameHeader['frameid'];
            $frameDescription = 'Unknown';

            if(isset(Frame::get($frameId))) {
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

            if(($frameSize = $frameHeader['size']) < 1 || (ftell($this->fp) + $frameSize) > $pos_end) {
                continue;
            }

            $frameFlag = array
            (
                $this->conv_flag($frameHeader['flag0']),
                $this->conv_flag($frameHeader['flag1'])
            );

            $frameCharsetData = @unpack('c', fread($this->fp, 1));
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
                fseek($this->fp, ftell($this->fp) - 1);
            }

            $frameData = @unpack("a{$frameDataSize}data", fread($this->fp, $frameDataSize));
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

        if($this->id3v2) {
            if(!isset($this->id3v2[0])) {
                $id3v2 = $this->id3v2;
                $this->id3v2 = array($id3v2);
            }
            $this->id3v2[] = $tag;
        } else {
            $this->id3v2 = $tag;
        }

        $this->posAudioStart = $pos_end;

        return true;
    }

    private function conv_flag($flag, $convtobin = true, $length = 8)
    {
        $flag = $convtobin ? decbin($flag) : $flag;
        $recruit = $length - strlen($flag);

        if($recruit < 1) {
            return $flag;
        }

        return sprintf('%0'.$length.'d', $flag);
    }

    private function getData()
    {
        while(true) {
            fseek($this->fp, $this->posAudioStart);

            $checkData = fread($this->fp, 3);

            if($checkData == "ID3") {
                if(!$this->getId3v2()) {
                    return false;
                }
            } else {
                fseek($this->fp, $this->posAudioStart);
                break;
            }

        }

        $paddingData = fread($this->fp, 1024);
        $paddingSize = @max(0, strpos($paddingData, trim($paddingData)));

        fseek($this->fp, $this->posAudioStart + $paddingSize);

        if($this->fileAnalysis > 0) {
            if(!$frameData = $this->getDataFrames()) {
                return false;
            }
        } else {
            $first_frame_header_data = fread($this->fp, 4);
            $first_frame_header      = $this->getFrameHeader($first_frame_header_data);

            if(!$first_frame_header || !is_array($first_frame_header)) {
                return false;
            }

            $frameData = fread($this->fp, 36);
            $frameType = strpos($frameData, 'Xing') ? 'VBR' : 'CBR';

            if($frameType == 'CBR') {
                $frameTotal = $this->getDataCbr($first_frame_header);
            } else {
                $frameTotal = $this->getDataVbr($first_frame_header);
            }

            $frameData = $first_frame_header;
            unset($frameData['framesize']);

            $frameData['frametotal'] = $frameTotal;
            $frameData['type']       = $frameType;
        }

        $frameLength = $frameData['frametotal'] * 0.026;
        $frameTime   = $this->convTime(round($frameLength));

        $frameData['length']   = $frameLength;
        $frameData['time']     = $frameTime;
        $frameData['filesize'] = $this->fileSize;

        $this->data = $frameData;

        return true;
    }

    private function getDataFrames()
    {
        $firstFrame = array();
        $frameTotal = 0;

        while(true) {
            $frameHeaders = fread($this->fp, 4);
            $posFrame     = ftell($this->fp);

            if($posFrame >= $this->posAudioEnd) {
                break;
            }

            if(!$frameHeader = $this->getFrameHeader($frameHeaders)) {
                break;
            }

            $firstFrame = $firstFrame ? $firstFrame : $frameHeader;
            extract($frameHeader);

            $this->bitRateMin  = $this->bitRateMin > 0 ? min($this->bitRateMin, $bitRate) : $bitRate;
            $this->bitRateMax  = max($this->bitrate_max, $bitRate);
            $this->bitRateSum += $bitRate;

            if($this->fileAnalysis > 1) {
                $this->audioFrames[] = array($posFrame - 4, $bitRate, $frameSize);
            }

            fseek($this->fp, $posFrame + $frameSize - 4);
            $frameTotal++;

        }

        $firstFrame['bitRate']    = @round($this->bitRateSum / $frameTotal);
        $firstFrame['frameTotal'] = $frameTotal;

        if($this->bitrate_max != $this->bitRateMin) {
            $firstFrame['bitrate_max'] = $this->bitrate_max;
            $firstFrame['bitRateMin'] = $this->bitRateMin;
            $firstFrame['type']        = 'VBR';
        } else {
            $firstFrame['type'] = 'CBR';
        }

        unset($firstFrame['frameSize']);

        return $firstFrame;
    }

    private function getFrameHeader($frameHeaders)
    {
        $frameHeader       = array();
        $frameHeaderLength = 4;

        if(strlen($frameHeaders) != $frameHeaderLength) {
            return false;
        }

        for($i = 0; $i < $frameHeaderLength; $i++) {
            $frameHeader[] = $this->conv_flag(ord($frameHeaders{$i}));
        }

        if($frameHeaders{0} != "\xFF" || substr($frameHeader[1], 0, 3) != '111') {
            return false;
        }

        switch(substr($frameHeader[1], 3, 2)) {
            case '00':
                $mpegver = '2.5';
                break;
            case '10':
                $mpegver = '2';
                break;
            case '11':
                $mpegver = '1';
                break;
            default:
                return false;
        }

        switch(substr($frameHeader[1], 5, 2)) {
            case '01':
                $layer = '3';
                break;
            case '10':
                $layer = '2';
                break;
            case '11':
                $layer = '1';
                break;
            default:
                return false;
        }

        $bitRate = substr($frameHeader[2], 0, 4);
        $bitRate = BitRate::get($bitRate, intval($mpegver) - 1,intval($layer) - 1);

        $samplingFrequency = substr($frameHeader[2], 4, 2);
        $samplingFrequency = SamplingFrequency::get($samplingFrequency,ceil($mpegver) - 1);

        if(!$bitRate || !$samplingFrequency) {
            return false;
        }

        $padding = $frameHeader[2]{6};

        $mode = substr($frameHeader[3], 0, 2);
        $mode = Mode::get($mode);

        $modeExtension = substr($frameHeader[3], 2, 2);
        $modeExtension = ModeExtension::get($modeExtension);

        if(!$mode || !$modeExtension) {
            return false;
        }

        $copyright = substr($frameHeader[3], 4, 1) ? 1 : 0;
        $original  = substr($frameHeader[3], 5, 1) ? 1 : 0;

        switch($mpegver) {
            case '1':
                $definite = $layer == '1' ? 48 : 144;
                break;
            case '2':
            case '2.5':
                $definite = $layer == '1' ? 24 : 72;
                break;
            default:
                return false;
        }

        $frameSize = intval($definite * $bitRate * 1000 / $samplingFrequency + intval($padding));

        return array(
            'mpegver'            => $mpegver,
            'layer'              => $layer,
            'bitRate'            => $bitRate,
            'samplingFrequency'  => $samplingFrequency,
            'padding'            => $padding,
            'mode'               => $mode,
            'modeExtension'      => array(
                'Intensity_Stereo' => $modeExtension[0],
                'MS_Stereo'        => $modeExtension[1]
            ),
            'copyright'          => $copyright,
            'original'           => $original,
            'frameSize'          => $frameSize
        );
    }

    private function getDataCbr($frameHeader) {
        extract($frameHeader);
        $audio_size = $this->posAudioEnd - $this->posAudioStart;

        return @ceil($audio_size / $frameSize);
    }

    private function getDataVbr($frameHeader) {

        $frameVbrData = @unpack('NVBR', fread($this->fp, 4));;
        $frameVbrs    = array(1, 3, 5, 7, 9, 11, 13, 15);

        if(!in_array($frameVbrData['VBR'], $frameVbrs)) {
            return 0;
        }

        $frameTotalData = @unpack('Nframetotal', fread($this->fp, 4));
        $frameTotal     = $frameTotalData['frametotal'];

        return $frameTotal;
    }

    private function convTime($seconds)
    {
        $return    = '';
        $separator = ':';

        if($seconds > 3600) {
            $return  .= intval($seconds / 3600).' ';
            $seconds -= intval($seconds / 3600) * 3600;
        }

        if($seconds > 60) {
            $return  .= sprintf('%02d', intval($seconds / 60)).' ';
            $seconds -= intval($seconds / 60) * 60;
        } else {
            $return .= '00 ';
        }

        $return .= sprintf('%02d', $seconds);
        $return  = trim($return);

        return str_replace(' ', $separator, $return);
    }

    public function setMp3($fileInput, $fileOutput, $id3v2 = array(), $id3v1 = array()) {

        if(!$mp3 = $this->getMp3($fileInput)) {
            return false;
        }

        if(!$fp = @fopen($fileOutput, 'wb')) {
            return false;
        }

        $id3v2 = is_array($id3v2) ? $id3v2 : array();
        $id3v1 = is_array($id3v1) ? $id3v1 : array();

        $id3v2_data = '';
        $id3v1_data = '';

        fseek($this->fp, $this->posAudioStart);

        $audio_length = $this->posAudioEnd - $this->posAudioStart;
        $audio_data   = fread($this->fp, $audio_length);

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

        fwrite($fp, $audio_data);
        fclose($fp);

        return true;
    }

    public function cut_mp3($fileInput, $fileOutput, $startIndex = 0, $endIndex = -1, $indexType = 'frame', $cleanTags = false) {

        if(!in_array($indexType, array('frame', 'second', 'percent'))) {
            return false;
        }

        if(!$mp3 = $this->getMp3($fileInput, true, true)) {
            return false;
        }

        if(!$mp3['data'] || !$mp3['frames']) {
            return false;
        }

        if(!$fp = @fopen($fileOutput, 'wb')) {
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

        if($startIndex < 0 || $start > $maxEndIndex) {
            return false;
        }

        $endIndex = $endIndex < 0 ? $maxEndIndex : $endIndex;
        $endIndex = min($endIndex, $maxEndIndex);

        if($endIndex <= $startIndex) {
            return false;
        }

        $pos_start = $indexs[$startIndex][0];
        $pos_end = $indexs[$endIndex][0] + $indexs[$endIndex][2];

        fseek($this->fp, $pos_start);
        $cutData = fread($this->fp, $pos_end - $pos_start);

        if($mp3['data']['type'] == 'VBR') {

            fseek($this->fp, $indexs[0][0]);
            $frame = fread($this->fp, $indexs[0][2]);

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
            rewind($this->fp);

            if($this->posAudioStart != 0) {
                $cutData = fread($this->fp, $this->posAudioStart) . $cutData;
            }

            if($this->posAudioEnd != $this->filesize) {
                fseek($this->fp, $this->posAudioEnd);
                $cutData .= fread($this->fp, 128);
            }
        }

        fwrite($fp, $cutData);
        fclose($fp);

        return true;
    }
}
