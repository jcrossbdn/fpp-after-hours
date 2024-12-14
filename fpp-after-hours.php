<?php
echo <<<EOF
<style scoped>
  @media screen and (min-width: 0px) {
    .d-none { display: none!important; }
    .d-inline { display: inline; }
    .d-block { display: block; }
    .d-table-cell { display: table-cell!important; }
  }

  @media screen and (min-width: 992px) {
    .d-none { display: none!important; }
    .d-inline { display: inline; }
    .d-block { display: block; }
    .d-table-cell { display: table-cell!important; }
    .d-lg-none { display: none!important;}
    .d-lg-inline { display: inline; }
    .d-lg-block { display: block; }
    .d-lg-table-cell { display: table-cell!important; }
  }

  .textboxAsLabel {
    background: transparent!important;
    border: none!important;
    outline: none!important;
    padding: 0px 0px 0px 0px!important;
  }

  .redIcon {color:red}
</style>

<div id="fahDependencies" class="hidden">
  <div class="row">
    <div class="col-sm-12 col-md-8 offset-md-2">
        <div id="serviceStatus" class="alert alert-danger" style="text-align:center; color:black" role="alert">
          <h3>! ! ! Additional Software Must Be Installed ! ! !</h3>
          <p>will run <i>sudo apt update && sudo apt -y install mpd mpc</i></p>
          <button id="fahInstallDepends" class="buttons btn-rounded" onClick="fahDependsInstall()">
            <i class='fas fa-fw fa-play'></i>
            <span class="playerControlButton-text">Install</span>
          </button>
        </div>
    </div>
  </div>
</div>

<div id="fahReady" class="container show">
  <div id="updateAvailable" class="hidden">
    An update is available <a href='/plugins.php'>(Click here to go to plugin manager)</a>
  </div>
  <div class="row" style="min-height:200px;">
    <div class="col-sm-12 col-md-8 offset-md-2">
      <div id="fahNowPlaying" class="alert alert-secondary" style="text-align:center; color:black" role="alert">No music is playing</div>
    </div>
  </div>
  <div class="row col-sm-12 col-md-8 offset-md-2 offset-lg-4">
    <div id="btnStartDiv" class="col-3 col-sm-auto">
      <button id="btnStart" class="buttons btn-rounded btn-success disableButtons" onClick="fahStart();">
        <i class='fas fa-fw fa-play'></i>
        <span class="playerControlButton-text">Start</span>
      </button>
    </div>
    <div id="btnStopDiv" class="col-3 col-sm-auto">
      <button id="btnStop" class="buttons btn-rounded btn-success disableButtons" onClick="fahStop();">
        <i class='fas fa-fw fa-stop'></i>
        <span class="playerControlButton-text">Stop</span>
      </button>
    </div>
    <div id="slideVolumeDiv" class="col-6 col-sm-auto" style="text-align:center">
      <input id="slideVolume" type="range" min="0" max="100" value="100" step="1" disabled oninput="slideVolumeChange(this.value);" onchange="slideVolumeChange(this.value);" />
      <span id="slideVolumeLabel" class="text-muted">100</span>
    </div>
  </div>

  <div class="row col-sm-3 col-md-3 col-lg-2 offset-sm-9 offset-md-9 offset-lg-10">
    <button id="addStream" class="buttons btn-rounded btn-success" onclick="addEditStream(0);"><i class='icon fpp-icon-plus' style='cursor:pointer;'></i> Add Stream</button>
  </div>

  <div id="fahTabs" class="row"><br><hr><br></div>
  <div id="fahStreamsDiv" class="row">
    <form id="fahStreamsForm">
      <table id="fahStreamsTable" class="fppSelectableRowTable">
        <thead>
          <tr>
            <th>Order</th>
            <th>Active</th>
            <th>Stream Name</th>
            <th class="d-none d-lg-table-cell">Stream URL</th>
            <th class="d-none d-lg-table-cell">Volume</th>
            <th class="d-none d-lg-table-cell">Options</th>
          </tr>
        </thead>
        <tbody id="fahStreams">
        </tbody>
      </table>
      <br>
      The Miller Lights has graciously offered their holiday streaming service for use by the community.  Just add https://radio.themillerlights.com:8000/radio.mp3 as the stream url to use it<br>
      <a href='https://www.internet-radio.com/stations/christmas/#' target='_blank'>https://www.internet-radio.com/stations/christmas/#</a> has lots of radio stations (see help for instructions)</i>
    </form>
  </div>
</div>


<script>
  let slideVolumeChanged=false;
  let actualVolume=100;

  function loadInternetStreams() {
    $.ajax({
      type: "GET",
      dataType: "json",
      url: "/api/plugin/fpp-after-hours/getStreams?noPing", //load quickly with no ping, then load again with ping check and identify any failed streams
      success: function(data) {
        if (typeof data.status !== 'undefined') {
          if (data.count > 0) {
            $.each(data.data, function(i,item) {
              tr=`
                <tr id='priority_` + item.uid + `'>
                  <td class="enable-sortable">
                    <i class='rowGripIcon fpp-icon-grip' style='cursor:grab;'></i>
                    <input type='hidden' id='prio_` + item.uid + `' value='` + item.priority + `'>
                  </td>
                  <td>
                    <input class='streamData' type='checkbox' id='active_` + item.uid + `' uid='` + item.uid + `' style='cursor:pointer;' onclick='saveStreamData($(this))' name='stream_active'` + (item.active==true ? " checked" : "") + `>
                  </td>
                  <td>
                    <i id='fah_streamError_` + item.uid + `' class='fas fa-exclamation-triangle redIcon d-none' alt='Stream URL not respoding'></i>
                    <div class='d-table-cell d-lg-none' uid='` + item.uid + `' style='cursor:pointer;' onclick='addEditStream($(this))'>` + ((item.streamName).trim().length==0 ? "_" : item.streamName) + `</div>
                    <input type='text' id='streamName_` + item.uid + `' uid='` + item.uid + `' name='stream_name' oninput='growToShow($(this),true)' class='streamData textboxAsLabel d-none d-lg-table-cell' value='` + item.streamName + `'></input>
                  </td>
                  <td class='d-none d-lg-table-cell'>
                    <input type='text' id='url_` + item.uid + `' uid='` + item.uid + `' name='stream_URL' oninput='growToShow($(this),true)' class='streamData textboxAsLabel' value='` + item.url + `'></input>
                  </td>
                  <td class='d-none d-lg-table-cell'>
                    <input type='number' size=4 maxlength=3 id='volume_` + item.uid + `' uid='` + item.uid + `' name='stream_volume' oninput='saveStreamData($(this))' class='streamData textboxAsLabel' value='` + item.volume + `'>
                  </td>
                  <td class='d-none d-lg-table-cell'>
                    <div class='d-lg-inline' style='margin-left:10px;margin-right:15px;' uid='` + item.uid + `' onclick='addEditStream($(this))'><i class='icon fpp-icon-edit' style='cursor:pointer;'></i></div>
                    <div class='d-lg-inline' style='margin-left:10px;margin-right:15px;' uid='` + item.uid + `' onclick='deleteStream(` + item.uid + `)'><i class='icon fpp-icon-trash' style='cursor:pointer;'></i></div>
                  </td>
                </tr>
              `;
              if ($.trim(tr) != '') $("#fahStreamsTable tbody").append(tr);
              growToShow($("#streamName_"+item.uid),false);
              growToShow($("#url_"+item.uid),false);
            });

            $.ajax({
              type: "GET",
              dataType: "json",
              url: "/api/plugin/fpp-after-hours/getStreams", //get streams ping data
              success: function(data) {
                if (typeof data.status !== 'undefined') {
                  if (data.count > 0) {
                    $.each(data.data, function(i,item) {
                      if (item.ping == false) {
                        $("#fah_streamError_"+item.uid).removeClass("d-none");
                      }
                    })
                  }
                }
              }
            })

          }
        }
      }
    });
  }

  function deleteStream(uid) {
    if (confirm("Are you sure you want to delete stream " + $("#streamName_"+uid).val())) {
      $.ajax({
        type: "GET",
        dataType: "json",
        url: "/api/plugin/fpp-after-hours/deleteStream?deleteStream=" + uid,
        success: function(data) {
          location.reload();
        },
        error: function(data) {alert("Error deleting stream")}
      });
    }
    return false;
  }

  function addEditStream(thiss) { //modal editor window
    if (thiss != 0) { //pull original form data for editing from the underlaying dom
      uid=thiss.attr('uid');
      //priority=$("#prio_"+uid).val();
      active=($("#active_"+uid).prop('checked') ? true : false);
      name=$("#streamName_"+uid).val();
      aes_url=$("#url_"+uid).val();
      volume=$("#volume_"+uid).val();
      title="Edit stream<br>" + name;
    }
    else {
      uid=0;
      active=true;
      name="";
      aes_url="";
      volume=100;
      title="Add new stream";
    }

    var fahAESOptions = {
      id: "fahAddEditStream",
      title: title,
      body: `
        <input type='checkbox' id='Eactive' style='cursor:pointer;' ` + (active==true ? " checked" : "") + `> Active
        <br><br>
        Stream Name<br>
        <input type='text' style="width:100%" id='EstreamName' value='` + name + `'></input>
        <br><br>
        Stream URL<br>
        <input type='text' style="width:100%" id='Eurl' value='` + aes_url + `'></input>
        <br><br>
        Volume<br>
        <input id="Evolume" type="range" min="0" max="100" value="100" step="1" oninput="EvolumeChange(this.value);" onchange="EvolumeChange(this.value);" />
        <span id="EvolumeLabel" class="text-muted">100</span>
        <div id="fahAddEditStreamError" class="alert alert-danger d-none"></div>
      `,
      class: "modal-dialog-scrollable",
      noClose: true,
      keyboard: true,
      backdrop: "static",
      footer: ""
    };
    if (uid > 0) { //  EDIT EXISTING ENTRY
      fahAESOptions.buttons= {
        "Delete": {
          id: 'fahDependsCloseDialogButton',
          click: function() {
            deleteStream(uid);
          },
          disabled: false,
          class: 'btn-danger'
        },
        "Cancel": {
          id: 'fahDependsCloseDialogButton',
          click: function() {CloseModalDialog("fahAddEditStream"); location.reload();},
          disabled: false,
          class: 'btn-secondary'
        },
        "Save": {
          id: 'fahDependsCloseDialogButton',
          click: function() {
            postData={uid:uid};
            postData.active=($("#Eactive").prop('checked') ? true : false);
            postData.name=$("#EstreamName").val();
            postData.url=$("#Eurl").val();
            postData.volume=$("#Evolume").val();
            $.ajax({
              type: "POST",
              data: postData,
              dataType: "json",
              url: "/api/plugin/fpp-after-hours/updateStream",
              success: function(data) {
                CloseModalDialog("fahAddEditStream");
                location.reload();
              },
              error: function(data) {
                $("#fahAddEditStreamError").removeClass("d-none").html("Error updating stream data");
              }
            }); 
           
          },
          disabled: false,
          class: 'btn-success'
        }
      }

    }
    else { // CREATE NEW ENTRY
      fahAESOptions.buttons= {
        "Cancel": {
          id: 'fahDependsCloseDialogButton',
          click: function() {CloseModalDialog("fahAddEditStream"); location.reload();},
          disabled: false,
          class: 'btn-secondary'
        },
        "Create": {
          id: 'fahDependsCloseDialogButton',
          click: function() {
            postData={uid:uid};
            postData.active=($("#Eactive").prop('checked') ? true : false);
            postData.name=$("#EstreamName").val();
            postData.url=$("#Eurl").val();
            postData.volume=$("#Evolume").val();
            $.ajax({
              type: "POST",
              data: postData,
              dataType: "json",
              url: "/api/plugin/fpp-after-hours/updateStream",
              success: function(data) {
                if (data.status===false) {
                  //EnableModalDialogCloseButton("fahAddEditStream");
                  $("#fahAddEditStreamError").removeClass("d-none").html(data.data);
                }
                else {
                  CloseModalDialog("fahAddEditStream");
                  location.reload();
                }
              },
              error: function(data) {
                //EnableModalDialogCloseButton("fahAddEditStream");
                $("#fahAddEditStreamError").removeClass("d-none").html("Error creating stream data");
              }
            }); 
          },
          disabled: false,
          class: 'btn-success'
        }
      }

    }
    $("#fahAddEditStreamCloseDialogButton").prop("disabled", false);
    DoModalDialog(fahAESOptions);
    EvolumeChange(volume);
    $("#Evolume").val(volume);
  }

  function EvolumeChange(value) { //slider only for small screen edit modal
    $("#EvolumeLabel").html(value);
  }


  function growToShow(thiss, saveValueNow) { //wanted to call this growerShower but growToShow is more representative, haha
    if ((thiss.val()).length >= 20)  {
      let aid=thiss.attr('id');
      $('#' + thiss.attr('id')).attr('size',(((thiss.val()).length + 1)));
    }
    else $('#' + thiss.attr('id')).attr('size',20);

    if (saveValueNow===true) {
      saveStreamData(thiss);
    }
  }

  let pendingPingRequest='none'; //stores ajax instance for ping request so we can cancel previous queries if another update has been made before the first one completed
  function saveStreamData(thiss) {
    postData={uid:thiss.attr('uid')};
    switch (thiss.attr('name')) {
      case 'stream_active':
        postData.active=(thiss.prop('checked') ? true : false); break;
      case 'stream_name':
        postData.name=(thiss.val()); break;
      case 'stream_URL':
        postData.url=(thiss.val()); break;
      case 'stream_volume':
        postData.volume=(thiss.val()); break;
    }

    $.ajax({
      type: "POST",
      data: postData,
      dataType: "json",
      url: "/api/plugin/fpp-after-hours/updateStream",
      success: function(data) {
        pendingPingRequest=$.ajax({
          dataType: "json",
          url: "/api/plugin/fpp-after-hours/getStreamPing?uid=" + thiss.attr('uid'),
          beforeSend: function() {
            if (pendingPingRequest != 'none' && pendingPingRequest.readyState < 4) pendingPingRequest.abort();
          },
          success: function(data) {
            if (data.status==false) $("#fah_streamError_"+thiss.attr('uid')).removeClass("d-none");
            else {
              if (data.data==true) $("#fah_streamError_"+thiss.attr('uid')).addClass("d-none");
              else $("#fah_streamError_"+thiss.attr('uid')).removeClass("d-none");
            }
          },
          error: function(data,ajaxOptions,thrownError) {
            if (thrownError=='abort') return;
            $("#fah_streamError_"+thiss.attr('uid')).removeClass("d-none");
          }
        });
      },
      error: function(data) {
        alert ("Error updating stream data");
      }
    });
  }


  function fahStart() {
    $.ajax({
      type: "GET",
      dataType: "json",
      url: "/api/plugin/fpp-after-hours/start",
      success: function(data) {
        playNowControls(false,true,true,'','');
      },
      error: function(data) {alert("Error running script")}
    });
  }
  
  function fahStop() {
    $.ajax({
      type: "GET",
      dataType: "json",
      url: "/api/plugin/fpp-after-hours/stop",
      success: function(data) {
        playNowControls(true,false,false,'','');
      },
      error: function(data) {alert("Error running script")}
    });
  }

  function playNowControls(start,stop,volume,previous,next) { // true is enabled, false is disabled, '' is hidden
    let c=$("#btnStart");
    let d=$("#btnStartDiv");
    if (start==true && c.hasClass("disableButtons")) c.removeClass("disableButtons");
    if (start==false && !c.hasClass("disableButtons")) c.addClass("disableButtons");
    if (start==='' && !d.hasClass("hidden")) d.addClass("hidden");
    else if (d.hasClass("hidden")) d.removeClass("hidden");
    
    c=$("#btnStop");
    d=$("#btnStopDiv");
    if (stop==true && c.hasClass("disableButtons")) c.removeClass("disableButtons");
    if (stop==false && !c.hasClass("disableButtons")) c.addClass("disableButtons");
    if (stop==='' && !d.hasClass("hidden")) d.addClass("hidden");
    else if (d.hasClass("hidden")) d.removeClass("hidden");

    c=$("#slideVolume");
    d=$("#slideVolumeDiv");
    if (volume==true && c.prop("disabled")===true) c.prop("disabled",false);
    if (volume==false && c.prop("disabled")===false) c.prop("disabled",true);
    if (volume==='' && !d.hasClass("hidden")) d.addClass("hidden");
    else if (d.hasClass("hidden")) d.removeClass("hidden");
  }

  function fahGetDetails() {
    $.ajax({
      type: "GET",
      dataType: "json",
      url: "/api/plugin/fpp-after-hours/getDetails",
      success: function(data) {
        //console.log(data.data);
        if (typeof data.status !== 'undefined') {
          $("#fahNowPlaying").html("<h4>"+data.data.title+"</h4><p>"+data.data.detail+"</p>");
          if (data.data.musicIsRunning===true) {
            if ($("#fahNowPlaying").hasClass("alert-danger")) $("#fahNowPlaying").removeClass("alert-danger").addClass("alert-secondary");
            actualVolume=parseInt(data.data.mpdVolume);
            playNowControls(false,true,true,'','');
          }
          else if (data.data.musicIsRunning===false && data.data.musicShouldBeRunning===true) {
            if ($("#fahNowPlaying").hasClass("alert-secondary")) $("#fahNowPlaying").removeClass("alert-secondary").addClass("alert-danger");
            actualVolume=parseInt(data.data.mpdVolume);
            playNowControls(false,true,true,'','');
          }
          else if (data.data.musicIsRunning===false) {
            playNowControls(true,false,false,'','');
          }

          if (data.data.dependenciesAreLoaded===false) {
            if ($("#fahDependencies").hasClass("hidden")) $("#fahDependencies").removeClass("hidden").addClass("show");
            if ($("#fahReady").hasClass("show")) $("#fahReady").removeClass("show").addClass("hidden");
          }
          else {
            if ($("#fahDependencies").hasClass("show")) $("#fahDependencies").removeClass("show").addClass("hidden");
            if ($("#fahReady").hasClass("hidden")) $("#fahReady").removeClass("hidden").addClass("show");
          }
        }
      }
    });
  }

  function slideVolumeChange(value) { //only pushed once a second and smart updates the range slider based on current volume as necessary
    slideVolumeChanged=true;
    $("#slideVolumeLabel").html(value);
  }
  setInterval(function() { //handle range slider volume set and get
    if ($("#fahReady").hasClass("show")) {
      let slideVolume=parseInt($("#slideVolume").val());
      //console.log("Desired: " + slideVolume + " - Current: " + actualVolume + " - SVC: "+ (slideVolumeChanged==true ? "Yes" : "No"));
      if (slideVolumeChanged===true) { //send desired volume to mpd
        if (actualVolume != slideVolume) {
          $.ajax({
            type: "GET",
            dataType: "json",
            url: "/api/plugin/fpp-after-hours/setMPDvolume?value=" + slideVolume,
            success: function(data) {
              if (parseInt(data.data)==slideVolume)  {
                actualVolume=parseInt(data.data);
                slideVolumeChanged=false;
              }
            },
            error: function() {
              slideVolumeChanged=false;
            }
          });
        }
      }
      else if (actualVolume != slideVolume) {  //reset volume to what is current from mpd
        $("#slideVolumeLabel").html(actualVolume);
        $("#slideVolume").val(actualVolume);
      }
    }
  },1000);

  $(document).ready(function() {
    fahGetDetails(); //run immediately and then will refresh every 3 seconds

    $("#fahStreamsTable tbody").sortable({
      placeholder: "ui-state-highlight",
      handle: ".enable-sortable",
      stop: function(event,ui) {
        console.log($("#fahStreamsTable tbody").sortable('serialize'));
        $.ajax({
          type: "POST",
          data: $("#fahStreamsTable tbody").sortable('serialize'),
          dataType: "json",
          url: "/api/plugin/fpp-after-hours/updateStream",
          success: function(data) {
            //console.log(data);
          },
          error: function(data) {
            //console.log(data);
          }
        });
      }
    });
    loadInternetStreams();

    $.ajax({
      type: "GET",
      dataType: "json",
      url: "/api/plugin/fpp-after-hours/updateScripts",
      success: function(data) {
        console.log(data);
      }
    });

    $.ajax({
      type: "POST",
      dataType: "json",
      url: "/api/plugin/fpp-after-hours/updates,
      success: function(data) {
        if (data.Status=="OK") {
          if (data.updatesAvailable != 0) {
            $("#updateAvailable").show();
          }
        }
      }
    });
  });
  
  setInterval(function() {
    fahGetDetails()
  },3000);

  function fahDependsInstall() {
    var fahDIOptions = {
      id: "fahDependsInstall",
      title: "Installing fpp-after-hours additional software",
      body: "<textarea style='width: 99%; height: 500px;' disabled id='fahInstallProgress'></textarea>",
      class: "modal-dialog-scrollable",
      noClose: true,
      keyboard: false,
      backdrop: "static",
      footer: "",
      buttons: {
        "Close and Restart FPPD": {
          id: 'fahDependsCloseDialogButton',
          click: function() {
            $.ajax({
              type: "GET",
              dataType: "json",
              url: "/api/system/fppd/restart",
              success: function(data) {
              }
            });
            CloseModalDialog("fahDependsInstall"); location.reload();
          },
          disabled: true,
          class: 'btn-success'
        }
      }
    };
    $("#fahDependsCloseDialogButton").prop("disabled", true);
    DoModalDialog(fahDIOptions);
    StreamURL('/api/plugin/fpp-after-hours/installDependencies', 'fahInstallProgress', 'fahDependsInstallDone');
  }
  function fahDependsInstallDone() {
    $("#fahDependsCloseDialogButton").prop("disabled",false);
    //EnableModalDialogCloseButton("fahDependsInstall");  //we want to force the location.reload task so instead of hooking into the modal, just force the close button to be used
  }
</script>
EOF;
?>
