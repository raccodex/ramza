<?php
declare(strict_types=1);

require dirname(__DIR__) . '/install/bootstrap.php';

$port = (int) (getenv('RAMZA_MIGRATION_TEST_PORT') ?: 0);
if ($port < 1024 || $port > 65535) {
    fwrite(STDERR, "Set RAMZA_MIGRATION_TEST_PORT to a disposable MariaDB instance.\n");
    exit(2);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = '127.0.0.1';
$sourceName = 'ramza_migration_source_disposable';
$targetName = 'ramza_migration_target_disposable';
$server = new mysqli($host, 'root', '', '', $port);
$exitCode = 1;

try {
    $server->query("DROP DATABASE IF EXISTS `{$sourceName}`");
    $server->query("DROP DATABASE IF EXISTS `{$targetName}`");
    $server->query("CREATE DATABASE `{$sourceName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $server->query("CREATE DATABASE `{$targetName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $paths = new RACInstallerPaths();
    $logger = new RACInstallerLogger($paths);
    $source = new mysqli($host, 'root', '', $sourceName, $port);
    $source->set_charset('utf8mb4');
    $source->query(
        "CREATE TABLE `Wo_Config` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `value` mediumtext NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_config_name` (`name`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4"
    );
    $source->query(
        "CREATE TABLE `Wo_Users` (
            `user_id` int unsigned NOT NULL AUTO_INCREMENT,
            `username` varchar(32) NOT NULL,
            `admin` tinyint unsigned NOT NULL DEFAULT 0,
            `active` tinyint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`user_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4"
    );
    $source->query(
        "CREATE TABLE `Wo_UserFields` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int unsigned NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4"
    );
    $source->query(
        "CREATE TABLE `Wo_Posts` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int unsigned NOT NULL,
            `postText` text NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4"
    );
    $source->query(
        "CREATE TABLE `Wo_Reactions_Types` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `wowonder_icon` varchar(300) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4"
    );
    for ($number = 1; $number <= 95; $number++) {
        $source->query(
            sprintf(
                'CREATE TABLE `Wo_MigrationFixture_%03d` (`id` int unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=MyISAM',
                $number
            )
        );
    }
    $config = $source->prepare('INSERT INTO `Wo_Config` (`name`,`value`) VALUES (?,?)');
    foreach ([
        'version' => '4.3.3',
        'site_url' => 'https://old.example.test',
        'siteName' => 'Old Community',
        'siteTitle' => 'Old Community Title',
        'siteEmail' => 'owner@example.test',
    ] as $name => $value) {
        $config->bind_param('ss', $name, $value);
        $config->execute();
    }
    $config->close();
    $source->query("INSERT INTO `Wo_Users` (`username`,`admin`,`active`) VALUES ('sourceadmin',1,1)");
    $source->query('INSERT INTO `Wo_UserFields` (`user_id`) VALUES (1)');
    $source->query("INSERT INTO `Wo_Posts` (`user_id`,`postText`) VALUES (1,'Migration fixture post')");

    $sourceUsersBefore = (int) $source->query('SELECT COUNT(*) total FROM `Wo_Users`')->fetch_assoc()['total'];
    $sourceConfigBefore = (string) $source->query(
        "SELECT `value` FROM `Wo_Config` WHERE `name`='siteName' LIMIT 1"
    )->fetch_assoc()['value'];
    $source->close();

    $migrator = new RACInstallerWoWonderMigrator($logger, $paths);
    $sourceConfig = $migrator->validateSource([
        'host' => $host . ':' . $port,
        'name' => $sourceName,
        'user' => 'root',
        'pass' => '',
        'root_path' => '',
    ], [
        'host' => $host . ':' . $port,
        'name' => $targetName,
        'user' => 'root',
        'pass' => '',
    ]);

    $target = new mysqli($host, 'root', '', $targetName, $port);
    $target->set_charset('utf8mb4');
    $migration = $migrator->migrate($target, $sourceConfig);
    $adminId = (new RACInstallerPostInstall($logger))->configureMigration($target, [
        'url' => 'https://new.example.test',
        'name' => 'Ramza Migrated Community',
        'title' => 'Migrated Community',
        'email' => 'owner@example.test',
    ]);
    $targetUsers = (int) $target->query('SELECT COUNT(*) total FROM `Wo_Users`')->fetch_assoc()['total'];
    $targetNameValue = (string) $target->query(
        "SELECT `value` FROM `Wo_Config` WHERE `name`='siteName' LIMIT 1"
    )->fetch_assoc()['value'];
    $target->close();

    $sourceCheck = new mysqli($host, 'root', '', $sourceName, $port);
    $sourceUsersAfter = (int) $sourceCheck->query('SELECT COUNT(*) total FROM `Wo_Users`')->fetch_assoc()['total'];
    $sourceConfigAfter = (string) $sourceCheck->query(
        "SELECT `value` FROM `Wo_Config` WHERE `name`='siteName' LIMIT 1"
    )->fetch_assoc()['value'];
    $sourceCheck->close();

    $sourceUnchanged = $sourceUsersBefore === $sourceUsersAfter
        && $sourceConfigBefore === $sourceConfigAfter;
    $ok = $sourceUnchanged
        && $targetUsers === $sourceUsersBefore
        && $targetNameValue === 'Ramza Migrated Community'
        && (int) ($sourceConfig['administrators'] ?? 0) === 1
        && (int) ($sourceConfig['settings'] ?? 0) === 5
        && (int) $migration['source_tables'] >= 100
        && (int) $migration['tables'] >= (int) $migration['source_tables'] + 18
        && !empty($migration['upload']['required'])
        && empty($migration['upload']['copied']);

    echo json_encode([
        'ok' => $ok,
        'fixture_source_tables' => 100,
        'simulated_wowonder_tables' => $migration['source_tables'],
        'migrated_ramza_tables' => $migration['tables'],
        'copied_rows' => $migration['rows'],
        'users_preserved' => $targetUsers,
        'administrators_detected' => $sourceConfig['administrators'] ?? 0,
        'settings_detected' => $sourceConfig['settings'] ?? 0,
        'source_unchanged' => $sourceUnchanged,
        'admin_id' => $adminId,
        'local_upload_copy_required' => $migration['upload']['required'],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    $exitCode = $ok ? 0 : 1;
} finally {
    try {
        $server->query("DROP DATABASE IF EXISTS `{$targetName}`");
        $server->query("DROP DATABASE IF EXISTS `{$sourceName}`");
    } finally {
        $server->close();
    }
}

exit($exitCode);
