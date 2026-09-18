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
     * Alias de conveniência para validateCsrfToken.
     */
    public static function verifyCsrfToken(?string $token): bool
    {
        return self::validateCsrfToken($token);
    }

    /**
     * Autentica o aluno através da Chave de Acesso da Turma.
     *
     * @param string $chaveAcesso Chave fornecida pelo aluno
     * @param string|null $identificadorEsperado Slug ou código de turma opcional para validação cruzada
     * @return array{success: bool, error?: string, turma?: array}
     */
    public function authenticateStudent(string $chaveAcesso, ?string $identificadorEsperado = null, ?array $alunoData = null): array
    {
        $chaveAcesso = trim($chaveAcesso);

        if (empty($chaveAcesso)) {
            return [
                'success' => false,
                'error'   => 'Por favor, informe a Chave de Acesso da Turma.',
            ];
        }

        // Busca a turma no banco pela chave de acesso
        $stmt = $this->pdo->prepare("
            SELECT * FROM turmas 
            WHERE LOWER(TRIM(chave_acesso)) = LOWER(:chave) 
            LIMIT 1
        ");
        $stmt->execute(['chave' => $chaveAcesso]);
        $turma = $stmt->fetch();

        if (!$turma) {
            $this->recordStudentFailedAttempt();
            return [
                'success' => false,
                'error'   => 'Chave de acesso não reconhecida. Verifique a chave informada pelo instrutor ou solicite suporte.',
            ];
        }

        // Bloqueia acesso se a turma estiver na lixeira ou cancelada
        if (!empty($turma['deleted_at'])) {
            return [
                'success' => false,
                'error'   => 'O acesso aos materiais desta turma foi desativado (turma na lixeira).',
            ];
        }

        if ($turma['status'] === 'cancelada') {
            return [
                'success' => false,
                'error'   => 'O acesso aos materiais desta turma cancelada foi desativado.',
            ];
        }

        // Validação cruzada com identificador esperado (slug na URL), se fornecido
        if ($identificadorEsperado !== null && trim($identificadorEsperado) !== '') {
            $identEsperado = strtolower(trim($identificadorEsperado));
            $chaveTurma = strtolower(trim((string)$turma['chave_acesso']));
            $codigoTurma = strtolower(trim((string)$turma['codigo_turma']));

            // Se o identificador esperado não bate nem com a chave nem com o código
            if ($identEsperado !== $chaveTurma && $identEsperado !== $codigoTurma) {
                // Checa se o identificador esperado pertence a outra turma existente
                $stmtCheck = $this->pdo->prepare("
                    SELECT id FROM turmas 
                    WHERE LOWER(TRIM(chave_acesso)) = :id OR LOWER(TRIM(codigo_turma)) = :id 
                    LIMIT 1
                ");
                $stmtCheck->execute(['id' => $identEsperado]);
                $outraTurma = $stmtCheck->fetch();
                if ($outraTurma && (int)$outraTurma['id'] !== (int)$turma['id']) {
                    $this->recordStudentFailedAttempt();
                    return [
                        'success' => false,
                        'error'   => 'A chave informada não pertence a esta turma específica.',
                    ];
                }
            }
        }

        // Sucesso: limpa tentativas falhas de aluno
        $this->clearStudentFailedAttempts();

        // Inicializa sessão segura e regenera ID para prevenir fixation
        self::startSecureSession();
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!headers_sent()) {
                @session_regenerate_id(true);

                // Estende cookie de sessão para 30 dias se cabeçalhos ainda permitirem
                $cookieParams = session_get_cookie_params();
                setcookie(
                    session_name(),
                    session_id(),
                    time() + (30 * 86400),
                    $cookieParams['path'],
                    $cookieParams['domain'],
                    $cookieParams['secure'],
                    $cookieParams['httponly']
                );
            }
        }

        // Armazena escopo do aluno na sessão (estritamente isolado do admin)
        $_SESSION[self::SESSION_STUDENT_KEY] = [
            'turma_id'         => (int)$turma['id'],
            'codigo_turma'     => (string)$turma['codigo_turma'],
            'curso_nome'       => (string)$turma['curso_nome'],
            'chave_acesso'     => (string)$turma['chave_acesso'],
            'cliente_nome'     => (string)($turma['cliente_nome'] ?? ''),
            'status'           => (string)$turma['status'],
            'portal_certificados_modo' => (string)($turma['portal_certificados_modo'] ?? 'nenhum'),
            'aluno_id'         => isset($alunoData['aluno_id']) ? (int)$alunoData['aluno_id'] : (isset($alunoData['id']) ? (int)$alunoData['id'] : null),
            'aluno_nome'       => isset($alunoData['aluno_nome']) ? (string)$alunoData['aluno_nome'] : (isset($alunoData['nome_completo']) ? (string)$alunoData['nome_completo'] : null),
            'aluno_cpf'        => isset($alunoData['aluno_cpf']) ? (string)$alunoData['aluno_cpf'] : (isset($alunoData['cpf_mascarado']) ? (string)$alunoData['cpf_mascarado'] : (isset($alunoData['cpf']) ? (string)$alunoData['cpf'] : null)),
            'aluno_email'      => isset($alunoData['aluno_email']) ? (string)$alunoData['aluno_email'] : (isset($alunoData['email']) ? (string)$alunoData['email'] : null),
            'aluno_telefone'   => isset($alunoData['aluno_telefone']) ? (string)$alunoData['aluno_telefone'] : (isset($alunoData['telefone']) ? (string)$alunoData['telefone'] : null),
            'expira_em'        => time() + (30 * 86400),
            'authenticated_at' => date('Y-m-d H:i:s'),
        ];

        return [
            'success' => true,
            'turma'   => $turma,
        ];
    }

    /**
     * Verifica se há sessão ativa de aluno (e opcionalmente se pertence a uma turma específica).
     */
    public static function isStudentAuthenticated(?int $turmaId = null): bool
    {
        self::startSecureSession();
        if (empty($_SESSION[self::SESSION_STUDENT_KEY]['turma_id'])) {
            return false;
        }
        if ($turmaId !== null) {
            return (int)$_SESSION[self::SESSION_STUDENT_KEY]['turma_id'] === $turmaId;
        }
        return true;
    }

    /**
     * Retorna os dados da turma na sessão do aluno ou null.
     */
    public static function getAuthenticatedStudentTurma(): ?array
    {
        self::startSecureSession();
        return $_SESSION[self::SESSION_STUDENT_KEY] ?? null;
    }

    /**
     * Realiza logout seguro do aluno, preservando a sessão do operador administrativo.
     */
    public static function logoutStudent(): void
    {
        self::startSecureSession();
        unset($_SESSION[self::SESSION_STUDENT_KEY]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Guardião de rota de alunos: exige autenticação da turma.
     */
    public static function requireStudentAuth(?int $turmaId = null, string $redirectUrl = '/turmas'): array
    {
        self::startSecureSession();
        if (!self::isStudentAuthenticated($turmaId)) {
            if (!headers_sent()) {
                header("Location: {$redirectUrl}");
            }
            exit;
        }
        return $_SESSION[self::SESSION_STUDENT_KEY];
    }

    /**
     * Registra tentativa incorreta de aluno na sessão e calcula delay progressivo.
     * Retorna o atraso em segundos (1s, 2s, 4s).
     */
    public function recordStudentFailedAttempt(?string $ipAddress = null, bool $applySleep = true): int
    {
        self::startSecureSession();
        $attempts = (int)($_SESSION['student_failed_attempts'] ?? 0) + 1;
        $_SESSION['student_failed_attempts'] = $attempts;
        $_SESSION['student_last_failed_time'] = time();

        // Delay progressivo amigável a redes corporativas compartilhadas (NAT/Wi-Fi):
        // 1 erro: 1s; 2 erros: 2s; 3+ erros: 4s
        $delay = match (true) {
            $attempts === 1 => 1,
            $attempts === 2 => 2,
            default         => 4,
        };

        if ($delay > 0 && $applySleep && php_sapi_name() !== 'cli') {
            sleep($delay);
        }

        return $delay;
    }

    /**
     * Retorna a contagem atual de tentativas incorretas de chave de aluno na sessão.
     */
    public function getStudentFailedAttempts(?string $ipAddress = null): int
    {
        self::startSecureSession();
        return (int)($_SESSION['student_failed_attempts'] ?? 0);
    }

    /**
     * Limpa tentativas falhas de aluno na sessão após autenticação com sucesso.
     */
    public function clearStudentFailedAttempts(?string $ipAddress = null): void
    {
        self::startSecureSession();
        unset($_SESSION['student_failed_attempts']);
        unset($_SESSION['student_last_failed_time']);
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

    /**
     * Extrai o sobrenome principal a partir do nome completo.
     * Retorna o último token significativo em caixa alta.
     */
    public static function extractSurname(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName));
        if (!$parts || empty($parts[0])) {
            return '';
        }
        $last = end($parts);
        return mb_strtoupper($last, 'UTF-8');
    }

    /**
     * Gera o desafio de sobrenome em CAIXA ALTA (1 correto + 3 distratores).
     *
     * @param string $correctFullName Nome completo do aluno
     * @param int|null $turmaId Turma para buscar sobrenomes de outros alunos
     * @return array{options: string[], correct: string}
     */
    public function generateSurnameChallenge(string $correctFullName, ?int $turmaId = null): array
    {
        $correctSurname = self::extractSurname($correctFullName);

        $distractorPool = [
            'SILVA', 'SANTOS', 'OLIVEIRA', 'SOUZA', 'RODRIGUES',
            'FERREIRA', 'ALVES', 'PEREIRA', 'LIMA', 'GOMES',
            'COSTA', 'RIBEIRO', 'MARTINS', 'CARVALHO', 'ALMEIDA',
            'LOPES', 'SOARES', 'FERNANDES', 'VIEIRA', 'BARBOSA',
            'ROCHA', 'DIAS', 'NASCIMENTO', 'ANDRADE', 'MOREIRA',
            'NUNES', 'MARQUES', 'MACHADO', 'MENDES', 'FREITAS',
            'CARDOSO', 'RAMOS', 'GONCALVES', 'SANTANA', 'TEIXEIRA'
        ];

        // Tenta colher sobrenomes reais de outros alunos no banco
        try {
            $stmt = $this->pdo->prepare("SELECT nome_completo FROM alunos WHERE nome_completo != ? LIMIT 30");
            $stmt->execute([$correctFullName]);
            $nomes = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($nomes as $n) {
                $sn = self::extractSurname((string)$n);
                if (!empty($sn) && !in_array($sn, $distractorPool, true)) {
                    $distractorPool[] = $sn;
                }
            }
        } catch (\Throwable) {
            // Usa pool padrão
        }

        // Filtra para garantir que nenhum distrator seja igual ao correto
        $filtered = array_values(array_filter(
            $distractorPool,
            fn($s) => mb_strtoupper($s, 'UTF-8') !== $correctSurname
        ));

        shuffle($filtered);
        $distractors = array_slice($filtered, 0, 3);

        // Garante 4 opções todas em CAIXA ALTA
        $options = array_map(
            fn($s) => mb_strtoupper(trim($s), 'UTF-8'),
            array_merge([$correctSurname], $distractors)
        );

        shuffle($options);

        return [
            'options' => $options,
            'correct' => $correctSurname,
        ];
    }

    /**
     * Valida a resposta do desafio de sobrenome.
     */
    public static function verifySurnameChallenge(string $chosenSurname, string $correctFullName): bool
    {
        $expected = self::extractSurname($correctFullName);
        $chosen = mb_strtoupper(trim($chosenSurname), 'UTF-8');

        if (empty($expected) || empty($chosen)) {
            return false;
        }

        return hash_equals($expected, $chosen);
    }

    /**
     * Rate-limiting do validador público de certificados.
     * Limita a no máximo 30 consultas por minuto por IP utilizando a tabela tentativas_login.
     *
     * @param string $ip Endereço IP do cliente
     * @param int $maxPerMinute Máximo de requisições por minuto (padrão: 30)
     * @return bool True se permitido, False se excedeu a cota de rate limit
     */
    public function checkValidatorRateLimit(string $ip, int $maxPerMinute = 30): bool
    {
        $ip = trim($ip) ?: '127.0.0.1';
        $username = 'rate_limit_validator';
        $now = time();

        try {
            $stmt = $this->pdo->prepare("
                SELECT id, tentativas, updated_at 
                FROM tentativas_login 
                WHERE ip_address = :ip AND username = :username 
                LIMIT 1
            ");
            $stmt->execute(['ip' => $ip, 'username' => $username]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                $lastUpdated = strtotime((string)$row['updated_at']);
                $elapsed = $now - $lastUpdated;

                if ($elapsed > 60) {
                    // Janela de 1 minuto expirou: reinicia contagem
                    $updateStmt = $this->pdo->prepare("
                        UPDATE tentativas_login 
                        SET tentativas = 1, updated_at = :agora 
                        WHERE id = :id
                    ");
                    $updateStmt->execute(['agora' => date('Y-m-d H:i:s', $now), 'id' => $row['id']]);
                    return true;
                }

                $tentativas = (int)$row['tentativas'];
                if ($tentativas >= $maxPerMinute) {
                    return false;
                }

                // Incrementa contador dentro da janela
                $incStmt = $this->pdo->prepare("
                    UPDATE tentativas_login 
                    SET tentativas = tentativas + 1, updated_at = :agora 
                    WHERE id = :id
                ");
                $incStmt->execute(['agora' => date('Y-m-d H:i:s', $now), 'id' => $row['id']]);
                return true;
            }

            // Primeiro acesso do IP no validador
            $insertStmt = $this->pdo->prepare("
                INSERT INTO tentativas_login (ip_address, username, tentativas, ultimo_erro, created_at, updated_at)
                VALUES (:ip, :username, 1, :agora, :agora, :agora)
            ");
            $agoraStr = date('Y-m-d H:i:s', $now);
            $insertStmt->execute([
                'ip'       => $ip,
                'username' => $username,
                'agora'    => $agoraStr,
            ]);
            return true;
        } catch (\Throwable) {
            // Em caso de instabilidade na tabela transitória, permite a consulta
            return true;
        }
    }

    /**
     * Atualiza dados do aluno na sessão atual ativa.
     */
    public static function updateAuthenticatedStudentData(array $alunoData): void
    {
        self::startSecureSession();
        if (!isset($_SESSION[self::SESSION_STUDENT_KEY])) {
            return;
        }
        $map = [
            'id'            => 'aluno_id',
            'aluno_id'      => 'aluno_id',
            'nome_completo' => 'aluno_nome',
            'aluno_nome'    => 'aluno_nome',
            'cpf_mascarado' => 'aluno_cpf',
            'cpf'           => 'aluno_cpf',
            'aluno_cpf'     => 'aluno_cpf',
            'email'         => 'aluno_email',
            'aluno_email'   => 'aluno_email',
            'telefone'      => 'aluno_telefone',
            'aluno_telefone'=> 'aluno_telefone',
        ];
        foreach ($map as $k => $dest) {
            if (isset($alunoData[$k])) {
                $_SESSION[self::SESSION_STUDENT_KEY][$dest] = $alunoData[$k];
            }
        }
    }

    /**
     * Rate-limiting do formulário de auto-acesso/auto-cadastro de alunos via QR Code (/turmas/entrar).
     * Limita a no máximo 3 requisições a cada 10 minutos (600s) por endereço IP.
     *
     * @param string $ip Endereço IP do cliente
     * @param int $maxRequests Máximo de requisições na janela (padrão: 3)
     * @param int $windowSeconds Janela de tempo em segundos (padrão: 600)
     * @return bool True se permitido, False se excedeu o limite
     */
    public static function checkStudentAccessRateLimit(string $ip, int $maxRequests = 3, int $windowSeconds = 600, ?\PDO $pdo = null): bool
    {
        $pdo = $pdo ?? Database::getConnection();
        $ip = trim($ip) ?: '127.0.0.1';
        $username = 'rate_limit_auto_acesso';
        $now = time();

        try {
            $stmt = $pdo->prepare("
                SELECT id, tentativas, updated_at 
                FROM tentativas_login 
                WHERE ip_address = :ip AND username = :username 
                LIMIT 1
            ");
            $stmt->execute(['ip' => $ip, 'username' => $username]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                $lastUpdated = strtotime((string)$row['updated_at']);
                $elapsed = $now - $lastUpdated;

                if ($elapsed > $windowSeconds) {
                    // Janela expirou: reinicia contagem
                    $updateStmt = $pdo->prepare("
                        UPDATE tentativas_login 
                        SET tentativas = 1, updated_at = :agora 
                        WHERE id = :id
                    ");
                    $updateStmt->execute(['agora' => date('Y-m-d H:i:s', $now), 'id' => $row['id']]);
                    return true;
                }

                $tentativas = (int)$row['tentativas'];
                if ($tentativas >= $maxRequests) {
                    return false;
                }

                // Incrementa contador dentro da janela
                $incStmt = $pdo->prepare("
                    UPDATE tentativas_login 
                    SET tentativas = tentativas + 1, updated_at = :agora 
                    WHERE id = :id
                ");
                $incStmt->execute(['agora' => date('Y-m-d H:i:s', $now), 'id' => $row['id']]);
                return true;
            }

            // Primeiro acesso do IP no auto-acesso
            $insertStmt = $pdo->prepare("
                INSERT INTO tentativas_login (ip_address, username, tentativas, ultimo_erro, created_at, updated_at)
                VALUES (:ip, :username, 1, 'Auto-acesso aluno', :agora, :agora)
            ");
            $insertStmt->execute([
                'ip'       => $ip,
                'username' => $username,
                'agora'    => date('Y-m-d H:i:s', $now),
            ]);
            return true;
        } catch (\Throwable) {
            // Em caso de falha transitória de banco no rate limit, permite a requisição por resiliência
            return true;
        }
    }
}
