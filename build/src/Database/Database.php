<?php

namespace Hazem\CSVDataUploader\Database;

/**
 * Wraps PDO object
 */
class Database implements DatabaseInterface
{
    public const DRIVER_MYSQL  = 'mysql';
    public const DRIVER_PGSQL  = 'pgsql';
    public const DRIVER_SQLITE = 'sqlite';

    private const DEFAULT_MYSQL_PORT = '3306';

    private string $driver;
    private ?\PDO $db;
    private ?string $lastError;

    public function __construct()
    {
        $this->db = null;
        $this->lastError = null;
    }

    public function open(array $options): bool
    {
        // Use Singleton Design Pattern to call one instance from DB connection
        if (null === $this->db) {
            return $this->doOpen($options);
        }
        return true;
    }

    public function createTable(string $name, array $columns): bool
    {
        if (null === $this->db) {
            $this->lastError = 'No Database connection';
            return false;
        }
        $this->db->exec($this->getCreateTableSQL($name, $columns));
        return true;
    }

    public function tableExists(string $name): bool
    {
        try {
            $result = $this->db->query("SELECT 1 FROM {$name} LIMIT 1");
        } catch (\Exception $e) {
            return false;
        }
        // false|PDOStatement
        return $result !== false;
    }

    public function beginTransaction(): bool
    {
        return $this->db->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->db->commit();
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function insertBatch(string $tableName, array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        $fields = \array_keys($batch[0] ?? []);
        $placeholders = [];
        $values = [];
        foreach ($batch as $row) {
            $placeholders[] = '(' . \rtrim(\str_repeat('?,', \count($row)) , ',') . ')';
            $values = \array_merge($values, \array_values($row)); // TODO: should be improved due to a performance impact
        }
        $sql = 'INSERT INTO `' . $tableName . '` (' . \implode(',', $fields) . ') VALUES ' . \implode(',', $placeholders);

        $this->beginTransaction();
        try {
            $s = $this->db->prepare($sql);
            $s->execute($values);
            $this->commit();
        } catch (\PDOException $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    private function doOpen(array $options): bool
    {
        try {
            $this->db = $this->createConnection($options);
            $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            return true;
        } catch (\PDOException $PDOException) {
            $this->lastError = $PDOException->getMessage();
            return false;
        }
    }

    private function createConnection(array $options): \PDO
    {
        // choose the connection drive (Mysql or Sqlite) based on the passed parameter
        $this->driver = \strtolower(\trim($options['driver'] ?? ''));
        switch ($this->driver) {
            case self::DRIVER_MYSQL:
                return $this->mysqlConnection($options);
            case self::DRIVER_PGSQL:
                return $this->pgsqlConnection($options);
            case self::DRIVER_SQLITE:
                return $this->sqliteConnection($options);
        }
        throw new \OutOfRangeException('Unknown database driver [' . $this->driver . ']');
    }

    private function mysqlConnection(array $options): \PDO
    {
        // create Mysql Connection
        [$host, $port] = $this->parseHostname($this->requireOption('host', $options));
        $user = $this->requireOption('user', $options);
        $pass = $this->requireOption('password', $options);
        $dbname = $this->requireOption('dbname', $options);
        $extraOptions = $options['options'] ?? [];
        return new \PDO("mysql:host=$host;dbname=$dbname;port=$port", $user, $pass, $extraOptions);
    }

    private function pgsqlConnection(array $options): \PDO
    {
        // create pgsql Connection
        [$host, $port] = $this->parseHostname($this->requireOption('host', $options));
        $user = $this->requireOption('user', $options);
        $pass = $this->requireOption('password', $options);
        $dbname = $this->requireOption('dbname', $options);
        $extraOptions = $options['options'] ?? [];
        return new \PDO("pgsql:host=$host;dbname=$dbname;port=$port", $user, $pass, $extraOptions);

    }
    private function parseHostname(string $hostname): array
    {
        if (\strpos($hostname, ':') === false) {
            return [$hostname, self::DEFAULT_MYSQL_PORT];
        }

        $p = \explode(':', $hostname);
        return [$p[0], $p[1]];
    }

    private function sqliteConnection(array $options): \PDO
    {
        // create Sqlite Connection
        $path = $this->requireOption('path', $options);
        $extraOptions = $options['options'] ?? [];
        return new \PDO('sqlite:' . $path, null, null, $extraOptions);
    }

    private function getCreateTableSQL(string $name, array $columns): string
    {
        $ddlColumns = [];
        foreach ($columns as $column => $options) {
            $ddlColumns[] = $this->ddlColumn($column, $options);
        }
        return "CREATE TABLE IF NOT EXISTS $name (" . \implode(',', $ddlColumns) . ")";
    }

    private function ddlColumn(string $columnName, array $columnOptions): string
    {
        $type = \strtolower($columnOptions['type'] ?? 'string');
        switch ($type) {
            case 'string':
                $dbType = $this->getDriverStringType($this->driver);
                break;
            case 'int':
            case 'integer':
                $dbType = $this->getDriverIntegerType($this->driver);
                break;
            default:
                $dbType = $this->getDriverStringType($this->driver);
                break;
        }

        $nullable = ($columnOptions['nullable'] ?? true) === true ? '' : ' NOT NULL';
        $unique = ($columnOptions['unique'] ?? false) === true ? ' UNIQUE' : '';
        return $columnName . ' ' . $dbType . $nullable . $unique;
    }

    private function getDriverStringType(string $driver, int $len = 255): string
    {
        // this for the data type difference between Sqlite and mysql
        switch ($driver) {
            case self::DRIVER_SQLITE:
                return 'TEXT';
            case self::DRIVER_MYSQL:
                return "VARCHAR($len)";
        }
        throw new \RuntimeException('Unknown driver');
    }

    private function getDriverIntegerType(string $driver): string
    {
        switch ($driver) {
            case self::DRIVER_MYSQL:
                return "INTEGER";
        }
        throw new \RuntimeException('Unknown driver');
    }

    private function requireOption(string $optName, array $options): string
    {
        $val = \trim($options[$optName] ?? '');
        if (empty($val)) {
            throw new \InvalidArgumentException("[{$this->driver}] option '$optName' must be non empty string");
        }
        return $val;
    }
}
