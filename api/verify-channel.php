<?php

/**

 * Verify WhatsApp / Instagram channel credentials (AJAX).

 */



require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/../includes/db.php';

require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../includes/whatsapp.php';

require_once __DIR__ . '/../includes/whatsapp-token.php';

require_once __DIR__ . '/../includes/instagram.php';



$user = require_login();



if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    json_response(['success' => false, 'message' => 'Method not allowed'], 405);

}



security_require_api_csrf();



$input = security_cached_json_body();

$channel = $input['channel'] ?? '';

$botId = (int) ($input['bot_id'] ?? 0);



if (!$botId) {

    json_response(['success' => false, 'message' => 'Bot ID required.'], 400);

}



$bot = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, (int) $user['id']]);

if (!$bot) {

    json_response(['success' => false, 'message' => 'Bot not found.'], 404);

}



if ($channel === 'whatsapp') {

    $phoneId = trim($input['phone_id'] ?? '');

    $token = trim($input['token'] ?? '');
    $usedSavedToken = false;



    $existing = db_fetch(

        'SELECT whatsapp_phone_id, whatsapp_token FROM bots WHERE id = ? AND user_id = ?',

        'ii',

        [$botId, (int) $user['id']]

    );



    if ($phoneId === '') {

        json_response(['success' => false, 'message' => 'Phone Number ID is required.'], 400);

    }



    if ($token === '') {

        if (empty($existing['whatsapp_token'])) {

            json_response([

                'success' => false,

                'message' => 'Access token is required. In Meta App → WhatsApp → API Setup, click "Generate access token" and paste it in field 2.',

            ], 400);

        }

        $token = bot_whatsapp_token_plain((string) $existing['whatsapp_token']);

        if ($token === false || $token === '') {

            json_response(['success' => false, 'message' => 'Could not read saved token. Enter a new access token.'], 400);

        }

        $usedSavedToken = true;

    }



    $result = verify_whatsapp_credentials($phoneId, $token);

    if (!$result['success'] && $usedSavedToken && !str_contains((string) ($result['message'] ?? ''), 'cannot reach Meta')) {
        $result['message'] = ($result['message'] ?? 'Verification failed.')
            . ' You did not paste a new token — we tested your previously saved token. Click Replace token, paste the token you just generated in Meta, then Verify & Connect again.';
    }



    if ($result['success']) {

        $resolvedPhoneId = $result['phone_id'] ?? $phoneId;

        bot_whatsapp_token_save($botId, (int) $user['id'], $resolvedPhoneId, $token);



        foreach (whatsapp_waba_ids_from_token($token) as $wabaId) {

            whatsapp_subscribe_waba_to_app($wabaId, $token);

        }



        $result['message'] = ($result['message'] ?? 'WhatsApp connected successfully.')

            . ' Register the webhook URL in Meta (see setup guide).';

        $result['phone_id'] = $resolvedPhoneId;

    } else {

        whatsapp_mark_token_failure($botId, (string) ($result['message'] ?? 'Verification failed.'));

    }



    json_response($result);

}



if ($channel === 'instagram') {

    $pageId = trim($input['page_id'] ?? '');

    $token = trim($input['token'] ?? '');



    if ($pageId === '' || $token === '') {

        json_response(['success' => false, 'message' => 'Page ID and token are required.'], 400);

    }



    $result = verify_instagram_credentials($pageId, $token);



    if ($result['success']) {

        db_execute(

            'UPDATE bots SET instagram_page_id = ?, instagram_token = ?, instagram_verified = 1 WHERE id = ? AND user_id = ?',

            'ssii',

            [$pageId, encrypt_token($token), $botId, (int) $user['id']]

        );

    }



    json_response($result);

}



json_response(['success' => false, 'message' => 'Invalid channel.'], 400);

