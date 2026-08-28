<?php

/**

 * Unified website fetch — knowledge snippet + product catalog import.

 */



declare(strict_types=1);



require_once __DIR__ . '/db.php';

require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/demo-training.php';

require_once __DIR__ . '/website-import.php';

require_once __DIR__ . '/bot-knowledge.php';

require_once __DIR__ . '/lead-lifecycle.php';



/**

 * Fetch website content and import catalog for a bot.

 *

 * @return array{success: bool, error?: string, knowledge_added?: int, products_imported?: int, products_total?: int, website_url?: string, preview?: string, domain_changed?: bool, history_cleared?: bool}

 */

function fetch_business_from_website(int $botId, int $userId, string $url, bool $mergeKnowledge = true, bool $importCatalog = true): array
{
    @set_time_limit($importCatalog ? 180 : 90);

    ensure_bot_training_schema();

    ensure_lead_lifecycle_schema();



    $url = trim($url);

    if ($url === '') {

        return ['success' => false, 'error' => 'Enter your website URL first.'];

    }



    if (!preg_match('#^https?://#i', $url)) {

        $url = 'https://' . $url;

    }



    $bot = db_fetch('SELECT * FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);

    if (!$bot) {

        return ['success' => false, 'error' => 'Bot not found.'];

    }



    $snippet = fetch_website_snippet($url, 8000);

    if ($snippet === '') {

        return ['success' => false, 'error' => 'Could not read that website. Check the URL is public, or try again — if this is your own domain, the server may need outbound HTTPS/DNS enabled by your host.'];

    }



    if (function_exists('website_import_normalize_visible_text')) {

        $snippet = website_import_normalize_visible_text($snippet);

    }

    if (function_exists('website_import_dedupe_lines')) {

        $snippet = website_import_dedupe_lines($snippet);

    }



    $businessModel = trim((string) ($bot['business_model'] ?? ''));

    if ($businessModel !== '' && mb_strlen($snippet) > 120) {

        similar_text(

            mb_strtolower(preg_replace('/\s+/u', ' ', $businessModel) ?? $businessModel),

            mb_strtolower(preg_replace('/\s+/u', ' ', $snippet) ?? $snippet),

            $overlap

        );

        if ($overlap > 72) {

            $snippet = mb_substr($snippet, 0, 1200);

        }

    }



    $import = ['success' => true, 'imported' => 0, 'updated' => 0, 'total' => 0, 'platform' => ''];
    $productsImported = 0;
    $productsTotal = 0;

    if ($importCatalog) {
        try {
            $import = website_import_sync($botId, $userId, $url);
            $productsImported = (int) ($import['imported'] ?? $import['synced'] ?? 0);
            $productsTotal = (int) ($import['total'] ?? $productsImported);
        } catch (Throwable $e) {
            error_log('fetch_business_from_website catalog: ' . $e->getMessage());
            $import['success'] = false;
            $import['error'] = $e->getMessage();
        }
    }



    $knowledgeBlock = "── Website: {$url} ──\n" . $snippet;

    $existingKnowledge = trim((string) ($bot['bot_knowledge'] ?? ''));

    $oldHost = bot_knowledge_host_from_url((string) ($bot['website_url'] ?? ''));

    $newHost = bot_knowledge_host_from_url($url);

    $domainChanged = $oldHost !== '' && $newHost !== '' && $oldHost !== $newHost;

    $historyCleared = false;



    if ($domainChanged) {

        // New business domain — drop all prior website imports and manual paste tied to old business.

        $newKnowledge = $knowledgeBlock;

        $historyCleared = true;

    } elseif ($mergeKnowledge) {

        $manualKnowledge = bot_knowledge_manual_notes($existingKnowledge, $businessModel);



        $newKnowledge = $manualKnowledge !== ''

            ? $manualKnowledge . "\n\n" . $knowledgeBlock

            : $knowledgeBlock;

    } else {

        $newKnowledge = $knowledgeBlock;

    }



    $newKnowledge = bot_knowledge_normalize_for_storage($newKnowledge, $businessModel);



    $businessMode = $bot['business_mode'] ?? 'mixed';

    if ($productsImported > 0) {

        $businessMode = 'ecommerce';

    } elseif (($bot['business_mode'] ?? '') === '' || ($bot['business_mode'] ?? '') === 'mixed') {

        $businessMode = lifecycle_infer_business_mode($snippet);

    }



    db_execute(

        'UPDATE bots SET website_url = ?, bot_knowledge = ?, business_mode = ?, catalog_source_url = ?,

         catalog_source_synced_at = NOW(), knowledge_updated_at = NOW(),

         qualify_trigger = IF(? = 1, \'\', qualify_trigger),

         qualifying_questions = IF(? = 1, \'[]\', qualifying_questions)

         WHERE id = ? AND user_id = ?',

        'ssssiiii',

        [$url, $newKnowledge, $businessMode, $url, ($domainChanged && !(qualification_flow_load() && qualification_is_custom($bot))) ? 1 : 0, ($domainChanged && !(qualification_flow_load() && qualification_is_custom($bot))) ? 1 : 0, $botId, $userId]

    );



    bot_refresh_after_knowledge_change($botId);
    $historyCleared = true;

    if ($domainChanged) {
        db_execute(
            'DELETE FROM bot_products WHERE bot_id = ? AND user_id = ?',
            'ii',
            [$botId, $userId]
        );
    }

    return [

        'success'           => true,

        'website_url'       => $url,

        'knowledge_chars'   => mb_strlen($newKnowledge),

        'knowledge_added'   => mb_strlen($newKnowledge) - mb_strlen($existingKnowledge),

        'products_imported' => $productsImported,

        'products_total'    => $productsTotal,

        'preview'           => mb_substr($snippet, 0, 400) . (mb_strlen($snippet) > 400 ? '…' : ''),

        'domain_changed'    => $domainChanged,

        'history_cleared'   => $historyCleared,

        'import_note'       => $import['success'] ?? false ? null : ($import['error'] ?? 'Catalog import had issues — knowledge was still saved.'),

    ];

}

