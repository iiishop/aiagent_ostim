<?php

require_once __DIR__ . "/../../lib/chat_helper_functions.php";

function aiagentNsfwSceneTrackerDefault()
{
    return [
        "active" => false,
        "session_id" => "",
        "started_gamets" => "",
        "started_unix" => 0,
        "player_name" => "",
        "actors" => [],
        "participants" => [],
        "stage_history" => [],
        "journal_baseline" => [],
        "journal_window" => 1200,
        "journal_floor_sk_date" => "",
    ];
}

function aiagentNsfwGetConfValue($id)
{
    $safeId = $GLOBALS["db"]->escape($id);
    $row = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id='{$safeId}'");
    if (!is_array($row) || !array_key_exists("value", $row)) {
        return null;
    }
    return $row["value"];
}

function aiagentNsfwSetConfValue($id, $value)
{
    $safeId = $GLOBALS["db"]->escape($id);
    $safeValue = $GLOBALS["db"]->escape($value);
    $exists = $GLOBALS["db"]->fetchOne("SELECT id FROM conf_opts WHERE id='{$safeId}'");
    if ($exists) {
        $GLOBALS["db"]->update("conf_opts", "value='{$safeValue}'", "id='{$safeId}'");
    } else {
        $GLOBALS["db"]->insert("conf_opts", ["id" => $id, "value" => $value]);
    }
}

function aiagentNsfwLoadSceneTracker()
{
    $raw = aiagentNsfwGetConfValue("AIAGENT_NSFW_PLAYER_SCENE_TRACKER");
    if (empty($raw)) {
        return aiagentNsfwSceneTrackerDefault();
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return aiagentNsfwSceneTrackerDefault();
    }
    return array_merge(aiagentNsfwSceneTrackerDefault(), $decoded);
}

function aiagentNsfwSaveSceneTracker(array $state)
{
    aiagentNsfwSetConfValue("AIAGENT_NSFW_PLAYER_SCENE_TRACKER", json_encode($state, JSON_UNESCAPED_UNICODE));
}

function aiagentNsfwLoadSceneReports()
{
    $raw = aiagentNsfwGetConfValue("AIAGENT_NSFW_SCENE_REPORTS");
    if (empty($raw)) {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    return array_values($decoded);
}

function aiagentNsfwSaveSceneReports(array $reports)
{
    aiagentNsfwSetConfValue("AIAGENT_NSFW_SCENE_REPORTS", json_encode(array_values($reports), JSON_UNESCAPED_UNICODE));
}

function aiagentNsfwAppendSceneReport(array $report)
{
    $reports = aiagentNsfwLoadSceneReports();
    array_unshift($reports, $report);
    $maxKeep = 120;
    if (count($reports) > $maxKeep) {
        $reports = array_slice($reports, 0, $maxKeep);
    }
    aiagentNsfwSaveSceneReports($reports);
}

function aiagentNsfwUniqueNames(array $names)
{
    $out = [];
    foreach ($names as $name) {
        $name = trim((string)$name);
        if ($name === "") {
            continue;
        }
        if (!in_array($name, $out, true)) {
            $out[] = $name;
        }
    }
    return $out;
}

function aiagentNsfwNormalizeName($name)
{
    $name = strtolower(trim((string)$name));
    $name = preg_replace('/\s+/', '', $name);
    $name = preg_replace('/[^a-z0-9_\-]/', '', $name);
    return $name;
}

function aiagentNsfwNameLooksLikePlayer($actorName, $playerName)
{
    $actorRaw = strtolower(trim((string)$actorName));
    $playerRaw = strtolower(trim((string)$playerName));
    if ($actorRaw === '' || $playerRaw === '') {
        return false;
    }

    if ($actorRaw === $playerRaw) {
        return true;
    }

    $actorNorm = aiagentNsfwNormalizeName($actorRaw);
    $playerNorm = aiagentNsfwNormalizeName($playerRaw);
    if ($actorNorm !== '' && $actorNorm === $playerNorm) {
        return true;
    }

    if ($playerNorm !== '' && strpos($actorNorm, $playerNorm) !== false) {
        return true;
    }

    if ($actorNorm === 'player' || $actorNorm === 'theplayer') {
        return true;
    }

    return false;
}

function aiagentNsfwHashJournalRow(array $row)
{
    $speaker = trim((string)($row["speaker"] ?? ""));
    $listener = trim((string)($row["listener"] ?? ""));
    $speech = trim((string)($row["speech"] ?? ""));
    $date = trim((string)($row["sk_date"] ?? ""));
    return md5($speaker . "|" . $listener . "|" . $speech . "|" . $date);
}

function aiagentNsfwCompareSkDate($a, $b)
{
    $a = trim((string)$a);
    $b = trim((string)$b);
    if ($a === $b) {
        return 0;
    }
    if ($a === "") {
        return -1;
    }
    if ($b === "") {
        return 1;
    }

    $aTs = strtotime($a);
    $bTs = strtotime($b);
    if ($aTs !== false && $bTs !== false) {
        return $aTs <=> $bTs;
    }

    return strcmp($a, $b);
}

function aiagentNsfwIsAfterSkDate($candidate, $floor)
{
    $floor = trim((string)$floor);
    if ($floor === "") {
        return true;
    }
    return aiagentNsfwCompareSkDate($candidate, $floor) > 0;
}

function aiagentNsfwCaptureSpeechBaseline(array $actors, $historyLimit = 1200)
{
    $baseline = [];
    if (!function_exists("DataSpeechJournal")) {
        return $baseline;
    }

    foreach (aiagentNsfwUniqueNames($actors) as $actor) {
        $rows = json_decode(DataSpeechJournal($actor, $historyLimit), true);
        if (!is_array($rows)) {
            $baseline[$actor] = [];
            continue;
        }
        $hashes = [];
        $lastSkDate = "";
        foreach ($rows as $row) {
            if (is_array($row)) {
                $hashes[] = aiagentNsfwHashJournalRow($row);
                $curSkDate = trim((string)($row["sk_date"] ?? ""));
                if ($curSkDate !== "" && aiagentNsfwCompareSkDate($curSkDate, $lastSkDate) > 0) {
                    $lastSkDate = $curSkDate;
                }
            }
        }
        $baseline[$actor] = [
            "hashes" => array_values(array_unique($hashes)),
            "last_sk_date" => $lastSkDate,
        ];
    }

    return $baseline;
}

function aiagentNsfwCollectSceneDialogue(array $actors, array $baseline, $historyLimit = 1200)
{
    $lines = [];
    if (!function_exists("DataSpeechJournal")) {
        return $lines;
    }

    $actors = aiagentNsfwUniqueNames($actors);
    $allowedSpeaker = array_flip($actors);
    $seq = 0;

    foreach ($actors as $actor) {
        $rows = json_decode(DataSpeechJournal($actor, $historyLimit), true);
        if (!is_array($rows)) {
            continue;
        }

        $baselineSet = [];
        $baselineEntry = $baseline[$actor] ?? [];
        $baselineHashes = [];
        $baselineSkDate = "";

        if (is_array($baselineEntry)) {
            if (isset($baselineEntry["hashes"]) && is_array($baselineEntry["hashes"])) {
                $baselineHashes = $baselineEntry["hashes"];
                $baselineSkDate = trim((string)($baselineEntry["last_sk_date"] ?? ""));
            } else {
                // backward compatibility for old tracker data format
                $baselineHashes = $baselineEntry;
            }
        }

        if (!empty($baselineHashes)) {
            $baselineSet = array_flip($baselineHashes);
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $hash = aiagentNsfwHashJournalRow($row);
            if (isset($baselineSet[$hash])) {
                continue;
            }

            $speaker = trim((string)($row["speaker"] ?? ""));
            $listener = trim((string)($row["listener"] ?? ""));
            $skDate = trim((string)($row["sk_date"] ?? ""));
            $speech = trim((string)($row["speech"] ?? ""));
            if ($speaker === "" || $speech === "") {
                continue;
            }

            if (!isset($allowedSpeaker[$speaker])) {
                continue;
            }

            if ($listener !== "" && !isset($allowedSpeaker[$listener])) {
                continue;
            }

            if (!aiagentNsfwIsAfterSkDate($skDate, $baselineSkDate)) {
                continue;
            }

            $lines[] = [
                "sk_date" => $skDate,
                "speaker" => $speaker,
                "listener" => $listener,
                "speech" => preg_replace('/\s+/', ' ', $speech),
                "seq" => $seq++,
                "hash" => $hash,
            ];
        }
    }

    $unique = [];
    $deduped = [];
    foreach ($lines as $line) {
        if (isset($unique[$line["hash"]])) {
            continue;
        }
        $unique[$line["hash"]] = true;
        $deduped[] = $line;
    }

    usort($deduped, function ($a, $b) {
        $aDate = $a["sk_date"];
        $bDate = $b["sk_date"];
        if ($aDate === $bDate) {
            return $a["seq"] <=> $b["seq"];
        }
        if ($aDate === "") {
            return 1;
        }
        if ($bDate === "") {
            return -1;
        }
        return strcmp($aDate, $bDate);
    });

    return $deduped;
}

function aiagentNsfwTrackPlayerSceneStage($stageId, $sceneDescription, $hasCustomDescription, array $sexTags, array $orderedActorList, $gameTs)
{
    $player = trim((string)($GLOBALS["PLAYER_NAME"] ?? ""));
    $actors = aiagentNsfwUniqueNames($orderedActorList);
    if ($player === "" || empty($actors)) {
        return;
    }

    $playerMatchedName = null;
    foreach ($actors as $actorName) {
        if (aiagentNsfwNameLooksLikePlayer($actorName, $player)) {
            $playerMatchedName = $actorName;
            break;
        }
    }

    if ($playerMatchedName === null) {
        return;
    }

    $state = aiagentNsfwLoadSceneTracker();
    if (empty($state["active"])) {
        $participants = array_values(array_filter($actors, function ($name) use ($playerMatchedName) {
            return $name !== $playerMatchedName;
        }));

        $state = aiagentNsfwSceneTrackerDefault();
        $state["active"] = true;
        $state["session_id"] = uniqid("ostim_", true);
        $state["started_gamets"] = (string)$gameTs;
        $state["started_unix"] = time();
        $state["player_name"] = $playerMatchedName;
        $state["actors"] = array_merge([$playerMatchedName], $participants);
        $state["participants"] = $participants;
        $state["journal_window"] = 1200;
        $state["journal_baseline"] = aiagentNsfwCaptureSpeechBaseline($state["actors"], $state["journal_window"]);
        $floorSkDate = "";
        foreach ($state["journal_baseline"] as $entry) {
            if (is_array($entry)) {
                $entrySkDate = trim((string)($entry["last_sk_date"] ?? ""));
                if ($entrySkDate !== "" && aiagentNsfwCompareSkDate($entrySkDate, $floorSkDate) > 0) {
                    $floorSkDate = $entrySkDate;
                }
            }
        }
        $state["journal_floor_sk_date"] = $floorSkDate;

        $startEvent = $GLOBALS["gameRequest"];
        $startEvent[0] = "ext_nsfw_scene_start";
        $startEvent[3] = "# PLAYER OSTIM SCENE STARTED: participants=" . implode(", ", $participants);
        logEvent($startEvent);
    } else {
        $mergedActors = aiagentNsfwUniqueNames(array_merge($state["actors"] ?? [], $actors));
        $state["actors"] = $mergedActors;
        $state["participants"] = array_values(array_filter($mergedActors, function ($name) use ($playerMatchedName) {
            return $name !== $playerMatchedName;
        }));
    }

    $newStage = [
        "stage" => (string)$stageId,
        "description" => trim((string)$sceneDescription),
        "has_db_description" => (bool)$hasCustomDescription,
        "tags" => array_values($sexTags),
        "actors" => $actors,
        "gamets" => (string)$gameTs,
    ];

    $history = $state["stage_history"] ?? [];
    $last = end($history);
    if (!is_array($last) || (($last["stage"] ?? "") !== $newStage["stage"])) {
        $history[] = $newStage;
        $state["stage_history"] = $history;
    }

    aiagentNsfwSaveSceneTracker($state);
}

function aiagentNsfwHandlePlayerSceneEnd(array $endedActors, $gameTs)
{
    $state = aiagentNsfwLoadSceneTracker();
    if (empty($state["active"])) {
        return;
    }

    $player = trim((string)($state["player_name"] ?? ($GLOBALS["PLAYER_NAME"] ?? "")));
    $endedActors = aiagentNsfwUniqueNames($endedActors);
    $knownActors = aiagentNsfwUniqueNames($state["actors"] ?? []);
    if (!in_array($player, $knownActors, true)) {
        $knownActors[] = $player;
    }

    $participants = aiagentNsfwUniqueNames(array_values(array_filter(array_merge($state["participants"] ?? [], $endedActors), function ($name) use ($player) {
        return $name !== $player;
    })));

    $actorsForDialogue = aiagentNsfwUniqueNames(array_merge([$player], $participants));
    $historyWindow = (int)($state["journal_window"] ?? 1200);
    if ($historyWindow < 200) {
        $historyWindow = 200;
    }
    $baseline = $state["journal_baseline"] ?? [];
    $floorSkDate = trim((string)($state["journal_floor_sk_date"] ?? ""));
    if ($floorSkDate !== "") {
        foreach ($actorsForDialogue as $actorName) {
            if (!isset($baseline[$actorName]) || !is_array($baseline[$actorName])) {
                $baseline[$actorName] = ["hashes" => [], "last_sk_date" => $floorSkDate];
                continue;
            }
            if (!isset($baseline[$actorName]["hashes"])) {
                $baseline[$actorName] = ["hashes" => $baseline[$actorName], "last_sk_date" => $floorSkDate];
                continue;
            }
            if (empty($baseline[$actorName]["last_sk_date"])) {
                $baseline[$actorName]["last_sk_date"] = $floorSkDate;
            }
        }
    }

    $dialogue = aiagentNsfwCollectSceneDialogue($actorsForDialogue, $baseline, $historyWindow);
    $stages = $state["stage_history"] ?? [];

    $report = [];
    $report[] = "# PLAYER OSTIM SCENE END";
    $report[] = "session_id=" . ($state["session_id"] ?? "unknown");
    $report[] = "participants_excluding_player=" . (empty($participants) ? "(none)" : implode(", ", $participants));
    $report[] = "";
    $report[] = "## Stage progression";
    if (empty($stages)) {
        $report[] = "(no stage data captured)";
    } else {
        $idx = 1;
        foreach ($stages as $stageItem) {
            $stage = trim((string)($stageItem["stage"] ?? ""));
            $desc = trim((string)($stageItem["description"] ?? ""));
            $hasDbDesc = !empty($stageItem["has_db_description"]);
            $line = $idx . ". " . ($stage !== "" ? $stage : "(unknown stage)");
            if ($desc !== "") {
                $line .= " | " . ($hasDbDesc ? "db_desc=" : "fallback_desc=") . $desc;
            }
            $report[] = $line;
            $idx++;
        }
    }

    $report[] = "";
    $report[] = "## Dialogue timeline (player + participants)";
    if (empty($dialogue)) {
        $report[] = "(no incremental dialogue captured from speech journal during this scene)";
    } else {
        $maxLines = 120;
        $count = 0;
        foreach ($dialogue as $line) {
            $speaker = $line["speaker"];
            $listener = $line["listener"] !== "" ? (" -> " . $line["listener"]) : "";
            $date = $line["sk_date"] !== "" ? ("[" . $line["sk_date"] . "] ") : "";
            $speech = $line["speech"];
            $report[] = ($count + 1) . ". " . $date . $speaker . $listener . ": " . $speech;
            $count++;
            if ($count >= $maxLines) {
                $report[] = "... (truncated at {$maxLines} lines)";
                break;
            }
        }
    }

    $reportText = implode("\n", $report);

    $reportItem = [
        "id" => uniqid("scene_report_", true),
        "session_id" => ($state["session_id"] ?? "unknown"),
        "started_gamets" => ($state["started_gamets"] ?? ""),
        "ended_gamets" => (string)$gameTs,
        "started_unix" => (int)($state["started_unix"] ?? 0),
        "ended_unix" => time(),
        "player_name" => $player,
        "participants" => array_values($participants),
        "participants_count" => count($participants),
        "stage_count" => count($stages),
        "dialogue_count" => count($dialogue),
        "stages" => array_values($stages),
        "dialogue" => array_values($dialogue),
        "report_text" => $reportText,
    ];

    aiagentNsfwAppendSceneReport($reportItem);
    aiagentNsfwSetConfValue("AIAGENT_NSFW_LAST_SCENE_REPORT", $reportText);

    $endEvent = $GLOBALS["gameRequest"];
    $endEvent[0] = "ext_nsfw_scene_end";
    $endEvent[3] = $reportText;
    logEvent($endEvent);

    aiagentNsfwSaveSceneTracker(aiagentNsfwSceneTrackerDefault());
}

/*
Post process info from lugin events:

    * ext_nsfw_sexcene
    * chatnf_sl_end
    * chatnf_sl_naked
    * chatnf_sl_climax
    * chatnf_sl_moan
    * ext_nsfw_action

*/

function processInfoSexScene()
{
    global $gameRequest;

    if ($gameRequest[0] == "ext_nsfw_sexcene") {
        // Parse info_sexscene data
        // Arrok Standing Foreplay/["Loving", "Standing", "LeadIn", "kissing", "Vaginal", "Penis", "Mouth", "Foreplay", "BBP", "Arrok", "FM", "MF"]/Arrok_StandingForeplay_A1_S1/Acto1Æctor2
        error_log("Rewriting info_sexscene data {$gameRequest[3]}");
        $infoSexSceneParts = explode("/", $gameRequest[3]);
        $sexSceneName      = $infoSexSceneParts[0];
        $sexTags           = explode(",", strtolower($infoSexSceneParts[1]));
        $sexStageName      = strtr($infoSexSceneParts[2], ["_A1" => ""]);
        $actorInfos        = array_slice($infoSexSceneParts, 3);

        $priority = $GLOBALS["PLAYER_NAME"];
        usort($actorInfos, function ($a, $b) use ($priority) {
            return ($a === $priority ? 1 : 0) + ($b === $priority ? -1 : 0);
        });

        $orderedActorList = [];

        foreach (array_reverse($actorInfos) as $actorinfo) {
            if (! empty($actorinfo)) {
                $orderedActorList[] = $actorinfo;
            }
        }

        error_log("[AIAGENTNSFW] Erotic Scene. Actors" . json_encode($orderedActorList));

        foreach ($actorInfos as $actor) {
            $intimacyStatus = getIntimacyForActor(($actor));
            if (in_array("idle", $sexTags)) {
                $intimacyStatus["level"] = 1;
            } else {
                $intimacyStatus["level"] = 2;
            }

            updateIntimacyForActor(($actor), $intimacyStatus);
        }

        error_log("Searching for description $sexStageName");

        // Fill descriptions

        $sceneDescription = findRowByFirstColumn(__DIR__ . "/scene_descriptions.csv", $sexStageName);
        $hasCustomDescription = !empty($sceneDescription);
        if (! $sceneDescription) {
            $sceneDescription = "{actor0},{actor1},{actor2},{actor3},{actor4} are having an intimate moment";
        }
        $sceneDescriptionParsed = preg_replace_callback('/\{actor(\d+)\}/', function ($matches) use ($orderedActorList) {
            $index = (int) $matches[1];
            return $orderedActorList[$index] ?? $matches[0]; // fallback to original if key not found
        }, $sceneDescription);
        $cleanedSceneDesc = preg_replace('/\{actor\d+\}/', '', $sceneDescriptionParsed);

        aiagentNsfwTrackPlayerSceneStage(
            $sexStageName,
            $cleanedSceneDesc,
            $hasCustomDescription,
            $sexTags,
            $orderedActorList,
            $gameRequest[2] ?? ""
        );

        // Rewrite data
        $GLOBALS["gameRequest"][3]         = "#INTIMATE SCENE: $cleanedSceneDesc. Scene tags:" . implode(",", $sexTags);
        $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
        logEvent($GLOBALS["gameRequest"]);

    } else if ($gameRequest[0] == "chatnf_sl_end") {
        // Set level to 0, this affects voice hook modifications

        error_log("[AIAGENT_NSFW] {$gameRequest[3]}");
        // Result
        $sceneResultParts = explode("/", $gameRequest[3]);
        $scoringPart      = array_slice($sceneResultParts, 1);
        $scoring          = [];
        $sceneActors = [];
        foreach ($scoringPart as $part) {
            $actorResult = explode("@", $part);
            $actorName = trim((string)($actorResult[0] ?? ""));
            $actorScore = trim((string)($actorResult[1] ?? ""));
            if ($actorName !== "") {
                $sceneActors[] = $actorName;
                updateIntimacyForActor($actorName, ["level" => 0, "sex_disposal" => 10, "orgasmed" => false]);
            }
            if ($actorName !== "") {
                $scoring[] = $actorName . " satisfaction score: " . ($actorScore === "" ? "n/a" : $actorScore);
            }
        }
        $actor = $GLOBALS["HERIKA_NAME"];
        updateIntimacyForActor($actor, ["level" => 0, "sex_disposal" => 10, "orgasmed" => false]);

        // Overwrite prompt
        $GLOBALS["PROMPTS"]["chatnf_sl_end"]["player_request"] = ["The Narrator: " . implode(",", $scoring)];
        $GLOBALS["PATCH_PROMPT_ENFORCE_ACTIONS"]               = false;
        $GLOBALS["COMMAND_PROMPT_ENFORCE_ACTIONS"]             = "";

        aiagentNsfwHandlePlayerSceneEnd($sceneActors, $gameRequest[2] ?? "");

    } else if ($gameRequest[0] == "chatnf_sl_naked") {
        $actor                      = $GLOBALS["HERIKA_NAME"];
        $intimacyStatus             = getIntimacyForActor($actor);
        $intimacyStatus["is_naked"] = 2;
        updateIntimacyForActor($actor, $intimacyStatus);

    } else if ($gameRequest[0] == "chatnf_sl_climax") {

        $actor          = $GLOBALS["HERIKA_NAME"];
        $intimacyStatus = getIntimacyForActor($actor);

        if (isset($intimacyStatus["orgasm_generated"]) && $intimacyStatus["orgasm_generated"] && isset($intimacyStatus["orgasm_generated_text"])) {
            // We have used GASP. Let's use it.

            //echo "{$actor}|ScriptQueue|".trim(unmoodSentence($intimacyStatus["orgasm_generated_text"]))."////\r\n";
            
            // We force here the response.

            if ($GLOBALS["AIAGENT_NSFW"]["USE_GASP"]) {
                echo "{$actor}|ScriptQueue|" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text_original"])) . "////" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text"])) . "\r\n";
                error_log("[AIAGENT-NSFW] Climax from orgasm_generated_text_original");
            } else {
                echo "{$actor}|ScriptQueue|" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text"])) . "////" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text"])) . "\r\n";
                error_log("[AIAGENT-NSFW] Climax from orgasm_generated_text");
                //echo "{$actor}|ScriptQueue|" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text_original"])) . "////" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text_original"])) . "\r\n";
            }

            $intimacyStatus["orgasm_generated"]               = false;
            $intimacyStatus["orgasm_generated_text"]          = "";
            $intimacyStatus["orgasm_generated_text_original"] = "";

            updateIntimacyForActor($actor, $intimacyStatus);
            $GLOBALS["gameRequest"][0]="infaction";
            $GLOBALS["gameRequest"][3]="$actor had an orgasm";
            logEvent($GLOBALS["gameRequest"]);

            terminate();

        } else {
            // NPC will generate response via standard prompt
            error_log("[AIAGENT-NSFW] Climax from llm_request should happend");
        }

    } else if ($gameRequest[0] == "chatnf_sl_moan") {

        $randomMoans = ["...Ahh ... Ohh..", "Yeah oh...yes", "... Mmmh ... ", "... Ahmmm ...", "..Ouch!... "];
        $moan        = $randomMoans[array_rand($randomMoans)];
        returnLines([$moan]);

        $actor          = $GLOBALS["HERIKA_NAME"];
        $intimacyStatus = getIntimacyForActor($actor);
        if (! isset($intimacyStatus["orgasm_generated"]) || $intimacyStatus["orgasm_generated"] == false) {
            generateClimaxSpeech();

        } else {
            error_log("Orgams sound already generated");

        }

        //logEvent($GLOBALS["gameRequest"]); Don't log, chat will do
        terminate();

    } else if ($gameRequest[0] == "ext_nsfw_action") {

        // Just log the information

        $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
        logEvent($GLOBALS["gameRequest"]);

    }

}

function processInfoFertility()
{
    global $gameRequest;

    if ($gameRequest[0] == "fertility_notification") {
        $actor = $GLOBALS["HERIKA_NAME"];

        $npcManager = new NpcMaster();
        $npcData    = $npcManager->getByName($actor);

        if (! $npcData) {
            $npcData = $npcManager->getByName(ucFirst(strtolower($actor)));
        }
        $extended = json_decode($npcData["extended_data"], true);

        $subCmd = explode("@", $gameRequest[3]);
        if ($subCmd[1] == "pregnant") {
            $extended["fertility_is_pregnant"] = true;
        } else if ($subCmd[1] == "aborted") {
            $extended["fertility_is_pregnant"] = false;
        } else if ($subCmd[1] == "birth") {
            $extended["fertility_is_pregnant"] = false;
            $extended["fertility_recent_birth"] = $gameRequest[2];
        }

        $npcData["extended_data"] = json_encode($extended);
        $npcData["gamets_last_updated"]=$gameRequest[2];
        $npcManager->updateByArray($npcData);

        $gameRequest[0]="info";
        logEvent($gameRequest);
        terminate();
    }
    
}

function getIntimacyForActor($actorName)
{

    $npcManager = new NpcMaster();
    $npcData    = $npcManager->getByName($actorName);
    if (! $npcData) {
        $npcData = $npcManager->getByName(ucFirst(strtolower($actorName)));
    }
    if (isset($npcData["extended_data"])) {
        $extended = json_decode($npcData["extended_data"], true);
    } else {
        $extended = [];
    }

    if (isset($extended["aiagent_nsfw_intimacy_data"]) && isNonEmptyArray($extended["aiagent_nsfw_intimacy_data"])) {
        $intimacyStatus = $extended["aiagent_nsfw_intimacy_data"];

    } else {
        $intimacyStatus = ["level" => 0, "sex_disposal" => 0];
    }

    return $intimacyStatus;
}

/*
 Make AI aware this NPC has given birth a child recently
*/

function setBirthPrompt($actorName)
{
    $GLOBALS["HERIKA_PERSONALITY"].="\nImportant: {$actorName} had a child recently, (out of context, check 'Baby' item on inventory/equipment, this means $actorName is carrying the baby";
}

function setSexSpeechStyle($actorName)
{

    $npcManager = new NpcMaster();
    $npcData    = $npcManager->getByName($actorName);
    if (! $npcData) {
        $npcData = $npcManager->getByName(ucFirst(strtolower($actorName)));
    }
    if (isset($npcData["extended_data"])) {
        $extended = json_decode($npcData["extended_data"], true);
    } else {
        $extended = [];
    }

    if (isset($extended["sex_speech_style"]) && ! empty($extended["sex_speech_style"])) {
        $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n#Sex Expressions\n" . $extended["sex_speech_style"];

    }
}

/*
Custom prompt added when in intimacy scene
extended_data->sex_prompt
*/

function setSexPrompt($actorName)
{

    $npcManager = new NpcMaster();
    $npcData    = $npcManager->getByName($actorName);
    if (! $npcData) {
        $npcData = $npcManager->getByName(ucFirst(strtolower($actorName)));
    }

    if (isset($npcData["extended_data"])) {
        $extended = json_decode($npcData["extended_data"], true);
    } else {
        $extended = [];
    }

    if (isset($extended["sex_prompt"]) && ! empty($extended["sex_prompt"])) {
        $GLOBALS["HERIKA_PERSONALITY"] .= "\n#Personality (sex scenes)\n" . $extended["sex_prompt"];

    }
}

function updateIntimacyForActor($actorName, $idata)
{

    if ($actorName == $GLOBALS["PLAYER_NAME"]) {
        return;
    }

    error_log("[AIAGENTNSFW] Updating intimacy for $actorName. " . json_encode($idata));

    $currentIntimacy = getIntimacyForActor($actorName);
    $npcManager      = new NpcMaster();
    $npcData         = $npcManager->getByName($actorName);
    $metadata       = $npcManager->getMetadata($npcData);

    if (! $npcData) {
        $npcData = $npcManager->getByName(ucFirst(strtolower($actorName)));
    }

    
    $extended = json_decode($npcData["extended_data"], true);

    // Update timestamp on level change
    if ($extended["aiagent_nsfw_intimacy_data"]["level"]!=$idata["level"]) {
        $npcData["gamets_last_updated"]=$GLOBALS["gameRequest"][2];
        error_log("[AIAGENTNSFW] Updating timestamp as level changed");
    } else {
        error_log("[AIAGENTNSFW] No change {$extended["aiagent_nsfw_intimacy_data"]["level"]} {$idata["level"]}");
    }

    if (isset($extended["aiagent_nsfw_intimacy_data"]) && isNonEmptyArray($extended["aiagent_nsfw_intimacy_data"])) {
        $extended["aiagent_nsfw_intimacy_data"] = array_merge($extended["aiagent_nsfw_intimacy_data"], $idata);
    } else {
        $extended["aiagent_nsfw_intimacy_data"] = $idata;
    }

    

    // Naked check from inventory
    if (!empty($metadata["equipment"]["armor"])) {
        if  ($extended["aiagent_nsfw_intimacy_data"]["is_naked"]==2) {
            if ($extended["aiagent_nsfw_intimacy_data"]["is_naked_check"]>2) {
                $extended["aiagent_nsfw_intimacy_data"]["is_naked"]=0;
                $extended["aiagent_nsfw_intimacy_data"]["is_naked_check"]=0;
                error_log("[AIAGENTNSFW] Forcing no naked for $actorName because is_naked_check");

            } else {
                $extended["aiagent_nsfw_intimacy_data"]["is_naked_check"]++;
                error_log("[AIAGENTNSFW] Naked check {$extended["aiagent_nsfw_intimacy_data"]["is_naked_check"]}");
            }

        }
    }

    $npcData["extended_data"] = json_encode($extended);
    $npcManager->updateByArray($npcData);

}

function saveAllDisposals()
{
    error_log("[AIAGENT NSFW] saveAllDisposals is deprecated");
    return;
    audit_log(__FILE__ . " [AIAGENT NSFW]  " . __LINE__);
    $data       = $GLOBALS["db"]->fetchAll("select * from conf_opts where id like '%_intimacy'");
    $datatoSave = [];
    foreach ($data as $rowactor) {
        $datatoSave[] = $rowactor;
    }

    $GLOBALS["db"]->upsertRowOnConflict(
        "conf_opts",
        [
            "id"    => "aiagent_nsfw_intimacy",
            "value" => json_encode($datatoSave),
        ],
        'id'
    );
    audit_log(__FILE__ . " [AIAGENT NSFW]  " . __LINE__);

}

function loadAllDisposals()
{
    error_log("[AIAGENT NSFW] loadAllDisposals is deprecated");
    return;

    audit_log(__FILE__ . " [AIAGENT NSFW]  " . __LINE__);

    $GLOBALS["db"]->execQuery("delete  from conf_opts where id like '%_intimacy'");

    $savedData     = $GLOBALS["db"]->fetchOne("select value from conf_opts where id like 'aiagent_nsfw_intimacy'");
    $savedDataFull = [];

    if ($savedData) {
        $savedDataFull = json_decode($savedData["value"], true);

    }

    if (is_array($savedDataFull)) {
        foreach ($savedDataFull as $actorIntimacyData) {
            $GLOBALS["db"]->upsertRowOnConflict(
                "conf_opts",
                [
                    "id"    => $actorIntimacyData["id"],
                    "value" => $actorIntimacyData["value"],
                ],
                'id'
            );
        }
    }

    audit_log(__FILE__ . " [AIAGENT NSFW]  " . __LINE__);

}

/*
Calculates intimacy disposal based on moods issued when talking.
*/

function getSexDisposalFromMood($actorName, $currentGamets)
{

    $playerNameE = $GLOBALS["db"]->escape($GLOBALS["PLAYER_NAME"]);
    $actorNameE  = $GLOBALS["db"]->escape($actorName);

    $sdQuery = "
    WITH mood_scores AS (
    SELECT
        speaker,
        listener,
        mood,
        CASE
            WHEN mood = 'playful' THEN 1
            WHEN mood = 'seductive' THEN 1
            WHEN mood = 'sexy' THEN 1
            WHEN mood = 'aroused' THEN 1
            WHEN mood = 'sensual' THEN 1
            WHEN mood = 'flirty' THEN 1
            WHEN mood = 'lovely' THEN 1
            WHEN mood = 'loving' THEN 1
            WHEN mood = 'drunk' THEN 1
            WHEN mood = 'tipsy' THEN 1
            WHEN mood = 'irritated' THEN -2
            WHEN mood = 'grumpy' THEN -1
            ELSE 0
        END AS sex_disposal_speech,gamets
    FROM public.moods_issued
    WHERE mood IS NOT NULL
    and speaker like '$actorNameE'
    and (listener like '$playerNameE' or 1=1)
    and ($currentGamets-gamets)<(7/ 0.0000024)
    order by gamets DESC
    limit 100
)
SELECT
    speaker,
    listener,
    SUM(sex_disposal_speech) AS total_sentiment,
    COUNT(*) AS interactions,
    ROUND(AVG(sex_disposal_speech), 2) AS avg_sentiment,
    MIN(gamets) AS gamets_from,
    MAX(gamets) AS gamets_to
FROM mood_scores
GROUP BY speaker, listener
ORDER BY total_sentiment DESC";

    $statData = $GLOBALS["db"]->fetchOne($sdQuery);
    error_log("[AIAGENT NSFW] Mood speech analisys: $actorName: " . json_encode($statData));
    if (isNonEmptyArray($statData)) {
        return $statData["avg_sentiment"];

    }

    return 0;

}

function getLastIssuedMood($actorName, $currentGamets, $timeFrameLimit = 5)
{

    $playerNameE = $GLOBALS["db"]->escape($GLOBALS["PLAYER_NAME"]);
    $actorNameE  = $GLOBALS["db"]->escape($actorName);

    $sdQuery = "
    select *
    FROM public.moods_issued
    WHERE mood IS NOT NULL
    and speaker like '$actorNameE'
    and ($currentGamets-gamets)<(1/ 0.0000024*$timeFrameLimit)
    order by gamets DESC
    limit 1";
    $statData = $GLOBALS["db"]->fetchOne($sdQuery);
    error_log("Last mood for $actorName: " . json_encode($statData) . "<$sdQuery>");
    if (isNonEmptyArray($statData)) {
        return $statData["mood"];

    }

    return "";

}

function findRowByFirstColumn($filePath, $searchValue)
{
    $trlField="a.description";
    
    if (isset($GLOBALS["CORE_LANG"])&&!empty($GLOBALS["CORE_LANG"])) {
        if ($GLOBALS["CORE_LANG"]=="es") {
            $trlField="COALESCE(a.description_es,a.description)";
        }
    } 

    $desc=$GLOBALS["db"]->fetchOne("SELECT a.*,$trlField  as final_desc FROM public.ext_aiagentnsfw_scenes a where stage ilike '$searchValue'");
    if (isset($desc["final_desc"])) {
        error_log("Found description for $searchValue!");
        return strtolower($desc["final_desc"]);
    }

    // Insert for further description
    if (!isset($desc["stage"])) {
        $desc=$GLOBALS["db"]->insert("public.ext_aiagentnsfw_scenes",["stage"=>$searchValue]);
    }

    return null; // No match found
}

function findRowByFirstColumnOld($filePath, $searchValue)
{
    if (($fh = fopen($filePath, 'r')) === false) {
        return null;
    }

    $header = fgetcsv($fh, 0, ",", '"', '\\'); // Read and skip header
    while (($row = fgetcsv($fh, 0, ",", '"', '\\')) !== false) {

        if (trim(mb_strtolower($row[0])) === trim(mb_strtolower($searchValue))) {
            error_log("Found description for $searchValue!");
            fclose($fh);
            return $row[1];
        }
    }

    fclose($fh);
    return null; // No match found
}

// Process info from  info_sexscene and chatnf_sl_end event. Will rewrite context info entry.

function gasper($original_speech, $moan, $sourceaudio, $sourcevoiceaudio)
{

    //based on $moan
    $moanfile = "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/cough.wav";
    //$moanTranscription="Ahhm Ahm Ahm mmm Ah Ah mm ";

    $moanLibrary = [

        ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxD1.wav"],
        ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxE1.wav"],
        ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxE2.wav"],
        ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxE4.wav"],
        ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxE5.wav"],
        ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxE6.wav"],
        ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxF1.wav"],
        ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxG1.wav"],
        ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxA1.wav"],
        ["transcription" => true, "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxA2.wav"],
        ["transcription" => true, "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxA3.wav"],

    ];
    $selectedIndex = rand(0, sizeof($moanLibrary) - 1);
    //$selectedIndex=sizeof($moanLibrary)-1;

    if (isset($GLOBALS["AIAGENT_NSFW"]["USE_GASP"]) && $GLOBALS["AIAGENT_NSFW"]["USE_GASP"]) {
        $tempfile = "/tmp/" . uniqid() . ".wav";
        $command  = "/usr/local/bin/gasp $sourceaudio {$moanLibrary[$selectedIndex]["file"]} \"$original_speech\" $tempfile";

        $output = shell_exec($command);
        error_log("[GASP] Command output: " . $output);
        error_log("[GASP] Source {$moanLibrary[$selectedIndex]["file"]}, Out file: " . $tempfile);

        // Step 1: Remove double dots
        $input = str_replace("..", " ", trim($output));

        // Step 2: Define substitution patterns (order matters: longest to shortest)
        $patterns = [
            '/AAaa/' => 'AAah',
            '/aaAA/' => 'aaAH',
            '/AAAA/' => 'AAAA', // in case you want to map that differently
            '/AA/'   => 'AH',
            '/aaaa/' => 'Aaah',
            '/aa/'   => 'Ah',
        ];

        // Step 3: Apply replacements
        $output = preg_replace(array_keys($patterns), array_values($patterns), $input);
        $output .= "  $original_speech";

        $finalPseudoPhonetic = $output;

        $finalPseudoPhonetic = trim(unmoodSentence($finalPseudoPhonetic));
    } else {
        $tempfile    = "/tmp/" . uniqid() . ".wav";
        $sourceaudio =
        $command     = "/usr/local/bin/gasp /opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/silence.wav {$moanLibrary[$selectedIndex]["file"]} \"$original_speech\" $tempfile";

        $output = shell_exec($command);
        error_log("[GASP] Command output: " . $output);
        error_log("[GASP] Out file: " . $tempfile);

        // Step 1: Remove double dots
        $input = str_replace("..", " ", trim($output));

        // Step 2: Define substitution patterns (order matters: longest to shortest)
        $patterns = [
            '/AAaa/' => 'AAah',
            '/aaAA/' => 'aaAH',
            '/AAAA/' => 'AAAA', // in case you want to map that differently
            '/AA/'   => 'AH',
            '/aaaa/' => 'Aaah',
            '/aa/'   => 'Ah',
        ];

        // Step 3: Apply replacements
        $output = preg_replace(array_keys($patterns), array_values($patterns), $input);

        $finalPseudoPhonetic = $output;

        $finalPseudoPhonetic = trim(unmoodSentence($finalPseudoPhonetic));

    }

    error_log("[GASP] finalPseudoPhonetic: $finalPseudoPhonetic");

    if (! file_exists($tempfile)) {
        error_log("[GASP] Source audio file not found: $tempfile");
    }
    if (! file_exists($sourcevoiceaudio)) {
        error_log("[GASP] Reference audio file not found: $sourcevoiceaudio");
    }

    $sourceAudioPath    = realpath($tempfile);
    $referenceAudioPath = realpath($sourcevoiceaudio);

    if (! $sourceAudioPath || ! $referenceAudioPath) {
        error_log("[GASP] File path resolution failed.");
    }

    // Check if files actually exist and are readable
    if (! file_exists($sourceAudioPath) || ! is_readable($sourceAudioPath)) {
        error_log("[GASP] Source audio file not accessible: " . $sourceAudioPath);
    }
    if (! file_exists($referenceAudioPath) || ! is_readable($referenceAudioPath)) {
        error_log("[GASP] Reference audio file not accessible: " . $referenceAudioPath);
    }

    $tempResfile = $GLOBALS["ENGINE_PATH"] . "/soundcache/" . md5($finalPseudoPhonetic) . ".wav";

    $original_speech_cleaned = trim(unmoodSentence($original_speech));
    $tempResfile2            = $GLOBALS["ENGINE_PATH"] . "/soundcache/" . md5($original_speech_cleaned) . ".wav";

    copy($tempfile, $tempResfile);
    //copy($tempfile,$tempResfile2);

    error_log("[GASP] $tempResfile saved successfully.");

    return $finalPseudoPhonetic;

}

//  Guess if player is naked
function playerIsNaked()
{

    audit_log(__FILE__ . " [AIAGENT NSFW]  " . __LINE__);

    $val = $GLOBALS["db"]->fetchOne("select value from conf_opts where id='player_naked'");
    if ($val["value"] == 1) {
        return true;

    }

    return false;
}

function generateClimaxSpeech()
{

    $actor          = $GLOBALS["HERIKA_NAME"];
    $intimacyStatus = getIntimacyForActor($actor);

    error_log("[GASP] $actor");

    if (! isset($intimacyStatus["orgasm_generated"]) || $intimacyStatus["orgasm_generated"] == false) {

        error_log("Generating gasped orgasm sound");

        $historyData  = "";
        $lastPlace    = "";
        $lastListener = "";
        $lastDateTime = "";

                                            // Determine how much context history to use for dynamic profiles
        $dynamicProfileContextHistory = 50; // Default value
        if (isset($GLOBALS["CONTEXT_HISTORY_DYNAMIC_PROFILE"]) && $GLOBALS["CONTEXT_HISTORY_DYNAMIC_PROFILE"] > 0) {
            $dynamicProfileContextHistory = $GLOBALS["CONTEXT_HISTORY_DYNAMIC_PROFILE"];
        } elseif (isset($GLOBALS["CONTEXT_HISTORY"]) && $GLOBALS["CONTEXT_HISTORY"] > 0) {
            $dynamicProfileContextHistory = $GLOBALS["CONTEXT_HISTORY"];
        }

        foreach (json_decode(DataSpeechJournal($GLOBALS["HERIKA_NAME"], $dynamicProfileContextHistory), true) as $element) {
            if ($element["listener"] == "The Narrator") {
                continue;
            }
            if ($lastListener != $element["listener"]) {

                $listener     = " (talking to {$element["listener"]})";
                $lastListener = $element["listener"];
            } else {
                $listener = "";
            }

            if ($lastPlace != $element["location"]) {
                $place     = " (at {$element["location"]})";
                $lastPlace = $element["location"];
            } else {
                $place = "";
            }

            if ($lastDateTime != substr($element["sk_date"], 0, 15)) {
                $date         = substr($element["sk_date"], 0, 10);
                $time         = substr($element["sk_date"], 11);
                $dateTime     = "(on date {$date} at {$time})";
                $lastDateTime = substr($element["sk_date"], 0, 15);
            } else {
                $dateTime = "";
            }

            $historyData .= trim("{$element["speaker"]}:" . trim($element["speech"]) . " $listener $place $dateTime") . PHP_EOL;

        }

        $partyConf  = DataGetCurrentPartyConf();
        $partyConfA = json_decode($partyConf, true);
        Logger::debug($partyConf);
        // Use the global DYNAMIC_PROMPT
        $updateProfilePrompt = "Write a VERY SHORT sentence (3/4 words) for {$GLOBALS["HERIKA_NAME"]}
        to say when she/he reaches Climax based on Dialogue history, and {$GLOBALS["PLAYER_NAME"]} requests,stuttering. Read  {$GLOBALS["HERIKA_NAME"]} character sheet to figure out her sexual preferences.";
        // Database Prompt (Dynamic Profile Head)
        $head[] = ["role" => "system", "content" => "You are an NSFW writer.  "];

        // Use centralized function from data_functions.php
        $currentDynamicProfile = buildDynamicProfileDisplay();

        $prompt[]    = ["role" => "user", "content" => "Current character profile you are generating content for:\n" . "Character name:\n" . $GLOBALS["HERIKA_NAME"] . "\nCharacter static biography:\n" . $GLOBALS["HERIKA_PERS"] . "\n" . $currentDynamicProfile];
        $prompt[]    = ["role" => "user", "content" => "* Dialogue history:\n" . $historyData];
        $prompt[]    = ["role" => "user", "content" => $updateProfilePrompt];
        $contextData = array_merge($head, $prompt);

        if (isset($GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"])) {
            $connector         = new LLMConnector();
            $connectionHandler = $connector->getConnector($GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"]);
            error_log("[CORE SYSTEM] Using new profile system {$GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"]["driver"]}/{$GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"]["model"]}");
        } else {
            error_log("No connector defined");
            return;
        }

        $GLOBALS["FORCE_MAX_TOKENS"] = 50;
        $buffer                      = $connectionHandler->fast_request($contextData, ["max_tokens" => 50], "aiagent_nsfw");

        $original_speech = " ... Ohh .. " . (strtr(trim($buffer), ['"' => '', "{$GLOBALS["HERIKA_NAME"]}:" => ""]));

        $GLOBALS["PATCH_DONT_STORE_SPEECH_ON_DB"] = true;
        unset($GLOBALS["HOOKS"]["XTTS_TEXTMODIFIER"]);

        $GLOBALS["HOOKS"]["XTTS_TEXTMODIFIER"][] = function ($text) {

            $randomStrings = ["  ", "  "];
            $result        = $text;

            // Generate a random index
            $randomIndex = mt_rand(0, count($randomStrings) - 1);

            // Split the sentence into an array of words
            $words = explode(' ', $text);

            // Select a random word index to insert the random string
            $wordIndex = mt_rand(0, count($words) - 1);

            // Insert the random string into the selected word
            $randomWord     = $words[$wordIndex];
            $insertPosition = strpos($result, $randomWord);
            $result         = substr_replace($result, $randomStrings[$randomIndex], $insertPosition, 0);
            error_log("Applying text modifier for XTTS (speed=>0.6) $text => $result " . __FILE__);

            xtts_fastapi_settings(["temperature" => 1, "speed" => 0.6, "enable_text_splitting" => false, "top_p" => 1, "top_k" => 100], true);
            return $result;

        };
        returnLines([$original_speech], false);
        $generatedFile = end($GLOBALS["TRACK"]["FILES_GENERATED"]);

        $intimacyStatus["orgasm_generated"]               = true;
        $intimacyStatus["orgasm_generated_text"]          = $original_speech;
        $intimacyStatus["orgasm_generated_text_original"] = trim(unmoodSentence($original_speech));

        updateIntimacyForActor($actor, $intimacyStatus);
    } else {
        error_log("Orgams sound already generated");

    }
}
