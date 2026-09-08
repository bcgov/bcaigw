<?php

return [
    'max_payload_bytes' => (int) env('GATEWAY_MAX_PAYLOAD_BYTES', 1_048_576),
    'max_messages' => (int) env('GATEWAY_MAX_MESSAGES', 128),
    'max_inputs' => (int) env('GATEWAY_MAX_INPUTS', 128),
    'max_content_parts' => (int) env('GATEWAY_MAX_CONTENT_PARTS', 64),
    'max_text_characters' => (int) env('GATEWAY_MAX_TEXT_CHARACTERS', 200_000),
    'max_image_url_characters' => (int) env('GATEWAY_MAX_IMAGE_URL_CHARACTERS', 2_048),
    'max_metadata_entries' => (int) env('GATEWAY_MAX_METADATA_ENTRIES', 16),
    'max_embedding_dimensions' => (int) env('GATEWAY_MAX_EMBEDDING_DIMENSIONS', 3072),
    'safe_connect_retries' => (int) env('GATEWAY_SAFE_CONNECT_RETRIES', 1),
    'max_concurrent_requests_per_application' => (int) env('GATEWAY_MAX_CONCURRENCY', 20),
    'connect_timeout_seconds' => (int) env('GATEWAY_CONNECT_TIMEOUT', 5),
    'first_byte_timeout_seconds' => (int) env('GATEWAY_FIRST_BYTE_TIMEOUT', 15),
    'idle_timeout_seconds' => (int) env('GATEWAY_IDLE_TIMEOUT', 30),
    'max_stream_event_bytes' => (int) env('GATEWAY_MAX_STREAM_EVENT_BYTES', 262_144),
    'secret_directory' => env('GATEWAY_SECRET_DIRECTORY', '/run/secrets'),
    'azure_api_version' => env('GATEWAY_AZURE_API_VERSION', '2024-10-21'),
];
