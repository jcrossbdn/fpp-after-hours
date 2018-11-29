<?php
$fah_config_file=$settings['mediaDirectory'].'/plugindata/fpp-after-hours-config.json';

if (!isset($fah_config_file) || !file_exists($settings['scriptDirectory'].'/fpp-after-hours-start.php') || !file_exists($settings['scriptDirectory'].'/fpp-after-hours-stop.php')) { //this is a first run so write start and stop scripts to "scriptDirectory"
  //start script
  $file=updateScriptFile($settings['pluginDirectory'].'/fpp-after-hours/fpp-after-hours-startTemplate.php',$settings);
  file_put_contents($settings['scriptDirectory'].'/fpp-after-hours-start.php',$file);
  
  //stop script
  $file=updateScriptFile($settings['pluginDirectory'].'/fpp-after-hours/fpp-after-hours-stopTemplate.php',$settings);
  file_put_contents($settings['scriptDirectory'].'/fpp-after-hours-stop.php',$file);
}

//check for crontab file for stream monitoring
if (!file_exists('/etc/cron.d/fpp-after-hours-cron') || file_get_contents('/etc/cron.d/fpp-after-hours-cron') != file_get_contents($settings['pluginDirectory'].'/fpp-after-hours/fpp-after-hours-cronTemplate')) {
  exec("sudo cp {$settings['pluginDirectory']}/fpp-after-hours/fpp-after-hours-cronTemplate /etc/cron.d/fpp-after-hours-cron");
  //echo "Updating cron configuration";
}


if (isset($_POST['fpp-after-hours-submit']) ) {
  if (file_exists($fah_config_file)) {
    $fah_config=json_decode(file_get_contents($fah_config_file),true);
  }
  $allowUpdate=true;
  //add a new stream
  if (trim($_POST['fpp-after-hours-newStreamName']) != '' && trim($_POST['fpp-after-hours-newStreamURL']) != '') {
    $_POST['fpp-after-hours-newStreamName']=trim($_POST['fpp-after-hours-newStreamName']);
    $_POST['fpp-after-hours-newStreamURL']=trim($_POST['fpp-after-hours-newStreamURL']);
    $fah_config['streams'][$_POST['fpp-after-hours-newStreamName']]=array('url'=>$_POST['fpp-after-hours-newStreamURL'],'active'=>0,'priority'=>9,'volume'=>'-');
  }
  
  //update an existing stream
  if (isset($_POST['priority'])) { //only update if there is at least 1 existing item posted
    foreach ($_POST['priority'] as $streamName=>$null) {
      $fah_config['streams'][$streamName]['priority']=trim($_POST['priority'][$streamName]);
      $fah_config['streams'][$streamName]['volume']=trim($_POST['volume'][$streamName]);
      $fah_config['streams'][$streamName]['url']=trim($_POST['url'][$streamName]);
      if (isset($_POST['active'][$streamName])) $fah_config['streams'][$streamName]['active']=1;
      else $fah_config['streams'][$streamName]['active']=0;
      if (trim($streamName) !== trim($_POST['streamName'][$streamName])) {
        $nn=trim($_POST['streamName'][$streamName]);
        $fah_config['streams'][$nn]=$fah_config['streams'][$streamName];
        unset($fah_config['streams'][$streamName]);
      }
    }
  }

  if ($allowUpdate) file_put_contents($fah_config_file,json_encode($fah_config));
}

//load config file
if (file_exists($fah_config_file)) {
  $fah_config=json_decode(file_get_contents($fah_config_file),true);
}

function updateScriptFile($path,$settings) { //returns file data
  //$startTemplate=explode("\n",file_get_contents($settings['pluginDirectory'].'/fpp-after-hours/fpp-after-hours-startTemplate.php'));
  $startTemplate=explode("\n",file_get_contents($path));
  foreach ($startTemplate as $index=>$row) {
    if (strstr($row,"/*autoupdated*/\$pluginDirectory=") !== false) $startTemplate[$index]="/*autoupdated*/\$pluginDirectory=\"{$settings['pluginDirectory']}/\";";
    if (strstr($row,"/*autoupdated*/\$pluginDataDirectory=") !== false) $startTemplate[$index]="/*autoupdated*/\$pluginDataDirectory=\"{$settings['mediaDirectory']}/plugindata/\";";
  }
  return implode("\n",$startTemplate);
}

function pingHost($host) {
  $purl=parse_url($host);
  $host=$purl['host'];
  $port=(isset($purl['port']) ? $purl['port'] : ($purl['scheme']=='http' ? 80 : 443));
  if ($fp=fsockopen($host, $port, $errno, $errstr, 1)) return true;
  return false;
}


$dependencies='';
if (isset($_GET['installDependencies'])) {
    exec('sudo apt-get -y install mpd mpc',$out);
    $dependencies="<div id=\"elements\" class=\"settings\"><fieldset><legend>! ! ! DEPENDENCY INSTALLATION RESPONSE ! ! !</legend><div>";
    foreach ($out as $o) $dependencies.="$o<br>";
    $dependencies.="<br><div align=\"center\"><a href='?plugin=fpp-after-hours&page=fpp-after-hours.php'>Click here to return to after hours plugin page</a></div>";
    $dependencies.="</div></fieldset></div><br><br>";
  }
//Check for dependencies
exec('mpc version',$ret);
if (strstr(implode(",",$ret)," version: ")) $dependencies="";
else {
  $dependencies="<div id=\"elements\" class=\"settings\">
  <fieldset>
    <legend>! ! ! DEPENDENCIES ARE MISSING ! ! !</legend>
    <div align=\"center\"><a href='?plugin=fpp-after-hours&page=fpp-after-hours.php&installDependencies'>Install now (this may take several minutes)</a><br>will run <i>sudo apt-get -y install mpd mpc</i></div>
  </fieldset>
</div>
<br><br>";
}

//get saved show volume
exec('mpc current',$song);
if (isset($song[0]) && file_exists($settings['mediaDirectory'].'/plugindata/fpp-after-hours-showVolume'))  $showVolume='Volume will be reset to '.file_get_contents($settings['mediaDirectory'].'/plugindata/fpp-after-hours-showVolume').' (Show Level) when Stop Script is executed';
else $showVolume='';
if ($dependencies == '') {
  echo "
  <script>
    function getElements() {
      $.ajax({
        type: \"GET\",
        url: \"?plugin=fpp-after-hours&page=nowPlaying.php&nopage=1\",
        dataType: \"html\",
        success: function(data) {
          $('#fpp-after-hours-nowPlaying').html(data);
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
}
echo $dependencies;

if ($dependencies == '') {
  echo "<div id=\"elements\" class=\"settings\">
    <fieldset>
      <legend>Now Playing</legend>
      <div align=\"center\" id=\"fpp-after-hours-nowPlaying\"></div>
    </fieldset>
  </div>
  
  <br><br>
  
  <div id=\"elements\" class=\"settings\">
    <fieldset>
      <legend>Control</legend>
      <div align=\"center\"><table border=0><tr><td><a href='?plugin=fpp-after-hours&page=runScriptStart.php&nopage=1'>Run Start Script</a></td><td> &nbsp; &nbsp; &nbsp; &nbsp; </td><td><a href='?plugin=fpp-after-hours&page=runScriptStop.php&nopage=1'>Run Stop Script</a></td><td> &nbsp; &nbsp; &nbsp; &nbsp; </td><td>".($showVolume!='' ? "<a href='?plugin=fpp-after-hours&page=volume.php&nopage=1&vup'>Volume +</a>" : "Volume +")."</td><td> &nbsp; &nbsp; &nbsp; &nbsp; </td><td>".($showVolume!='' ? "<a href='?plugin=fpp-after-hours&page=volume.php&vdn&nopage=1'>Volume -</a>" : "Volume -")."</td></tr></table>
  ";
  echo $showVolume;
  echo "
      </div>
    </fieldset>
  </div>
  
  <br><br>
  
  <div id=\"elements\" class=\"settings\">
    <fieldset>
      <legend>FPP After Hours Music Stream</legend>
      <form method='post' enctype='multipart/form-data'>
  ";
      echo "<table border=1><tr><td>Active</td><td>Priority</td><td>Volume</td><td>Status</td><td>Stream Name</td><td>Stream URL</td></tr>";
      if (count($fah_config['streams'])) {
        foreach ($fah_config['streams'] as $streamName=>$streamData) {
          echo "<tr><td><input type='checkbox' name='active[$streamName]'".($streamData['active']==0 ? "" : " checked")."></td><td><input type='text' size=3 name='priority[$streamName]' value='{$streamData['priority']}'></td>
          <td><input type='text' size=3 name='volume[$streamName]' value='{$streamData['volume']}'></td><td>".(pingHost($streamData['url'])===true ? "Reachable":"<font color='red'>Unreachable</font>")."</td><td><input type='text' size=30 name='streamName[$streamName]' value='$streamName'></td><td><input type='text' size=50 name='url[$streamName]' value='{$streamData['url']}'></td></tr>";
        }
      }
      else echo "<tr><td colspan=5>No streams entered yet</td></tr>";
      echo "</table>";
  echo "
      <br><br>
      <input type='submit' value='Save' name='fpp-after-hours-submit'>
      
      <br><br><hr><br><br>
      
      add new stream Name: <input type='text' name='fpp-after-hours-newStreamName' size=40><br>
      Add new stream URL: <input type='text' name='fpp-after-hours-newStreamURL' size=100>
      <br><br>
      <i><a href='https://www.internet-radio.com/stations/christmas/#' target='_blank'>https://www.internet-radio.com/stations/christmas/#</a> has lots of radio stations</i>
      </form>
    </fieldset>
  </div>
  <br/>
  </div>
  ";
}
?>