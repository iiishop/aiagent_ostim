<?php
    // Common Includes
    $enginePath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
    require_once $enginePath . "conf" . DIRECTORY_SEPARATOR . "conf.php";
    require_once $enginePath . "lib" . DIRECTORY_SEPARATOR . "model_dynmodel.php";
    require_once $enginePath . "lib" . DIRECTORY_SEPARATOR . "{$GLOBALS["DBDRIVER"]}.class.php";
    require_once $enginePath . "lib" . DIRECTORY_SEPARATOR . "chat_helper_functions.php";
    require_once $enginePath . "lib" . DIRECTORY_SEPARATOR . "data_functions.php";
    require_once $enginePath . "lib" . DIRECTORY_SEPARATOR . "logger.php";
    require_once $enginePath . "lib" . DIRECTORY_SEPARATOR . "utils_game_timestamp.php";
    require_once $enginePath . "lib" . DIRECTORY_SEPARATOR . "rolemaster_helpers.php";
    require_once $enginePath . "lib" . DIRECTORY_SEPARATOR . "dynamic_update_util.php";
    $GLOBALS["ENGINE_PATH"] = $enginePath;

    // Global DB object
    $db = new sql();

    require_once $enginePath . "lib/core/npc_master.class.php";
    require_once $enginePath . "lib/core/api_badge.class.php";
    require_once $enginePath . "lib/core/core_profiles.class.php";
    require_once $enginePath . "lib/core/llm_connector.class.php";
    require_once $enginePath . "lib/core/tts_connector.class.php";

    require_once $enginePath . "lib" . DIRECTORY_SEPARATOR . "lazy_xml.php";

    // Global DB object
    $db            = new sql();
    $GLOBALS["db"] = $db;

    // Handle AJAX requests
    $action = $_GET['action'] ?? null;

    if ($action === 'read') {
        handleRead();
    } elseif ($action === 'create') {
        handleCreate();
    } elseif ($action === 'update') {
        handleUpdate();
    } elseif ($action === 'delete') {
        handleDelete();
    } elseif ($action === 'loadNPCs') {
        handleLoadNPCs();
    } elseif ($action === 'loadConnectors') {
        handleLoadConnectors();
    } elseif ($action === 'getNPCStatus') {
        handleGetNPCStatus();
    } elseif ($action === 'submitToolsForm') {
        handleSubmitToolsForm();
    } elseif ($action === 'loadSettings') {
        handleLoadSettings();
    } elseif ($action === 'saveSettings') {
        handleSaveSettings();
    } elseif ($action === 'generateTable') {
        handleGenerateTable();
    } elseif ($action === 'importData') {
        handleImportData();
    }

    // CRUD Functions
    function handleImportData()
    {
        try {
            if (!isset($_FILES['importFile']) || $_FILES['importFile']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('No file uploaded or upload error');
            }

            $file = $_FILES['importFile']['tmp_name'];
            if (!is_readable($file)) {
                throw new Exception('Cannot read uploaded file');
            }

            $lines = file($file, FILE_SKIP_EMPTY_LINES);
            if (empty($lines)) {
                throw new Exception('File is empty');
            }

            // Parse header row
            $headerLine = trim($lines[0]);
            $headers = str_getcsv($headerLine, "\t", '"');
            $headers = array_map('trim', $headers);

            if (empty($headers) || !in_array('stage', $headers)) {
                throw new Exception('Invalid file format: missing "stage" field in header');
            }

            $importedCount = 0;
            $skippedCount = 0;
            $errors = [];

            // Process data rows
            for ($i = 1; $i < count($lines); $i++) {
                try {
                    $dataLine = trim($lines[$i]);
                    if (empty($dataLine)) {
                        continue;
                    }

                    $values = str_getcsv($dataLine, "\t", '"');
                    $values = array_map('trim', $values);

                    if (count($values) !== count($headers)) {
                        $skippedCount++;
                        continue;
                    }

                    // Create associative array
                    $row = array_combine($headers, $values);

                    // Handle \N as NULL
                    foreach ($row as $key => $value) {
                        if ($value === '\N' || $value === 'NULL' || $value === 'null') {
                            $row[$key] = null;
                        }
                    }

                    if (empty($row['stage'])) {
                        $skippedCount++;
                        continue;
                    }

                    // Only include valid columns
                    $validColumns = ['stage', 'description', 'description_es', 'description_en', 'i_desc'];
                    $insertData = [];
                    foreach ($validColumns as $col) {
                        if (isset($row[$col])) {
                            $insertData[$col] = $row[$col];
                        }
                    }

                    // Insert or update the row
                    try {
                        $GLOBALS["db"]->insert('ext_aiagentnsfw_scenes', $insertData);
                        $importedCount++;
                    } catch (Exception $insertError) {
                        // Check if it's a duplicate key error, if so try updating
                        if (strpos($insertError->getMessage(), 'duplicate') !== false || 
                            strpos($insertError->getMessage(), 'unique') !== false ||
                            strpos($insertError->getMessage(), 'already exists') !== false) {
                            
                            $set = [];
                            foreach (['description', 'description_es', 'description_en', 'i_desc'] as $col) {
                                if (isset($insertData[$col])) {
                                    $val = is_null($insertData[$col]) ? 'NULL' : "'" . $GLOBALS["db"]->escape($insertData[$col]) . "'";
                                    $set[] = "$col=$val";
                                }
                            }

                            if (!empty($set)) {
                                $setStr = implode(', ', $set);
                                $where = "stage='" . $GLOBALS["db"]->escape($insertData['stage']) . "'";
                                $GLOBALS["db"]->update('ext_aiagentnsfw_scenes', $setStr, $where);
                                $importedCount++;
                            } else {
                                $skippedCount++;
                            }
                        } else {
                            throw $insertError;
                        }
                    }
                } catch (Exception $rowError) {
                    $errors[] = "Row " . ($i + 1) . ": " . $rowError->getMessage();
                }
            }

            $message = "Import completed. Imported/Updated: $importedCount, Skipped: $skippedCount";
            if (!empty($errors)) {
                $message .= ". Errors: " . implode("; ", array_slice($errors, 0, 5));
                if (count($errors) > 5) {
                    $message .= " (and " . (count($errors) - 5) . " more)";
                }
            }

            echo json_encode([
                'success' => true,
                'message' => $message,
                'imported' => $importedCount,
                'skipped' => $skippedCount,
                'errors' => $errors,
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error'   => $e->getMessage(),
            ]);
        }
        exit;
    }
    function handleGenerateTable()
    {
        try {
            $sql = <<<SQL
CREATE TABLE IF NOT EXISTS public.ext_aiagentnsfw_scenes (
    stage text NOT NULL,
    description text,
    description_es text,
    description_en text,
    i_desc text
);

ALTER TABLE public.ext_aiagentnsfw_scenes OWNER TO dwemer;

COMMENT ON TABLE public.ext_aiagentnsfw_scenes IS 'ostim scenes descriptions';

ALTER TABLE ONLY public.ext_aiagentnsfw_scenes
    ADD CONSTRAINT ext_aiagentnsfw_scenes_pkey PRIMARY KEY (stage);
SQL;

            // Execute the SQL statement
            $GLOBALS["db"]->query($sql);

            echo json_encode([
                'success' => true,
                'message' => 'Table ext_aiagentnsfw_scenes created successfully',
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error'   => $e->getMessage(),
            ]);
        }
        exit;
    }
    function handleRead()
    {
        try {
            $query   = "SELECT * FROM ext_aiagentnsfw_scenes ORDER BY description asc nulls first,stage";
            $results = $GLOBALS["db"]->fetchAll($query);
            echo json_encode([
                'success' => true,
                'data'    => $results,
            ]);
        } catch (Exception $e) {
            // Check if the table exists
            $tableExistsQuery = "SELECT EXISTS (
                SELECT FROM information_schema.tables 
                WHERE table_schema = 'public' 
                AND table_name = 'ext_aiagentnsfw_scenes'
            )";
            
            $tableExists = false;
            try {
                $result = $GLOBALS["db"]->fetchOne($tableExistsQuery);
                $tableExists = isset($result[0]) ? (bool)$result[0] : false;
            } catch (Exception $checkException) {
                // If we can't check, assume table doesn't exist
                $tableExists = false;
            }
            
            $errorMessage = $e->getMessage();
            if (!$tableExists) {
                $errorMessage .= ' [TABLE NOT FOUND: Please create the ext_aiagentnsfw_scenes table first using the "Generate ext_aiagentnsfw_scenes Table" button in Settings]';
            }
            
            echo json_encode([
                'success' => false,
                'error'   => $errorMessage,
                'tableExists' => $tableExists,
            ]);
        }
        exit;
    }

    function handleCreate()
    {
        try {
            $stage          = $_POST['stage'] ?? '';
            $description    = $_POST['description'] ?? '';
            $description_es = $_POST['description_es'] ?? '';
            $description_en = $_POST['description_en'] ?? '';
            $i_desc         = $_POST['i_desc'] ?? '';

            if (empty($stage)) {
                throw new Exception('Stage is required');
            }

            $data = [
                'stage'          => $stage,
                'description'    => $description,
                'description_es' => $description_es,
                'description_en' => $description_en,
                'i_desc'         => $i_desc,
            ];

            $GLOBALS["db"]->insert('ext_aiagentnsfw_scenes', $data);

            echo json_encode([
                'success' => true,
                'message' => 'Scene created successfully',
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error'   => $e->getMessage(),
            ]);
        }
        exit;
    }

    function handleUpdate()
    {
        try {
            $stage          = $_POST['stage'] ?? '';
            $description    = $_POST['description'] ?? '';
            $description_es = $_POST['description_es'] ?? '';
            $description_en = $_POST['description_en'] ?? '';
            $i_desc         = $_POST['i_desc'] ?? '';

            if (empty($stage)) {
                throw new Exception('Stage is required');
            }

            $set = "description='" . $GLOBALS["db"]->escape($description) . "', " .
            "description_es='" . $GLOBALS["db"]->escape($description_es) . "', " .
            "description_en='" . $GLOBALS["db"]->escape($description_en) . "', " .
            "i_desc='" . $GLOBALS["db"]->escape($i_desc) . "'";

            $where = "stage='" . $GLOBALS["db"]->escape($stage) . "'";

            $GLOBALS["db"]->update('ext_aiagentnsfw_scenes', $set, $where);

            echo json_encode([
                'success' => true,
                'message' => 'Scene updated successfully',
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error'   => $e->getMessage(),
            ]);
        }
        exit;
    }

    function handleDelete()
    {
        try {
            $stage = $_POST['stage'] ?? '';

            if (empty($stage)) {
                throw new Exception('Stage is required');
            }

            $where = "stage='" . $GLOBALS["db"]->escape($stage) . "'";
            $GLOBALS["db"]->delete('ext_aiagentnsfw_scenes', $where);

            echo json_encode([
                'success' => true,
                'message' => 'Scene deleted successfully',
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error'   => $e->getMessage(),
            ]);
        }
        exit;
    }

    function handleLoadNPCs()
    {
        try {
            $search = trim($_GET['q'] ?? '');
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
            if ($limit < 1) {
                $limit = 1;
            }
            if ($limit > 100) {
                $limit = 100;
            }

            if ($search !== '') {
                $escapedSearch = $GLOBALS["db"]->escape(strtolower($search));
                $query = "
                    SELECT id, npc_name, gamets_last_updated, extended_data
                    FROM core_npc_master
                    WHERE LOWER(npc_name) LIKE '%{$escapedSearch}%'
                    ORDER BY npc_name ASC
                    LIMIT {$limit}
                ";
            } else {
                $query = "
                    SELECT id, npc_name, gamets_last_updated, extended_data
                    FROM core_npc_master
                    ORDER BY COALESCE(gamets_last_updated, 0) DESC, npc_name ASC
                    LIMIT {$limit}
                ";
            }

            $results = $GLOBALS["db"]->fetchAll($query);
            $formatted = [];

            foreach ($results as $row) {
                $sexDisposal = null;
                $intimacyLevel = null;

                if (!empty($row['extended_data'])) {
                    $extendedData = json_decode($row['extended_data'], true);
                    if (
                        is_array($extendedData)
                        && isset($extendedData['aiagent_nsfw_intimacy_data'])
                        && is_array($extendedData['aiagent_nsfw_intimacy_data'])
                    ) {
                        $sexDisposal = $extendedData['aiagent_nsfw_intimacy_data']['sex_disposal'] ?? null;
                        $intimacyLevel = $extendedData['aiagent_nsfw_intimacy_data']['level'] ?? null;
                    }
                }

                $formatted[] = [
                    'id' => $row['id'],
                    'npc_name' => $row['npc_name'],
                    'gamets_last_updated' => $row['gamets_last_updated'] ?? null,
                    'sex_disposal' => $sexDisposal,
                    'level' => $intimacyLevel,
                ];
            }

            echo json_encode([
                'success' => true,
                'search' => $search,
                'data'    => $formatted,
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error'   => $e->getMessage(),
            ]);
        }
        exit;
    }

    function handleLoadConnectors()
    {
        try {
            $query   = "SELECT id, label FROM core_llm_connector ORDER BY label";
            $results = $GLOBALS["db"]->fetchAll($query);
            echo json_encode([
                'success' => true,
                'data'    => $results,
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error'   => $e->getMessage(),
            ]);
        }
        exit;
    }

    function handleGetNPCStatus()
    {
        try {
            $npcId = trim($_GET['npc_id'] ?? '');
            if ($npcId === '') {
                throw new Exception('npc_id is required');
            }

            $id = (int)$npcId;
            if ($id < 1) {
                throw new Exception('Invalid npc_id');
            }

            $query = "
                SELECT id, npc_name, gamets_last_updated, extended_data
                FROM core_npc_master
                WHERE id = {$id}
                LIMIT 1
            ";
            $row = $GLOBALS["db"]->fetchOne($query);

            if (!$row) {
                throw new Exception('NPC not found');
            }

            $status = [
                'sex_disposal' => null,
                'level' => null,
                'is_naked' => null,
                'orgasmed' => null,
                'orgasm_generated' => null,
                'orgasm_generated_text' => null,
                'orgasm_generated_text_original' => null,
                'adult_entertainment_services_autodetected' => null,
            ];

            if (!empty($row['extended_data'])) {
                $extendedData = json_decode($row['extended_data'], true);
                if (
                    is_array($extendedData)
                    && isset($extendedData['aiagent_nsfw_intimacy_data'])
                    && is_array($extendedData['aiagent_nsfw_intimacy_data'])
                ) {
                    $intimacy = $extendedData['aiagent_nsfw_intimacy_data'];
                    $status['sex_disposal'] = $intimacy['sex_disposal'] ?? null;
                    $status['level'] = $intimacy['level'] ?? null;
                    $status['is_naked'] = $intimacy['is_naked'] ?? null;
                    $status['orgasmed'] = $intimacy['orgasmed'] ?? null;
                    $status['orgasm_generated'] = $intimacy['orgasm_generated'] ?? null;
                    $status['orgasm_generated_text'] = $intimacy['orgasm_generated_text'] ?? null;
                    $status['orgasm_generated_text_original'] = $intimacy['orgasm_generated_text_original'] ?? null;
                    $status['adult_entertainment_services_autodetected'] = $intimacy['adult_entertainment_services_autodetected'] ?? null;
                }
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'id' => $row['id'],
                    'npc_name' => $row['npc_name'],
                    'gamets_last_updated' => $row['gamets_last_updated'] ?? null,
                    'status' => $status,
                ],
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
        exit;
    }

    function handleSubmitToolsForm()
    {
        try {
            $npcId = isset($_POST["npc_id"]) ? (int)$_POST["npc_id"] : 0;
            if ($npcId < 1) {
                throw new Exception('Invalid NPC id');
            }

            $npcMaster = new NpcMaster();
            $currentNpcData = $npcMaster->getById($npcId);
            if (!$currentNpcData) {
                throw new Exception('NPC not found');
            }

            $extended_data=$npcMaster->getExtendedData($currentNpcData);
            if (!is_array($extended_data)) {
                $extended_data = [];
            }

            $extended_data["sex_prompt"]=$_POST["sex_prompt"] ?? '';
            $extended_data["sex_speech_style"]=$_POST["sex_speech_style"] ?? '';

            $sexDisposalInput = isset($_POST['sex_disposal']) ? trim((string)$_POST['sex_disposal']) : '';
            if ($sexDisposalInput !== '') {
                $sexDisposal = (int)$sexDisposalInput;
                if ($sexDisposal < -1) {
                    $sexDisposal = -1;
                }
                if ($sexDisposal > 100) {
                    $sexDisposal = 100;
                }

                if (!isset($extended_data['aiagent_nsfw_intimacy_data']) || !is_array($extended_data['aiagent_nsfw_intimacy_data'])) {
                    $extended_data['aiagent_nsfw_intimacy_data'] = [];
                }
                $extended_data['aiagent_nsfw_intimacy_data']['sex_disposal'] = $sexDisposal;
            }

            $currentNpcData=$npcMaster->setExtendedData($currentNpcData,$extended_data);
            $npcMaster->updateByArray($currentNpcData);

            echo json_encode([
                'success' => true,
                'message' => 'NPC prompt and intimacy settings saved',
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error'   => $e->getMessage(),
            ]);
        }
        exit;
    }

    function handleLoadSettings()
    {
        try {
            $settingsRow = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_settings'");
            
            $settings = [
                'XTTS_MODIFY_LEVEL1' => false,
                'XTTS_MODIFY_LEVEL2' => false,
                'GENERIC_GLOSSARY' => '',
                'TRACK_DRUNK_STATUS' => false,
                'TRACK_FERTILITY_INFO' => false,
            ];

            if ($settingsRow && !empty($settingsRow['value'])) {
                $parsedSettings = json_decode($settingsRow['value'], true);
                if (is_array($parsedSettings)) {
                    $settings = array_merge($settings, $parsedSettings);
                }
            }

            echo json_encode([
                'success' => true,
                'data' => $settings,
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
        exit;
    }

    function handleSaveSettings()
    {
        try {
            $settings = [
                'XTTS_MODIFY_LEVEL1' => isset($_POST['XTTS_MODIFY_LEVEL1']) ? filter_var($_POST['XTTS_MODIFY_LEVEL1'], FILTER_VALIDATE_BOOLEAN) : false,
                'XTTS_MODIFY_LEVEL2' => isset($_POST['XTTS_MODIFY_LEVEL2']) ? filter_var($_POST['XTTS_MODIFY_LEVEL2'], FILTER_VALIDATE_BOOLEAN) : false,
                'GENERIC_GLOSSARY' => $_POST['GENERIC_GLOSSARY'] ?? '',
                'TRACK_DRUNK_STATUS' => isset($_POST['TRACK_DRUNK_STATUS']) ? filter_var($_POST['TRACK_DRUNK_STATUS'], FILTER_VALIDATE_BOOLEAN) : false,
                'TRACK_FERTILITY_INFO' => isset($_POST['TRACK_FERTILITY_INFO']) ? filter_var($_POST['TRACK_FERTILITY_INFO'], FILTER_VALIDATE_BOOLEAN) : false,
            ];

            $jsonSettings = json_encode($settings);

            $GLOBALS["db"]->upsertRowOnConflict(
                'conf_opts',
                [
                    'id' => 'aiagent_nsfw_settings',
                    'value' => $jsonSettings,
                ],
                'id'
            );

            echo json_encode([
                'success' => true,
                'message' => '设置已保存',
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
        exit;
    }

    // If we get here, render the HTML page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NSFW Agent 配置面板</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 28px;
            margin-bottom: 5px;
        }

        .header p {
            font-size: 14px;
            opacity: 0.9;
        }

        .tabs {
            display: flex;
            border-bottom: 2px solid #e0e0e0;
            background: #f5f5f5;
        }

        .tab-button {
            flex: 1;
            padding: 15px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: #666;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
        }

        .tab-button:hover {
            color: #667eea;
            background: #fff;
        }

        .tab-button.active {
            color: #667eea;
            border-bottom-color: #667eea;
            background: white;
        }

        .tab-content {
            display: none;
            padding: 30px;
            animation: fadeIn 0.3s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
            font-size: 13px;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 13px;
            font-family: inherit;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 200px;
        }

        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        button {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-secondary {
            background: #e0e0e0;
            color: #333;
        }

        .btn-secondary:hover {
            background: #d0d0d0;
        }

        .btn-danger {
            background: #ff6b6b;
            color: white;
        }

        .btn-danger:hover {
            background: #ff5252;
        }

        .btn-warning {
            background: #ffa502;
            color: white;
        }

        .btn-warning:hover {
            background: #ff9500;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 13px;
            display: none;
        }

        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            display: block;
        }

        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            display: block;
        }

        .table-wrapper {
            overflow-x: auto;
            margin-top: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        table thead {
            background: #f5f5f5;
            border-bottom: 2px solid #ddd;
        }

        table th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #333;
        }

        table td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
            color: #666;
        }

        table tr:hover {
            background: #f9f9f9;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-buttons button {
            padding: 6px 12px;
            font-size: 12px;
        }

        .loading {
            display: none;
            text-align: center;
            padding: 20px;
            color: #667eea;
        }

        .loading.active {
            display: block;
        }

        .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .row.full {
            grid-template-columns: 1fr;
        }

        .searchable-select-wrapper {
            position: relative;
            margin-bottom: 15px;
        }

        .searchable-select-input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 13px;
            font-family: inherit;
        }

        .searchable-select-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .searchable-select-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 5px 5px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 100;
            display: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .searchable-select-dropdown.active {
            display: block;
        }

        .searchable-select-option {
            padding: 10px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
        }

        .searchable-select-option:hover {
            background: #f5f5f5;
        }

        .searchable-select-option.selected {
            background: #e8eef7;
            color: #667eea;
            font-weight: 600;
        }

        .textarea-with-button {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .textarea-with-button textarea {
            flex: 1;
            min-width:0;
            min-height:50px
        }

        .textarea-with-button button {
            padding: 10px 15px;
            height: fit-content;
            white-space: nowrap;
            margin-top: 0;
        }

        p.legend {
            font-family:"Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            font-size:13px;
            padding-bottom:20px;;
            padding-top:20px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .pagination button,
        .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            background: white;
            color: #333;
            transition: all 0.2s ease;
        }

        .pagination button:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .pagination button.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
            font-weight: 600;
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination span {
            cursor: default;
            border: none;
            padding: 8px 0;
        }

        .pagination-info {
            text-align: center;
            margin-top: 10px;
            font-size: 13px;
            color: #666;
        }

        :root {
            --bg-0: #f5f7f2;
            --bg-1: #eef4ec;
            --panel: #ffffff;
            --panel-soft: #f8fbf6;
            --line: #dce6d8;
            --text: #1f2a20;
            --muted: #5a6a5d;
            --brand: #2d5b44;
            --brand-2: #4e7d61;
            --accent: #b17f3d;
            --danger: #bd4d4d;
        }

        body {
            font-family: "Trebuchet MS", "Segoe UI", Tahoma, sans-serif;
            background: radial-gradient(circle at 15% 5%, #fef7e7 0%, transparent 45%), linear-gradient(165deg, var(--bg-0) 0%, var(--bg-1) 100%);
            color: var(--text);
        }

        .container {
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: 0 24px 60px rgba(31, 42, 32, 0.12);
        }

        .header {
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-2) 100%);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .tabs {
            background: var(--panel-soft);
            border-bottom: 1px solid var(--line);
            gap: 6px;
            padding: 8px;
        }

        .tab-button {
            border-radius: 10px;
            border-bottom: none;
            color: var(--muted);
            margin-bottom: 0;
        }

        .tab-button:hover {
            color: var(--brand);
            background: #ffffff;
        }

        .tab-button.active {
            color: var(--brand);
            background: #ffffff;
            box-shadow: 0 6px 14px rgba(45, 91, 68, 0.12);
        }

        .tab-content {
            background: var(--panel);
            padding: 28px;
        }

        .form-group label,
        table th {
            color: var(--text);
        }

        .form-group input,
        .form-group textarea,
        .searchable-select-input,
        #profanityLevel,
        #sexDisposalValue {
            border-color: var(--line);
            border-radius: 10px;
            background: #fff;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .searchable-select-input:focus,
        #profanityLevel:focus,
        #sexDisposalValue:focus {
            border-color: var(--brand-2);
            box-shadow: 0 0 0 3px rgba(78, 125, 97, 0.18);
        }

        button {
            border-radius: 10px;
            font-weight: 700;
        }

        .btn-primary {
            background: var(--brand);
        }

        .btn-primary:hover {
            background: #244c39;
            box-shadow: 0 8px 18px rgba(45, 91, 68, 0.3);
        }

        .btn-secondary {
            background: #e8eee7;
            color: var(--text);
        }

        .btn-secondary:hover {
            background: #dce6da;
        }

        .btn-warning {
            background: var(--accent);
        }

        .btn-warning:hover {
            background: #9a6f36;
        }

        .btn-danger {
            background: var(--danger);
        }

        table thead {
            background: #edf3ee;
            border-bottom-color: var(--line);
        }

        table td {
            border-bottom-color: #e9efe9;
            color: #3d4f40;
        }

        .searchable-select-dropdown {
            border-color: var(--line);
            border-radius: 0 0 10px 10px;
            box-shadow: 0 12px 24px rgba(26, 44, 33, 0.12);
        }

        .searchable-select-option:hover {
            background: #edf3ee;
        }

        .searchable-select-option.selected {
            background: #dceadf;
            color: var(--brand);
        }

        p.legend {
            color: #4f5f52;
            line-height: 1.6;
        }

        .panel {
            background: var(--panel-soft);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 14px;
        }

        .panel-title {
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.3px;
            color: var(--brand);
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .tools-layout {
            display: grid;
            grid-template-columns: 1.7fr 1fr;
            gap: 16px;
            align-items: start;
        }

        .tools-main,
        .tools-side {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .tools-side {
            position: sticky;
            top: 14px;
        }

        .status-box {
            background: #f7fbf8;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px;
            font-size: 12px;
            color: #273629;
        }

        .status-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .status-item {
            background: #ffffff;
            border: 1px solid #dde7de;
            border-radius: 8px;
            padding: 7px 9px;
            min-height: 50px;
        }

        .status-item .k {
            font-size: 11px;
            color: #55705f;
            margin-bottom: 4px;
            display: block;
        }

        .status-item .v {
            font-size: 12px;
            color: #213628;
            font-weight: 700;
            word-break: break-word;
        }

        .status-text-card {
            margin-top: 8px;
            background: #fff;
            border: 1px solid #dde7de;
            border-radius: 8px;
            padding: 8px;
        }

        .status-text-card summary {
            cursor: pointer;
            font-size: 12px;
            color: #234936;
            font-weight: 700;
            user-select: none;
        }

        .status-text-card .mono {
            margin-top: 8px;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 12px;
            line-height: 1.5;
            background: #f8fbf6;
            border: 1px dashed #d5e1d7;
            border-radius: 8px;
            padding: 8px;
            color: #33463a;
            max-height: 160px;
            overflow: auto;
        }

        .level-guide {
            display: grid;
            grid-template-columns: 1fr;
            gap: 6px;
        }

        .level-guide div {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            padding: 8px 10px;
            font-size: 12px;
            color: #2f4333;
        }

        .level-guide strong {
            color: var(--brand);
        }

        .capture-bar {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .capture-status {
            font-size: 12px;
            color: var(--muted);
            background: #f4f8f3;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 7px 10px;
        }

        .capture-status.active {
            color: #234936;
            border-color: #a6c8b2;
            background: #eaf7ef;
        }

        .unlock-section {
            margin-top: 12px;
        }

        .unlock-title {
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 6px;
            color: #2b4330;
        }

        .chip-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 11px;
            font-weight: 700;
            line-height: 1;
            border: 1px solid transparent;
        }

        .chip.unlocked {
            background: #e6f3e8;
            color: #24533a;
            border-color: #bfd9c6;
        }

        .chip.locked {
            background: #f2f4f2;
            color: #66736a;
            border-color: #d8e1da;
        }

        .chip.info {
            background: #eff2fb;
            color: #2f466d;
            border-color: #ccd9f1;
        }

        .row-new-stage {
            background: #fff7e8 !important;
            outline: 2px solid #e8b667;
            animation: stagePulse 1.8s ease-in-out 3;
        }

        @keyframes stagePulse {
            0% { box-shadow: 0 0 0 0 rgba(177, 127, 61, 0.0); }
            50% { box-shadow: 0 0 0 6px rgba(177, 127, 61, 0.15); }
            100% { box-shadow: 0 0 0 0 rgba(177, 127, 61, 0.0); }
        }

        @media (max-width: 768px) {
            .row {
                grid-template-columns: 1fr;
            }

            .tabs {
                flex-direction: column;
            }

            .header h1 {
                font-size: 20px;
            }

            .tools-layout {
                grid-template-columns: 1fr;
            }

            .tools-side {
                position: static;
            }

            .status-grid {
                grid-template-columns: 1fr;
            }

            .textarea-with-button {
                flex-direction: column;
            }

            .textarea-with-button button {
                width: 100%;
            }
        }

        /* ===== Visual Refactor Layer (no logic changes) ===== */
        :root {
            --ui-bg: #efe6d4;
            --ui-bg-2: #e5dbc7;
            --ui-panel: #fffaf0;
            --ui-panel-soft: #f7efdf;
            --ui-line: #c9b99a;
            --ui-text: #243128;
            --ui-muted: #5c644f;
            --ui-brand: #214c38;
            --ui-brand-2: #2d6447;
            --ui-copper: #9b6a35;
            --ui-copper-soft: #b7864e;
            --ui-warn: #96612d;
            --ui-danger: #9e433b;
        }

        body {
            margin: 0;
            font-family: "Trebuchet MS", "Segoe UI", Tahoma, sans-serif;
            color: var(--ui-text);
            background:
                radial-gradient(circle at 0% 0%, #fff6e3 0%, transparent 28%),
                radial-gradient(circle at 100% 12%, #e6efe6 0%, transparent 34%),
                repeating-linear-gradient(0deg, rgba(112, 90, 52, 0.03) 0, rgba(112, 90, 52, 0.03) 1px, transparent 1px, transparent 6px),
                linear-gradient(145deg, var(--ui-bg) 0%, var(--ui-bg-2) 100%);
        }

        .container {
            max-width: 1440px;
            min-height: calc(100vh - 40px);
            border-radius: 18px;
            border: 1px solid #bca984;
            background: var(--ui-panel-soft);
            display: grid;
            grid-template-columns: 240px 1fr;
            grid-template-areas:
                "header header"
                "tabs main";
            box-shadow: 0 28px 60px rgba(17, 34, 22, 0.14);
        }

        .header {
            grid-area: header;
            text-align: left;
            padding: 22px 26px;
            background: linear-gradient(115deg, #1d4634 0%, #2a5f44 100%);
            box-shadow: inset 0 -1px 0 rgba(255, 255, 255, 0.12);
        }

        .header h1 {
            font-family: "Palatino Linotype", "Book Antiqua", serif;
            font-size: 30px;
            font-weight: 700;
            letter-spacing: 0.2px;
            margin-bottom: 2px;
        }

        .header p {
            opacity: 0.9;
            font-size: 13px;
        }

        .tabs {
            grid-area: tabs;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 6px;
            border: none;
            border-right: 1px solid #cfbe9f;
            padding: 12px;
            background: var(--ui-panel-soft);
        }

        .tab-button {
            text-align: left;
            font-size: 14px;
            line-height: 1.2;
            border: 1px solid transparent;
            border-radius: 12px;
            padding: 12px 12px;
            color: var(--ui-muted);
        }

        .tab-button:hover {
            color: var(--ui-brand);
            border-color: #c5b08a;
            background: #fffaf2;
        }

        .tab-button.active {
            color: var(--ui-brand);
            border-color: #bc9b69;
            background: #fffefb;
            box-shadow: 0 8px 18px rgba(58, 43, 22, 0.12);
        }

        .tab-content {
            grid-area: main;
            margin: 12px;
            border-radius: 16px;
            border: 1px solid #cdb995;
            background: var(--ui-panel);
            padding: 20px;
        }

        .tab-content h2 {
            margin-bottom: 14px !important;
            font-size: 21px;
            font-weight: 800;
            color: #233326 !important;
        }

        .tab-content h3 {
            color: #35503d !important;
            margin-top: 18px !important;
            margin-bottom: 10px !important;
        }

        .panel,
        .form-group,
        .table-wrapper,
        #info > div[style*="background"] {
            border-radius: 12px;
        }

        .form-group input,
        .form-group textarea,
        .searchable-select-input,
        #profanityLevel,
        #sexDisposalValue,
        #importFile {
            border-color: #cbb895;
            border-radius: 10px;
            background: #fffdf8;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .searchable-select-input:focus,
        #profanityLevel:focus,
        #sexDisposalValue:focus {
            border-color: #4c785f;
            box-shadow: 0 0 0 3px rgba(90, 121, 90, 0.16);
        }

        .button-group {
            flex-wrap: wrap;
            gap: 8px;
        }

        button {
            border-radius: 10px;
            border: 1px solid transparent;
        }

        .btn-primary {
            background: linear-gradient(180deg, var(--ui-brand) 0%, #1b4331 100%);
            color: #fff;
        }

        .btn-primary:hover {
            background: linear-gradient(180deg, #1a4b35 0%, #163c2b 100%);
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(31, 90, 63, 0.28);
        }

        .btn-secondary {
            background: #efe5d2;
            color: #3c3a2d;
            border-color: #cab693;
        }

        .btn-secondary:hover {
            background: #e6dcc8;
        }

        .btn-warning {
            background: linear-gradient(180deg, var(--ui-copper-soft) 0%, var(--ui-copper) 100%);
            color: #fff;
        }

        .btn-warning:hover {
            background: #875828;
        }

        .btn-danger {
            background: var(--ui-danger);
            color: #fff;
        }

        .btn-danger:hover {
            background: #a13b3b;
        }

        .table-wrapper {
            border: 1px solid #c9b48d;
            background: #fff;
        }

        table thead {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #efe5d3;
        }

        table td,
        table th {
            vertical-align: top;
        }

        table tr:hover {
            background: #fcf6ea;
        }

        .alert {
            border-radius: 10px;
            font-weight: 600;
        }

        #editModal > div {
            border: 1px solid var(--ui-line);
            border-radius: 14px;
        }

        @media (max-width: 1024px) {
            .container {
                grid-template-columns: 1fr;
                grid-template-areas:
                    "header"
                    "tabs"
                    "main";
            }

            .tabs {
                flex-direction: row;
                flex-wrap: wrap;
                border-right: none;
                border-bottom: 1px solid #cfbe9f;
            }

            .tab-button {
                flex: 1 1 calc(50% - 6px);
                min-width: 130px;
            }

            .tab-content {
                margin: 10px;
                padding: 16px;
            }
        }

        @media (max-width: 640px) {
            .container {
                min-height: auto;
                border-radius: 12px;
            }

            .header {
                padding: 16px 14px;
            }

            .header h1 {
                font-size: 24px;
            }

            .tab-button {
                flex: 1 1 100%;
            }

            .tab-content {
                margin: 8px;
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>NSFW Agent 配置</h1>
            <p>管理场景与配置</p>
        </div>

        <div class="tabs">
            <button class="tab-button active" onclick="switchTab('scenes')">场景管理</button>
            <button class="tab-button" onclick="switchTab('tools')">工具</button>
            <button class="tab-button" onclick="switchTab('settings')">设置</button>
            <button class="tab-button" onclick="switchTab('info')">说明</button>
        </div>

        <!-- Scenes Tab -->
        <div id="scenes" class="tab-content active">
            <div class="alert success" id="sceneSuccessAlert"></div>
            <div class="alert error" id="sceneErrorAlert"></div>

            <h2 style="margin-bottom: 20px; color: #333;">新建场景</h2>

            <div class="row">
                <div class="form-group">
                    <label for="sceneStage">Stage（ID）*</label>
                    <input type="text" id="sceneStage" placeholder="例如：scene_01" required>
                </div>
                <div class="form-group">
                    <label for="sceneDesc">描述（默认）</label>
                    <input type="text" id="sceneDesc" placeholder="默认描述">
                </div>
            </div>

            <div class="row">
                <div class="form-group">
                    <label for="sceneDescEs">描述（西语）</label>
                    <input type="text" id="sceneDescEs" placeholder="西语描述">
                </div>
                <div class="form-group">
                    <label for="sceneDescEn">描述（英语）</label>
                    <input type="text" id="sceneDescEn" placeholder="英语描述">
                </div>
            </div>

            <div class="row full">
                <div class="form-group">
                    <label for="sceneIDesc">内部描述</label>
                    <textarea id="sceneIDesc" placeholder="内部/技术描述"></textarea>
                </div>
            </div>

            <div class="button-group">
                <button class="btn-primary" onclick="createScene()">创建场景</button>
                <button class="btn-secondary" onclick="clearSceneForm()">清空表单</button>
                <button class="btn-secondary" onclick="generateCurrentSceneDescription()">生成当前描述</button>
                <button class="btn-warning" onclick="generateSceneDescriptions()" title='Will Use AI'>($) 从内部描述生成文案</button>
            </div>

            <div class="capture-bar">
                <button class="btn-secondary" onclick="startStageCapture()">开始捕获 Stage</button>
                <button class="btn-secondary" onclick="stopStageCapture()">停止捕获</button>
                <div id="stageCaptureStatus" class="capture-status">捕获未启动</div>
            </div>
            <p class="legend">“从内部描述生成文案”用于快速生成可读描述。你可以先写内部描述，
                其中角色可用 "actor zero"、"actor one" 这样的占位写法，然后点击按钮自动生成规范描述。
                也可以配合 <a target="_blank" href="https://chromewebstore.google.com/detail/voice-in-speech-to-text-d/pjnefijmagpdjfhhkpljicbbpicelgko">Voice In - Speech to Text</a>
                用语音更快填写内部描述。
            </p>

            <h2 style="margin: 30px 0 20px; color: #333;">已有场景</h2>
            <div class="loading active" id="scenesLoading">正在加载场景...</div>
            <div class="table-wrapper">
                <table id="scenesTable" style="display: none;">
                    <thead>
                        <tr>
                            <th>Stage</th>
                            <th>描述</th>
                            <th>描述（西语）</th>
                            <th>描述（英语）</th>
                            <th>内部描述</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="scenesTableBody">
                    </tbody>
                </table>
            </div>
            <div id="paginationContainer" style="display: none;">
                <div class="pagination" id="paginationControls"></div>
                <div class="pagination-info" id="paginationInfo"></div>
            </div>
        </div>

        <!-- Tools Tab -->
        <div id="tools" class="tab-content">
            <div class="alert success" id="toolsSuccessAlert"></div>
            <div class="alert error" id="toolsErrorAlert"></div>

            <h2 style="margin-bottom: 20px; color: #333;">NPC 提示生成器</h2>
            <p class="legend">设置亲密场景用到的 NPC 扩展数据。你可以在这里生成提示词，并直接调整 sex_disposal 档位。</p>

            <div class="tools-layout">
                <div class="tools-main">
                    <div class="panel">
                        <div class="panel-title">NPC 选择</div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="npcSelect">选择 NPC *</label>
                            <div class="searchable-select-wrapper">
                                <input
                                    type="text"
                                    id="npcSelectInput"
                                    class="searchable-select-input"
                                    placeholder="搜索 NPC..."
                                    autocomplete="off"
                                >
                                <div class="searchable-select-dropdown" id="npcSelectDropdown"></div>
                                <input type="hidden" id="npcSelectValue">
                            </div>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-title">资料文本</div>
                        <div class="form-group">
                            <label for="connectorSelect">选择 Connector *</label>
                            <div class="searchable-select-wrapper">
                                <input
                                    type="text"
                                    id="connectorSelectInput"
                                    class="searchable-select-input"
                                    placeholder="搜索 Connector..."
                                    autocomplete="off"
                                >
                                <div class="searchable-select-dropdown" id="connectorSelectDropdown"></div>
                                <input type="hidden" id="connectorSelectValue">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="profanityLevel">粗口等级 *</label>
                            <select id="profanityLevel" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 13px; font-family: inherit;">
                                <option value="">-- 请选择粗口等级 --</option>
                                <option value="0">轻度</option>
                                <option value="1">中性</option>
                                <option value="2">重度</option>
                                <option value="3">露骨</option>
                            </select>
                        </div>

                        <div class="textarea-with-button">
                            <div style="flex: 1;">
                                <label for="sexPrompt" style="display: block; margin-bottom: 5px; font-weight: 600; color: #333; font-size: 13px;">Sex Prompt</label>
                                <textarea id="sexPrompt" placeholder="输入 sex_prompt..."></textarea>
                            </div>
                            <button class="btn-secondary" onclick="generatePrompt('sex_prompt')" style="align-self: center;">生成</button>
                        </div>

                        <div class="textarea-with-button">
                            <div style="flex: 1;">
                                <label for="sexSpeechStyle" style="display: block; margin-bottom: 5px; font-weight: 600; color: #333; font-size: 13px;">Sex Speech Style</label>
                                <textarea id="sexSpeechStyle" placeholder="输入 sex_speech_style..."></textarea>
                            </div>
                            <button class="btn-secondary" onclick="generatePrompt('sex_speech_style')" style="align-self: center;">生成</button>
                        </div>

                        <div class="button-group">
                            <button class="btn-primary" onclick="submitToolsForm()">保存 NPC 设置</button>
                            <button class="btn-secondary" onclick="clearToolsForm()">清空表单</button>
                        </div>
                    </div>
                </div>

                <div class="tools-side">
                    <div class="panel">
                        <div class="panel-title">当前亲密状态</div>
                        <div id="npcStatusBox" class="status-box">
                            <div>请选择 NPC 查看状态。</div>
                        </div>
                        <div class="button-group" style="margin-top: 10px;">
                            <button class="btn-secondary" onclick="loadSelectedNPCStatus(document.getElementById('npcSelectValue').value)">刷新状态</button>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-title">sex_disposal 编辑器</div>
                        <div class="form-group">
                            <label for="sexDisposalValue">sex_disposal（可编辑）</label>
                            <select id="sexDisposalValue" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 13px; font-family: inherit;">
                                <option value="">保持当前值</option>
                                <option value="-1">-1：冷淡 / 拒绝亲密</option>
                                <option value="0">0：中性基线</option>
                                <option value="1">1：解锁 Kiss</option>
                                <option value="5">5：解锁 RemoveClothes</option>
                                <option value="10">10：解锁 Massage / SelfMasturbation / Handjob</option>
                                <option value="20">20：解锁全部场景动作</option>
                                <option value="30">30：高意愿（与 20+ 解锁相同）</option>
                            </select>
                            <div class="legend" style="margin-top: 8px; line-height: 1.6; padding-top: 8px; padding-bottom: 0;">
                                选择一个值后，点击“保存 NPC 设置”。
                            </div>
                        </div>

                        <div class="level-guide">
                            <div><strong>&lt; 1</strong> 不解锁亲密动作</div>
                            <div><strong>1+</strong> 解锁 Kiss</div>
                            <div><strong>5+</strong> 解锁 RemoveClothes</div>
                            <div><strong>10+</strong> 解锁 Massage / SelfMasturbation / Handjob</div>
                            <div><strong>20+</strong> 解锁 Blowjob / Sex / Threesome / Titfuck / Anal</div>
                        </div>

                        <div id="unlockActionsBox" class="unlock-section"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Settings Tab -->
        <div id="settings" class="tab-content">
            <div class="alert success" id="settingsSuccessAlert"></div>
            <div class="alert error" id="settingsErrorAlert"></div>

            <h2 style="margin-bottom: 20px; color: #333;">通用设置</h2>

            <div class="form-group">
                <p class="legend">NPC 在 idle 场景中语速更慢。仅对 XTTS 生效。</p>
                <label for="xttsModifyLevel1" style="">
                    <input type="checkbox" id="xttsModifyLevel1" name="XTTS_MODIFY_LEVEL1">
                    <span>XTTS 调整等级 1</span>
                </label>
            </div>

            <div class="form-group">
                <p class="legend">NPC 在动作场景中语速更慢并带喘息。仅对 XTTS 生效。</p>
                <label for="xttsModifyLevel2" style="">
                    <input type="checkbox" id="xttsModifyLevel2" name="XTTS_MODIFY_LEVEL2">
                    <span>XTTS 调整等级 2</span>
                </label>
            </div>

            <div class="form-group">
                <label for="trackDrunkStatus" style="">
                    <input type="checkbox" id="trackDrunkStatus" name="TRACK_DRUNK_STATUS">
                    <span>跟踪醉酒状态</span>
                </label>
            </div>

            <div class="form-group">
                <label for="trackFertilityInfo" style="">
                    <input type="checkbox" id="trackFertilityInfo" name="TRACK_FERTILITY_INFO">
                    <span>跟踪生育信息</span>
                </label>
            </div>

            <div class="row full">
                <p class="legend">逗号分隔词条，仅用于本页面调用 AI 生成内容时提供上下文。</p>
                <div class="form-group">
                    <label for="genericGlossary">通用词汇表</label>
                    <textarea id="genericGlossary" placeholder="输入词汇表..." style="min-height: 200px;"></textarea>
                    
                </div>
            </div>

            <h2 style="margin: 30px 0 20px; color: #333;">数据库管理</h2>

            <h3 style="margin: 20px 0 15px; color: #555; font-size: 15px;">创建数据表</h3>
            <p class="legend">如果数据库中不存在 ext_aiagentnsfw_scenes，则创建它。</p>
            <div class="button-group">
                <button class="btn-warning" onclick="generateTable()">创建 ext_aiagentnsfw_scenes 表</button>
            </div>

            <h3 style="margin: 20px 0 15px; color: #555; font-size: 15px;">从文件导入场景</h3>
            <p class="legend">从制表符分隔（TSV）文件导入。首行字段需为：stage, description, description_es, description_en, i_desc。NULL 请使用 \N。</p>
            <div class="form-group">
                <label for="importFile">选择 TSV 文件</label>
                <input type="file" id="importFile" accept=".tsv,.txt" />
            </div>
            <div class="button-group">
                <button class="btn-primary" onclick="importScenes()">导入场景</button>
            </div>

            <h3 style="margin: 20px 0 15px; color: #555; font-size: 15px;">设置</h3>
            <div class="button-group">
                <button class="btn-primary" onclick="saveSettings()">保存设置</button>
                <button class="btn-secondary" onclick="resetSettings()">重新加载设置</button>
            </div>
        </div>

        <!-- Info Tab -->
        <div id="info" class="tab-content">
            <h2 style="margin-bottom: 20px; color: #333;">NSFW Agent 文档</h2>

            <h3 style="margin: 25px 0 15px; color: #555; font-size: 16px;">概览</h3>
            <p style="line-height: 1.6; color: #666; margin-bottom: 15px;">
                该扩展将亲密内容与 OStim 动画联动。NPC 能感知玩家所处的 OStim 场景并执行对应动作。
                默认情况下多数动作不可用，随着 NPC 的 <strong>sex_disposal</strong> 数值在互动和放松状态下提升，动作会逐步解锁。
            </p>

            <h3 style="margin: 25px 0 15px; color: #555; font-size: 16px;">核心概念</h3>
            <div style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin-bottom: 15px; border-left: 4px solid #667eea;">
                <p><strong>动画类型：</strong></p>
                <ul style="margin: 10px 0; padding-left: 20px; color: #666;">
                    <li>NPC 可以直接开始动画</li>
                    <li>NPC 可以发起 idle 场景</li>
                    <li>NPC 可以根据聊天互动切换动画</li>
                </ul>
            </div>

            <h3 style="margin: 25px 0 15px; color: #555; font-size: 16px;">NPC 扩展数据</h3>
            <p style="color: #666; margin-bottom: 10px;">NPC 扩展数据用于存储亲密状态与配置字段：</p>
            <div style="background: #f0f4ff; padding: 15px; border-radius: 5px; margin-bottom: 15px; font-family: monospace; font-size: 12px; color: #333; border: 1px solid #ddd; overflow-x: auto;">
                <div><strong>aiagent_nsfw_intimacy_data:</strong> {</div>
                <div style="margin-left: 20px;">
                    <div><strong>level:</strong> 0-2 (0: not in scene, 1: idle scene, 2: active scene)</div>
                    <div><strong>is_naked:</strong> 0|1 (tracks PutOffClothes/PutOnClothes actions)</div>
                    <div><strong>orgasmed:</strong> boolean (true if NPC climaxed in session)</div>
                    <div><strong>sex_disposal:</strong> 0-100 (above 10, sex actions become available)</div>
                    <div><strong>orgasm_generated:</strong> boolean (precached climax speech)</div>
                    <div><strong>orgasm_generated_text:</strong> string (generated climax dialogue)</div>
                    <div><strong>adult_entertainment_services_autodetected:</strong> boolean (sexual worker marker)</div>
                </div>
                <div>}</div>
            </div>

            <h3 style="margin: 25px 0 15px; color: #555; font-size: 16px;">NPC 配置</h3>
            <p style="color: #666; margin-bottom: 10px;">两个关键扩展字段：</p>
            <div style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin-bottom: 15px; border-left: 4px solid #667eea;">
                <p><strong>sex_prompt:</strong> NPC 处于 OStim 场景时使用的提示词（在 Tools 中配置）</p>
                <p style="margin-top: 10px;"><strong>sex_speech_style:</strong> 成人对话语气风格（在 Tools 中配置）</p>
            </div>

            <h3 style="margin: 25px 0 15px; color: #555; font-size: 16px;">导入规则</h3>
            <p style="color: #666; margin-bottom: 10px;">可通过导入规则自动分类 NPC。示例：将 "Ancient Profession" 模组中的女性 NPC 分配到 profile 6：</p>
            <div style="background: #f0f4ff; padding: 15px; border-radius: 5px; margin-bottom: 15px; font-family: monospace; font-size: 11px; color: #333; border: 1px solid #ddd; overflow-x: auto;">
                <div>id | description | match_name | match_race | match_gender | match_base | match_mods | action | profile | priority | enabled</div>
                <div style="margin-top: 5px; border-top: 1px solid #ddd; padding-top: 5px;">
                    2 | Ancient Profession | .* | .* | female | .* | {prostitutes.esp} | {"metadata": {"rule_applied": true}} | 6 | 1 | TRUE
                </div>
            </div>

            <h3 style="margin: 25px 0 15px; color: #555; font-size: 16px;">Profile 配置</h3>
            <p style="color: #666; margin-bottom: 10px;">在目标 profile（如 profile 6）里设置 metadata：</p>
            <div style="background: #f0f4ff; padding: 15px; border-radius: 5px; margin-bottom: 15px; font-family: monospace; font-size: 12px; color: #333; border: 1px solid #ddd;">
                <div><strong>AIAGENT_NSFW_DEFAULT_AROUSAL:</strong> 20</div>
                <div style="margin-top: 10px; color: #666;">该 profile 下 NPC 的基础 arousal 为 20，会解锁全部 sex 动作。可用 Profile Prompt 补充上下文：</div>
                <div style="margin-top: 10px; background: #fff; padding: 10px; border-radius: 3px; border-left: 3px solid #667eea;">
                    <div>#HERIKA_NAME# is a sex worker. Offers adult entertainment services for gold:</div>
                    <div style="margin-top: 5px; color: #666;">• massage: 50 gold</div>
                    <div>• manual: 100 gold</div>
                    <div>• pectoral job: 150 gold</div>
                    <div>• mouth job: 200 gold</div>
                    <div>• love: 500 gold</div>
                </div>
            </div>

            <h3 style="margin: 25px 0 15px; color: #555; font-size: 16px;">路线图</h3>
            <div style="background: #fff3cd; padding: 15px; border-radius: 5px; border-left: 4px solid #ffc107; color: #333;">
                <p><strong>计划功能：</strong></p>
                <ul style="margin: 10px 0; padding-left: 20px;">
                    <li>多人 NPC 亲密场景</li>
                    <li>非玩家角色之间的场景</li>
                </ul>
            </div>

            <h3 style="margin: 25px 0 15px; color: #555; font-size: 16px;">快速跳转</h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 15px;">
                <button class="btn-secondary" onclick="switchTab('scenes')" style="cursor: pointer;">前往场景管理</button>
                <button class="btn-secondary" onclick="switchTab('tools')" style="cursor: pointer;">前往工具</button>
                <button class="btn-secondary" onclick="switchTab('settings')" style="cursor: pointer;">前往设置</button>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 10px; width: 90%; max-width: 500px; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
            <h2 style="margin-bottom: 20px; color: #333;">编辑场景</h2>

            <div class="form-group">
                <label>Stage (ID)</label>
                <input type="text" id="editStage" disabled style="background: #f5f5f5;">
            </div>

            <div class="form-group">
                <label>描述</label>
                <input type="text" id="editDesc">
            </div>

            <div class="form-group">
                <label>描述（西语）</label>
                <input type="text" id="editDescEs">
            </div>

            <div class="form-group">
                <label>描述（英语）</label>
                <input type="text" id="editDescEn">
            </div>

            <div class="form-group">
                <label>内部描述</label>
                <textarea id="editIDesc"></textarea>
            </div>

            <div class="button-group">
                <button class="btn-primary" onclick="saveEdit()">保存修改</button>
                <button class="btn-secondary" onclick="closeEditModal()">取消</button>
            </div>
        </div>
    </div>

    <script>
        // Pagination variables
        let allScenes = [];
        let currentPage = 1;
        const itemsPerPage = 50;
        let stageCaptureActive = false;
        let stageCaptureBaseline = new Set();
        let stageCaptureTimer = null;
        let highlightedStages = new Set();

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadScenes();
            loadNPCSelector();
            loadConnectorSelector();
            loadSettings();
        });

        // Tab switching
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Deactivate all buttons
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName).classList.add('active');

            // Activate button
            event.target.classList.add('active');
        }

        // Alert handling
        function showAlert(elementId, message, type) {
            const alertEl = document.getElementById(elementId);
            alertEl.textContent = message;
            alertEl.className = `alert ${type}`;
            alertEl.style.display = 'block';

            setTimeout(() => {
                alertEl.style.display = 'none';
            }, 10000);
        }

        // Load scenes
        function loadScenes() {
            fetch('<?php echo $_SERVER['PHP_SELF']; ?>?action=read')
                .then(response => response.json())
                .then(data => {
                    document.getElementById('scenesLoading').classList.remove('active');

                    if (data.success) {
                        allScenes = data.data;
                        currentPage = 1;
                        displayScenesPage();
                    } else {
                        showAlert('sceneErrorAlert', '加载场景失败: ' + data.error, 'error');
                    }
                })
                .catch(error => {
                    document.getElementById('scenesLoading').classList.remove('active');
                    showAlert('sceneErrorAlert', '网络错误: ' + error.message, 'error');
                });
        }

        // Display scenes for current page
        function displayScenesPage() {
            const tbody = document.getElementById('scenesTableBody');
            tbody.innerHTML = '';

            if (allScenes.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: #999;">未找到场景，请先创建。</td></tr>';
                document.getElementById('scenesTable').style.display = 'table';
                document.getElementById('paginationContainer').style.display = 'none';
                return;
            }

            // Calculate pagination
            const totalPages = Math.ceil(allScenes.length / itemsPerPage);
            const startIndex = (currentPage - 1) * itemsPerPage;
            const endIndex = Math.min(startIndex + itemsPerPage, allScenes.length);
            const scenesOnPage = allScenes.slice(startIndex, endIndex);

            // Populate table
            scenesOnPage.forEach(scene => {
                const row = document.createElement('tr');
                row.dataset.stage = scene.stage;
                if (highlightedStages.has(String(scene.stage || '').trim())) {
                    row.classList.add('row-new-stage');
                }
                row.innerHTML = `
                    <td><strong>${escapeHtml(scene.stage)}</strong></td>
                    <td>${escapeHtml(scene.description || '-')}</td>
                    <td>${escapeHtml(scene.description_es || '-')}</td>
                    <td>${escapeHtml(scene.description_en || '-')}</td>
                    <td>${escapeHtml(scene.i_desc || '-')}</td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn-warning" onclick="editScene('${escapeAttr(scene.stage)}', '${escapeAttr(scene.description || '')}', '${escapeAttr(scene.description_es || '')}', '${escapeAttr(scene.description_en || '')}', '${escapeAttr(scene.i_desc || '')}')">编辑</button>
                            <button class="btn-danger" onclick="deleteScene('${escapeAttr(scene.stage)}')">删除</button>
                        </div>
                    </td>
                `;
                tbody.appendChild(row);
            });

            document.getElementById('scenesTable').style.display = 'table';

            // Show pagination controls
            if (totalPages > 1) {
                document.getElementById('paginationContainer').style.display = 'block';
                renderPagination(totalPages);
            } else {
                document.getElementById('paginationContainer').style.display = 'none';
            }

            // Update pagination info
            document.getElementById('paginationInfo').textContent = 
                `显示 ${startIndex + 1}-${endIndex} / 共 ${allScenes.length} 条（第 ${currentPage}/${totalPages} 页）`;
        }

        // Render pagination controls
        function renderPagination(totalPages) {
            const paginationControls = document.getElementById('paginationControls');
            paginationControls.innerHTML = '';

            // Previous button
            const prevBtn = document.createElement('button');
            prevBtn.textContent = '上一页';
            prevBtn.disabled = currentPage === 1;
            prevBtn.onclick = () => {
                if (currentPage > 1) {
                    currentPage--;
                    displayScenesPage();
                }
            };
            paginationControls.appendChild(prevBtn);

            // Page numbers
            const maxVisiblePages = 7;
            let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
            let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
            
            if (endPage - startPage < maxVisiblePages - 1) {
                startPage = Math.max(1, endPage - maxVisiblePages + 1);
            }

            if (startPage > 1) {
                const firstBtn = document.createElement('button');
                firstBtn.textContent = '1';
                firstBtn.onclick = () => {
                    currentPage = 1;
                    displayScenesPage();
                };
                paginationControls.appendChild(firstBtn);

                if (startPage > 2) {
                    const dots = document.createElement('span');
                    dots.textContent = '...';
                    paginationControls.appendChild(dots);
                }
            }

            for (let i = startPage; i <= endPage; i++) {
                const btn = document.createElement('button');
                btn.textContent = i;
                btn.className = i === currentPage ? 'active' : '';
                btn.onclick = () => {
                    currentPage = i;
                    displayScenesPage();
                };
                paginationControls.appendChild(btn);
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    const dots = document.createElement('span');
                    dots.textContent = '...';
                    paginationControls.appendChild(dots);
                }

                const lastBtn = document.createElement('button');
                lastBtn.textContent = totalPages;
                lastBtn.onclick = () => {
                    currentPage = totalPages;
                    displayScenesPage();
                };
                paginationControls.appendChild(lastBtn);
            }

            // Next button
            const nextBtn = document.createElement('button');
            nextBtn.textContent = '下一页';
            nextBtn.disabled = currentPage === totalPages;
            nextBtn.onclick = () => {
                if (currentPage < totalPages) {
                    currentPage++;
                    displayScenesPage();
                }
            };
            paginationControls.appendChild(nextBtn);
        }

        // Create scene
        function createScene() {
            const stage = document.getElementById('sceneStage').value.trim();
            const description = document.getElementById('sceneDesc').value.trim();
            const description_es = document.getElementById('sceneDescEs').value.trim();
            const description_en = document.getElementById('sceneDescEn').value.trim();
            const i_desc = document.getElementById('sceneIDesc').value.trim();

            if (!stage) {
                showAlert('sceneErrorAlert', 'Stage/ID 为必填项', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('stage', stage);
            formData.append('description', description);
            formData.append('description_es', description_es);
            formData.append('description_en', description_en);
            formData.append('i_desc', i_desc);

            fetch('<?php echo $_SERVER['PHP_SELF']; ?>?action=create', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('sceneSuccessAlert', data.message, 'success');
                    clearSceneForm();
                    loadScenes();
                } else {
                    showAlert('sceneErrorAlert', '错误: ' + data.error, 'error');
                }
            })
            .catch(error => {
                showAlert('sceneErrorAlert', '网络错误: ' + error.message, 'error');
            });
        }

        // Edit scene
        function editScene(stage, description, description_es, description_en, i_desc) {
            document.getElementById('editStage').value = stage;
            document.getElementById('editDesc').value = description;
            document.getElementById('editDescEs').value = description_es;
            document.getElementById('editDescEn').value = description_en;
            document.getElementById('editIDesc').value = i_desc;
            document.getElementById('editModal').style.display = 'block';
        }

        // Save edit
        function saveEdit() {
            const stage = document.getElementById('editStage').value;
            const description = document.getElementById('editDesc').value.trim();
            const description_es = document.getElementById('editDescEs').value.trim();
            const description_en = document.getElementById('editDescEn').value.trim();
            const i_desc = document.getElementById('editIDesc').value.trim();

            const formData = new FormData();
            formData.append('stage', stage);
            formData.append('description', description);
            formData.append('description_es', description_es);
            formData.append('description_en', description_en);
            formData.append('i_desc', i_desc);

            fetch('<?php echo $_SERVER['PHP_SELF']; ?>?action=update', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('sceneSuccessAlert', data.message, 'success');
                    closeEditModal();
                    loadScenes();
                } else {
                    showAlert('sceneErrorAlert', '错误: ' + data.error, 'error');
                }
            })
            .catch(error => {
                showAlert('sceneErrorAlert', '网络错误: ' + error.message, 'error');
            });
        }

        // Delete scene
        function deleteScene(stage) {
            if (!confirm('确定要删除这个场景吗？')) {
                return;
            }

            const formData = new FormData();
            formData.append('stage', stage);

            fetch('<?php echo $_SERVER['PHP_SELF']; ?>?action=delete', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('sceneSuccessAlert', data.message, 'success');
                    loadScenes();
                } else {
                    showAlert('sceneErrorAlert', '错误: ' + data.error, 'error');
                }
            })
            .catch(error => {
                showAlert('sceneErrorAlert', '网络错误: ' + error.message, 'error');
            });
        }

        // Clear form
        function clearSceneForm() {
            document.getElementById('sceneStage').value = '';
            document.getElementById('sceneDesc').value = '';
            document.getElementById('sceneDescEs').value = '';
            document.getElementById('sceneDescEn').value = '';
            document.getElementById('sceneIDesc').value = '';
        }

        // Generate Scene Descriptions
        function generateCurrentSceneDescription() {
            const iDesc = document.getElementById('sceneIDesc').value.trim();
            if (!iDesc) {
                showAlert('sceneErrorAlert', '请先填写内部描述', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('mode', 'single');
            formData.append('i_desc', iDesc);

            showProcessing();
            fetch('<?php echo dirname($_SERVER['PHP_SELF']); ?>/cmd/gen_scene_desc.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    document.getElementById('sceneDesc').value = data.description || '';
                    showAlert('sceneSuccessAlert', '已基于当前内部描述生成默认描述。', 'success');
                } else {
                    showAlert('sceneErrorAlert', '错误: ' + (data.error || '未知错误'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                showAlert('sceneErrorAlert', '网络错误: ' + error.message, 'error');
            });
        }

        function generateSceneDescriptions() {
            if (!confirm('从内部描述生成文案？这将向服务器发起请求。')) {
                return;
            }

            showProcessing();
            fetch('<?php echo dirname($_SERVER['PHP_SELF']); ?>/cmd/gen_scene_desc.php', {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    const processed = Number(data.processed || 0);
                    if (processed > 0) {
                        showAlert('sceneSuccessAlert', '文案生成成功，已处理 ' + processed + ' 条记录。', 'success');
                    } else {
                        showAlert('sceneSuccessAlert', '未处理任何记录。仅会处理 description 为空且 i_desc 不为空的场景。', 'success');
                    }
                    loadScenes();
                } else {
                    showAlert('sceneErrorAlert', '错误: ' + (data.error || '未知错误'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                showAlert('sceneErrorAlert', '网络错误: ' + error.message, 'error');
            });
        }

        // Import Scenes from File
        function importScenes() {
            const fileInput = document.getElementById('importFile');
            if (!fileInput.files || fileInput.files.length === 0) {
                showAlert('sceneErrorAlert', '请先选择要导入的文件', 'error');
                return;
            }

            if (!confirm('确定从文件导入场景吗？重复的 stage 将被更新。')) {
                return;
            }

            const formData = new FormData();
            formData.append('importFile', fileInput.files[0]);

            showProcessing();
            fetch('<?php echo $_SERVER['PHP_SELF']; ?>?action=importData', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showAlert('sceneSuccessAlert', data.message, 'success');
                    fileInput.value = ''; // Clear file input
                    loadScenes();
                } else {
                    showAlert('sceneErrorAlert', '错误: ' + (data.error || '未知错误'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                showAlert('sceneErrorAlert', '网络错误: ' + error.message, 'error');
            });
        }

        // Close edit modal
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // Utility functions
        function escapeHtml(text) {
            if (!text) return '';
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.toString().replace(/[&<>"']/g, m => map[m]);
        }

        function escapeAttr(text) {
            if (!text) return '';
            return text.toString().replace(/'/g, "\\'").replace(/"/g, '&quot;');
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('editModal');
            if (event.target === modal) {
                closeEditModal();
            }
        });

        // ==================== TOOLS TAB FUNCTIONS ====================

        // Load NPC Selector
        function loadNPCSelector(searchTerm = '') {
            const encodedSearch = encodeURIComponent(searchTerm || '');
            fetch('<?php echo $_SERVER['PHP_SELF']; ?>?action=loadNPCs&limit=25&q=' + encodedSearch)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const dropdown = document.getElementById('npcSelectDropdown');
                        dropdown.innerHTML = '';

                        if (data.data.length === 0) {
                            dropdown.innerHTML = '<div class="searchable-select-option">未找到 NPC</div>';
                        } else {
                            const header = document.createElement('div');
                            header.className = 'searchable-select-option';
                            header.style.fontWeight = '700';
                            header.style.cursor = 'default';
                            header.style.opacity = '0.7';
                            header.textContent = searchTerm ? '搜索结果' : '最近 NPC';
                            dropdown.appendChild(header);

                            data.data.forEach(npc => {
                                const option = document.createElement('div');
                                option.className = 'searchable-select-option';
                                const tags = [];
                                if (npc.sex_disposal !== null && npc.sex_disposal !== undefined) {
                                    tags.push('sex_disposal: ' + npc.sex_disposal);
                                }
                                if (npc.level !== null && npc.level !== undefined) {
                                    tags.push('level: ' + npc.level);
                                }

                                option.innerHTML = escapeHtml(npc.npc_name);
                                if (tags.length > 0) {
                                    option.innerHTML += '<span style="opacity:0.7; font-size:11px; margin-left:8px;">(' + escapeHtml(tags.join(', ')) + ')</span>';
                                }
                                option.dataset.id = npc.id;
                                option.dataset.name = npc.npc_name;
                                option.onclick = function() {
                                    selectNPC(npc.id, npc.npc_name, npc.sex_disposal, npc.level);
                                };
                                dropdown.appendChild(option);
                            });
                        }
                    } else {
                        showAlert('toolsErrorAlert', '加载 NPC 失败: ' + data.error, 'error');
                    }
                })
                .catch(error => {
                    showAlert('toolsErrorAlert', '网络错误: ' + error.message, 'error');
                });
        }

        // Load Connector Selector
        function loadConnectorSelector() {
            fetch('<?php echo $_SERVER['PHP_SELF']; ?>?action=loadConnectors')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const dropdown = document.getElementById('connectorSelectDropdown');
                        dropdown.innerHTML = '';

                        if (data.data.length === 0) {
                            dropdown.innerHTML = '<div class="searchable-select-option">未找到 Connector</div>';
                        } else {
                            window.connectorListData = data.data; // Store for searching
                            data.data.forEach(connector => {
                                const option = document.createElement('div');
                                option.className = 'searchable-select-option';
                                option.innerHTML = escapeHtml(connector.label);
                                option.dataset.id = connector.id;
                                option.dataset.label = connector.label;
                                option.onclick = function() {
                                    selectConnector(connector.id, connector.label);
                                };
                                dropdown.appendChild(option);
                            });
                        }
                    } else {
                        showAlert('toolsErrorAlert', '加载 Connector 失败: ' + data.error, 'error');
                    }
                })
                .catch(error => {
                    showAlert('toolsErrorAlert', '网络错误: ' + error.message, 'error');
                });
        }

        // NPC Search Handler
        document.addEventListener('DOMContentLoaded', function() {
            const npcInput = document.getElementById('npcSelectInput');
            const npcDropdown = document.getElementById('npcSelectDropdown');
            let npcSearchTimer = null;

            if (npcInput) {
                npcInput.addEventListener('focus', function() {
                    npcDropdown.classList.add('active');
                    loadNPCSelector(this.value.trim());
                });

                npcInput.addEventListener('input', function() {
                    const searchTerm = this.value.trim();
                    clearTimeout(npcSearchTimer);
                    npcSearchTimer = setTimeout(function() {
                        loadNPCSelector(searchTerm);
                    }, 200);
                    npcDropdown.classList.add('active');
                });

                document.addEventListener('click', function(e) {
                    if (!e.target.closest('.searchable-select-wrapper')) {
                        npcDropdown.classList.remove('active');
                    }
                });
            }
        });

        // Select NPC
        function selectNPC(npcId, npcName, sexDisposal = null, level = null) {
            document.getElementById('npcSelectInput').value = npcName;
            document.getElementById('npcSelectValue').value = npcId;
            document.getElementById('npcSelectDropdown').classList.remove('active');
            renderNPCStatus({
                npc_name: npcName,
                status: {
                    sex_disposal: sexDisposal,
                    level: level,
                    is_naked: null,
                    orgasmed: null,
                    orgasm_generated: null,
                    orgasm_generated_text: null,
                    orgasm_generated_text_original: null,
                    adult_entertainment_services_autodetected: null
                },
                gamets_last_updated: null
            });
            loadSelectedNPCStatus(npcId);
        }

        function renderNPCStatus(data) {
            const box = document.getElementById('npcStatusBox');
            if (!box) {
                return;
            }

            if (!data || !data.status) {
                box.innerHTML = '<div>暂无状态数据。</div>';
                return;
            }

            const st = data.status;
            const asText = (v) => (v === null || v === undefined || v === '') ? '-' : String(v);
            const shorten = (v, maxLen = 120) => {
                const t = asText(v);
                if (t === '-' || t.length <= maxLen) {
                    return t;
                }
                return t.slice(0, maxLen) + '...';
            };

            const fields = [
                { k: 'NPC', v: data.npc_name },
                { k: 'sex_disposal', v: st.sex_disposal },
                { k: 'level', v: st.level },
                { k: 'is_naked', v: st.is_naked },
                { k: 'orgasmed', v: st.orgasmed },
                { k: 'orgasm_generated', v: st.orgasm_generated },
                { k: 'adult_entertainment_services_autodetected', v: st.adult_entertainment_services_autodetected },
                { k: 'gamets_last_updated', v: data.gamets_last_updated }
            ];

            const gridHtml = fields.map(item => {
                return '' +
                    '<div class="status-item">' +
                        '<span class="k">' + escapeHtml(item.k) + '</span>' +
                        '<span class="v">' + escapeHtml(asText(item.v)) + '</span>' +
                    '</div>';
            }).join('');

            box.innerHTML = '' +
                '<div class="status-grid">' + gridHtml + '</div>' +
                '<details class="status-text-card">' +
                    '<summary>orgasm_generated_text（点击展开）</summary>' +
                    '<div class="mono">' + escapeHtml(asText(st.orgasm_generated_text)) + '</div>' +
                '</details>' +
                '<details class="status-text-card">' +
                    '<summary>orgasm_generated_text_original（点击展开）</summary>' +
                    '<div class="mono">' + escapeHtml(asText(st.orgasm_generated_text_original)) + '</div>' +
                '</details>' +
                '<div class="legend" style="margin-top:8px; font-size:11px;">文本预览：' + escapeHtml(shorten(st.orgasm_generated_text, 80)) + '</div>';

            setSexDisposalSelection(st.sex_disposal);
            renderActionUnlock(st);
        }

        function renderActionUnlock(status) {
            const box = document.getElementById('unlockActionsBox');
            if (!box) {
                return;
            }

            if (!status) {
                box.innerHTML = '';
                return;
            }

            const level = Number(status.level ?? 0);
            const disposal = Number(status.sex_disposal ?? -1);
            const isNaked = Number(status.is_naked ?? 0);
            const unlocked = [];
            const locked = [];

            if (level >= 1) {
                box.innerHTML = '' +
                    '<div class="unlock-title">动作解锁可视化</div>' +
                    '<div class="chip-list"><span class="chip info">当前为场景模式：仅启用 SexAction</span></div>';
                return;
            }

            unlocked.push('GiveHug', 'PutOnClothes', 'RitualConsumeSoul');

            if (disposal >= 1) {
                unlocked.push('Kiss');
            } else {
                locked.push('Kiss (need 1+)');
            }

            if (disposal >= 5) {
                if (isNaked < 1) {
                    unlocked.push('RemoveClothes');
                } else {
                    locked.push('RemoveClothes (already naked)');
                }
            } else {
                locked.push('RemoveClothes (need 5+)');
            }

            if (disposal >= 10) {
                unlocked.push('GiveMassage', 'StartSelfMasturbation', 'Masturbate');
            } else {
                locked.push('GiveMassage / StartSelfMasturbation / Masturbate (need 10+)');
            }

            if (disposal >= 20) {
                unlocked.push('GiveOralSex', 'MakeLove', 'StartThreesome', 'StartBoobjob', 'StartAnalSex');
            } else {
                locked.push('High scene actions (need 20+)');
            }

            const renderChipList = function(list, chipClass) {
                if (!list.length) {
                    return '<div class="chip-list"><span class="chip ' + chipClass + '">none</span></div>';
                }
                return '<div class="chip-list">' + list.map(item => '<span class="chip ' + chipClass + '">' + escapeHtml(item) + '</span>').join('') + '</div>';
            };

            box.innerHTML = '' +
                '<div class="unlock-title">动作解锁可视化</div>' +
                '<div class="unlock-section"><div class="unlock-title">当前已解锁</div>' + renderChipList(unlocked, 'unlocked') + '</div>' +
                '<div class="unlock-section"><div class="unlock-title">未解锁 / 条件</div>' + renderChipList(locked, 'locked') + '</div>';
        }

        function setSexDisposalSelection(value) {
            const selector = document.getElementById('sexDisposalValue');
            if (!selector) {
                return;
            }

            if (value === null || value === undefined || value === '') {
                selector.value = '';
                return;
            }

            const textValue = String(value);
            const existingOption = Array.from(selector.options).some(function(opt) {
                return opt.value === textValue;
            });

            if (!existingOption) {
                const dynamicOption = document.createElement('option');
                dynamicOption.value = textValue;
                dynamicOption.text = textValue + ': 当前自定义值';
                selector.appendChild(dynamicOption);
            }
            selector.value = textValue;
        }

        function loadSelectedNPCStatus(npcId) {
            if (!npcId) {
                return;
            }

            fetch('<?php echo $_SERVER['PHP_SELF']; ?>?action=getNPCStatus&npc_id=' + encodeURIComponent(npcId))
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderNPCStatus(data.data);
                    } else {
                        showAlert('toolsErrorAlert', '加载 NPC 状态失败: ' + (data.error || '未知错误'), 'error');
                    }
                })
                .catch(error => {
                    showAlert('toolsErrorAlert', '网络错误: ' + error.message, 'error');
                });
        }

        function setCaptureStatus(text, active = false) {
            const statusEl = document.getElementById('stageCaptureStatus');
            if (!statusEl) {
                return;
            }
            statusEl.textContent = text;
            statusEl.classList.toggle('active', active);
        }

        function startStageCapture() {
            stageCaptureBaseline = new Set(allScenes.map(scene => String(scene.stage || '').trim()).filter(Boolean));
            stageCaptureActive = true;
            setCaptureStatus('正在捕获... 请在游戏中触发场景。', true);

            if (stageCaptureTimer) {
                clearInterval(stageCaptureTimer);
            }

            stageCaptureTimer = setInterval(pollCapturedStages, 2500);
            showAlert('sceneSuccessAlert', '已开始捕获 Stage。请在游戏中触发场景并等待 2-3 秒。', 'success');
        }

        function stopStageCapture() {
            stageCaptureActive = false;
            if (stageCaptureTimer) {
                clearInterval(stageCaptureTimer);
                stageCaptureTimer = null;
            }
            setCaptureStatus('捕获未启动', false);
        }

        function focusStageInTable(stageId) {
            const index = allScenes.findIndex(scene => String(scene.stage || '').trim() === stageId);
            if (index < 0) {
                return;
            }
            currentPage = Math.floor(index / itemsPerPage) + 1;
            displayScenesPage();
        }

        function pollCapturedStages() {
            if (!stageCaptureActive) {
                return;
            }

            fetch('<?php echo $_SERVER['PHP_SELF']; ?>?action=read')
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        return;
                    }

                    const latestScenes = data.data || [];
                    const newStages = [];

                    latestScenes.forEach(scene => {
                        const stageId = String(scene.stage || '').trim();
                        if (!stageId) {
                            return;
                        }
                        if (!stageCaptureBaseline.has(stageId)) {
                            stageCaptureBaseline.add(stageId);
                            highlightedStages.add(stageId);
                            newStages.push(stageId);
                        }
                    });

                    if (newStages.length > 0) {
                        allScenes = latestScenes;
                        focusStageInTable(newStages[0]);
                        setCaptureStatus('已捕获: ' + newStages.join(', '), true);
                        showAlert('sceneSuccessAlert', '已捕获新 Stage: ' + newStages.join(', '), 'success');
                    }
                })
                .catch(() => {
                    // Keep capture alive on transient network errors
                });
        }

        // Select Connector
        function selectConnector(connectorId, connectorLabel) {
            document.getElementById('connectorSelectInput').value = connectorLabel;
            document.getElementById('connectorSelectValue').value = connectorId;
            document.getElementById('connectorSelectDropdown').classList.remove('active');
        }

        // Connector Search Handler
        document.addEventListener('DOMContentLoaded', function() {
            const connectorInput = document.getElementById('connectorSelectInput');
            const connectorDropdown = document.getElementById('connectorSelectDropdown');

            if (connectorInput) {
                connectorInput.addEventListener('focus', function() {
                    connectorDropdown.classList.add('active');
                });

                connectorInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const options = connectorDropdown.querySelectorAll('.searchable-select-option');

                    options.forEach(option => {
                        const text = option.textContent.toLowerCase();
                        option.style.display = text.includes(searchTerm) ? 'block' : 'none';
                    });

                    if (searchTerm.length > 0) {
                        connectorDropdown.classList.add('active');
                    } else {
                        connectorDropdown.classList.add('active');
                    }
                });

                document.addEventListener('click', function(e) {
                    if (!e.target.closest('.searchable-select-wrapper')) {
                        connectorDropdown.classList.remove('active');
                    }
                });
            }
        });

        // Generate Prompt
        function generatePrompt(fieldType) {
            const npcId = document.getElementById('npcSelectValue').value;
            const connectorId = document.getElementById('connectorSelectValue').value;
            const profanityLevel = document.getElementById('profanityLevel').value;

            if (!npcId) {
                showAlert('toolsErrorAlert', '请先选择 NPC', 'error');
                return;
            }

            if (!connectorId) {
                showAlert('toolsErrorAlert', '请先选择 Connector', 'error');
                return;
            }

            if (!profanityLevel) {
                showAlert('toolsErrorAlert', '请选择粗口等级', 'error');
                return;
            }

            // Fetch to cmd/gen_prompt.php with the selected NPC, connector, profanity level, and field type
            const formData = new FormData();
            formData.append('npc_id', npcId);
            formData.append('connector_id', connectorId);
            formData.append('profanity_level', profanityLevel);
            formData.append('field_type', fieldType);
            // Coding Agent: Do not touch URL. is ok as is.
            showProcessing()
            fetch('<?php echo dirname($_SERVER['PHP_SELF']); ?>/cmd/gen_prompt.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    // Update the appropriate textarea with generated content
                    if (fieldType === 'sex_prompt') {
                        document.getElementById('sexPrompt').value = data.prompt || '';
                    } else if (fieldType === 'sex_speech_style') {
                        document.getElementById('sexSpeechStyle').value = data.prompt || '';
                    }
                    showAlert('toolsSuccessAlert', '提示词生成成功', 'success');
                } else {
                    showAlert('toolsErrorAlert', '生成提示词失败: ' + (data.error || '未知错误'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                showAlert('toolsErrorAlert', '网络错误: ' + error.message, 'error');
            });
        }

        // Submit Tools Form
        function submitToolsForm() {
            const npcId = document.getElementById('npcSelectValue').value;
            const connectorId = document.getElementById('connectorSelectValue').value;
            const profanityLevel = document.getElementById('profanityLevel').value;
            const sexPrompt = document.getElementById('sexPrompt').value.trim();
            const sexSpeechStyle = document.getElementById('sexSpeechStyle').value.trim();
            const sexDisposal = document.getElementById('sexDisposalValue').value;

            if (!npcId) {
                showAlert('toolsErrorAlert', '请选择 NPC', 'error');
                return;
            }

            /* This fields are not needed when submitting form, we only need npc_id, sex_prompt and sex_speech_style
            if (!connectorId) {
                showAlert('toolsErrorAlert', 'Please select a Connector', 'error');
                return;
            }

            if (!profanityLevel) {
                showAlert('toolsErrorAlert', 'Please select a Profanity Level', 'error');
                return;
            }
            */
            const formData = new FormData();
            formData.append('npc_id', npcId);
            formData.append('connector_id', connectorId);
            formData.append('profanity_level', profanityLevel);
            formData.append('sex_prompt', sexPrompt);
            formData.append('sex_speech_style', sexSpeechStyle);
            formData.append('sex_disposal', sexDisposal);

            showProcessing();
            fetch('<?php echo $_SERVER['PHP_SELF']; ?>?action=submitToolsForm', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showAlert('toolsSuccessAlert', data.message || '提交成功', 'success');
                    loadSelectedNPCStatus(npcId);
                } else {
                    showAlert('toolsErrorAlert', '错误: ' + (data.error || '未知错误'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                showAlert('toolsErrorAlert', '网络错误: ' + error.message, 'error');
            });
        }

        // Clear Tools Form
       function clearToolsForm()
        {
            document . getElementById('npcSelectInput') . value       = '';
            document . getElementById('npcSelectValue') . value       = '';
            document . getElementById('connectorSelectInput') . value = '';
            document . getElementById('connectorSelectValue') . value = '';
            document . getElementById('sexPrompt') . value            = '';
            document . getElementById('sexSpeechStyle') . value       = '';
            document . getElementById('sexDisposalValue') . value     = '';
            const statusBox = document.getElementById('npcStatusBox');
            if (statusBox) {
                statusBox.innerHTML = '<div>请选择 NPC 查看状态。</div>';
            }
            const unlockBox = document.getElementById('unlockActionsBox');
            if (unlockBox) {
                unlockBox.innerHTML = '';
            }
        }

        // ==================== SETTINGS TAB FUNCTIONS ====================

        // Load Settings
        function loadSettings() {
            fetch('<?php echo $_SERVER['PHP_SELF']; ?>?action=loadSettings')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('xttsModifyLevel1').checked = data.data.XTTS_MODIFY_LEVEL1 || false;
                        document.getElementById('xttsModifyLevel2').checked = data.data.XTTS_MODIFY_LEVEL2 || false;
                        document.getElementById('trackDrunkStatus').checked = data.data.TRACK_DRUNK_STATUS || false;
                        document.getElementById('trackFertilityInfo').checked = data.data.TRACK_FERTILITY_INFO || false;
                        document.getElementById('genericGlossary').value = data.data.GENERIC_GLOSSARY || '';
                    } else {
                        showAlert('settingsErrorAlert', '加载设置失败: ' + data.error, 'error');
                    }
                })
                .catch(error => {
                    showAlert('settingsErrorAlert', '网络错误: ' + error.message, 'error');
                });
        }

        // Save Settings
        function saveSettings() {
            const formData = new FormData();
            formData.append('XTTS_MODIFY_LEVEL1', document.getElementById('xttsModifyLevel1').checked);
            formData.append('XTTS_MODIFY_LEVEL2', document.getElementById('xttsModifyLevel2').checked);
            formData.append('TRACK_DRUNK_STATUS', document.getElementById('trackDrunkStatus').checked);
            formData.append('TRACK_FERTILITY_INFO', document.getElementById('trackFertilityInfo').checked);
            formData.append('GENERIC_GLOSSARY', document.getElementById('genericGlossary').value.trim());

            fetch('<?php echo $_SERVER['PHP_SELF']; ?>?action=saveSettings', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('settingsSuccessAlert', data.message, 'success');
                } else {
                    showAlert('settingsErrorAlert', '保存设置失败: ' + data.error, 'error');
                }
            })
            .catch(error => {
                showAlert('settingsErrorAlert', '网络错误: ' + error.message, 'error');
            });
        }

        // Reset Settings
        function resetSettings() {
            loadSettings();
        }

        // Generate Table
        function generateTable() {
            if (!confirm('确定创建 ext_aiagentnsfw_scenes 表吗？这会初始化场景数据表。')) {
                return;
            }

            showProcessing();
            fetch('<?php echo $_SERVER['PHP_SELF']; ?>?action=generateTable', {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showAlert('settingsSuccessAlert', data.message, 'success');
                } else {
                    showAlert('settingsErrorAlert', '错误: ' + (data.error || '未知错误'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                showAlert('settingsErrorAlert', '网络错误: ' + error.message, 'error');
            });
        }
        function showProcessing(){

            processingMessage                           = document . createElement('div');
            processingMessage . textContent             = '处理中...';
            processingMessage . style . position        = 'fixed';
            processingMessage . style . top             = '50%';
            processingMessage . style . left            = '50%';
            processingMessage . style . transform       = 'translate(-50%, -50%)';
            processingMessage . style . backgroundColor = '#000';
            processingMessage . style . color           = '#fff';
            processingMessage . style . padding         = '10px 20px';
            processingMessage . style . borderRadius    = '8px';
            processingMessage . style . zIndex          = '10001';
            processingMessage . id                      = "processing_wheel";
            document . body . appendChild(processingMessage);
        }
        function hideProcessing()
        {
            processingMessage . innerHTML      = '';
            processingMessage . style . zIndex = '-10001';

        }

    var processingMessage;
    </script>
</body>
</html>
