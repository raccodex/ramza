<?php
declare(strict_types=1);

final class RACInstallerDatabaseValidator
{
    public function __construct(private readonly RACInstallerLogger $logger)
    {
    }

    public function validate(array $input): array
    {
        foreach (['host', 'name', 'user', 'pass'] as $key) {
            if (!array_key_exists($key, $input) || !is_string($input[$key])) {
                throw new RACInstallerUserException('Database fields must be plain text values.');
            }
        }
        if (trim($input['host']) === '' || trim($input['name']) === '' || trim($input['user']) === '') {
            throw new RACInstallerUserException('Database host, name, and username are required.');
        }

        $target = $this->parseHost(trim($input['host']));
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $mysqli = new mysqli($target['host'], $input['user'], $input['pass'], $input['name'], $target['port'], $target['socket']);
            $mysqli->set_charset('utf8mb4');
            $serverVersion = $mysqli->server_info;
            $sqlMode = $mysqli->query('SELECT @@sql_mode AS sql_mode')->fetch_assoc()['sql_mode'] ?? '';
            $charset = $mysqli->query('SELECT @@character_set_database AS charset_name, @@collation_database AS collation_name')->fetch_assoc();
            $tables = [];
            $result = $mysqli->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            while ($row = $result->fetch_array(MYSQLI_NUM)) {
                $tables[] = (string) $row[0];
            }
            $mysqli->close();
        } catch (mysqli_sql_exception $error) {
            $this->logger->error('database-validation', $error, ['errno' => $error->getCode(), 'host' => $target['host'], 'database' => $input['name']]);
            throw new RACInstallerUserException($this->safeConnectMessage($error));
        }

        if (version_compare($serverVersion, '5.7.0', '<') && !str_contains(strtolower($serverVersion), 'mariadb')) {
            throw new RACInstallerUserException('The selected database server is older than the supported MySQL baseline. Use MySQL 5.7+/MariaDB 10.2+ or newer.');
        }
        if (count($tables) > 0) {
            throw new RACInstallerUserException('The selected database is not empty. Create a fresh empty disposable database for installation; the installer will not drop existing tables.');
        }

        return [
            'host' => trim($input['host']),
            'user' => $input['user'],
            'pass' => $input['pass'],
            'name' => trim($input['name']),
            'server_version' => $serverVersion,
            'sql_mode' => (string) $sqlMode,
            'charset' => (string) ($charset['charset_name'] ?? ''),
            'collation' => (string) ($charset['collation_name'] ?? ''),
        ];
    }

    public function connect(array $database): mysqli
    {
        $target = $this->parseHost($database['host']);
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $mysqli = new mysqli($target['host'], $database['user'], $database['pass'], $database['name'], $target['port'], $target['socket']);
        $mysqli->set_charset('utf8mb4');
        return $mysqli;
    }

    private function parseHost(string $host): array
    {
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

    private function safeConnectMessage(mysqli_sql_exception $error): string
    {
        return match ((int) $error->getCode()) {
            1045 => 'Database authentication failed. Check the username and password.',
            1049 => 'The selected database does not exist. Create it first, then retry.',
            2002, 2003 => 'The database host could not be reached. Check host, port, socket, and firewall settings.',
            1044 => 'The database user does not have permission to access the selected database.',
            default => 'Database validation failed. A redacted diagnostic was written to the protected installer log.',
        };
    }
}
