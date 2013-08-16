<?php

require_once 'vendor/autoload.php';

use Rheck\Mp3Bundle\Service\Mp3Service;

$data = array(
    'plink' => 'http://media.sermonindex.net/23/SID23817.mp3'
);

$filename = $data['plink'];
$handle   = fopen($filename, "rb");
$tmpfile  = uniqid().basename($filename);

$filePath = __DIR__ . '/public/1min/';

$handle1  = fopen($filePath . $tmpfile, "w");

?>

<style type="text/css">
    .mp3file
    {
        position:relative;
        float:left;
        width:auto;
        padding:5px;
    }
    .mp3player
    {
        position:relative;
        margin:0px;
        width:200px;
        float:left;
        padding:5px;

    }
</style>


<div class="mp3file">
    Music Sample
</div>
<div class="mp3player">
    <object type="application/x-shockwave-flash" data="dewplayer.swf" width="200" height="20" id="dewplayer" name="dewplayer">
        <param name="wmode" value="transparent" />
        <param name="movie" value="dewplayer.swf" />
        <param name="flashvars" value="mp3=<?php  echo '/rheck/mp3-bundle/public/1min/'.urlencode($tmpfile).'s';?>" />
    </object>
</div>

<?

flush();
$c=0;
while (!feof($handle)) {
    flush();
    $temp = fread($handle, 1024);
    fwrite($handle1, $temp);
    $c++;
    if($c>1000)
    {
        break;
    }
}

fflush($handle1);
fclose($handle1);
fclose($handle);

$mp3 = new Mp3Service();
$mp3->cutMp3($filePath . $tmpfile, $filePath . $tmpfile . 's', 0, 3, 'second', false);
unlink($filePath . $tmpfile);