<?php
exec('mpc current',$song);
exec("mpc",$status);
$status=implode("<br>",$status);
if (isset($song[0])) $status=str_replace($song[0]."<br>","",$status);
if (isset($song[0])) echo "{$song[0]}<hr>{$status}";
else echo "----No stream playing----";
?>