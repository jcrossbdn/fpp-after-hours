<?php
require_once '/home/fpp/media/plugins/fpp-after-hours/fpp-after-hours-class.php';
$fah=new fppAfterHours(false); //do not load ui data now

//only run the functions that we need for this task
//$fah->checkIsMusicRunning();
//$fah->checkMusicShouldBeRunning();

if ($fah->musicShouldBeRunning) {
  if ($fah->musicIsRunning==false)  {
    sleep(10); //try again in a few seconds incase we were between songs
    $fah->checkIsMusicRunning();
    $fah->checkMusicShouldBeRunning();
    if ($fah->musicShouldBeRunning) {
      if ($fah->musicIsRunning===false)  {
        if (file_exists($fah->directories->scriptDirectory.'/fpp-after-hours-start.php')) {
          include $fah->directories->scriptDirectory.'/fpp-after-hours-start.php';
        }
      }
      else {
        $host=$fah->getCurrentInternetRadioHost();
        if (trim($host) != '') {
          if ($fah->pingInternetRadio($host)===false) { //stop this broken stream and attempt to restart or start a new one
            exec("mpc stop && mpc clear");
            $fah->setCurrentInternetRadioHost();
            if (file_exists($fah->directories->scriptDirectory.'/fpp-after-hours-start.php')) {
              include $fah->directories->scriptDirectory.'/fpp-after-hours-start.php';
            }
          }
        }
      }
    }
  }
}
else { //should not be running
  exec("mpc stop && mpc clear"); //send kill command just in case
}
?>