<?php
/**
 * Serviço de Integração com Cloudflare Turnstile e Blindagem Anti-Automação
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 * 
 * Regras de Segurança (ADR-0005 / Ticket 07c):
 * - Suporta validação de tokens Turnstile em modo gerenciado e invisível
 * - Utiliza chaves de teste padronizadas da Cloudflare para ambiente local/desenvolvimento
 * - Permite bypass transparente em execuções de testes automatizados (CLI/Mock)
 * - Renderiza snippets HTML defensivos do widget oficial
 */

declare(strict_types=1);

namespace FuturoFacil\Services;

class TurnstileService
{
    // Chaves de teste oficiais da Cloudflare (sempre passam)
    public const TEST_SITE_KEY = '1x00000000000000000000AA';
    public const TEST_SECRET_KEY = '1x0000000000000000000000000000000AA';

    // Endpoint oficial de verificação
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    // Limite de falhas de aluno antes de exigir Turnstile
    public const STUDENT_FAIL_THRESHOLD = 3;

    private string $siteKey;
    private string $secretKey;
    private bool $isTestMode;

    public function __construct(?string $siteKey = null, ?string $secretKey = null)
    {
        // Lê de credenciais locais se disponíveis
        $configuredSiteKey = null;
        $configuredSecretKey = null;

        $credentialsFile = dirname(__DIR__) . '/Config/credentials.local.php';
        if (file_exists($credentialsFile)) {
            $creds = require $credentialsFile;
            if (is_array($creds)) {
                $configuredSiteKey = $creds['TURNSTILE_SITE_KEY'] ?? null;
                $configuredSecretKey = $creds['TURNSTILE_SECRET_KEY'] ?? null;
            }
        }

        $this->siteKey = $siteKey 
            ?? $configuredSiteKey 
            ?? (getenv('TURNSTILE_SITE_KEY') ?: null)
            ?: self::TEST_SITE_KEY;

        $this->secretKey = $secretKey 
            ?? $configuredSecretKey 
            ?? (getenv('TURNSTILE_SECRET_KEY') ?: null)
            ?: self::TEST_SECRET_KEY;

        $this->isTestMode = ($this->secretKey === self::TEST_SECRET_KEY || php_sapi_name() === 'cli');
    }

    /**
     * Retorna a Site Key pública para injeção nas páginas.
     */
    public function getSiteKey(): string
    {
        return $this->siteKey;
    }

    /**
     * Verifica se o desafio Turnstile é exigido com base no número de tentativas falhas.
     */
    public static function isRequiredForStudent(int $failedAttempts): bool
    {
        return $failedAttempts >= self::STUDENT_FAIL_THRESHOLD;
    }

    /**
     * Valida o token recebido do Cloudflare Turnstile.
     *
     * @param string|null $token Token gerado no front-end ('cf-turnstile-response')
     * @param string|null $remoteIp IP do cliente requisitante
     * @return bool True se o token for válido e autenticado
     */
    public function verify(?string $token, ?string $remoteIp = null): bool
    {
        // Se estiver em modo de teste ou CLI com token de teste ou chave padrão
        if ($this->isTestMode) {
            if (!empty($token) && (
                $token === 'XXXX.DUMMY.TOKEN.XXXX' ||
                str_starts_with($token, 'test-') ||
                $token === 'turnstile-valid-token' ||
                $this->secretKey === self::TEST_SECRET_KEY
            )) {
                return true;
            }
            if (empty($token)) {
                return false;
            }
        }

        if (empty($token) || !is_string($token)) {
            return false;
        }

        $postData = [
            'secret'   => $this->secretKey,
            'response' => $token,
        ];
        if (!empty($remoteIp)) {
            $postData['remoteip'] = $remoteIp;
        }

        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($postData),
                'timeout' => 4.0, // Timeout estrito defensivo
            ],
        ];

        try {
            $context = stream_context_create($options);
            $response = @file_get_contents(self::VERIFY_URL, false, $context);
            if ($response === false) {
                return $this->isTestMode;
            }

            $data = json_decode($response, true);
            return is_array($data) && !empty($data['success']);
        } catch (\Throwable) {
            return $this->isTestMode;
        }
    }

    /**
     * Renderiza a tag do widget Cloudflare Turnstile.
     *
     * @param string $mode 'managed' (visível padrão) ou 'invisible'
     * @param array $extraAttributes Atributos adicionais data-*
     */
    public function renderWidget(string $mode = 'managed', array $extraAttributes = []): string
    {
        $siteKeyEscaped = htmlspecialchars($this->siteKey, ENT_QUOTES, 'UTF-8');
        $attrStr = '';
        foreach ($extraAttributes as $k => $v) {
            $attrStr .= ' data-' . htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') . '="' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '"';
        }

        if ($mode === 'invisible') {
            return "<div class=\"cf-turnstile\" data-sitekey=\"{$siteKeyEscaped}\" data-size=\"invisible\"{$attrStr}></div>";
        }

        return "<div class=\"cf-turnstile\" data-sitekey=\"{$siteKeyEscaped}\" data-theme=\"light\"{$attrStr}></div>";
    }

    /**
     * Renderiza o script oficial da Cloudflare de forma segura.
     */
    public static function renderScriptTag(): string
    {
        return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
    }
}
