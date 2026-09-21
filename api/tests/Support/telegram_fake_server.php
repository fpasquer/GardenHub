<?php

// Router for PHP's built-in web server, used only by stream_telegram_transport_test.php.
// The scenario is selected via the chat_id form field already sent by StreamTelegramTransport::send().
$scenario = $_POST['chat_id'] ?? 'ok';

header('Content-Type: application/json');

if ('http_error' === $scenario) {
    http_response_code(500);
}

echo match ($scenario) {
    'invalid_json' => 'not-json{{{',
    'not_ok' => json_encode(['ok' => false, 'description' => 'simulated telegram rejection']),
    default => json_encode(['ok' => true, 'result' => ['message_id' => 1]]),
};
