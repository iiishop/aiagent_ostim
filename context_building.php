<?php 

// Current historic context data here. $GLOBALS["CONTEXT_BUILDING_DATA"]
// This is called when build NPC context, we can filter here our custom eventypes

foreach ($GLOBALS["CONTEXT_BUILDING_DATA"] as $n => $line) {
    if ($line["role"] == "ext_nsfw_scene") {
        $GLOBALS["CONTEXT_BUILDING_DATA"][$n]["role"] = "user";
        
    } else  if ($line["role"] == "ext_nsfw_scene_start") {
        $GLOBALS["CONTEXT_BUILDING_DATA"][$n]["role"] = "user";

    } else  if ($line["role"] == "ext_nsfw_scene_end") {
        $GLOBALS["CONTEXT_BUILDING_DATA"][$n]["role"] = "user";

    } else  if ($line["role"] == "ext_nsfw_sexcene") {
        $GLOBALS["CONTEXT_BUILDING_DATA"][$n]["role"] = "user";
        
    } else  if ($line["role"] == "ext_nsfw_action") {
        $GLOBALS["CONTEXT_BUILDING_DATA"][$n]["role"] = "user";
    } 
}
?>
