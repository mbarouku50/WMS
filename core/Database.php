<?php
/**
 * WMS - MySQLi (object oriented) database wrapper.
 *
 * A thin, deliberately small layer over mysqli that gives the rest of the
 * application prepared statements without boilerplate.  Parameter types are
 * inferred from the PHP values, so callers just pass an array of values.
 *
 * Usage:
 *      $db   = Database::getInstance();
 *      $rows = $db->fetchAll('SELECT * FROM customers WHERE status = ?', ['active']);
 *      $id   = $db->insert('customers', ['full_name' => 'Amina', 'phone' => '0754...']);
 */
class Database
{
    private static ?Database $instance = null;

    private ?mysqli $connection = null;
    private array $config;
    private bool $inTransaction = false;

    /** Number of queries executed - handy while tuning pages. */
    public int $queryCount = 0;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? require CONFIG_PATH . '/database.php';
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Opens the connection on first use.
     *
     * @throws RuntimeException when the database is unreachable.
     */
    public function getConnection(): mysqli
    {
        if ($this->connection instanceof mysqli) {
            return $this->connection;
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $conn = new mysqli(
                $this->config['host'],
                $this->config['user'],
                $this->config['pass'],
                $this->config['name'],
                $this->config['port']
            );
            $conn->set_charset($this->config['charset'] ?? 'utf8mb4');
        } catch (Throwable $e) {
            Logger::error('Database connection failed: ' . $e->getMessage());
            throw new RuntimeException('DB_CONNECTION_FAILED', 0, $e);
        }

        $this->connection = $conn;
        return $this->connection;
    }

    /** True when the configured database can actually be reached. */
    public function isConnected(): bool
    {
        try {
            $this->getConnection();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Query execution                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Runs a prepared statement and returns the statement itself.
     *
     * @param array<int,mixed> $params
     */
    public function run(string $sql, array $params = []): mysqli_stmt
    {
        $conn = $this->getConnection();

        try {
            $stmt = $conn->prepare($sql);
        } catch (Throwable $e) {
            Logger::error('SQL prepare failed: ' . $e->getMessage(), ['sql' => $sql]);
            throw new RuntimeException('DB_QUERY_FAILED', 0, $e);
        }

        if ($params) {
            $stmt->bind_param($this->typeString($params), ...$params);
        }

        try {
            $stmt->execute();
        } catch (Throwable $e) {
            Logger::error('SQL execute failed: ' . $e->getMessage(), ['sql' => $sql]);
            throw new RuntimeException('DB_QUERY_FAILED', 0, $e);
        }

        $this->queryCount++;
        return $stmt;
    }

    /**
     * SELECT returning every row as an associative array.
     *
     * @return array<int,array<string,mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt   = $this->run($sql, $params);
        $result = $stmt->get_result();
        $rows   = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }

    /** SELECT returning the first row, or null. */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt   = $this->run($sql, $params);
        $result = $stmt->get_result();
        $row    = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }

    /** SELECT returning a single scalar value from the first row. */
    public function fetchColumn(string $sql, array $params = [], mixed $default = null): mixed
    {
        $row = $this->fetchOne($sql, $params);
        if ($row === null) {
            return $default;
        }
        $value = reset($row);
        return $value === null ? $default : $value;
    }

    /** Convenience wrapper for COUNT(*) style queries. */
    public function count(string $sql, array $params = []): int
    {
        return (int)$this->fetchColumn($sql, $params, 0);
    }

    /** INSERT/UPDATE/DELETE returning the number of affected rows. */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->run($sql, $params);
        $rows = $stmt->affected_rows;
        $stmt->close();
        return max(0, (int)$rows);
    }

    /* ------------------------------------------------------------------ */
    /* Convenience CRUD                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Inserts one row and returns the new id.
     *
     * @param array<string,mixed> $data column => value
     */
    public function insert(string $table, array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES (' . $placeholders . ')';

        $stmt = $this->run($sql, array_values($data));
        $id   = (int)$this->getConnection()->insert_id;
        $stmt->close();
        return $id;
    }

    /**
     * Updates rows matching a WHERE clause.
     *
     * @param array<string,mixed> $data
     * @param array<int,mixed>    $whereParams
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if (!$data) {
            return 0;
        }
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = '`' . $column . '` = ?';
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        return $this->execute($sql, array_merge(array_values($data), $whereParams));
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        return $this->execute('DELETE FROM `' . $table . '` WHERE ' . $where, $params);
    }

    public function lastInsertId(): int
    {
        return (int)$this->getConnection()->insert_id;
    }

    /** Escapes an identifier that cannot be bound (e.g. an ORDER BY column). */
    public function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '', $identifier) . '`';
    }

    /* ------------------------------------------------------------------ */
    /* Transactions                                                        */
    /* ------------------------------------------------------------------ */

    public function beginTransaction(): void
    {
        if (!$this->inTransaction) {
            $this->getConnection()->begin_transaction();
            $this->inTransaction = true;
        }
    }

    public function commit(): void
    {
        if ($this->inTransaction) {
            $this->getConnection()->commit();
            $this->inTransaction = false;
        }
    }

    public function rollback(): void
    {
        if ($this->inTransaction) {
            $this->getConnection()->rollback();
            $this->inTransaction = false;
        }
    }

    /** True when the given table exists in the current database. */
    public function tableExists(string $table): bool
    {
        try {
            $row = $this->fetchOne(
                'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            );
            return (int)($row['c'] ?? 0) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /* ------------------------------------------------------------------ */

    /** Builds the mysqli bind_param type string from the PHP value types. */
    private function typeString(array $params): string
    {
        $types = '';
        foreach ($params as $param) {
            $types .= match (true) {
                is_int($param)   => 'i',
                is_float($param) => 'd',
                is_null($param)  => 's',
                default          => 's',
            };
        }
        return $types;
    }

    public function close(): void
    {
        if ($this->connection instanceof mysqli) {
            $this->connection->close();
            $this->connection = null;
        }
    }
}
