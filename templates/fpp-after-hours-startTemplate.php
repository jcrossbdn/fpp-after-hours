#!/usr/bin/php
<?php
//file_put_contents('/home/fpp/media/plugindata/fpp-after-hours-teststart',print_r($argv,true),FILE_APPEND);
require_once '/home/fpp/media/plugins/fpp-after-hours/fpp-after-hours-class.php';
$fah=new fppAfterHours();

if ($fah->config !== false) {
  if ($fah->checkForNewSoundCard()===true) $fah->updateMPDConfig(); //there is a new sound card, add it to mpd config
  if ($fah->checkForRemovedSoundCards() !== false) $fah->updateMPDConfig(); //there is a sound card in mpd that is no longer in the system, remove it from mpd config
  $soundCardName=$fah->getFPPActiveSoundCardName();

  $streamPick=array();
  if ($fah->config['activeSource']=='internet') {
    if (isset($fah->config['streams']) && count($fah->config['streams'])) {
      foreach ($fah->config['streams'] as $sdata) {
        if ($sdata['active']===true || $sdata['active']=="true")  {
          if ($fah->pingInternetRadio($sdata['url'])) $streamPick[$sdata['priority']][]=array('url'=>$sdata['url'], 'volume'=>$sdata['volume']);
        }
      }
      do {
        if (count($streamPick)) {
          ksort($streamPick);
          foreach ($streamPick as $spId=>$pickme) {
            $rnd=rand(1,count($pickme))-1;
            exec("mpc volume",$volRet);
            $vol=str_replace('volume: ',"",$volRet[0]);
            $vol=str_replace('%',"",$vol);
            if (!$fah->musicShouldBeRunning) $fah->setShowVolume($vol); //don't change the show volume if stream is already active (or should be active)
            $fah->setMusicRunningStatus(true);
            $fah->setCurrentInternetRadioHost($pickme[$rnd]['url']);

            //handle command line switches for fade in
            if (isset($argv[1])) $argv[1]=preg_replace('/\D/','',$argv[1]); //fade in over seconds
            if (isset($argv[2])) $argv[2]=preg_replace('/\D/','',$argv[2]); //start at volume percentage
            if (isset($argv[1]) && is_numeric($argv[1])) {
              if (isset($argv[2]) && is_numeric($argv[2])) $vol=$argv[2]; //set the start fade volume level
              else $vol=0;
              exec("mpc clear ".($soundCardName!==false ? "&& mpc enable only \"$soundCardName\" " : "")."&& mpc add {$pickme[$rnd]['url']} && mpc volume {$vol} && mpc play");
              $startTime=floor(microtime(true)*1000);
              $mustCompleteBy=($startTime + ((intval($argv[1]) * 1000) - 1000)); //must finish before this timestamp
              $maxVol=($pickme[$rnd]['volume'] != '-' ? intval($pickme[$rnd]['volume']) : 100);
              do {
                  $os=floor(microtime(true)*1000); //operation start time
                  exec("mpc volume $vol",$volRet);
                  $volRet=array_reverse($volRet);
                  foreach ($volRet as $v) {
                      if (substr($v,0,7)=='volume:') {
                          $vol=str_replace('volume: ',"",trim(substr($v,7,3)));
                          $vol=str_replace('%',"",$vol);
                          break;
                      }
                  }
                  $vol=intval($vol);
                  
                  $nowtime=floor(microtime(true)*1000);
                  $cmdOffset=$nowtime-$os; //how long did it take to run the function above
                  
                  $remain=($mustCompleteBy - $cmdOffset - $nowtime); //used to calculate delay
                  
                  $volStepsRemain = ($maxVol - $vol) / 5;
          
                  if ($volStepsRemain > 0) (($delay=$remain / $volStepsRemain) - 100);
                  else break; //prevents divide by zero
                  if ($delay <= 0 || $remain <= 100+$cmdOffset) break; //add more time for the final volume command at the end of this loop in case command takes longer this run
                  $vol+=5;
                  usleep($delay*1000);
              } while ($vol <= $maxVol);
              exec("mpc volume $maxVol");
            }
            
            else { //just start mpd to desired end volume
              exec("mpc clear ".($soundCardName!==false ? "&& mpc enable only \"$soundCardName\" " : "")."&& mpc add {$pickme[$rnd]['url']} ".($pickme[$rnd]['volume'] != '-' ? "&& mpc volume {$pickme[$rnd]['volume']} " : "")." && mpc play");
            }     
            
            break;
          }
        }
        else  {
          error_log("fpp-after-hours... ERROR: No reachable streams could be started");
        }

        $npd=$fah->getNowPlayingDetail();
        if (trim($npd->error) != '') {
          if (strstr($npd->error,"Failed to open \"default detected output\" (sndio);") !== false) {
            //mpd likely needs a restart
            error_log("fpp-after-hours... MPD ERROR (Failed to open \"default detected output\" (sndio).  Attempting mpd config rebuild and restart");
            $fah->updateMPDConfig(true);
          }
          else {
            error_log("fpp-after-hours... ERROR from active stream: ".$npd->error);
            unset($streamPick[$spId]);
          }
        }
      } while (count($streamPick) && trim($npd->error)!=''); //try to load a stream until there is no error returned or no entries left to try
    }
  }
}
?>