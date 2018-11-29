<?php
/*autoupdated*/$pluginDirectory="/home/fpp/media/plugins/";
/*autoupdated*/$pluginDataDirectory="/home/fpp/media/plugindata/";

$alreadyRunning=false;
if (file_exists($pluginDataDirectory."fpp-after-hours-streamRunning") && trim(file_get_contents($pluginDataDirectory."fpp-after-hours-streamRunning"))=="1") $alreadyRunning=true;

if (file_exists($pluginDataDirectory."fpp-after-hours-config.json")) {
  $fah_config=json_decode(file_get_contents($pluginDataDirectory."fpp-after-hours-config.json"),true);
}

if (count($fah_config['streams'])) {
  foreach ($fah_config['streams'] as $sdata) {
    if ($sdata['active']==1)  {
      if (pingHost($sdata['url'])) $streamPick[$sdata['priority']][]=array('url'=>$sdata['url'], 'volume'=>$sdata['volume']);
    }
  }
  if (count($streamPick)) {
    ksort($streamPick);
    foreach ($streamPick as $pickme) {
      $rnd=rand(1,count($pickme))-1;
      exec("mpc volume",$volRet);
      $vol=str_replace('volume: ',"",$volRet[0]);
      $vol=str_replace('%',"",$vol);
      if (!$alreadyRunning) file_put_contents($pluginDataDirectory.'fpp-after-hours-showVolume',$vol); //don't change the show volume if stream is already active
      exec("mpc clear && mpc add {$pickme[$rnd]['url']} ".($pickme[$rnd]['volume'] != '-' ? "&& mpc volume {$pickme[$rnd]['volume']}" : "")." && mpc play");
      file_put_contents($pluginDataDirectory."fpp-after-hours-streamRunning","1");
      break;
    }    
  }
  else  {
    error_log("fpp-after-hours... ERROR: No reachable streams could be started");
    file_put_contents($pluginDataDirectory."fpp-after-hours-streamRunning","0");
  }
}

function pingHost($host) {
  $purl=parse_url($host);
  $host=$purl['host'];
  $port=(isset($purl['port']) ? $purl['port'] : ($purl['scheme']=='http' ? 80 : 443));
  if ($fp=fsockopen($host, $port, $errno, $errstr, 1)) return true;
  return false;
}
?>