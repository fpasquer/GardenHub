<?php

// Router for PHP's built-in web server, used only by stream_telegram_transport_test.php.
// The scenario is selected via the chat_id form field already sent by StreamTelegramTransport::send().
$scenario = $_POST['chat_id'] ?? 'ok';

if ('gateway_markup' === $scenario) {
    $valid = '/bottest-token/editMessageReplyMarkup' === $_SERVER['REQUEST_URI']
        && '42' === ($_POST['message_id'] ?? null)
        && !array_key_exists('text', $_POST)
        && ['inline_keyboard' => []] === json_decode($_POST['reply_markup'] ?? '', true);
    header('Content-Type: application/json');
    echo json_encode(['ok' => $valid, 'result' => true]);

    return;
}

header('Content-Type: application/json');

if ('http_error' === $scenario) {
    http_response_code(500);
}

echo match ($scenario) {
    'invalid_json' => 'not-json{{{',
    'not_ok' => json_encode(['ok' => false, 'description' => 'simulated telegram rejection']),
    default => json_encode(['ok' => true, 'result' => ['message_id' => 1]]),
};
