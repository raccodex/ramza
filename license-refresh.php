<?php
declare(strict_types=1);

// Kept as a compatibility endpoint for older license portals. Ramza is now
// open source, so remote refresh, block, and entitlement commands are ignored.
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode([
    'ok' => true,
    'status' => 'open_source',
    'message' => 'Remote license verification is disabled.',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
