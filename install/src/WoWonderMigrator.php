<?php
declare(strict_types=1);

final class RACInstallerWoWonderMigrator
{
    public function __construct(
        private readonly RACInstallerLogger $logger,
        private readonly RACInstallerPaths $paths
    ) {
    }

    public function validateSource(array $input, array $target): array
    {
        foreach (['host', 'name', 'user', 'pass', 'root_path'] as $key) {
            if (!array_key_exists($key, $input) || !is_string($input[$key])) {
                throw new RACInstallerUserException('WoWonder source fields must be plain text values.');
            }
        }
        if (trim($input['host']) === '' || trim($input['name']) === '' || trim($input['user']) === '') {
            throw new RACInstallerUserException('WoWonder database host, name, and username are required.');
        }
        if ($this->sameDatabase($input, $target)) {
            throw new RACInstallerUserException('The WoWonder source and Ramza target databases must be different. The source database is never modified.');
        }

        $source = null;
        try {
            $source = $this->connect($input);
            $this->beginReadOnly($source);
            $tables = $this->baseTables($source);
            foreach (['Wo_Config', 'Wo_Users', 'Wo_UserFields', 'Wo_Posts'] as $required) {
                if (!$this->containsTable($tables, $required)) {
                    throw new RACInstallerUserException('The source is not a supported WoWonder database. Required table missing: ' . $required . '.');
                }
            }
            if (count($tables) < 100) {
                throw new RACInstallerUserException('The source WoWonder database is incomplete. At least 100 base tables are expected.');
            }

            $publicConfig = $this->publicSourceConfig($source);
            $users = (int) ($source->query('SELECT COUNT(*) AS total FROM `Wo_Users`')->fetch_assoc()['total'] ?? 0);
            $posts = (int) ($source->query('SELECT COUNT(*) AS total FROM `Wo_Posts`')->fetch_assoc()['total'] ?? 0);
            $administrators = (int) ($source->query("SELECT COUNT(*) AS total FROM `Wo_Users` WHERE `admin` = '1'")->fetch_assoc()['total'] ?? 0);
            $settings = (int) ($source->query('SELECT COUNT(*) AS total FROM `Wo_Config`')->fetch_assoc()['total'] ?? 0);
            if ($administrators < 1) {
                throw new RACInstallerUserException('The WoWonder source does not contain an administrator account.');
            }
            $source->rollback();

            $rootPath = $this->validateSourceRoot($input['root_path']);
            $usesLocalStorage = !$this->remoteStorageEnabled($publicConfig);
            return [
                'host' => trim($input['host']),
                'name' => trim($input['name']),
                'user' => $input['user'],
                'pass' => $input['pass'],
                'root_path' => $rootPath,
                'tables' => count($tables),
                'users' => $users,
                'posts' => $posts,
                'administrators' => $administrators,
                'settings' => $settings,
                'version' => (string) ($publicConfig['version'] ?? 'unknown'),
                'site_url' => (string) ($publicConfig['site_url'] ?? ''),
                'site_name' => (string) ($publicConfig['siteName'] ?? 'Ramza'),
                'site_title' => (string) ($publicConfig['siteTitle'] ?? ''),
                'site_email' => (string) ($publicConfig['siteEmail'] ?? ''),
                'uses_local_storage' => $usesLocalStorage,
            ];
        } catch (RACInstallerUserException $error) {
            throw $error;
        } catch (mysqli_sql_exception $error) {
            $this->logger->error('wowonder-source-validation', $error, [
                'errno' => $error->getCode(),
                'host' => trim($input['host']),
                'database' => trim($input['name']),
            ]);
            throw new RACInstallerUserException($this->safeConnectMessage($error));
        } finally {
            if ($source instanceof mysqli) {
                $source->close();
            }
        }
    }

    public function migrate(mysqli $target, array $sourceConfig): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        $existingTargetTables = $this->baseTables($target);
        if ($existingTargetTables !== []) {
            throw new RACInstallerUserException('The Ramza target database is no longer empty. Create a new empty database and restart migration.');
        }

        $source = $this->connect($sourceConfig);
        $tableCount = 0;
        $rowCount = 0;
        $target->query('SET FOREIGN_KEY_CHECKS=0');
        try {
            $this->beginReadOnly($source);
            foreach ($this->baseTables($source) as $table) {
                $this->assertIdentifier($table);
                $createResult = $source->query('SHOW CREATE TABLE `' . $table . '`');
                $createRow = $createResult->fetch_array(MYSQLI_NUM);
                $createResult->free();
                if (!isset($createRow[1]) || !is_string($createRow[1])) {
                    throw new RACInstallerSystemException('Unable to read the source schema for ' . $table . '.');
                }
                $target->query($createRow[1]);
                $copied = $this->copyTableRows($source, $target, $table);
                $rowCount += $copied;
                $tableCount++;
                $this->logger->info('wowonder-migration-table', [
                    'table' => $table,
                    'rows' => $copied,
                ]);
            }
            $source->commit();
        } catch (Throwable $error) {
            try {
                $source->rollback();
            } catch (Throwable $ignored) {
            }
            $this->logger->error('wowonder-migration', $error, [
                'completed_tables' => $tableCount,
                'copied_rows' => $rowCount,
            ]);
            throw $error;
        } finally {
            try {
                $target->query('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable $ignored) {
            }
            $source->close();
        }

        $this->upgradeRamzaSchema($target);
        $importer = new RACInstallerSqlImporter($this->logger);
        $importer->import($target, $this->paths->algorithmMigration);
        $importer->import($target, $this->paths->mobileApiMigration);
        $this->ensureRamzaConfigDefaults($target);
        $this->verifyMigration($target, $tableCount);

        $upload = ['files' => 0, 'bytes' => 0, 'required' => false, 'copied' => false];
        if (!empty($sourceConfig['uses_local_storage'])) {
            $upload['required'] = true;
            if (!empty($sourceConfig['root_path'])) {
                $upload = array_merge($upload, $this->copyLocalUploads((string) $sourceConfig['root_path']));
                $upload['copied'] = true;
            }
        }

        return [
            'tables' => count($this->baseTables($target)),
            'source_tables' => $tableCount,
            'rows' => $rowCount,
            'upload' => $upload,
        ];
    }

    private function connect(array $database): mysqli
    {
        $target = $this->parseHost((string) $database['host']);
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $mysqli = new mysqli(
            $target['host'],
            (string) $database['user'],
            (string) $database['pass'],
            (string) $database['name'],
            $target['port'],
            $target['socket']
        );
        $mysqli->set_charset('utf8mb4');
        return $mysqli;
    }

    private function beginReadOnly(mysqli $source): void
    {
        try {
            $source->query('SET SESSION TRANSACTION READ ONLY');
        } catch (mysqli_sql_exception $ignored) {
            $source->query('SET TRANSACTION READ ONLY');
        }
        $source->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    }

    private function baseTables(mysqli $database): array
    {
        $tables = [];
        $result = $database->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        while ($row = $result->fetch_array(MYSQLI_NUM)) {
            $tables[] = (string) $row[0];
        }
        $result->free();
        sort($tables, SORT_STRING);
        return $tables;
    }

    private function copyTableRows(mysqli $source, mysqli $target, string $table): int
    {
        $result = $source->query('SELECT * FROM `' . $table . '`', MYSQLI_USE_RESULT);
        $fields = $result->fetch_fields();
        if ($fields === []) {
            $result->free();
            return 0;
        }
        $columns = array_map(
            static fn(object $field): string => '`' . str_replace('`', '``', (string) $field->name) . '`',
            $fields
        );
        $prefix = 'INSERT INTO `' . $table . '` (' . implode(',', $columns) . ') VALUES ';
        $packetRow = $target->query('SELECT @@max_allowed_packet AS packet_size')->fetch_assoc();
        $packetSize = max(1024 * 1024, (int) ($packetRow['packet_size'] ?? 4 * 1024 * 1024));
        $chunkLimit = min(4 * 1024 * 1024, (int) floor($packetSize * 0.6));
        $values = [];
        $bytes = strlen($prefix);
        $copied = 0;

        while ($row = $result->fetch_array(MYSQLI_NUM)) {
            $encoded = [];
            foreach ($row as $value) {
                $encoded[] = $value === null ? 'NULL' : "'" . $target->real_escape_string((string) $value) . "'";
            }
            $tuple = '(' . implode(',', $encoded) . ')';
            if (strlen($prefix) + strlen($tuple) >= $packetSize * 0.8) {
                $result->free();
                throw new RACInstallerUserException('A row in ' . $table . ' exceeds the target database packet limit. Increase max_allowed_packet and retry with a new empty target database.');
            }
            if ($values !== [] && ($bytes + strlen($tuple) + 1 > $chunkLimit || count($values) >= 250)) {
                $target->query($prefix . implode(',', $values));
                $copied += count($values);
                $values = [];
                $bytes = strlen($prefix);
            }
            $values[] = $tuple;
            $bytes += strlen($tuple) + 1;
        }
        $result->free();
        if ($values !== []) {
            $target->query($prefix . implode(',', $values));
            $copied += count($values);
        }
        return $copied;
    }

    private function upgradeRamzaSchema(mysqli $target): void
    {
        if ($this->columnExists($target, 'Wo_Reactions_Types', 'wowonder_icon')
            && !$this->columnExists($target, 'Wo_Reactions_Types', 'ramza_icon')) {
            $target->query("ALTER TABLE `Wo_Reactions_Types` CHANGE `wowonder_icon` `ramza_icon` varchar(300) NOT NULL DEFAULT ''");
        }
        $target->query(
            "CREATE TABLE IF NOT EXISTS `Wo_Ramza_WebRTC_Signals` (
                `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
                `call_type` enum('audio','video','live') NOT NULL,
                `call_id` bigint UNSIGNED NOT NULL,
                `sender_id` int UNSIGNED NOT NULL,
                `recipient_id` int UNSIGNED NOT NULL,
                `message_type` enum('join','offer','answer','candidate','hangup') NOT NULL,
                `payload` text NOT NULL,
                `created_at` int UNSIGNED NOT NULL,
                `delivered_at` int UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `recipient_call` (`recipient_id`,`call_type`,`call_id`,`delivered_at`,`id`),
                KEY `created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function ensureRamzaConfigDefaults(mysqli $target): void
    {
        if (!is_file($this->paths->sqlDump) || !is_readable($this->paths->sqlDump)) {
            throw new RACInstallerSystemException('ramza.sql is unavailable while applying migration defaults.');
        }
        $inside = false;
        $defaults = [];
        $handle = fopen($this->paths->sqlDump, 'rb');
        if ($handle === false) {
            throw new RACInstallerSystemException('Unable to read ramza.sql migration defaults.');
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'INSERT INTO `Wo_Config`')) {
                    $inside = true;
                    continue;
                }
                if (!$inside) {
                    continue;
                }
                if (preg_match("/^\\(\\d+,\\s*'((?:\\\\.|[^'])*)',\\s*'((?:\\\\.|[^'])*)'\\)(?:,|;)$/", $trimmed, $match)) {
                    $defaults[$this->decodeSqlString($match[1])] = $this->decodeSqlString($match[2]);
                }
                if (str_ends_with($trimmed, ');')) {
                    $inside = false;
                }
            }
        } finally {
            fclose($handle);
        }

        foreach (array_chunk($defaults, 100, true) as $chunk) {
            $values = [];
            foreach ($chunk as $name => $value) {
                $values[] = "('" . $target->real_escape_string((string) $name) . "','"
                    . $target->real_escape_string((string) $value) . "')";
            }
            if ($values !== []) {
                $target->query(
                    'INSERT IGNORE INTO `Wo_Config` (`name`, `value`) VALUES ' . implode(',', $values)
                );
            }
        }
    }

    private function publicSourceConfig(mysqli $source): array
    {
        $wanted = [
            'version', 'site_url', 'siteName', 'siteTitle', 'siteEmail',
            'amazone_s3', 'ftp_upload', 'spaces', 'cloud_upload', 'wasabi_storage',
            'backblaze_storage', 'cloudflare_r2_storage', 's3_compatible_storage'
        ];
        $quoted = array_map(
            static fn(string $name): string => "'" . $source->real_escape_string($name) . "'",
            $wanted
        );
        $values = [];
        $result = $source->query("SELECT `name`,`value` FROM `Wo_Config` WHERE `name` IN (" . implode(',', $quoted) . ')');
        while ($row = $result->fetch_assoc()) {
            $values[(string) $row['name']] = (string) $row['value'];
        }
        $result->free();
        return $values;
    }

    private function remoteStorageEnabled(array $config): bool
    {
        foreach (['amazone_s3', 'ftp_upload', 'spaces', 'cloud_upload', 'wasabi_storage', 'backblaze_storage', 'cloudflare_r2_storage', 's3_compatible_storage'] as $key) {
            if (in_array(strtolower(trim((string) ($config[$key] ?? '0'))), ['1', 'true', 'yes', 'on', 'enabled'], true)) {
                return true;
            }
        }
        return false;
    }

    private function copyLocalUploads(string $sourceRoot): array
    {
        $sourceUpload = is_dir($sourceRoot . DIRECTORY_SEPARATOR . 'upload')
            ? $sourceRoot . DIRECTORY_SEPARATOR . 'upload'
            : $sourceRoot;
        $sourceReal = realpath($sourceUpload);
        $targetReal = realpath($this->paths->rootDir . DIRECTORY_SEPARATOR . 'upload');
        if ($sourceReal === false || $targetReal === false || $sourceReal === $targetReal) {
            throw new RACInstallerUserException('The old local upload directory could not be copied safely.');
        }

        $files = 0;
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceReal, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                continue;
            }
            $relative = ltrim(substr($item->getPathname(), strlen($sourceReal)), '/\\');
            if ($relative === '' || str_contains($relative, '..')) {
                continue;
            }
            $destination = $targetReal . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir()) {
                if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
                    throw new RACInstallerSystemException('Unable to create an upload migration directory.');
                }
                continue;
            }
            if (!$item->isFile()) {
                continue;
            }
            $directory = dirname($destination);
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RACInstallerSystemException('Unable to create an upload migration directory.');
            }
            if (!copy($item->getPathname(), $destination)) {
                throw new RACInstallerSystemException('Unable to copy a local upload during migration.');
            }
            $files++;
            $bytes += (int) $item->getSize();
        }
        return ['files' => $files, 'bytes' => $bytes];
    }

    private function validateSourceRoot(string $value): string
    {
        $value = trim(str_replace("\0", '', $value));
        if ($value === '') {
            return '';
        }
        $real = realpath($value);
        if ($real === false || !is_dir($real)) {
            throw new RACInstallerUserException('The old WoWonder server path does not exist or is not readable.');
        }
        if (!is_dir($real . DIRECTORY_SEPARATOR . 'upload') && basename($real) !== 'upload') {
            throw new RACInstallerUserException('The old WoWonder path must be its application root or its upload directory.');
        }
        $target = realpath($this->paths->rootDir);
        if ($target !== false && strcasecmp($real, $target) === 0) {
            throw new RACInstallerUserException('The old WoWonder path and new Ramza path must be different.');
        }
        return $real;
    }

    private function verifyMigration(mysqli $target, int $sourceTables): void
    {
        $tables = $this->baseTables($target);
        if (count($tables) < $sourceTables + 18) {
            throw new RACInstallerSystemException('Ramza migration verification found missing additive tables.');
        }
        foreach (['Wo_Config', 'Wo_Users', 'Wo_Posts', 'Ramza_AlgorithmTopics', 'Ramza_MobileSessions', 'Wo_Ramza_WebRTC_Signals'] as $required) {
            if (!$this->containsTable($tables, $required)) {
                throw new RACInstallerSystemException('Ramza migration verification failed for table ' . $required . '.');
            }
        }
        if (!$this->columnExists($target, 'Wo_Reactions_Types', 'ramza_icon')) {
            throw new RACInstallerSystemException('Ramza reaction schema migration did not complete.');
        }
    }

    private function columnExists(mysqli $database, string $table, string $column): bool
    {
        $statement = $database->prepare(
            'SELECT COUNT(*) AS total FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $statement->bind_param('ss', $table, $column);
        $statement->execute();
        $exists = (int) ($statement->get_result()->fetch_assoc()['total'] ?? 0) > 0;
        $statement->close();
        return $exists;
    }

    private function containsTable(array $tables, string $required): bool
    {
        foreach ($tables as $table) {
            if (strcasecmp((string) $table, $required) === 0) {
                return true;
            }
        }
        return false;
    }

    private function parseHost(string $host): array
    {
        $host = trim($host);
        $target = ['host' => $host, 'port' => 3306, 'socket' => null];
        if (preg_match('/^(.+):(\d+)$/', $host, $match)) {
            $target['host'] = $match[1];
            $target['port'] = (int) $match[2];
        } elseif (preg_match('/^(localhost|127\.0\.0\.1):(.+)$/', $host, $match)) {
            $target['host'] = $match[1];
            $target['socket'] = $match[2];
            $target['port'] = 0;
        }
        return $target;
    }

    private function sameDatabase(array $source, array $target): bool
    {
        $sourceHost = $this->parseHost((string) $source['host']);
        $targetHost = $this->parseHost((string) ($target['host'] ?? ''));
        $hostSame = in_array(strtolower($sourceHost['host']), ['localhost', '127.0.0.1'], true)
            && in_array(strtolower($targetHost['host']), ['localhost', '127.0.0.1'], true);
        if (!$hostSame) {
            $hostSame = strcasecmp($sourceHost['host'], $targetHost['host']) === 0
                && $sourceHost['port'] === $targetHost['port'];
        }
        return $hostSame && strcasecmp(trim((string) $source['name']), trim((string) ($target['name'] ?? ''))) === 0;
    }

    private function decodeSqlString(string $value): string
    {
        return strtr($value, [
            "\\0" => "\0",
            "\\n" => "\n",
            "\\r" => "\r",
            "\\'" => "'",
            '\\"' => '"',
            "\\Z" => chr(26),
            "\\\\" => "\\",
        ]);
    }

    private function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new RACInstallerSystemException('The source database contains an unsafe table identifier.');
        }
    }

    private function safeConnectMessage(mysqli_sql_exception $error): string
    {
        return match ((int) $error->getCode()) {
            1045 => 'WoWonder database authentication failed.',
            1049 => 'The WoWonder source database does not exist.',
            2002, 2003 => 'The WoWonder database host could not be reached.',
            1044 => 'The WoWonder database user cannot read the selected database.',
            default => 'WoWonder source validation failed. A redacted diagnostic was written to the protected installer log.',
        };
    }
}
