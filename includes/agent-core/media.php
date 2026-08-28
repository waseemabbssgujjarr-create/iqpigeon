<?php
/**
 * Media enrich interface. Phase 1: wrap turn_engine_process_turn_media.
 * Called from deliver_turn only for enabled bots. Not a Graph send.
 */
declare(strict_types=1);

/**
 * @param array<string, mixed> $bot
 */
function agent_core_media_should_enrich(array $bot): bool
{
    return function_exists('agent_core_enabled') && agent_core_enabled($bot);
}

function agent_core_media_enrich(int $turnId, string $downloadToken): void
{
    if ($turnId <= 0 || !function_exists('turn_engine_process_turn_media')) {
        return;
    }
    turn_engine_process_turn_media($turnId, $downloadToken);
}
