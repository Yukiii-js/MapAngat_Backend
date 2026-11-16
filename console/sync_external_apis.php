<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('PREVENT_DIRECT_ACCESS', true);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR
    . 'libraries' . DIRECTORY_SEPARATOR . 'ExternalHazardService.php';

class ExternalApiSyncDatabase
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function raw($sql, $values = [])
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);
        return $statement;
    }
}

$driver = strtolower(getenv('DB_DRIVER') ?: 'mysql');
if ($driver !== 'mysql') {
    fwrite(STDERR, "External API sync requires the MySQL database driver.\n");
    exit(1);
}

$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$database = getenv('DB_NAME') ?: 'defaultdb';
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: (getenv('DB_PASS') ?: '');
$sslValue = getenv('DB_SSL');
$sslEnabled = $sslValue === false
    ? !in_array($host, ['localhost', '127.0.0.1'], true)
    : strtolower($sslValue) === 'true';
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

if ($sslEnabled) {
    if (!defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
        fwrite(STDERR, "PDO MySQL TLS support is required for this database connection.\n");
        exit(1);
    }
    $sslCa = getenv('DB_SSL_CA') ?: '';
    if ($sslCa !== '') {
        if (!is_file($sslCa) || !is_readable($sslCa) || !defined('PDO::MYSQL_ATTR_SSL_CA')) {
            fwrite(STDERR, "DB_SSL_CA must point to a readable CA certificate supported by PDO MySQL.\n");
            exit(1);
        }
        $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
    }
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $sslCa !== '';
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
        $username,
        $password,
        $options
    );
    $service = new ExternalHazardService(new ExternalApiSyncDatabase($pdo));
    if (!$service->tables_available()) {
        throw new RuntimeException('External API tables are not installed. Apply database/migrations/external_apis.sql first.');
    }

    $due = $pdo->query(
        "SELECT api_key FROM external_api_configs
         WHERE is_enabled = 1
           AND (last_synced_at IS NULL
                OR last_synced_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL sync_interval_minutes MINUTE))
         ORDER BY FIELD(api_key, 'usgs', 'gdacs', 'hdx')"
    )->fetchAll(PDO::FETCH_COLUMN);

    $failed = false;
    foreach ($due as $apiKey) {
        try {
            $result = $service->sync($apiKey);
            echo strtoupper($apiKey) . ': synced ' . $result['records_cached']
                . ' cached record(s) in ' . $result['duration_ms'] . " ms.\n";
        } catch (Throwable $error) {
            $failed = true;
            fwrite(STDERR, strtoupper($apiKey) . ' sync failed: ' . $error->getMessage() . "\n");
        }
    }

    if (!$due) {
        echo "No enabled external APIs are due for sync.\n";
    }
    exit($failed ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, 'External API scheduler failed: ' . $error->getMessage() . "\n");
    exit(1);
}
