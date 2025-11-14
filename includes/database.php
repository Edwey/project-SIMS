<?php
/**
 * Database connection and helper utilities
 */

require_once __DIR__ . '/config.php';

class Database
{
    private static ?Database $instance = null;
    private ?PDO $connection = null;

    private function __construct()
    {
        // Connect directly to the configured database. On shared hosting (e.g. InfinityFree)
        // the database is created in the hosting control panel, so we do NOT attempt to
        // CREATE DATABASE here.
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        // Database is already selected by dbname in DSN; no CREATE DATABASE or USE needed.
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    public function selectDatabase(string $database): void
    {
        if ($this->connection === null) {
            return;
        }

        $this->connection->exec('USE `' . $database . '`');
    }
}

function db_query(string $sql, array $params = []): array
{
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_query_one(string $sql, array $params = []): ?array
{
    $results = db_query($sql, $params);
    return $results[0] ?? null;
}

function db_execute(string $sql, array $params = []): bool
{
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare($sql);
    return $stmt->execute($params);
}

function db_last_id(): string
{
    return Database::getInstance()->getConnection()->lastInsertId();
}
