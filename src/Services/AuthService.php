<?php
/**
 * Serviço de Autenticação e Segurança Administrativa (AuthService)
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 * 
 * Regras de Segurança (ADR-0004 e ADR-0007):
 * - Senhas com hash bcrypt seguro via password_hash / password_verify
 * - Cookies blindados (HttpOnly, Secure condicional/estrito, SameSite=Strict)
 * - session_regenerate_id(true) após login
 * - Rate limiting e proteção contra força bruta persistente em banco
 * - Isolamento estrito de sessão do operador (usuario_admin vs aluno_turma_autenticado)
 * - Proteção contra CSRF via tokens criptográficos por sessão
 */

declare(strict_types=1);

namespace FuturoFacil\Services;

use FuturoFacil\Config\Database;
use PDO;
use PDOException;
use RuntimeException;

class AuthService
{
    public const MAX_ATTEMPTS = 5;
    public const LOCKOUT_MINUTES = 15;
    public const SESSION_ADMIN_KEY = 'usuario_admin';
    public const SESSION_STUDENT_KEY = 'aluno_turma_autenticado';
    public const CSRF_TOKEN_KEY = 'admin_csrf_token';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * Inicializa a sessão com cabeçalhos e parâmetros blindados.
     */
    public static function startSecureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) {
                $isHttps = (
                    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                    (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
                    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                );

                // Configurações de cookies de sessão seguros
                ini_set('session.use_strict_mode', '1');
                ini_set('session.use_cookies', '1');
                ini_set('session.use_only_cookies', '1');

                session_set_cookie_params([
                    'lifetime' => 0, // Sessão de navegador
                    'path'     => '/',
                    'domain'   => '',
                    'secure'   => $isHttps,
                    'httponly' => true,
                    'samesite' => 'Strict',
                ]);
                session_start();
            } else {
                @session_start();
            }
        }
    }

    /**
     * Valida credenciais do operador, aplicando rate-limiting e mitigação de força bruta.
     *
     * @return array{success: bool, error?: string, blocked?: bool, remaining_seconds?: int, user?: array}
     */
    public function authenticate(string $username, string $password, ?string $ip = null): array
    {
        $username = trim($username);
        $ip = $ip ?: $this->getClientIp();

        if (empty($username) || empty($password)) {
            return [
                'success' => false,
                'error'   => 'Usuário e senha são obrigatórios.',
            ];
        }

        // 1. Verifica rate-limiting no banco
        $blockStatus = $this->checkRateLimit($ip, $username);
        if ($blockStatus['blocked']) {
            return [
                'success'           => false,
                'blocked'           => true,
                'remaining_seconds' => $blockStatus['remaining_seconds'],
                'error'             => "Acesso bloqueado temporariamente por excesso de tentativas. Tente novamente em {$blockStatus['remaining_minutes']} minuto(s).",
            ];
        }

        // 2. Busca usuário admin
        $stmt = $this->pdo->prepare("
            SELECT id, username, password_hash, nome, email, ultimo_login 
            FROM usuarios_admin 
            WHERE username = :username 
            LIMIT 1
        ");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        // 3. Valida senha via bcrypt
        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            $this->recordFailedAttempt($ip, $username);
            $newAttempts = $this->getAttemptCount($ip, $username);
            $remainingAttempts = max(0, self::MAX_ATTEMPTS - $newAttempts);

            if ($remainingAttempts === 0) {
                return [
                    'success'           => false,
                    'blocked'           => true,
                    'remaining_seconds' => self::LOCKOUT_MINUTES * 60,
                    'error'             => "Credenciais inválidas. Limite de tentativas atingido. Bloqueado por " . self::LOCKOUT_MINUTES . " minutos.",
                ];
            }

            return [
                'success' => false,
                'error'   => "Credenciais inválidas. Você tem mais {$remainingAttempts} tentativa(s) antes do bloqueio temporário.",
            ];
        }

        // 4. Sucesso: limpa tentativas falhas
        $this->clearFailedAttempts($ip, $username);

        // 5. Atualiza data do último login
        $this->updateLastLogin((int)$user['id']);

        // 6. Regenera ID de sessão para prevenir Session Fixation
        self::startSecureSession();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // 7. Salva usuário no escopo isolado do operador
        $_SESSION[self::SESSION_ADMIN_KEY] = [
            'id'           => (int)$user['id'],
            'username'     => (string)$user['username'],
            'nome'         => (string)$user['nome'],
            'email'        => (string)$user['email'],
            'ultimo_login' => $user['ultimo_login'],
            'logged_at'    => date('Y-m-d H:i:s'),
        ];

        // Gera token CSRF inicial para o operador
        $this->getCsrfToken();

        return [
            'success' => true,
            'user'    => $_SESSION[self::SESSION_ADMIN_KEY],
        ];
    }

    /**
     * Verifica se o operador atual está autenticado na sessão.
     */
    public static function isAuthenticated(): bool
    {
        self::startSecureSession();
        return !empty($_SESSION[self::SESSION_ADMIN_KEY]['id']);
    }

    /**
     * Retorna os dados do operador logado ou null.
     */
    public static function getCurrentUser(): ?array
    {
        self::startSecureSession();
        return $_SESSION[self::SESSION_ADMIN_KEY] ?? null;
    }

    /**
     * Guardião de sessão: exige autenticação administrativa.
     * Redireciona imediatamente para a tela de login se não autenticado.
     */
    public static function requireAuth(string $redirectUrl = '/diario/login'): array
    {
        self::startSecureSession();

        if (!self::isAuthenticated()) {
            if (!headers_sent()) {
                header("Location: {$redirectUrl}");
            }
            exit;
        }

        return $_SESSION[self::SESSION_ADMIN_KEY];
    }

    /**
     * Realiza logout seguro do operador, preservando escopo de alunos.
     */
    public static function logout(): void
    {
        self::startSecureSession();

        // Remove apenas o escopo administrativo
        unset($_SESSION[self::SESSION_ADMIN_KEY]);
        unset($_SESSION[self::CSRF_TOKEN_KEY]);

        // Regenera ID de sessão para invalidar identificador anterior
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Gera ou recupera o token anti-CSRF da sessão administrativa.
     */
    public static function getCsrfToken(): string
    {
        self::startSecureSession();
        if (empty($_SESSION[self::CSRF_TOKEN_KEY])) {
            $_SESSION[self::CSRF_TOKEN_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::CSRF_TOKEN_KEY];
    }

    /**
     * Valida o token anti-CSRF recebido.
     */
    public static function validateCsrfToken(?string $token): bool
    {
        self::startSecureSession();
        $stored = $_SESSION[self::CSRF_TOKEN_KEY] ?? '';
        if (empty($stored) || empty($token)) {
            return false;
        }
        return hash_equals($stored, $token);
    }

    /**
     * Retorna o endereço IP do cliente.
     */
    public function getClientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($parts[0]);
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
    }

    /**
     * Verifica se o par IP/username está temporariamente bloqueado.
     *
     * @return array{blocked: bool, remaining_seconds: int, remaining_minutes: int}
     */
    public function checkRateLimit(string $ip, string $username): array
    {
        $stmt = $this->pdo->prepare("
            SELECT tentativas, bloqueado_ate 
            FROM tentativas_login 
            WHERE ip_address = :ip AND username = :username 
            LIMIT 1
        ");
        $stmt->execute(['ip' => $ip, 'username' => $username]);
        $row = $stmt->fetch();

        if (!$row || empty($row['bloqueado_ate'])) {
            return ['blocked' => false, 'remaining_seconds' => 0, 'remaining_minutes' => 0];
        }

        $bloqueadoAte = strtotime((string)$row['bloqueado_ate']);
        $agora = time();

        if ($bloqueadoAte > $agora) {
            $diff = $bloqueadoAte - $agora;
            return [
                'blocked'           => true,
                'remaining_seconds' => $diff,
                'remaining_minutes' => (int)ceil($diff / 60),
            ];
        }

        // Se o bloqueio já expirou, podemos resetar o bloqueio
        $clearStmt = $this->pdo->prepare("
            UPDATE tentativas_login 
            SET bloqueado_ate = NULL, tentativas = 0 
            WHERE ip_address = :ip AND username = :username
        ");
        $clearStmt->execute(['ip' => $ip, 'username' => $username]);

        return ['blocked' => false, 'remaining_seconds' => 0, 'remaining_minutes' => 0];
    }

    /**
     * Registra uma tentativa falha e calcula se deve acionar o bloqueio.
     */
    public function recordFailedAttempt(string $ip, string $username): void
    {
        $stmt = $this->pdo->prepare("
            SELECT id, tentativas 
            FROM tentativas_login 
            WHERE ip_address = :ip AND username = :username 
            LIMIT 1
        ");
        $stmt->execute(['ip' => $ip, 'username' => $username]);
        $row = $stmt->fetch();

        $agora = date('Y-m-d H:i:s');

        if ($row) {
            $novasTentativas = (int)$row['tentativas'] + 1;
            $bloqueadoAte = null;
            if ($novasTentativas >= self::MAX_ATTEMPTS) {
                $bloqueadoAte = date('Y-m-d H:i:s', time() + (self::LOCKOUT_MINUTES * 60));
            }

            $updateStmt = $this->pdo->prepare("
                UPDATE tentativas_login 
                SET tentativas = :tentativas,
                    bloqueado_ate = :bloqueado_ate,
                    ultimo_erro = :ultimo_erro,
                    updated_at = :updated_at
                WHERE id = :id
            ");
            $updateStmt->execute([
                'tentativas'    => $novasTentativas,
                'bloqueado_ate' => $bloqueadoAte,
                'ultimo_erro'   => $agora,
                'updated_at'    => $agora,
                'id'            => $row['id'],
            ]);
        } else {
            $insertStmt = $this->pdo->prepare("
                INSERT INTO tentativas_login (ip_address, username, tentativas, bloqueado_ate, ultimo_erro, created_at, updated_at)
                VALUES (:ip, :username, 1, NULL, :ultimo_erro, :created_at, :updated_at)
            ");
            $insertStmt->execute([
                'ip'          => $ip,
                'username'    => $username,
                'ultimo_erro' => $agora,
                'created_at'  => $agora,
                'updated_at'  => $agora,
            ]);
        }
    }

    /**
     * Retorna a contagem atual de tentativas falhas.
     */
    public function getAttemptCount(string $ip, string $username): int
    {
        $stmt = $this->pdo->prepare("
            SELECT tentativas 
            FROM tentativas_login 
            WHERE ip_address = :ip AND username = :username 
            LIMIT 1
        ");
        $stmt->execute(['ip' => $ip, 'username' => $username]);
        $row = $stmt->fetch();
        return $row ? (int)$row['tentativas'] : 0;
    }

    /**
     * Limpa tentativas falhas após autenticação com sucesso.
     */
    public function clearFailedAttempts(string $ip, string $username): void
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM tentativas_login 
            WHERE ip_address = :ip AND username = :username
        ");
        $stmt->execute(['ip' => $ip, 'username' => $username]);
    }

    /**
     * Atualiza o timestamp de último login do operador.
     */
    private function updateLastLogin(int $userId): void
    {
        $agora = date('Y-m-d H:i:s');
        try {
            $stmt = $this->pdo->prepare("
                UPDATE usuarios_admin 
                SET ultimo_login = :agora 
                WHERE id = :id
            ");
            $stmt->execute(['agora' => $agora, 'id' => $userId]);
        } catch (PDOException $e) {
            // Silencia erro se campo já foi atualizado ou formato incompatível
        }
    }
}
