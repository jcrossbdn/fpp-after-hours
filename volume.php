<?php
if (isset($_GET['vup'])) exec('mpc volume +1');
else exec('mpc volume -1');
header("Location: ?plugin=fpp-after-hours&page=fpp-after-hours.php");
?>