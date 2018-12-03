<?php
require_once '/home/fpp/media/plugins/fpp-after-hours/fpp-after-hours-class.php';
$fah=new fppAfterHours();

if ($fah->config !== false) {
  if ($fah->checkForNewSoundCard()===true) $fah->updateMPDConfig(); //there is a new sound card, add it to mpd config
  if ($fah->checkForRemovedSoundCards() !== false) $fah->updateMPDConfig(); //there is a sound card in mpd that is no longer in the system, remove it from mpd config
  $soundCardName=$fah->getFPPActiveSoundCardName();

  if ($fah->config->activeSource=='internet') {
    if (isset($fah->config->streams) && count($fah->config->streams)) {
      foreach ($fah->config->streams as $sdata) {
        if ($sdata->active==1)  {
          if ($fah->pingInternetRadio($sdata->url)) $streamPick[$sdata->priority][]=array('url'=>$sdata->url, 'volume'=>$sdata->volume);
        }
      }
      if (count($streamPick)) {
        ksort($streamPick);
        foreach ($streamPick as $pickme) {
          $rnd=rand(1,count($pickme))-1;
          exec("mpc volume",$volRet);
          $vol=str_replace('volume: ',"",$volRet[0]);
          $vol=str_replace('%',"",$vol);
          if (!$fah->musicShouldBeRunning) $fah->setShowVolume($vol); //don't change the show volume if stream is already active (or should be active)
          exec("mpc clear ".($soundCardName!==false ? "&& mpc enable only \"$soundCardName\" " : "")."&& mpc add {$pickme[$rnd]['url']} ".($pickme[$rnd]['volume'] != '-' ? "&& mpc volume {$pickme[$rnd]['volume']} " : "")." && mpc play");
          
          $fah->setMusicRunningStatus(true);
          break;
        }
      }
      else  {
        error_log("fpp-after-hours... ERROR: No reachable streams could be started");
        $fah->setMusicRunningStatus(false);
      }
    }
  }
}
?>