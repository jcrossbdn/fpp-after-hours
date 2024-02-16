<?php
//file_put_contents('/home/fpp/media/plugindata/teststop',print_r($argv,true),FILE_APPEND);
if (isset($argv[1]) && $argv[1]=='fade' && isset($argv[2]) && is_numeric($argv[2])) {
    if (isset($argv[3]) && is_numeric($argv[3])) $minVolume=$argv[3]; //set the minimum fade volume level
    else $minVolume=0;
    sleep(1); //no idea why fade out script does not work through fpp scheduler without this delay
    require_once '/home/fpp/media/plugins/fpp-after-hours/fpp-after-hours-class.php';
    $fah=new fppAfterHours();
    $fah->setMusicRunningStatus(false);
    $fah->setCurrentInternetRadioHost();
    $startTime=floor(microtime(true)*1000);

    $mustCompleteBy=($startTime + ((intval($argv[2]) * 1000) - 1000)); //must finish before this timestamp
    do {
        $os=floor(microtime(true)*1000); //operation start time
        if (!isset($vol)) $volStr="";
        else $volStr = " $vol";
        //file_put_contents('/home/fpp/media/plugindata/teststop',"$os - mpc volume$volStr\n",FILE_APPEND);
        exec("mpc volume$volStr",$volRet);
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
        
        $volStepsRemain = ($vol-$minVolume) / 5;

        //file_put_contents('/home/fpp/media/plugindata/teststop',"$os - minuteSecond ".date("i:s"). " - remain $remain - volStepsRemain $volStepsRemain\n",FILE_APPEND);

        if ($volStepsRemain > 0) (($delay=$remain / $volStepsRemain) - 100);
        else break; //prevents divide by zero
        if ($delay <= 0 || $remain <= 300) break;
        $vol-=5;
        usleep($delay*1000);
    } while ($vol > 0);
    $vol=$fah->showVolume;
    exec("mpc stop && mpc clear".($vol !== false ? " && mpc volume $vol" : ""));
}
else {
    exec("mpc stop"); //release the sound card as fast as possible
    require_once '/home/fpp/media/plugins/fpp-after-hours/fpp-after-hours-class.php';
    $fah=new fppAfterHours();
    $fah->setMusicRunningStatus(false);
    $fah->setCurrentInternetRadioHost();
    $vol=$fah->showVolume;
    exec("mpc stop && mpc clear".($vol !== false ? " && mpc volume $vol" : ""));
}
?>