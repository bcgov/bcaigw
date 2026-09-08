<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input'), true);

header('Content-Type: application/json');
header('x-request-id: deterministic-mock-request');

if ($path === '/v1/chat/completions' && is_array($body)) {
    echo json_encode([
        'id' => 'chatcmpl_mock',
        'object' => 'chat.completion',
        'created' => 1,
        'model' => $body['model'] ?? null,
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'Deterministic mock response.'],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 4,
            'completion_tokens' => 3,
            'total_tokens' => 7,
        ],
    ], JSON_THROW_ON_ERROR);

    return;
}

http_response_code(404);
echo json_encode(['error' => 'not found'], JSON_THROW_ON_ERROR);
