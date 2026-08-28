<?php
/**
 * MySQLi database connection and prepared-statement helpers.
 */

require_once __DIR__ . '/../config.php';

/** @var mysqli|null */
$db = null;

/**
 * Get or create the MySQLi connection.
 *
 * @return mysqli
 */
function db_connect(): mysqli
{
    global $db;

    if ($db instanceof mysqli && $db->ping()) {
        return $db;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $db->set_charset('utf8mb4');

        if (defined('APP_TIMEZONE') && APP_TIMEZONE !== '') {
            try {
                $tzNow = new DateTime('now', new DateTimeZone(APP_TIMEZONE));
                $db->query('SET time_zone = \'' . $db->real_escape_string($tzNow->format('P')) . '\'');
            } catch (Throwable $e) {
                error_log('MySQL timezone sync skipped: ' . $e->getMessage());
            }
        }
    } catch (mysqli_sql_exception $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        if (defined('APP_DEBUG') && APP_DEBUG) {
            die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
        }
        $hint = is_file(dirname(__DIR__) . '/config.local.php')
            ? ''
            : ' (Check DB credentials in config.php or create config.local.php on the server.)';
        die('Service temporarily unavailable.' . $hint);
    }

    return $db;
}

/**
 * Execute a prepared statement.
 *
 * @param string $sql    SQL with ? placeholders
 * @param string $types  mysqli bind types (e.g. 'ssi')
 * @param array  $params Values to bind
 * @return mysqli_stmt
 */
function db_query(string $sql, string $types = '', array $params = []): mysqli_stmt
{
    $conn = db_connect();
    $stmt = $conn->prepare($sql);

    if ($types !== '' && !empty($params)) {
        if (strlen($types) !== count($params)) {
            throw new InvalidArgumentException(sprintf(
                'DB bind mismatch: %d type chars vs %d params (%s)',
                strlen($types),
                count($params),
                preg_replace('/\s+/', ' ', substr($sql, 0, 160)) ?? $sql
            ));
        }
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    return $stmt;
}

/**
 * Fetch all rows from a query.
 *
 * @param string $sql
 * @param string $types
 * @param array  $params
 * @return array<int, array<string, mixed>>
 */
function db_fetch_all(string $sql, string $types = '', array $params = []): array
{
    $stmt = db_query($sql, $types, $params);
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

/**
 * Fetch a single row.
 *
 * @param string $sql
 * @param string $types
 * @param array  $params
 * @return array<string, mixed>|null
 */
function db_fetch(string $sql, string $types = '', array $params = []): ?array
{
    $rows = db_fetch_all($sql, $types, $params);
    return $rows[0] ?? null;
}

/**
 * Insert a row and return the last insert ID.
 *
 * @param string $sql
 * @param string $types
 * @param array  $params
 * @return int
 */
function db_insert(string $sql, string $types = '', array $params = []): int
{
    $stmt = db_query($sql, $types, $params);
    $id = (int) db_connect()->insert_id;
    $stmt->close();
    return $id;
}

/**
 * Execute an update/delete and return affected rows.
 *
 * @param string $sql
 * @param string $types
 * @param array  $params
 * @return int
 */
function db_execute(string $sql, string $types = '', array $params = []): int
{
    $stmt = db_query($sql, $types, $params);
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}
