<?php

return [
    // Must remain above the maximum upstream target timeout (currently 120 seconds).
    'reservation_timeout_seconds' => (int) env('QUOTA_RESERVATION_TIMEOUT', 300),
    'recovery_batch_size' => (int) env('QUOTA_RECOVERY_BATCH_SIZE', 100),
];
