<?php

$pages = Array(
	'name' => "After Hours Music",
	'type' => "content",
	'page' => "fpp-after-hours.php");

?>

<li><a href="<?php echo preg_replace('/.*\/plugins\/(.*)/', 'plugin.php?plugin=$1&page=fpp-after-hours.php', dirname(__FILE__)); ?>">After Hours Music</a></li>
