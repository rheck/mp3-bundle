<?php

namespace Rheck\Mp3Bundle\Handler;

use Rheck\Mp3Bundle\Registry\BitRate;
use Rheck\Mp3Bundle\Registry\Mode;
use Rheck\Mp3Bundle\Registry\ModeExtension;
use Rheck\Mp3Bundle\Registry\SamplingFrequency;
use Rheck\Mp3Bundle\StaticFactory\HandlerFactory;

class Mp3Handler
{
    public function convertFlag($flag, $convertToBin = true, $length = 8)
    {
        $flag = $convertToBin ? decbin($flag) : $flag;
        $recruit = $length - strlen($flag);

        if($recruit < 1) {
            return $flag;
        }

        return sprintf('%0'.$length.'d', $flag);
    }

    public function getData($fileSource, $fileSize, $posAudioStart, $posAudioEnd, $id3v2, $fileAnalysis)
    {
        while(true) {
            fseek($fileSource, $posAudioStart);

            $checkData = fread($fileSource, 3);

            if($checkData == "ID3") {
                $id3v2 = HandlerFactory::get('id3v2')
                    ->handle($fileSource, $id3v2, $posAudioStart);

                if(!$id3v2) {
                    return false;
                }
            } else {
                fseek($fileSource, $posAudioStart);
                break;
            }

        }

        $paddingData = fread($fileSource, 1024);
        $paddingSize = max(0, strpos($paddingData, trim($paddingData)));

        fseek($fileSource, $posAudioStart + $paddingSize);

        $dataFrames = array(
            'audioFrames' => array()
        );

        if($fileAnalysis > 0) {
            $dataFrames = $this->getDataFrames($fileSource, $posAudioEnd, $fileAnalysis);

            if(!$frameData = $dataFrames['firstFrame']) {
                return false;
            }
        } else {
            $first_frame_header_data = fread($fileSource, 4);
            $first_frame_header      = $this->getFrameHeader($first_frame_header_data);

            if(!$first_frame_header || !is_array($first_frame_header)) {
                return false;
            }

            $frameData = fread($fileSource, 36);
            $frameType = strpos($frameData, 'Xing') ? 'VBR' : 'CBR';

            if($frameType == 'CBR') {
                $frameTotal = $this->getDataCbr($first_frame_header, $posAudioStart, $posAudioEnd);
            } else {
                $frameTotal = $this->getDataVbr($fileSource);
            }

            $frameData = $first_frame_header;
            unset($frameData['frameSize']);

            $frameData['frameTotal'] = $frameTotal;
            $frameData['type']       = $frameType;
        }

        $frameLength = $frameData['frameTotal'] * 0.026;
        $frameTime   = $this->convertTime(round($frameLength));

        $frameData['length']   = $frameLength;
        $frameData['time']     = $frameTime;
        $frameData['fileSize'] = $fileSize;

        return array(
            'data'        => $frameData,
            'audioFrames' => $dataFrames['audioFrames']
        );
    }

    public function getDataFrames($fileSource, $posAudioEnd, $fileAnalysis)
    {
        $bitRateMin = 0;
        $bitRateMax = 0;
        $bitRateSum = 0;

        $audioFrames = array();
        $firstFrame  = array();
        $frameTotal = 0;

        while(true) {
            $frameHeaders = fread($fileSource, 4);
            $posFrame     = ftell($fileSource);

            if($posFrame >= $posAudioEnd) {
                break;
            }

            if(!$frameHeader = $this->getFrameHeader($frameHeaders)) {
                break;
            }

            $firstFrame = $firstFrame ? $firstFrame : $frameHeader;

            $bitRateMin  = $bitRateMin > 0 ? min($bitRateMin, $frameHeader['bitRate']) : $frameHeader['bitRate'];
            $bitRateMax  = max($bitRateMax, $frameHeader['bitRate']);
            $bitRateSum += $frameHeader['bitRate'];

            if($fileAnalysis > 1) {
                $audioFrames[] = array($posFrame - 4, $frameHeader['bitRate'], $frameHeader['frameSize']);
            }

            fseek($fileSource, $posFrame + $frameHeader['frameSize'] - 4);
            $frameTotal++;

        }

        $firstFrame['bitRate']    = round($bitRateSum / $frameTotal);
        $firstFrame['frameTotal'] = $frameTotal;

        if($bitRateMax != $bitRateMin) {
            $firstFrame['bitRateMax'] = $bitRateMax;
            $firstFrame['bitRateMin'] = $bitRateMin;
            $firstFrame['type']       = 'VBR';
        } else {
            $firstFrame['type'] = 'CBR';
        }

        unset($firstFrame['frameSize']);

        return array(
            'firstFrame'  => $firstFrame,
            'audioFrames' => $audioFrames
        );
    }

    private function getFrameHeader($frameHeaders)
    {
        $frameHeader       = array();
        $frameHeaderLength = 4;

        if(strlen($frameHeaders) != $frameHeaderLength) {
            return false;
        }

        for($i = 0; $i < $frameHeaderLength; $i++) {
            $frameHeader[] = $this->convertFlag(ord($frameHeaders{$i}));
        }

        if($frameHeaders{0} != "\xFF" || substr($frameHeader[1], 0, 3) != '111') {
            return false;
        }

        $mpegVer = null;
        switch(substr($frameHeader[1], 3, 2)) {
            case '00':
                $mpegVer = '2.5';
                break;
            case '10':
                $mpegVer = '2';
                break;
            case '11':
                $mpegVer = '1';
                break;
            default:
                return false;
        }

        $layer = null;
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
        $bitRate = BitRate::get($bitRate, intval($mpegVer) - 1,intval($layer) - 1);

        $samplingFrequency = substr($frameHeader[2], 4, 2);
        $samplingFrequency = SamplingFrequency::get($samplingFrequency,ceil($mpegVer) - 1);

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

        switch($mpegVer) {
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
            'mpegVer'            => $mpegVer,
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

    private function getDataCbr($frameHeader, $posAudioStart, $posAudioEnd)
    {
        $audio_size = $posAudioEnd - $posAudioStart;

        return ceil($audio_size / $frameHeader['frameSize']);
    }

    private function getDataVbr($fileSource)
    {

        $frameVbrData = unpack('NVBR', fread($fileSource, 4));;
        $frameVbrs    = array(1, 3, 5, 7, 9, 11, 13, 15);

        if(!in_array($frameVbrData['VBR'], $frameVbrs)) {
            return 0;
        }

        $frameTotalData = unpack('Nframetotal', fread($fileSource, 4));
        $frameTotal     = $frameTotalData['frametotal'];

        return $frameTotal;
    }

    private function convertTime($seconds)
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
}
