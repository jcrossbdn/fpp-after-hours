<?php
ini_set('display_errors',true);
error_reporting(E_ERROR);
require_once $settings['pluginDirectory'].'/fpp-after-hours/fpp-after-hours-class.php';

$fah=new fppAfterHours();

if (isset($_GET['nowPlaying'])) { //return json nowPlaying details (ajax auto refresh)
  $npd=$fah->getNowPlayingDetail();
  @$out->title=($npd->title!==false ? $npd->title : "----No music is playing----");
  @$out->detail=($npd->detail!==false ? $npd->detail : "");
  die(json_encode($out));
}


if (isset($_GET['runStartScript'])) {
  include $fah->directories->scriptDirectory.'fpp-after-hours-start.php';
  header("Location: ?plugin=fpp-after-hours&page=fpp-after-hours.php");
  exit;
}

if (isset($_GET['runStopScript'])) {
  include $fah->directories->scriptDirectory.'fpp-after-hours-stop.php';
  header("Location: ?plugin=fpp-after-hours&page=fpp-after-hours.php");
  exit;
}

if (isset($_GET['vup'])) {
  if ($fah->musicShouldBeRunning) exec('mpc volume +1');
  header("Location: ?plugin=fpp-after-hours&page=fpp-after-hours.php");
}
if (isset($_GET['vdn'])) {
  if ($fah->musicShouldBeRunning) exec('mpc volume -1');
  header("Location: ?plugin=fpp-after-hours&page=fpp-after-hours.php");
}



// *********************************************************************************************************************************************************************************************************************
// ********************************************************************************************************* S T A R T    L O C A L    M E D I A *******************************************************************
if (isset($_GET['loadLocalMedia'])) {  //local media tab
  echo "<font size=-1>";
  echo "local media controls have not been written yet";
  echo "</font>";
  exit;
}
// ********************************************************************************************************* E N D    L O C A L    M E D I A *******************************************************************
// *********************************************************************************************************************************************************************************************************************



// *********************************************************************************************************************************************************************************************************************
// ********************************************************************************************************* S T A R T    I N T E R N E T    R A D I O *******************************************************************

if (isset($_POST['fah-submitInternet']) || isset($_POST['fah-deleteStream'])) {
  //add a new stream - also handles deletes so adds, updates and deletes are handled at the same time
  if (trim($_POST['fah-newStreamName']) != '' && trim($_POST['fah-newStreamURL']) != '') {
    $name=trim($_POST['fah-newStreamName']);
    $url=trim($_POST['fah-newStreamURL']);
    @$fah->config->streams->$name=(object)array('url'=>$url, 'active'=>0, 'priority'=>9, 'volume'=>'-');
  }

  //update an existing stream
  if (isset($_POST['priority'])) { //only update if there is at least 1 existing item posted
    foreach ($_POST['priority'] as $streamName=>$null) {
      //handle deletes
      if (trim($_POST['priority'][$streamName])=='' && trim($_POST['volume'][$streamName])=='' && trim($_POST['streamName'][$streamName])=='' && trim($_POST['url'][$streamName])=='') unset($fah->config->streams->$streamName);
      elseif (isset($_POST['fah-deleteStream']) && isset($_POST['fah-deleteStream'][$streamName])) unset($fah->config->streams->$streamName);
      else { //handle everything else
        @$fah->config->streams->$streamName->priority=intval(trim($_POST['priority'][$streamName]));
        @$fah->config->streams->$streamName->volume=(trim($_POST['volume'][$streamName])=='-' ? '100' : intval(trim($_POST['volume'][$streamName])));
        @$fah->config->streams->$streamName->url=trim($_POST['url'][$streamName]);
        if (isset($_POST['active'][$streamName])) $fah->config->streams->$streamName->active=1;
        else $fah->config->streams->$streamName->active=0;
        //handle rename
        $nn=trim($_POST['streamName'][$streamName]);
        if (trim($streamName) !== $nn && trim($streamName) != '') {
          $fah->config->streams->$nn = $fah->config->streams->$streamName;
          unset($fah->config->streams->$streamName);
        }
      }      
    }
  }
  $fah->saveConfigFile();
}

if (isset($_GET['loadInternetMedia'])) { //internet radio tab
  echo "<font size=-1><form method='post' enctype='multipart/form-data' action='?plugin=fpp-after-hours&page=fpp-after-hours.php&activeTab=1'>";
  echo "<input type='checkbox' name='activeSource[internet]'".($fah->getActiveSource()=='internet' ? " checked" : "")."> Internet Radio is the After Hours Music Source<br><br>";
  echo "<table border=1><tr><td>Active</td><td>Priority</td><td>Volume</td><td>Status</td><td>Stream Name</td><td>Stream URL</td><td>Options</td></tr>";
  
  if (isset($fah->config->streams) && count($fah->config->streams)) {
    foreach ($fah->config->streams as $streamName=>$streamData) {
      echo "<tr><td><input type='checkbox' name='active[$streamName]'".($streamData->active==0 ? "" : " checked")."></td><td><input type='text' size=3 name='priority[$streamName]' value='{$streamData->priority}'></td>
      <td><input type='text' size=3 name='volume[$streamName]' value='{$streamData->volume}'></td><td>".($fah->pingInternetRadio($streamData->url)===true ? "IP is pingable":"<font color='red'>IP is not pingable</font>")."</td><td><input type='text' size=30 name='streamName[$streamName]' value='$streamName'></td><td><input type='text' size=50 name='url[$streamName]' value='{$streamData->url}'></td><td><input type='submit' name='fah-deleteStream[$streamName]' value='Delete'></td></tr>";
    }
  }
  else echo "<tr><td colspan=6>No streams have been entered yet</td></tr>";
  echo "</table>";
  echo "
      <br><br>
      <input type='submit' value='Save' name='fah-submitInternet'>
      
      <br><br><hr><br><br>
      
      add new stream Name: <input type='text' name='fah-newStreamName' size=40><br>
      Add new stream URL: <input type='text' name='fah-newStreamURL' size=90>
      <br><br>
      <i><a href='https://www.internet-radio.com/stations/christmas/#' target='_blank'>https://www.internet-radio.com/stations/christmas/#</a> has lots of radio stations</i>
      </form></font>
   ";
  
  exit;
}
// ********************************************************************************************************* E N D   I N T E R N E T    R A D I O *******************************************************************
// *********************************************************************************************************************************************************************************************************************



// *********************************************************************************************************************************************************************************************************************
// ********************************************************************************************************* S T A R T   A D V A N C E D *******************************************************************
if (isset($_GET['fah-adv-updatePlugin'])) {
  (isset($_GET['gitHard']) ? $hard=true : $hard=false);
  $git=$fah->pluginGitUpdate($hard);
  if (count($git)) {
    foreach ($git as $g) echo "<ul>$g</ul>";
  }
  echo "<strong>Command Complete.</strong> &nbsp; &nbsp; <a href='?plugin=fpp-after-hours&page=fpp-after-hours.php&activeTab=2'>Click here to go back</a>";
  exit;
}

if (isset($_GET['loadAdvanced'])) {  //local media tab
  echo "<font size=-1>";
  //echo "<strong>FPP Active Sound Card:</strong> ".$fah->getFPPActiveSoundCardName()."<br><br>";
  //echo ($fah->checkForNewSoundCard()===true ? "There are sound cards in the system that are not configured in mpd. Click Run Start Script to load them<br><br>":"All system sound cards are currently loaded into mpd.<br><i>If you have changed the active sound card in fpp settings then you will have to click Run Start Script to update this plugin</i>");
  
  echo "<br><br><hr><strong>Plugin Github Status</strong> &nbsp; <a href='?plugin=fpp-after-hours&page=fpp-after-hours.php&fah-adv-updatePlugin&nopage'>Click here to update</a><br>";
  echo "<a href='?plugin=fpp-after-hours&page=fpp-after-hours.php&fah-adv-updatePlugin&gitHard&nopage'>reset --hard</a> if you have made local changes to the plugin<br><br>";
  $git=$fah->checkGitUpdates();
  if (count($git)) {
    foreach ($git as $g) echo "<ul>$g</ul>";
  }
  
  //echo "<br><br><hr><strong>If asked to provide Advanced debug data please copy everything in the grey box below</strong> (there is no sensitive data here)<br>";
  //echo "<pre>All Sound Cards<br>";
  //print_r($fah->getSystemSoundCards());
  //echo "<br>";
  echo "<pre>";
  echo "Settings<br>";
  print_r($settings);
  echo "<hr>fpp-after-hours class variables<br>";
  print_r($fah);
  echo "<hr>getFPPActiveSoundCardName<br>";
  print_r($fah->getFPPActiveSoundCardName());
  echo "<hr>getSystemSoundCards function return<br>";
  print_r($fah->getSystemSoundCards());
  echo "<hr>getSystemSoundCardToMPD function return<br>";
  print_r($fah->getSystemSoundCardToMPD());
  echo "<hr>MPDConfig function return<br>";
  print_r($fah->getMPDConfig());
  echo "<hr>fpp-after-hours and Playlists and Schedules<br>";
  print_r($fah->getDebugData());
  echo "</pre>";
  
  echo "</font>";
  exit;
}

// ********************************************************************************************************* S T O P    A D V A N C E D *******************************************************************
// *********************************************************************************************************************************************************************************************************************


if (isset($_GET['installDependencies'])) {
  $ret=$fah->installDependencies();
  echo "<div id=\"elements\" class=\"settings\"><fieldset><legend>! ! ! Software Installation Report ! ! !</legend><div>";
  if (count($ret)) {
    foreach ($ret as $o) echo "$o<br>";
    $fah=new fppAfterHours(); //reload class instance
  }
  else echo "ERROR: No response from installation command";
  echo "<br><div align=\"center\"><a href='?plugin=fpp-after-hours&page=fpp-after-hours.php'>Click here to return to after hours plugin page</a></div>";
  echo "</div></fieldset></div><br><br>";
  exit;
}

if (!$fah->dependenciesAreLoaded) {
  echo "
  <div id=\"elements\" class=\"settings\">
    <fieldset>
      <legend>! ! ! Additional Software Must Be Installed ! ! !</legend>
      <div align=\"center\"><a href='?plugin=fpp-after-hours&page=fpp-after-hours.php&installDependencies'>Install now (this may take several minutes)</a><br>will run <i>sudo apt-get -y update && sudo apt-get -y install mpd mpc</i><br><strong>PLEASE REBOOT ONCE INSTALL HAS COMPLETED!</strong></div>
    </fieldset>
  </div>
  <br><br>
  ";
  exit;
}
else {    // ************************************************** M A I N    P L U G I N   B O D Y ***************************************************
  if ($fah->config !== false) { //only show now play and control if the plugin has been configured (config file must exist)
    echo "
      <script>
        function getElements() {
          $.ajax({
            type: \"GET\",
            dataType: \"json\",
            url: \"?plugin=fpp-after-hours&page=fpp-after-hours.php&nowPlaying&nopage\",
            dataType: \"html\",
            success: function(data) {
              json=JSON.parse(data.replace('<!DOCTYPE html>\\n<html>\\n',''));
              $('#fpp-after-hours-nowPlaying').html(json.title + '<hr>' + json.detail);
              //if (json.allowVC) $(\"#fpp-after-hours-vup\").prop(\"disabled\",false); else $(\"#fpp-after-hours-vup\").prop(\"disabled\",true); 
              //console.log(json);
            }
          });
        }
        
        $(document).ready(function() {
          getElements();
        });
        
        setInterval(function() {
          getElements()
        },3000);
        
        
      </script>
    ";
    
    echo "
      <div id=\"elements\" class=\"settings\">
        <fieldset>
          <legend>Now Playing</legend>
          <div align=\"center\" id=\"fpp-after-hours-nowPlaying\"></div>
        </fieldset>
      </div>
      
      <br><br>
      
      <div id=\"elements\" class=\"settings\">
        <fieldset>
          <legend>Control</legend>
          <div align=\"center\">
            <table border=0><tr><td><a href='?plugin=fpp-after-hours&page=fpp-after-hours.php&runStartScript&nopage'>Run Start Script</a></td><td> &nbsp; &nbsp; &nbsp; &nbsp; </td><td><a href='?plugin=fpp-after-hours&page=fpp-after-hours.php&runStopScript&nopage'>Run Stop Script</a></td><td> &nbsp; &nbsp; &nbsp; &nbsp; </td><td><a id='fpp-after-hours-vup' href='?plugin=fpp-after-hours&page=fpp-after-hours.php&vup&nopage'>Volume +</a></td><td> &nbsp; &nbsp; &nbsp; &nbsp; </td><td><a id='fpp-after-hours-vup' href='?plugin=fpp-after-hours&page=fpp-after-hours.php&vdn&nopage'>Volume -</a></td></tr></table>
    ";

    //if ($fah->musicShouldBeRunning) echo "Volume will be reset to $fah->showVolume  (Show Level) when Stop Script is executed";
    
    echo "      
          </div>
        </fieldset>
      </div>
      
      <br><br>
    ";
  }
  
  $showNotices=false;
  $notices=array();
  
  if (!$fah->cronOkay) {
    $fah->updateCron();
    $fah->refreshCronOkayFlag();
    if (!$fah->cronOkay) {
      $showNotices=true;
      $notices[]="ERROR: Cron File could not be copied to cron.d";
    }
  }

  if (!$fah->scriptsOkay) {
    $fah->updateScripts();
    $fah->refreshScriptsOkayFlag();
    if (!$fah->scriptsOkay) {
      $showNotices=true;
      $notices[]="ERROR: script files could not be copied to fpp script directory";
    }
  }
  
  if ($showNotices && count($notices)) {
    echo "
      <div id=\"elements\" class=\"settings\">
        <fieldset>
          <legend>ERRORS and Warnings were encountered</legend>
    ";
    foreach ($notices as $notice) echo " - $notice<br>";
    echo "
        </fieldset>
      </div>
      <br><br>
    ";
  } 
  
  
  $activeTab=(isset($_GET['activeTab']) ? $_GET['activeTab'] : ($fah->getActiveSource()=="local" ? 0 : 1));
  echo "
    <script>
      $( function() {
        $( \"#tabs\" ).tabs({active:$activeTab});
      } );
    </script>
    </head>
    <body>
    
    <div id=\"elements\" class=\"settings\">
      <fieldset>
        <legend>FPP After Hours Music</legend> 
        <div id=\"tabs\">
          <ul>
            <li><a href=\"?plugin=fpp-after-hours&page=fpp-after-hours.php&loadLocalMedia&nopage\">Local Media</a></li>
            <li><a href=\"?plugin=fpp-after-hours&page=fpp-after-hours.php&loadInternetMedia&nopage\">Internet Radio</a></li>
            <li><a href=\"?plugin=fpp-after-hours&page=fpp-after-hours.php&loadAdvanced&nopage\">Advanced</a></li>
          </ul>
        </div>
      </fieldset>
    </div>
    <br><br>        
  ";
}
?>
