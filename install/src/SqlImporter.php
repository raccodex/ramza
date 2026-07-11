<?php
declare(strict_types=1);

final class RACInstallerSqlImporter
{
    public function __construct(private readonly RACInstallerLogger $logger)
    {
    }

    public function import(mysqli $database, string $dumpPath): array
    {
        if (!is_readable($dumpPath)) {
            throw new RACInstallerSystemException('The SQL dump is not readable.');
        }
        $handle = fopen($dumpPath, 'rb');
        if ($handle === false) {
            throw new RACInstallerSystemException('The SQL dump could not be opened.');
        }

        $statement = '';
        $quote = null;
        $escaped = false;
        $inBlockComment = false;
        $inLineComment = false;
        $delimiter = ';';
        $statements = 0;
        $started = microtime(true);
        try {
            while (($line = fgets($handle)) !== false) {
                if ($quote === null && !$inBlockComment && trim($statement) === '' && preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $delimiterMatch)) {
                    $delimiter = $delimiterMatch[1];
                    continue;
                }
                $length = strlen($line);
                for ($i = 0; $i < $length; $i++) {
                    $character = $line[$i];

                    if ($inLineComment) {
                        if ($character === "\n" || $character === "\r") {
                            $inLineComment = false;
                            $statement .= "\n";
                        }
                        continue;
                    }

                    if ($inBlockComment) {
                        $statement .= $character;
                        if ($character === '*' && ($line[$i + 1] ?? '') === '/') {
                            $statement .= '/';
                            $i++;
                            $inBlockComment = false;
                        }
                        continue;
                    }

                    if ($quote !== null) {
                        $statement .= $character;
                        if ($escaped) {
                            $escaped = false;
                            continue;
                        }
                        if ($character === '\\') {
                            $escaped = true;
                            continue;
                        }
                        if ($character === $quote) {
                            if (($line[$i + 1] ?? '') === $quote) {
                                $statement .= $line[++$i];
                            } else {
                                $quote = null;
                            }
                        }
                        continue;
                    }

                    if ($character === '-' && ($line[$i + 1] ?? '') === '-' && preg_match('/\s/', $line[$i + 2] ?? "\n")) {
                        $inLineComment = true;
                        $i++;
                        continue;
                    }
                    if ($character === '#') {
                        $inLineComment = true;
                        continue;
                    }
                    if ($character === '/' && ($line[$i + 1] ?? '') === '*') {
                        $statement .= '/*';
                        $inBlockComment = true;
                        $i++;
                        continue;
                    }
                    if ($character === "'" || $character === '"' || $character === '`') {
                        $statement .= $character;
                        $quote = $character;
                        continue;
                    }
                    if ($delimiter !== '' && substr($line, $i, strlen($delimiter)) === $delimiter) {
                        $sql = trim($statement);
                        $statement = '';
                        $i += strlen($delimiter) - 1;
                        $withoutOrdinaryComments = trim(preg_replace('/\/\*(?!\!).*?\*\//s', '', $sql) ?? $sql);
                        if ($withoutOrdinaryComments !== '') {
                            $database->query($sql);
                            $statements++;
                        }
                        continue;
                    }
                    $statement .= $character;
                }
            }
            if (!feof($handle)) {
                throw new RACInstallerSystemException('The SQL dump could not be read completely.');
            }
            if ($quote !== null || $inBlockComment || trim($statement) !== '') {
                throw new RACInstallerSystemException('The SQL dump ended with an incomplete statement.');
            }
        } catch (Throwable $error) {
            $this->logger->error('sql-import', $error, ['statement_number' => $statements + 1]);
            throw new RACInstallerUserException('Database import stopped on an SQL error. The selected fresh database may now be partial; discard it and retry with another empty database.');
        } finally {
            fclose($handle);
        }

        $tableRows = [];
        $tableResult = $database->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name");
        while ($tableRow = $tableResult->fetch_assoc()) {
            $tableRows[] = (string) $tableRow['table_name'];
        }
        $tableCount = count($tableRows);
        if ($tableCount < 100 || !$this->tableExists($database, 'Wo_Config') || !$this->tableExists($database, 'Wo_Users') || !$this->tableExists($database, 'Wo_UserFields')) {
            throw new RACInstallerUserException('Database import did not produce the expected RACSocial schema. Discard the database and verify wowonder.sql.');
        }
        $duration = round(microtime(true) - $started, 3);
        $this->logger->info('sql-import', ['result' => 'complete', 'statements' => $statements, 'tables' => $tableCount, 'duration_seconds' => $duration]);
        return [
            'statements' => $statements,
            'tables' => $tableCount,
            'duration_seconds' => $duration,
            'schema_fingerprint' => hash('sha256', implode("\n", $tableRows)),
        ];
    }

    private function tableExists(mysqli $database, string $table): bool
    {
        $statement = $database->prepare('SELECT COUNT(*) AS count_value FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $statement->bind_param('s', $table);
        $statement->execute();
        $exists = (int) ($statement->get_result()->fetch_assoc()['count_value'] ?? 0) === 1;
        $statement->close();
        return $exists;
    }
}
