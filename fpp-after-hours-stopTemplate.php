<?php
/*autoupdated*/$pluginDirectory="/home/fpp/media/plugins/";
/*autoupdated*/$pluginDataDirectory="/home/fpp/media/plugindata/";

$vol=false;
if (file_exists($pluginDataDirectory.'fpp-after-hours-showVolume'))  $vol=intval(file_get_contents($pluginDataDirectory.'fpp-after-hours-showVolume'));
exec("mpc stop && mpc clear".($vol !== false ? " && mpc volume $vol" : ""));
?>