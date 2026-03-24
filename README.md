# AI Agent NSFW (OStim Integration)

This extension integrates Herika/CHIM with OStim scenes so NPCs can react to intimate context, unlock actions progressively, and keep per-NPC state in extended data.

## What it does

- Hooks into OStim-related events and routes them into Herika/CHIM context.
- Tracks intimacy state per NPC (`aiagent_nsfw_intimacy_data`).
- Exposes action functions (kiss, clothes, scene starts, scene actions).
- Supports prompt/style generation for NPC intimate dialogue.
- Provides a web config manager for scenes, tools, and extension settings.

## Key concepts

- `sex_disposal`: progression value used to unlock actions.
- `level`:
  - `0` = no scene
  - `1` = idle/intimate scene context
  - `2` = active scene context
- Scene descriptions are stored in `ext_aiagentnsfw_scenes` and injected into context by stage.

## Repository layout

- `common.php` - core event processing and intimacy state updates.
- `functions.php` - function/action registration and unlock logic.
- `preprocessing.php`, `prerequest.php`, `context*.php`, `prepostrequest.php` - Herika pipeline hooks.
- `prompts.php` - prompt templates for scene-related messages.
- `config_manager.php` - web UI for scene CRUD, tools, and settings.
- `cmd/` - helper scripts for generating prompts and scene descriptions.
- `mod/Source/Scripts/AIAgentNSFW.psc` - Papyrus logic and OStim integration.

## Requirements

- Herika/CHIM environment with extension loading.
- OStim setup in-game.
- Database access for `core_npc_master` and extension tables.
- PHP runtime for server-side scripts.

## Installation

1. Copy this extension folder into your Herika extensions path, e.g.:
   - `HerikaServer/ext/aiagent_nsfw`
2. Ensure `manifest.json` is visible to your extension loader.
3. Open config manager:
   - `/HerikaServer/ext/aiagent_nsfw/config_manager.php`
4. If needed, create the scenes table from the Settings tab.

## Config manager quick start

### Scenes tab

- Add/edit scene stage descriptions.
- Use Stage Capture to detect new stage IDs while triggering scenes in-game.

### Tools tab

- Select NPC and connector.
- Generate `sex_prompt` / `sex_speech_style`.
- View and edit `sex_disposal`.
- Inspect intimacy fields including generated orgasm text fields.

### Settings tab

- Save extension settings such as XTTS modifiers and tracking toggles.
- Import scene definitions from TSV.

## Data model (extended NPC data)

`aiagent_nsfw_intimacy_data` commonly contains:

- `level`
- `is_naked`
- `orgasmed`
- `sex_disposal`
- `orgasm_generated`
- `orgasm_generated_text`
- `orgasm_generated_text_original`
- `adult_entertainment_services_autodetected`

## Notes

- UI text can be localized without changing logic keys/commands.
- Action names and command IDs should remain unchanged for compatibility.

## License

No license file is currently included in this repository.
