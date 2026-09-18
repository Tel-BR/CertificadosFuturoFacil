<?php
/**
 * Serviço de Disparo de E-mails Transacionais da Plataforma
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 * 
 * Regras de Design e Segurança (ADR-0005 / ADR-0006):
 * - Identidade visual editorial: tons Petróleo Tech (#0E7490), Papel Marfim (#FAF7F1), Carvão (#1B1918)
 * - Proibição estrita de emojis na interface e templates
 * - Entrega da Chave de Acesso da Turma com link de auto-login
 * - Suporte a modo de teste in-memory para validação determinística em suítes TDD
 */

declare(strict_types=1);

namespace FuturoFacil\Services;

class TransactionalMailService
{
    public static bool $testMode = true;
    public static ?array $lastSentEmail = null;

    public function __construct(?\PDO $pdo = null, bool $testMode = true)
    {
        self::$testMode = $testMode;
    }

    public function getSentMails(): array
    {
        return self::$lastSentEmail ? [self::$lastSentEmail] : [];
    }

    /**
     * Dispara e-mail transacional de confirmação de cadastro e chave de acesso da turma.
     */
    public function sendStudentAccessEmail(
        array|string $turma,
        string $studentEmail,
        string $studentName,
        bool|string $isNewRegistration = true,
        ?string $baseUrl = null
    ): array {
        if (is_string($turma)) {
            $studentActualName = $turma;
            $cursoNome = $studentName;
            $chave = is_string($isNewRegistration) ? $isNewRegistration : '';
            $turma = [
                'curso_nome'   => $cursoNome,
                'chave_acesso' => $chave,
                'codigo_turma' => $chave,
            ];
            $studentName = $studentActualName;
            $isNewRegistration = true;
        }

        $studentEmail = trim($studentEmail);
        $studentName = trim($studentName) ?: 'Aluno';
        $cursoNome = (string)($turma['curso_nome'] ?? 'Curso Futuro Fácil');
        $codigoTurma = (string)($turma['codigo_turma'] ?? '');
        $chaveAcesso = (string)($turma['chave_acesso'] ?? '');
        $clienteNome = (string)($turma['cliente_nome'] ?? '');

        $base = $baseUrl !== null ? rtrim($baseUrl, '/') : $this->detectBaseUrl();
        $slug = !empty($codigoTurma) ? urlencode($codigoTurma) : '';
        $directUrl = $base . '/turmas' . ($slug ? '/' . $slug : '') . '?chave=' . urlencode($chaveAcesso);
        $portalUrl = $base . '/turmas' . ($slug ? '/' . $slug : '');

        $subject = "Chave de Acesso aos Materiais Didáticos: {$cursoNome} — Futuro Fácil";

        $saudacaoStatus = $isNewRegistration
            ? "Seu cadastro na turma foi realizado com sucesso em sala de aula."
            : "Confirmamos sua identificação e matrícula oficial na turma.";

        // Template HTML aderente ao ADR-0006
        $bodyHtml = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
    <style>
        body { margin: 0; padding: 0; background-color: #FAF7F1; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1B1918; }
        .wrapper { max-width: 600px; margin: 20px auto; background-color: #FFFFFF; border: 1px solid #E2DFDA; border-radius: 8px; overflow: hidden; }
        .header { background-color: #0E7490; padding: 24px 32px; text-align: left; }
        .header h1 { margin: 0; color: #FFFFFF; font-size: 20px; font-weight: 700; letter-spacing: -0.3px; }
        .header p { margin: 4px 0 0 0; color: #E0F2FE; font-size: 13px; }
        .content { padding: 32px; font-size: 15px; line-height: 1.6; color: #1B1918; }
        .greeting { font-size: 17px; font-weight: 700; color: #0F172A; margin-bottom: 12px; }
        .status-box { background-color: #F8FAFC; border-left: 4px solid #0E7490; padding: 12px 16px; margin: 18px 0; font-size: 14px; border-radius: 4px; }
        .key-card { background-color: #FAF7F1; border: 1px solid #CBD5E1; border-radius: 6px; padding: 18px; text-align: center; margin: 24px 0; }
        .key-label { font-size: 12px; text-transform: uppercase; font-weight: 700; color: #64748B; letter-spacing: 0.5px; margin-bottom: 6px; }
        .key-value { font-family: 'JetBrains Mono', Consolas, Monaco, monospace; font-size: 24px; font-weight: 700; color: #0E7490; letter-spacing: 2px; }
        .btn-cta { display: inline-block; background-color: #0E7490; color: #FFFFFF !important; text-decoration: none; font-weight: 700; font-size: 15px; padding: 14px 28px; border-radius: 6px; margin: 12px 0 24px 0; }
        .footer { background-color: #F8FAFC; border-top: 1px solid #E2DFDA; padding: 20px 32px; font-size: 12px; color: #64748B; text-align: center; }
        .footer a { color: #0E7490; text-decoration: none; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>FUTURO FÁCIL</h1>
            <p>Plataforma Integrada de Gestão Pedagógica e Conteúdos</p>
        </div>
        <div class="content">
            <div class="greeting">Olá, {$studentName}!</div>
            <p>Você solicitou acesso aos materiais didáticos do curso <strong>{$cursoNome}</strong>.</p>
            <div class="status-box">
                {$saudacaoStatus}
            </div>
            
            <p>Utilize sua <strong>Chave de Acesso</strong> exclusiva para desbloquear apostilas, exercícios e slides:</p>
            
            <div class="key-card">
                <div class="key-label">Sua Chave de Acesso</div>
                <div class="key-value">{$chaveAcesso}</div>
            </div>

            <div style="text-align: center;">
                <a href="{$directUrl}" class="btn-cta">Acessar Materiais Didáticos</a>
            </div>

            <p style="font-size: 13px; color: #64748B;">
                Se preferir acessar manualmente em outro dispositivo, acesse <a href="{$portalUrl}" style="color: #0E7490;">{$portalUrl}</a> e digite sua Chave de Acesso informada acima.
            </p>
        </div>
        <div class="footer">
            <p>Este é um e-mail transacional automático do ecossistema pedagógico da Futuro Fácil.</p>
            <p>Dúvidas ou suporte? Entre em contato pelo e-mail <a href="mailto:contato@futurofacil.com.br">contato@futurofacil.com.br</a></p>
        </div>
    </div>
</body>
</html>
HTML;

        // Versão texto puro
        $bodyText = <<<TEXT
FUTURO FÁCIL — PLATAFORMA PEDAGÓGICA
Acesso aos Materiais Didáticos

Olá, {$studentName}!

Você solicitou acesso aos materiais do curso {$cursoNome}.
{$saudacaoStatus}

SUA CHAVE DE ACESSO:
{$chaveAcesso}

LINK DIRETO DE ACESSO (com auto-login):
{$directUrl}

Ou acesse {$portalUrl} e informe a chave acima.

Dúvidas ou suporte: contato@futurofacil.com.br
TEXT;

        $result = [
            'success'      => true,
            'to'           => $studentEmail,
            'subject'      => $subject,
            'body_html'    => $bodyHtml,
            'body_text'    => $bodyText,
            'chave_acesso' => $chaveAcesso,
            'direct_url'   => $directUrl,
        ];

        self::$lastSentEmail = $result;

        if (!self::$testMode && php_sapi_name() !== 'cli') {
            $headers = [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=UTF-8',
                'From: Futuro Fácil <contato@futurofacil.com.br>',
                'Reply-To: contato@futurofacil.com.br',
                'X-Mailer: FuturoFacil-Mailer/1.0',
            ];
            @mail($studentEmail, $subject, $bodyHtml, implode("\r\n", $headers));
        }

        return $result;
    }

    private function detectBaseUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'staging.futurofacil.com.br';
        return $protocol . $host;
    }
}
