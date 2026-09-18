<?php
/**
 * Gerenciador Central de Conexão PDO
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 * 
 * Regras de Segurança (ADR-0004 e ADR-0007):
 * - Conexão padrão estritamente local (127.0.0.1 / localhost)
 * - Prepared Statements nativos (sem emulação) contra SQL Injection
 * - Charset utf8mb4 nativo
 */

declare(strict_types=1);

namespace FuturoFacil\Config;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;
    private static ?array $customConfig = null;

    /**
     * Define configurações personalizadas (útil para testes unitários ou ambientes específicos).
     */
    public static function setConfig(array $config): void
    {
        self::$customConfig = $config;
        self::$instance = null; // Reinicia instância caso reconfigurado
    }

    /**
     * Obtém a instância única de PDO.
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }
        return self::$instance;
    }

    /**
     * Reseta a conexão ativa (para isolamento em testes).
     */
    public static function resetConnection(): void
    {
        self::$instance = null;
    }

    private static function createConnection(): PDO
    {
        $cfg = self::$customConfig ?? self::getDefaultConfig();

        $driver = $cfg['driver'] ?? 'mysql';

        if ($driver === 'sqlite') {
            $path = $cfg['database'] ?? ':memory:';
            $dsn = "sqlite:{$path}";
            $pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec("PRAGMA foreign_keys = ON;");
            $pdo->exec("PRAGMA busy_timeout = 5000;");
            return $pdo;
        }

        // Conexão MariaDB / MySQL padrão em localhost
        $host = $cfg['host'] ?? '127.0.0.1';
        $port = $cfg['port'] ?? 3306;
        $dbname = $cfg['database'] ?? 'futurofacil_diario';
        $user = $cfg['username'] ?? 'root';
        $pass = $cfg['password'] ?? '';
        $charset = $cfg['charset'] ?? 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        try {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci",
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException("Erro ao conectar ao banco de dados MariaDB ({$host}): " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    private static function getDefaultConfig(): array
    {
        return [
            'driver'   => self::getEnvVar('DB_DRIVER', 'mysql'),
            'host'     => self::getEnvVar('DB_HOST', '127.0.0.1'),
            'port'     => (int)self::getEnvVar('DB_PORT', 3306),
            'database' => self::getEnvVar('DB_DATABASE', 'futurofacil_diario'),
            'username' => self::getEnvVar('DB_USERNAME', 'root'),
            'password' => self::getEnvVar('DB_PASSWORD', ''),
            'charset'  => 'utf8mb4',
        ];
    }

    private static function getEnvVar(string $name, mixed $default = null): mixed
    {
        $val = getenv($name);
        if ($val !== false && $val !== '') {
            return $val;
        }
        if (!empty($_SERVER[$name])) {
            return $_SERVER[$name];
        }
        if (!empty($_ENV[$name])) {
            return $_ENV[$name];
        }

        static $localCredentials = null;
        if ($localCredentials === null) {
            $localFile = __DIR__ . '/credentials.local.php';
            if (file_exists($localFile)) {
                $loaded = require $localFile;
                $localCredentials = is_array($loaded) ? $loaded : [];
            } else {
                $localCredentials = [];
            }
        }

        return $localCredentials[$name] ?? $default;
    }
}
