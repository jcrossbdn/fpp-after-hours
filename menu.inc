<?php
# FPP After Hours menu entries (one per menu area, per PLUGIN_GUIDELINES §9.1).
# FPP sets $plugin (this plugin's folder) and $menu (the menu area being drawn).
$menuEntries = array(
    array('text' => 'After Hours Music', 'type' => 'content', 'page' => 'fpp-after-hours.php', 'wrap' => 1),
    array('text' => 'After Hours Music', 'type' => 'help', 'page' => 'help/fpp-after-hours.php', 'wrap' => 1),
);
foreach ($menuEntries as $entry) {
    if ($entry['type'] != $menu) {
        continue;
    }
    $nopage = (isset($entry['wrap']) && $entry['wrap'] == 0) ? '&nopage=1' : '';
    printf("<li><a href='plugin.php?plugin=%s&page=%s%s'>%s</a></li>\n", $plugin, $entry['page'], $nopage, $entry['text']);
}
?>
