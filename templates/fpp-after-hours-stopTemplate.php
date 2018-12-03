<?php
exec("mpc stop"); //release the sound card as fast as possible
require_once '/home/fpp/media/plugins/fpp-after-hours/fpp-after-hours-class.php';
$fah=new fppAfterHours();
$fah->setMusicRunningStatus(false);
$vol=$fah->showVolume;
exec("mpc stop && mpc clear".($vol !== false ? " && mpc volume $vol" : ""));
?>