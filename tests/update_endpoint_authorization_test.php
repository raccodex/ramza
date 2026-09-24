<?php
declare(strict_types=1);

$valid = ($argv[1] ?? '') === 'valid';
$f = 'ramza_update';
$s = 'unknown-test-action';
$wo = ['loggedin' => true];
$_POST['hash_id'] = $valid ? 'matching-session-token' : 'wrong-session-token';

function Wo_IsAdmin(): bool
{
    return true;
}

function Wo_CheckSession(string $hash): bool
{
    return hash_equals('matching-session-token', $hash);
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'xhr' . DIRECTORY_SEPARATOR . 'ramza_update.php';
