<?php
class fppAfterHours {
  public $dependenciesAreLoaded; //are mpd and mpc installed on this system
  public $musicIsRunning; //is mpc responding with a playing message
  public $musicShouldBeRunning; //is the running flag set in musicRunning file
  public $showVolume; //what was the systems volume level before the after hours music was loaded (false=not loaded, integer volume 0-100)
  public $config; //configuration file
  public $directories; //directories used in this environment
  public $pluginName; //name of this plugin
  public $cronOkay; //cron.d file not loaded or changed (boolean)
  public $scriptsOkay; //fpp scripts not loaded or changed (boolean)

  public function __construct($uiRequest=true) {
    $this->pluginName='fpp-after-hours';
    
    //if (!isset($GLOBALS['settings']['pluginDirectory'])) { //if class is called from outside fpp ui, load the variables we need from fpp config.php
    //  $skipJSsettings=true; //we don't want the javascript from the config.php include file
    //  include '/opt/fpp/www/config.php';
    //}
    //else global $settings;
    
    //@$this->directories->pluginDirectory=$settings['pluginDirectory']."/$this->pluginName/";  // /home/fpp/media/plugins/$this->pluginName/
    //@$this->directories->pluginDataDirectory=$settings['mediaDirectory']."/plugindata/";  // /home/fpp/media/plugindata/
    //@$this->directories->scriptDirectory=$settings['scriptDirectory'].'/'; // /home/fpp/media/scripts/
    @$this->directories->pluginDirectory="/home/fpp/media/plugins/$this->pluginName/";
    @$this->directories->pluginDataDirectory="/home/fpp/media/plugindata/";
    @$this->directories->scriptDirectory="/home/fpp/media/scripts/";
    @$this->directories->crondDirectory="/etc/cron.d/";
    @$this->directories->playlistDirectory="/home/fpp/media/playlists/";
    @$this->loadConfigFile();
    
    $this->checkDependenciesLoaded();
    $this->checkIsMusicRunning();
    $this->checkMusicShouldBeRunning();
    $this->getSavedShowVolume();
    $this->refreshCronOkayFlag();
    $this->refreshScriptsOkayFlag();
    $this->checkForMPDFormat(); //do this only so we don't have to update startup script to perform the format and bitrate mpd.conf update - 2019-11-06
  }
  
  public function saveConfigFile() {
    if (isset($this->config->streams) && count($this->config->streams)) { //perform a sort on the stream names to keep them alphabetical ( !! lower and upper case are sorted independently with ksort !! )
      $streams=json_decode(json_encode($this->config->streams),true);
      ksort($streams);
      $this->config->streams=(object)$streams;
    }
    if (!isset($this->config->activeSource)) {
      if (isset($this->config->local) && count($this->config->local)) $this->config->activeSource='local';
      else $this->config->activeSource='internet'; //default to this
    }
    if (!file_put_contents($this->directories->pluginDataDirectory.$this->pluginName."-config.history", date("Y-m-d H:i:s")." - ".json_encode($this->config)."\n",FILE_APPEND)) error_log("fpp-after-hours could not write history data to {$this->directories->pluginDataDirectory}{$this->pluginName}-config.history"); //write all changes to history file in case of booboo
    if (file_put_contents($this->directories->pluginDataDirectory.$this->pluginName."-config.json", json_encode($this->config))) return true;
    error_log("fpp-after-hours could not write current data to {$this->directories->pluginDataDirectory}{$this->pluginName}-config.json");
    return false;
  }
 
  public function loadConfigFile() {
    /*if (file_exists($this->directories->pluginDataDirectory.$this->pluginName."-config.json")) 
      $this->config=json_decode(file_get_contents(($this->directories->pluginDataDirectory.$this->pluginName."-config.json")));
    else 
      $this->config=false;
    */
    if (!file_exists($this->directories->pluginDataDirectory.$this->pluginName."-config.json")) 
      $this->saveConfigFile();

    if (file_exists($this->directories->pluginDataDirectory.$this->pluginName."-config.json"))
      $this->config=json_decode(file_get_contents(($this->directories->pluginDataDirectory.$this->pluginName."-config.json")));
    else
      $this->config=false;
      
    //upgrade volume in streams config since new version does not use the show volume setting from fpp
    if (isset($this->config->streams)) {
    	$update=false;
      foreach ($this->config->streams as $key=>$data) {
        if ($data->volume=="-") {
					$this->config->streams->$key->volume=100;
					$update=true;
				} 
      }
      if ($update) self::saveConfigFile();
    }
  }
  
  public function getActiveSource() { //is local or internet the active source
    if ($this->config === false) return false;
    if (!isset($this->config->activeSource)) return false;
    return $this->config->activeSource;
  }
  
  public function getNowPlayingDetail() {
    exec('mpc current',$song);
    exec("mpc",$status);
    $status=implode("<br>",$status);
    if (isset($song[0])) $status=str_replace($song[0]."<br>","",$status);
    $out=array('title'=>false,'detail'=>false);
    if (isset($song[0])) {
      $out['title']=$song[0];
      $out['detail']=$status;
    }
    return (object)$out;
  }
  
  public function checkDependenciesLoaded() {
    exec('mpc version',$ret);
    if (strstr(implode(",",$ret)," version: ")) $this->dependenciesAreLoaded=true;
    else  {
      $this->dependenciesAreLoaded=false;

      /*
      //Github issue 19 - https://github.com/jcrossbdn/fpp-after-hours/issues/19
      exec("mpc",$mpc);
      exec("mpc version",$mpcVersion);
      file_put_contents($this->directories->pluginDataDirectory."fpp-after-hours-debugLog19.log","Github Issue: 19\nDate: ".date("Y-m-d H:i:s")."\nmpc output:".print_r($mpc,true)."\nmpc version output:".print_r($mpcVersion,true)."\n---------\n",FILE_APPEND);
      exec("mpc stop && mpc clear"); //attempt to force mpd off when there is a failure finding mpd in the plugin
      */
      if (file_exists($this->directories->pluginDataDirectory."fpp-after-hours-debugLog19.log")) unlink($this->directories->pluginDataDirectory."fpp-after-hours-debugLog19.log");
      exec('sudo systemctl stop mpd');
      exec('sudo systemctl start mpd');
      exec('mpc version',$ret);
      if (strstr(implode(",",$ret)," version: ")) $this->dependenciesAreLoaded=true;
      // ** END Github issue 19

      //$this->setMusicRunningStatus(false);
    }
  }
  public function installDependencies() {
    exec('sudo apt-get -y update && sudo apt-get -y install mpd mpc',$out);
    return $out;
  }
  
  public function checkIsMusicRunning($returnHash=false) { //returns mpc command output 
    exec("mpc",$status);
    if ($returnHash) return md5(implode(",",$status));
    $this->musicIsRunning=false;
    if (count($status)) {
      foreach ($status as $a) {
        if (substr($a,0,11)=="[playing] #")  {
          $this->musicIsRunning=true;
          break;
        }
      }
      return $status;
    }
  }
  
  
  public function checkMusicShouldBeRunning() {
    if (file_exists($this->directories->pluginDataDirectory."$this->pluginName-musicRunning") && trim(file_get_contents($this->directories->pluginDataDirectory."$this->pluginName-musicRunning"))=="1")
      $this->musicShouldBeRunning=true;
    else
      $this->musicShouldBeRunning=false;
  }
  public function setMusicRunningStatus($value) {
    if ($value===true || $value===1) $value=true;
    else $value=false;
    file_put_contents($this->directories->pluginDataDirectory."fpp-after-hours-musicRunning",($value===true || $value===1 ? 1 : 0));
    $this->musicShouldBeRunning=$value;
  }

  public function setCurrentInternetRadioHost($value='') {
    file_put_contents($this->directories->pluginDataDirectory."fpp-after-hours-musicHost",($value));
  }
  public function getCurrentInternetRadioHost() {
    if (file_exists($this->directories->pluginDataDirectory."fpp-after-hours-musicHost")) return file_get_contents($this->directories->pluginDataDirectory."fpp-after-hours-musicHost");
    return '';
  }
  
  
  public function getSavedShowVolume() {
    if (file_exists($this->directories->pluginDataDirectory."$this->pluginName-showVolume"))  $this->showVolume=intval(file_get_contents($this->directories->pluginDataDirectory."$this->pluginName-showVolume"));
    else $this->showVolume=false;
  }
  public function setShowVolume($volume) {
    $volume=preg_replace('/\D/','',$volume);
    file_put_contents($this->directories->pluginDataDirectory.'fpp-after-hours-showVolume',$volume);
    $this->showVolume=$volume;
  }
  
  
  
  public function checkCronLoaded() {
    if (!file_exists("{$this->directories->crondDirectory}fpp-after-hours-cron")) return false;
    else return true;
  }
  public function checkCronChanged() {
    if (file_get_contents("{$this->directories->crondDirectory}fpp-after-hours-cron") != file_get_contents("{$this->directories->pluginDirectory}templates/fpp-after-hours-cronTemplate")) return true;
    else return false;
  }
  public function updateCron() {
    exec("sudo cp {$this->directories->pluginDirectory}templates/fpp-after-hours-cronTemplate {$this->directories->crondDirectory}fpp-after-hours-cron");
  }
  public function refreshCronOkayFlag() {
    @$this->cronOkay=false;
    if ($this->checkCronLoaded() == true)
      if ($this->checkCronChanged() == false)
        $this->cronOkay=true;
  }
  
  
  
  public function checkScriptsLoaded() {
    if (!file_exists($this->directories->scriptDirectory.'fpp-after-hours-start.php')) return false; 
    if (!file_exists($this->directories->scriptDirectory.'fpp-after-hours-stop.php')) return false;
    return true;
  }
  public function checkScriptsChanged() {
    if (file_get_contents($this->directories->scriptDirectory.'fpp-after-hours-start.php') != file_get_contents($this->directories->pluginDirectory.'templates/fpp-after-hours-startTemplate.php')) return true;
    if (file_get_contents($this->directories->scriptDirectory.'fpp-after-hours-stop.php') != file_get_contents($this->directories->pluginDirectory.'templates/fpp-after-hours-stopTemplate.php')) return true;
    return false;
  }
  public function updateScripts() {
    file_put_contents($this->directories->scriptDirectory.'fpp-after-hours-start.php',file_get_contents($this->directories->pluginDirectory.'templates/fpp-after-hours-startTemplate.php'));
    file_put_contents($this->directories->scriptDirectory.'fpp-after-hours-stop.php',file_get_contents($this->directories->pluginDirectory.'templates/fpp-after-hours-stopTemplate.php'));
  }
  public function refreshScriptsOkayFlag() {
    @$this->scriptsOkay=false;
    if ($this->checkScriptsLoaded() == true)
      if ($this->checkScriptsChanged() == false)
        $this->scriptsOkay=true;
  }
  
  
  public function getSystemSoundCards() {
    //exec("sudo aplay -l | grep '^card' | sed -e 's/^card //' -e 's/:[^\[]*\[/:/' -e 's/\].*\[.*\].*//' | uniq",$cards);
    /*if (count($cards)) {
      foreach ($cards as $name) {
        $pn=explode(":",$name);
        if (isset($pn[0]) && isset($pn[1])) {
          $cardName='';
          for ($inta=1; $inta<count($pn); $inta++) $cardName.=$pn[$inta].":";
          @$out[$pn[0]]->cardName=substr($cardName,0,-1); //remove trailing :
        }        
      }
      return $out;
    }
    return false;
    */
    exec("sudo aplay -l",$cards);
    $cardStr="";
    if (count($cards)) {
      foreach ($cards as $card) $cardStr.=$card."\n";
    }
    
    $out=array();
    preg_match_all('/^card (.*?):(.*?\[(.*?)\])/m',$cardStr,$cardDetail);
    if (count($cardDetail[0])) {
      foreach ($cardDetail[0] as $key=>$null) {
        $cardNo=$cardDetail[1][$key];
        $cardName=$cardDetail[3][$key];
        @$out[$cardNo]->cardName=$cardName;
      }
      return $out;
    }
    return false;    
  }

  public function getMPCHash() {
    if (!file_exists($this->directories->pluginDataDirectory.'/mpdStatus')) {
      $md5=$this->checkIsMusicRunning(true); //returns an md5 value of the output of the mpc command
      $obj=(object)array('lastChangeTimestamp'=>time()-11,'md5'=>$md5);
      file_put_contents($this->directories->pluginDataDirectory.'/mpdStatus',json_encode($obj));
    }
    $obj=json_decode(file_get_contents($this->directories->pluginDataDirectory.'/mpdStatus'));
    return $obj;
  }

  public function setMPCHash($obj) {
    file_put_contents($this->directories->pluginDataDirectory.'/mpdStatus',json_encode($obj));
  }
  
  public function getMPDConfig() {
    exec("sudo cat /etc/mpd.conf",$mpdConfArr);
    $mpdConf=implode("\n",$mpdConfArr);
    unset($mpdConfArr);
    preg_match_all('/^audio_output \{(.*?)^\}/sim', $mpdConf, $arrOutputs);
    if (count($arrOutputs) && count($arrOutputs[0])) {
      $out['full']=$mpdConf;
      $out['noOutputs']=str_replace($arrOutputs[0],"",$mpdConf);
      $out['outputBlock']=implode("\n",$arrOutputs[0]);
    }
    else {
      $out['full']=$mpdConf;
      $out['noOutputs']=$mpdConf;
      $out['outputBlock']="";
    }
    
    preg_match_all('/^(?:(#port\t|port\t)).*?(".*?")\n/sim', $mpdConf,$port);
    $out['port']['full']=$port[0][0]; //track for future find/replace if required
    $out['port']['number']=str_replace('"','',$port[2][0]);
      
    preg_match_all('/.*?\s(type|name|device|mixer_type|format).*?\"(.*?)\"/sim', $out['outputBlock'], $entries);
    if (count($entries) && count($entries[1])) {
      $index=0; //creates new index for each config group
      $out['outputEntries']=array();
      foreach ($entries[1] as $id=>$name) {
        if (isset($out['outputEntries'][$index][$name])) $index++;
        if ($name=="device") $out['outputEntries'][$index][$name]=preg_replace('/hw:(.*?),.*/', '$1', $entries[2][$id]);
        else $out['outputEntries'][$index][$name]=$entries[2][$id]; 
      }
    }
    unset($mpdConf);
    return json_decode(json_encode($out));
  }
  
  public function getSystemSoundCardToMPD() { //returns all system sound card names and t/f whether they are loaded into mpd
    exec("mpc outputs",$arr);
    if (count($arr)) {
      $system=$this->getSystemSoundCards();
      if ($system===false) return false; //no sound cards exist on system so we don't care what mpd knows about
      foreach ($system as $index) $out[$index->cardName]=false; //default to not in mpd
      foreach ($system as $index) {
        foreach ($arr as $a) {
          if (strstr($a,"($index->cardName)")!==false) $out[$index->cardName]=true;
        }
      }
    }
    return json_decode(json_encode($out));
  }
  
  public function checkForRemovedSoundCards() {
		$mppd=$this->getMPDConfig();
    $out=false;
    if (count($mppd->outputEntries)) {
      $system=$this->getSystemSoundCards();
      foreach($mppd->outputEntries as $mpd) {
        if (!count($system)) $out[]=$mpd->name;
        else {
          $found=false;
          foreach ($system as $sys) {
            if (isset($sys->cardName) && $sys->cardName==$mpd->name) {
              $found=true;
              break;
            }
          }
          if (!$found) $out[]=$mpd->name;
        }
      }
    }
    return $out;
  }
  
  public function checkForNewSoundCard() { //returns t/f on whether a new sound card has been found
		$cardToMPD=$this->getSystemSoundCardToMPD();
    if ($cardToMPD !== false && is_object($cardToMPD)) {
      $newCardFound=false;
      foreach ($cardToMPD as $val) {
        if ($val===false) {
          $newCardFound=true;
          break;
        }
      }
      return $newCardFound;
    }
    return false;
  }
  
  public function checkForMPDFormat() { //reloads the config if an old mpd config exists that does not contain format and bitrate variables in the audio_output definitions
    $config=self::getMPDConfig();
		if (isset($config->outputEntries) && count($config->outputEntries)) {
			foreach ($config->outputEntries as $entry) {
	    	if (!isset($entry->format))  { //format does not exist so force update the config file
					self::updateMPDConfig(true); //forceable update the mpd config file
					exec("sudo systemctl restart mpd.service");
					return true;
				}
	    }
    }
  }
  
  public function getFPPActiveSoundCardName() {
    //get active sound card id from /home/fpp/media/settings
    preg_match('/^AudioOutput = \"(.*?)\"\n/sim', file_get_contents('/home/fpp/media/settings'), $fppOutputArr);
    if (isset($fppOutputArr) && count($fppOutputArr)) {
      $fppOutput=intval($fppOutputArr[1]);
      $systemCards=$this->getSystemSoundCards();
      if ($systemCards !== false && isset($systemCards[$fppOutput])) return $systemCards[$fppOutput]->cardName;      
    }
    return false;
  }
  
  public function updateMPDConfig($forceUpdate=false) { //updates mpd config file if it is required
    $cardToMPD=$this->getSystemSoundCardToMPD();
    if ($cardToMPD !== false && is_object($cardToMPD)) {
      $requiresUpdate=false;
      foreach ($cardToMPD as $val) {
        if ($val===false) {
          $requiresUpdate=true;
          break;
        }
      }
      if ($this->checkForRemovedSoundCards() !== false) $requiresUpdate=true; //remove uninstalled sound cards from mpd config file
      if ($requiresUpdate || $forceUpdate) {
        $sysCards=$this->getSystemSoundCards();
        if (count($sysCards)) {
          $audio_output="";
          foreach ($sysCards as $cardNo=>$data1) {
            $type="alsa";
            $audio_output.="audio_output {\n\ttype\t\"$type\"\n\tname\t\"$data1->cardName\"\n\tdevice\t\"hw:$cardNo,0\"\n\tmixer_type\t\"software\"\n\tformat\t\"44100:16:2\"\n\tbitrate\t\"128\"\n}\n";
          }
          $mpdConfig=$this->getMPDConfig();
          if ($mpdConfig===false) return false;
          $newConfig=$audio_output."\n\n".trim($mpdConfig->noOutputs)."\n";

					if (file_put_contents($this->directories->pluginDataDirectory."fpp-after-hours-mpdConfig",$newConfig)) {
            if (!file_exists($this->directories->pluginDataDirectory."fpp-after-hours-mpdOriginal.conf")) exec("yes | sudo cp -rf /etc/mpd.conf {$this->directories->pluginDataDirectory}fpp-after-hours-mpdOriginal.conf"); //make a backup of this file
            exec("yes | sudo cp -rf {$this->directories->pluginDataDirectory}fpp-after-hours-mpdConfig /etc/mpd.conf");
            unlink("{$this->directories->pluginDataDirectory}fpp-after-hours-mpdConfig");
	          if (!$forceUpdate) {  
	            exec("sudo mpd --kill");
	            sleep(3);
	            exec("sudo mpd");
	            sleep(3);
	          }
	          unset($mpdConfig);
	          unset($newConfig);
	          
	          if ($this->checkForNewSoundCard()===false) return true; //update successful
	          return false;
	        }
        }
      }
    }
    return false;
  }
  
  public function checkGitUpdates() {
    exec("cd /home/fpp/media/plugins/fpp-after-hours && sudo git fetch --all && sudo git checkout",$ret);
    return $ret;
  }
  
  public function pluginGitUpdate($hard=false) {
    //exec("cd /home/fpp/media/plugins/fpp-after-hours".($hard===true ? " && sudo git reset --hard":"")." && sudo git fetch --all && sudo git pull origin",$ret);
    exec(($hard===true ? "cd /home/fpp/media/plugins/fpp-after-hours && sudo git reset --hard && ":"")."/opt/fpp/scripts/update_plugin fpp-after-hours",$ret);
    return $ret;
  }
  
  public function getDebugData() {
    $out['fpp-after-hoursConfig']=$this->config;    
    $schedules=array_map('str_getcsv',file('/home/fpp/media/schedule'));
    if ($schedules===false) return false;
    $d = dir($this->directories->playlistDirectory);
    while (false !== ($entry = $d->read())) {
      if ($entry=='.' || $entry=='..') continue;
      $pathInfo=pathinfo($this->directories->playlistDirectory.$entry);
      if (strtolower($pathInfo['extension'])=='json') {
        $obj=json_decode(file_get_contents($this->directories->playlistDirectory.$entry));
        if ($obj !== null) {
          foreach ($schedules as $sched) {
            if ($sched[1]==$obj->name) $obj->schedule=implode(",",$sched);
          }
        }
        $out[]=$obj;
      }
      else {
        $out[]="non json playlist file:<br>".file_get_contents($this->directories->playlistDirectory.$entry);
      }
    }
    $out['allSchedules']=print_r(file_get_contents('/home/fpp/media/schedule'),true);
    return $out;
  }
  
  public function pingInternetRadio($host) {
    $purl=parse_url($host);
    $host=$purl['host'];
    $port=(isset($purl['port']) ? $purl['port'] : ($purl['scheme']=='http' ? 80 : 443));
    if ($fp=@fsockopen($host, $port, $errno, $errstr, 1)) return true;
    return false;
  }
}
?>
