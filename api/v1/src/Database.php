<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

use mysqli;
use mysqli_stmt;
use RuntimeException;

final class Database
{
    public function __construct(private readonly mysqli $connection)
    {
    }

    public function one(string $sql, string $types = '', array $parameters = []): ?array
    {
        $statement = $this->statement($sql, $types, $parameters);
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $statement->close();
        return is_array($row) ? $row : null;
    }

    public function all(string $sql, string $types = '', array $parameters = []): array
    {
        $statement = $this->statement($sql, $types, $parameters);
        $result = $statement->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $statement->close();
        return $rows;
    }

    public function execute(string $sql, string $types = '', array $parameters = []): int
    {
        $statement = $this->statement($sql, $types, $parameters);
        $affected = $statement->affected_rows;
        $statement->close();
        return $affected;
    }

    public function connection(): mysqli
    {
        return $this->connection;
    }

    private function statement(string $sql, string $types, array $parameters): mysqli_stmt
    {
        $statement = $this->connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            throw new RuntimeException('Database statement preparation failed.');
        }
        if ($types !== '') {
            $statement->bind_param($types, ...$parameters);
        }
        $statement->execute();
        return $statement;
    }
}
