<?php
error_reporting(0);
ini_set('display_errors',false);
require_once '/home/fpp/media/plugins/fpp-after-hours/fpp-after-hours-class.php';

function getEndpointsfppafterhours() {
    $endpoints=array();
    $endpoints[]=array('method'=>'GET', 'endpoint'=>'getDetails', 'callback'=>'fah_getDetails'); //Querystring Parameters [optional titleOnly=true/false]
    $endpoints[]=array('method'=>'GET', 'endpoint'=>'setMPDvolume', 'callback'=>'fah_setMPDvolume'); //Querystring Parameters (*required value=0-100)
    $endpoints[]=array('method'=>'GET', 'endpoint'=>'start', 'callback'=>'fah_start');
    $endpoints[]=array('method'=>'GET', 'endpoint'=>'stop', 'callback'=>'fah_stop');

    $endpoints[]=array('method'=>'GET', 'endpoint'=>'getStreams', 'callback'=>'fah_getStreams');
    $endpoints[]=array('method'=>'GET', 'endpoint'=>'getStreamPing', 'callback'=>'fah_getStreamPing');
    $endpoints[]=array('method'=>'POST', 'endpoint'=>'updateStream', 'callback'=>'fah_updateStream'); //also used for creation when uid=0
    $endpoints[]=array('method'=>'GET', 'endpoint'=>'deleteStream', 'callback'=>'fah_deleteStream');

    $endpoints[]=array('method'=>'GET', 'endpoint'=>'updateScripts', 'callback'=>'fah_updateScripts');
    $endpoints[]=array('method'=>'GET', 'endpoint'=>'installDependencies', 'callback'=>'fah_installDependencies'); //Querystring Parameters [optional stream=true/false]

    return $endpoints;
}


// GET /api/plugin/fpp-after-hours/deleteStream?deleteStream=<uid of stream>
function fah_deleteStream() {
    $fah=new fppAfterHours();
    $streams=$fah->config['streams'];
    foreach ($streams as $sKey=>$sData) {
        if ($sData['uid']==$_GET['deleteStream']) {
            unset($streams[$sKey]);
            $fah->config['streams']=$streams;
            $save=$fah->saveConfigFile();
            return json(array('status'=>$save));
        }
    }
    return json(array('status'=>false, 'data'=>'Could not locate uid'));
}

// POST /api/plugin/fpp-after-hours/updateStream
//    priority[<orderZeroIndexed>][<uid value>]
//         *all streams should be included in a call to priority with their new orders.  There is no error checking for mismatches.
//    all other settings need to have the "uid" key posted with the streams uid value
//    to create a new entry pass uid=0.  The new entry will be created at the bottom of the priority list
function fah_updateStream() {
    $fah=new fppAfterHours();
    $streams=$fah->config['streams'];

    if (isset($_POST['uid']) && $_POST['uid']==0) { //omits priority only requests that do not contain a uid key
        $active=$_POST['active'] ?? 0;
        $name=$_POST['name'] ?? '';
        $url=$_POST['url'] ?? '';
        $volume=$_POST['volume'] ?? 100;
        //$priority=$_POST['priority'] ?? 99999; //ignore this for now
        $priority=99999; //force new items to the end of the priority list
        if (trim($name) == '' || trim($url)=='') return json(array('status'=>false, 'data'=>'Must provide at minimum a name and url', 'post'=>$_POST));
        if (isset($streams[$name])) return json(array('status'=>false, 'data'=>'This stream name already exists'));

        //figure out the current highest priority item and add 1
        if ($priority==99999) {
            $lastPriority=0;
            foreach ($streams as $sKey=>$sData) {
                if ($sData['priority'] > $lastPriority) $lastPriority=$sData['priority'];
            }
            $priority=$lastPriority+1;
        }

        //get highest uid
        $uid=0;
        foreach ($streams as $sKey=>$sData) {
            if ($sData['uid'] > $uid) $uid=$sData['uid'];
        }
        $uid++;

        //add new stream to the streams array
        $streams[$name]=array('active'=>$active, 'url'=>$url, 'volume'=>$volume, 'priority'=>$priority, 'uid'=>$uid);
    }
    else {
        foreach ($_POST as $key=>$val) {
            switch ($key) {
                case 'priority':
                    foreach ($_POST['priority'] as $order=>$uid) {
                        foreach ($streams as $sKey=>$sData) {
                            if ($sData['uid']==$uid) {
                                $streams[$sKey]['priority']=$order+1;
                            }
                        }
                    }
                    break;

                case 'volume': $val=($val > 100 ? 100 : ($val < 0 ? 0 : $val));
                case 'active':
                case 'url':
                    foreach ($streams as $sKey=>$sData) {
                        if ($sData['uid']==$_POST['uid']) {
                            $streams[$sKey][$key]=$val;
                            break;
                        }
                    }
                    break;

                case 'name': //name is a key in the config streams array
                    foreach ($streams as $sKey=>$sData) {
                        if ($sData['uid']==$_POST['uid']) {
                            $streams[$val]=$streams[$sKey];
                            if ($sKey != $val) unset($streams[$sKey]); //delete old array only if the name has changed
                            break;
                        }
                    }
                    break;

            }
        }
    }
    $fah->config['streams']=$streams;
    $save=$fah->saveConfigFile();
    return json(array('status'=>$save));
}

// GET /api/plugin/fpp-after-hours/getStreams?[noPing=true/false]
//   noPing=true will not ping any stream
function fah_getStreams() {
    $fah=new fppAfterHours();
    if (count((array)$fah->config['streams'])) {
        $streams=array();
        foreach ($fah->config['streams'] as $streamName=>$streamData) {
            $streams[$streamData['priority']]=$streamData;
            $streams[$streamData['priority']]['streamName']=$streamName;

            if (is_numeric($streamData['active'])) $streams[$streamData['priority']]['active']=($streamData['active']==1 ? true : false); //handle old configs with integers for active states
            else if ($streamData['active']=='true') $streams[$streamData['priority']]['active']=true; //handle string to bool conversion
            else $streams[$streamData['priority']]['active']=false;

            if (!isset($_GET['noPing']) || (isset($_GET['noPing']) && $_GET['noPing']=='false')) $streams[$streamData['priority']]['ping']=($fah->pingInternetRadio($streamData['url'])===true ? true:false);
        }
        return json(array('status'=>true, 'count'=>count($streams), 'data'=>$streams));
    }
    return json(array('status'=>true, 'count'=>0, 'data'=>array()));
}

// GET /api/plugin/fpp-after-hours/getStreamPing?uid=x
function fah_getStreamPing() {
    $fah=new fppAfterHours();
    if (count((array)$fah->config['streams'])) {
        foreach ($fah->config['streams'] as $streamName=>$streamData) {
            if ($_GET['uid']==$streamData['uid']) return json(array('status'=>true, 'data'=>($fah->pingInternetRadio($streamData['url'])===true ? true:false)));
        }
        return json(array('status'=>false, 'data'=>'UID was not found'));
    }
    return json(array('status'=>false, 'data'=>'No streams were found'));
}

// GET /api/plugin/fpp-after-hours/start
function fah_start() {
    $fah=new fppAfterHours();
    include $fah->directories['scriptDirectory'].'fpp-after-hours-start.php';
    return json(array('status'=>true));
}

// GET /api/plugin/fpp-after-hours/stop
function fah_stop() {
    $fah=new fppAfterHours();
    include $fah->directories['scriptDirectory'].'fpp-after-hours-stop.php';
    return json(array('status'=>true));
}

// GET /api/plugin/fpp-after-hours/getDetails[?titleOnly=true/false]
function fah_getDetails() {
    $fah=new fppAfterHours();
    $npd=$fah->getNowPlayingDetail();
    $out['title']=($npd->title!==false ? $npd->title : "----No music is playing----");
    if ($_GET['titleOnly'] ?? false === true) return json(array('status'=>true, 'data'=>$out));

    $out['detail']=($npd->detail!==false ? $npd->detail : "");
    $out['musicIsRunning']=$fah->musicIsRunning;
    $out['musicShouldBeRunning']=$fah->musicShouldBeRunning;
    $out['dependenciesAreLoaded']=$fah->dependenciesAreLoaded;
    $out['mpdVolume']=$fah->getMPDvolume();
    $mpc=$fah->getMPCHash();
    if ($fah->musicShouldBeRunning && time() - $mpc->lastChangeTimestamp > 60)  {
        if ($mpc != $fah->checkIsMusicRunning(true)) $fah->setMPCHash($mpc); //refresh immediately until cron notices the change
        else $out['detail']="!!! Attempting restarts every minute until a stream is started !!!";
    }
    if (trim($npd->error) != '') $out['detail'].="<br>".$npd->error."<br>!!! Attempting to use another available stream or restarting this one every minute !!!";
    return json(array('status'=>true, 'data'=>$out));
}

// GET /api/plugin/fpp-after-hours/setMPDvolume?value=x
//      value a10 to increase by 10%, s10 to decrease by 10%, 50 to set to 50%
// *only changes the volume of the MPD player and only works when fpp-after-hours is in control of the audio
function fah_setMPDvolume() {
    $fah=new fppAfterHours();
    $fah->setMPDvolume($_GET['value']);
    return json(array('status'=>true, 'data'=>$fah->getMPDvolume()));
}


// GET /api/plugin/fpp-after-hours/updateScripts
function fah_updateScripts() {
    $errors=array();
    $fah=new fppAfterHours();

    if (!$fah->cronOkay) {
        $fah->updateCron();
        $fah->refreshCronOkayFlag();
        if (!$fah->cronOkay) {
            $errors[]="ERROR: Cron File could not be copied to cron.d";
        }
    }
  
    if (!$fah->scriptsOkay) {
        $fah->updateScripts();
        $fah->refreshScriptsOkayFlag();
        if (!$fah->scriptsOkay) {
            $errors[]="ERROR: script files could not be copied to fpp script directory";
        }
    }

    if (count($errors)) return json(array('status'=>false, 'data'=>$errors));
    return json(array('status'=>true));
}


// GET /api/plugin/fpp-after-hours/installDependencies[?stream=true/false]
//  *stream=true - returns a stream response instead of a typical api response (designed for use in the gui modal for live updates)
function fah_installDependencies() {
    $stream=$_GET['stream'] ?? true;
    $fah=new fppAfterHours();
    if ($stream) {
        $fah->installDependenciesStream();
    }
    else {
        $fah->installDependencies();
    }
    exit;
}
?>