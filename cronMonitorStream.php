<?php
require_once '/home/fpp/media/plugins/fpp-after-hours/fpp-after-hours-class.php';
$fah=new fppAfterHours(false); //do not load ui data now
$runFromCronMonitorStream=true;

function testForLockedStream() {
  global $fah;
  $obj=$fah->getMPCHash();
  if ($obj) {
    $md5=$fah->checkIsMusicRunning(true); //returns an md5 value of the output of the mpc command
    if ($md5 != $obj->md5) {
      $obj->lastChangeTimestamp=time();
      $obj->md5=$md5;
      $fah->setMPCHash($obj);
    }
    elseif ($md5 == $obj->md5 && time() - $obj->lastChangeTimestamp >= 10) { //attempt to restart this failed stream
      exec("mpc stop && mpc clear");
      $fah->setCurrentInternetRadioHost();
      if (file_exists($fah->directories['scriptDirectory'].'/fpp-after-hours-start.php')) {
        include $fah->directories['scriptDirectory'].'/fpp-after-hours-start.php';
      }
    }
  }
}

if ($fah->musicShouldBeRunning) {
  if ($fah->musicIsRunning==false)  {
    sleep(10); //try again in a few seconds incase we were between songs
    $fah->checkIsMusicRunning();
    $fah->checkMusicShouldBeRunning();
    if ($fah->musicShouldBeRunning) {
      if ($fah->musicIsRunning===false)  {
        $npd=$fah->getNowPlayingDetail();
        if (trim($npd->error)!='') { //this stream has an error, find next stream and load it (if it exists)
        }
        if (file_exists($fah->directories['scriptDirectory'].'/fpp-after-hours-start.php')) {
          include $fah->directories['scriptDirectory'].'/fpp-after-hours-start.php';
          sleep(1);
        }
      }
      else {
        $host=$fah->getCurrentInternetRadioHost();
        if (trim($host) != '') {
          if ($fah->pingInternetRadio($host)===false) { //stop this broken stream and attempt to restart or start a new one
            exec("mpc stop && mpc clear");
            $fah->setCurrentInternetRadioHost();
            if (file_exists($fah->directories['scriptDirectory'].'/fpp-after-hours-start.php')) {
              include $fah->directories['scriptDirectory'].'/fpp-after-hours-start.php';
              sleep(1);
            }
          }
        }
      }
    }
  }
  testForLockedStream();
}
else { //should not be running
  exec("mpc stop && mpc clear"); //send kill command just in case
}
?>