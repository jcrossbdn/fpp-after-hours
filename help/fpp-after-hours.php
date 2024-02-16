<?php
echo "<p id='pageTop'></p>";
echo "<a href='https://github.com/jcrossbdn/fpp-after-hours/blob/master/README.md' target='_blank'>Please click here to go to the plugins github page</a><br><br>";

require_once '/home/fpp/media/plugins/fpp-after-hours/help/Parsedown.php';

$parsedown = new Parsedown();
$html=$parsedown->text(file_get_contents('/home/fpp/media/plugins/fpp-after-hours/README.md'));
preg_match_all('/img src=(?:(?:"([^"]+)")|(?:\'([^\']+)\'))/i', $html, $images);
if (isset($images[0]) && count($images[0])) {
    foreach ($images[1] as $index=>$img) {
        //leave absolute paths alone but convert relative paths to redirect through the fpp plugin file process
        if (substr($img,0,4)!="http") {
            $html=str_replace($images[1][$index],"/plugin.php?_menu=content&plugin=fpp-after-hours&file={$img}&nopage",$html);
        }
    }
}

//create table of contents
$toc="";
preg_match_all('/(<.+\/h[.-d]>)/m',$html,$headers);
if (isset($headers[1]) && count($headers[1])) {
    $tocLevel=0;
    foreach ($headers[1] as $header) {
        $headLevel=intval(substr($header,2,3)); //only works for h1 through h9
        $headerText=substr($header,4,-5);
        $headerLink=str_replace(" ","_",$headerText);
        $headerLink=str_replace(array('<','>','/',':','&','?','@'),'',$headerLink);

        if ($headLevel > $tocLevel) $toc.="<ul>";
        elseif ($headLevel < $tocLevel) $toc.="</ul>";
        $tocLevel=$headLevel;

        $toc.="<li><a href='#$headerLink'>$headerText</a></li>";
        $html=str_replace(substr($header,3,-4), " id='$headerLink'>$headerText <a href='#pageTop'> <sub>(^top^)</sub> </a><",$html);
    }
}
$toc=str_replace(array('<strong>','</strong>'),'',$toc); //remove any formatting
$html=str_replace("<h","<br><br><h",$html); //add break returns before each header

echo $toc."<br><br>";
echo $html;
?>