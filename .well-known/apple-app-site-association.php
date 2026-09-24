<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$teamId = preg_replace('/[^A-Z0-9]/', '', (string)(getenv('RAMZA_APPLE_TEAM_ID') ?: ''));
$appId = $teamId !== '' ? $teamId . '.com.raccodex.ramza' : '';

echo json_encode([
    'applinks' => [
        'details' => $appId === '' ? [] : [[
            'appIDs' => [$appId],
            'components' => [
                ['/' => '*', 'comment' => 'All RACSocial links'],
            ],
        ]],
    ],
    'webcredentials' => [
        'apps' => $appId === '' ? [] : [$appId],
    ],
], JSON_UNESCAPED_SLASHES);
