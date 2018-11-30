<?php
/*autoupdated*/$pluginDirectory="/home/fpp/media/plugins/";
/*autoupdated*/$pluginDataDirectory="/home/fpp/media/plugindata/";
/*autoupdated*/$scriptDirectory="/home/fpp/media/scripts/";

if (file_exists($pluginDataDirectory."fpp-after-hours-streamRunning") && trim(file_get_contents($pluginDataDirectory."fpp-after-hours-streamRunning"))=="1")  {
  echo "Should be running";
  //should be running so make sure
  exec('mpc current',$song);
  if (!isset($song[0])) {
    //echo "Is not running. Retry in 10";
    sleep(10); //try again incase we were between songs
    //echo "Retry";
    exec('mpc current',$song);
    if (!isset($song[0])) {
      //echo"still not running, restart";
      include $scriptDirectory.'/fpp-after-hours-start.php';
    }
  }
}
else { //should not be running
  exec("mpc stop && mpc clear"); //send kill command just in case
  //echo "Stopped and should not be running";
}
?>
