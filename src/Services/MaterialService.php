<?php
/**
 * Serviço de Gestão de Materiais Didáticos e Blindagem de Conteúdo (MaterialService)
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 * 
 * Regras de Negócio e Segurança (ADR-0008 / Ticket 07):
 * - Armazenamento de arquivos em diretório protegido fora do alcance HTTP direto
 * - Proteção estrita contra ataques de Path Traversal via realpath e raiz canônica
 * - Controle de visibilidade na hora da aula (ativo = 1/0) com alternância instantânea
 * - Suporte a links externos e detecção de embeds (YouTube, Google Forms, Microsoft Forms)
 * - Streaming seguro de downloads intermediado por controlador PHP autenticado
 */

declare(strict_types=1);

namespace FuturoFacil\Services;

use FuturoFacil\Config\Database;
use PDO;
use PDOException;
use RuntimeException;
use InvalidArgumentException;

class MaterialService
{
    private PDO $pdo;
    private string $baseStorageDir;

    public const ALLOWED_EXTENSIONS = [
        'pdf'  => 'application/pdf',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls'  => 'application/vnd.ms-excel',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'doc'  => 'application/msword',
        'zip'  => 'application/zip',
        'csv'  => 'text/csv',
    ];

    public function __construct(?PDO $pdo = null, ?string $baseStorageDir = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
        $this->baseStorageDir = $baseStorageDir ?? dirname(__DIR__, 2) . '/public/turmas/arquivos';

        if (!is_dir($this->baseStorageDir)) {
            @mkdir($this->baseStorageDir, 0755, true);
        }
    }

    /**
     * Retorna o diretório base de armazenamento de arquivos protegidos.
     */
    public function getBaseStorageDir(): string
    {
        return $this->baseStorageDir;
    }

    /**
     * Lista todos os materiais didáticos vinculados a uma turma.
     *
     * @param int $turmaId ID da turma
     * @param bool $apenasAtivos Se true, filtra apenas materiais com status ativo = 1
     * @return array<int, array<string, mixed>>
     */
    public function getMateriaisByTurma(int $turmaId, bool $apenasAtivos = true): array
    {
        $sql = "SELECT * FROM materiais_turma WHERE turma_id = :turma_id";
        if ($apenasAtivos) {
            $sql .= " AND ativo = 1";
        }
        $sql .= " ORDER BY ordem ASC, id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['turma_id' => $turmaId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $materiais = [];
        foreach ($rows as $row) {
            $materiais[] = $this->enrichMaterialData($row);
        }

        return $materiais;
    }

    /**
     * Lista materiais agrupados por categoria para exibição limpa no portal do aluno.
     */
    public function getMateriaisCategorizados(int $turmaId, bool $apenasAtivos = true): array
    {
        $lista = $this->getMateriaisByTurma($turmaId, $apenasAtivos);

        $categorias = [
            'apostila'  => ['titulo' => 'Apostilas & Material Didático', 'icone' => 'pdf', 'itens' => []],
            'exercicio' => ['titulo' => 'Planilhas & Exercícios Práticos', 'icone' => 'xlsx', 'itens' => []],
            'slide'     => ['titulo' => 'Slides & Apresentações', 'icone' => 'slide', 'itens' => []],
            'video'     => ['titulo' => 'Vídeos & Gravações Complementares', 'icone' => 'video', 'itens' => []],
            'formulario'=> ['titulo' => 'Formulários & Pesquisas', 'icone' => 'form', 'itens' => []],
            'link'      => ['titulo' => 'Links Úteis & Referências', 'icone' => 'link', 'itens' => []],
            'outro'     => ['titulo' => 'Materiais Complementares', 'icone' => 'outro', 'itens' => []],
        ];

        foreach ($lista as $m) {
            $catKey = $m['tipo'];
            if ($catKey === 'link' && !empty($m['embed_info']['is_embeddable'])) {
                if ($m['embed_info']['embed_type'] === 'youtube' || $m['embed_info']['embed_type'] === 'vimeo') {
                    $catKey = 'video';
                } elseif ($m['embed_info']['embed_type'] === 'google_forms' || $m['embed_info']['embed_type'] === 'ms_forms') {
                    $catKey = 'formulario';
                }
            }

            if (!isset($categorias[$catKey])) {
                $catKey = 'outro';
            }
            $categorias[$catKey]['itens'][] = $m;
        }

        return array_filter($categorias, fn($c) => !empty($c['itens']));
    }

    /**
     * Busca um material específico pelo seu ID.
     */
    public function getMaterialById(int $materialId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM materiais_turma WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $materialId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->enrichMaterialData($row);
    }

    /**
     * Cadastra um novo material didático (arquivo físico enviado ou link externo).
     *
     * @param array $data Metadados (turma_id, titulo, descricao, tipo, ordem, url_externa, ativo)
     * @param array|null $uploadedFile Array $_FILES['arquivo'] opcional
     * @return int ID do material inserido
     */
    public function createMaterial(array $data, ?array $uploadedFile = null): int
    {
        $turmaId = (int)($data['turma_id'] ?? 0);
        $titulo = trim((string)($data['titulo'] ?? ''));

        if ($turmaId <= 0) {
            throw new InvalidArgumentException("Turma inválida informada.");
        }
        if (empty($titulo)) {
            throw new InvalidArgumentException("O título do material é obrigatório.");
        }

        $descricao = !empty($data['descricao']) ? trim((string)$data['descricao']) : null;
        $tipo = trim((string)($data['tipo'] ?? 'apostila'));
        $ordem = (int)($data['ordem'] ?? 0);
        $ativo = isset($data['ativo']) ? (int)(bool)$data['ativo'] : 1;
        $urlExterna = !empty($data['url_externa']) ? trim((string)$data['url_externa']) : null;
        $caminhoArquivo = null;
        $tamanhoBytes = null;

        // Se houver arquivo enviado para upload
        if ($uploadedFile !== null && !empty($uploadedFile['name']) && ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException("Falha no upload do arquivo (código de erro: {$uploadedFile['error']}).");
            }

            $origName = basename($uploadedFile['name']);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!array_key_exists($ext, self::ALLOWED_EXTENSIONS)) {
                $extensoesPermitidas = implode(', ', array_keys(self::ALLOWED_EXTENSIONS));
                throw new InvalidArgumentException("Formato de arquivo .{$ext} não permitido. Formatos aceitos: {$extensoesPermitidas}.");
            }

            $nameWithoutExt = pathinfo($origName, PATHINFO_FILENAME);
            $cleanBaseName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $nameWithoutExt);
            $cleanFileName = "{$cleanBaseName}.{$ext}";

            $turmaDir = $this->baseStorageDir . '/' . $turmaId;
            if (!is_dir($turmaDir)) {
                @mkdir($turmaDir, 0755, true);
            }

            $targetPath = $turmaDir . '/' . $cleanFileName;
            $counter = 1;
            while (file_exists($targetPath)) {
                $targetPath = $turmaDir . '/' . $cleanBaseName . '_' . $counter . '.' . $ext;
                $cleanFileName = $cleanBaseName . '_' . $counter . '.' . $ext;
                $counter++;
            }

            $tmpFile = $uploadedFile['tmp_name'];
            if (is_uploaded_file($tmpFile)) {
                if (!move_uploaded_file($tmpFile, $targetPath)) {
                    throw new RuntimeException("Não foi possível salvar o arquivo no diretório protegido.");
                }
            } else {
                if (!copy($tmpFile, $targetPath)) {
                    throw new RuntimeException("Não foi possível copiar o arquivo no diretório protegido.");
                }
            }

            $caminhoArquivo = "{$turmaId}/{$cleanFileName}";
            $tamanhoBytes = (int)filesize($targetPath);
        } elseif (!empty($urlExterna)) {
            if (!filter_var($urlExterna, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException("A URL informada é inválida: '{$urlExterna}'.");
            }
        } else {
            throw new InvalidArgumentException("É necessário enviar um arquivo para upload ou informar uma URL externa.");
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO materiais_turma (
                turma_id, titulo, descricao, tipo, caminho_arquivo, url_externa, tamanho_bytes, ordem, ativo, created_at, updated_at
            ) VALUES (
                :turma_id, :titulo, :descricao, :tipo, :caminho_arquivo, :url_externa, :tamanho_bytes, :ordem, :ativo, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ");

        $stmt->execute([
            'turma_id'        => $turmaId,
            'titulo'          => $titulo,
            'descricao'       => $descricao,
            'tipo'            => $tipo,
            'caminho_arquivo' => $caminhoArquivo,
            'url_externa'     => $urlExterna,
            'tamanho_bytes'   => $tamanhoBytes,
            'ordem'           => $ordem,
            'ativo'           => $ativo,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Atualiza os metadados de um material existente.
     */
    public function updateMaterial(int $materialId, array $data): bool
    {
        $material = $this->getMaterialById($materialId);
        if (!$material) {
            return false;
        }

        $titulo = isset($data['titulo']) ? trim((string)$data['titulo']) : (string)$material['titulo'];
        $descricao = isset($data['descricao']) ? (trim((string)$data['descricao']) ?: null) : $material['descricao'];
        $tipo = isset($data['tipo']) ? trim((string)$data['tipo']) : (string)$material['tipo'];
        $ordem = isset($data['ordem']) ? (int)$data['ordem'] : (int)$material['ordem'];
        $ativo = isset($data['ativo']) ? (int)(bool)$data['ativo'] : (int)$material['ativo'];
        $urlExterna = isset($data['url_externa']) ? (trim((string)$data['url_externa']) ?: null) : $material['url_externa'];

        $stmt = $this->pdo->prepare("
            UPDATE materiais_turma 
            SET titulo = :titulo,
                descricao = :descricao,
                tipo = :tipo,
                ordem = :ordem,
                ativo = :ativo,
                url_externa = :url_externa,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        return $stmt->execute([
            'titulo'      => $titulo,
            'descricao'   => $descricao,
            'tipo'        => $tipo,
            'ordem'       => $ordem,
            'ativo'       => $ativo,
            'url_externa' => $urlExterna,
            'id'          => $materialId,
        ]);
    }

    /**
     * Alterna instantaneamente a visibilidade do material (Ativo <=> Oculto).
     */
    public function toggleMaterialStatus(int $materialId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE materiais_turma 
            SET ativo = CASE WHEN ativo = 1 THEN 0 ELSE 1 END,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        return $stmt->execute(['id' => $materialId]);
    }

    /**
     * Remove o material do banco e apaga com segurança o arquivo físico no disco.
     */
    public function deleteMaterial(int $materialId): bool
    {
        $material = $this->getMaterialById($materialId);
        if (!$material) {
            return false;
        }

        if (!empty($material['caminho_arquivo'])) {
            $absPath = $this->resolveFilePath((string)$material['caminho_arquivo']);
            if ($absPath && file_exists($absPath)) {
                @unlink($absPath);
            }
        }

        $stmt = $this->pdo->prepare("DELETE FROM materiais_turma WHERE id = :id");
        return $stmt->execute(['id' => $materialId]);
    }

    /**
     * Atualiza a Chave de Acesso da Turma com higienização e validação de unicidade.
     */
    public function updateTurmaChaveAcesso(int $turmaId, string $novaChave): bool
    {
        $chaveLimpa = strtolower(trim($novaChave));
        $chaveLimpa = preg_replace('/[^a-z0-9_\-]/', '', $chaveLimpa);

        if (strlen($chaveLimpa) < 3) {
            throw new InvalidArgumentException("A chave de acesso deve conter pelo menos 3 caracteres alfanuméricos.");
        }

        $stmtCheck = $this->pdo->prepare("
            SELECT id FROM turmas 
            WHERE LOWER(TRIM(chave_acesso)) = :chave AND id != :turma_id
            LIMIT 1
        ");
        $stmtCheck->execute(['chave' => $chaveLimpa, 'turma_id' => $turmaId]);
        if ($stmtCheck->fetch()) {
            throw new InvalidArgumentException("Esta chave de acesso já está em uso por outra turma.");
        }

        $stmt = $this->pdo->prepare("
            UPDATE turmas 
            SET chave_acesso = :chave,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :turma_id
        ");
        return $stmt->execute(['chave' => $chaveLimpa, 'turma_id' => $turmaId]);
    }

    /**
     * Atualiza o modo de entrega de certificados no portal do aluno.
     *
     * @param int $turmaId ID da turma
     * @param string $modo Modo: 'nenhum', 'coordenacao', 'download_direto'
     */
    public function updateTurmaCertificadosModo(int $turmaId, string $modo): bool
    {
        $modosValidos = ['nenhum', 'coordenacao', 'download_direto'];
        if (!in_array($modo, $modosValidos, true)) {
            throw new InvalidArgumentException("Modo de certificados inválido: '{$modo}'.");
        }

        $stmt = $this->pdo->prepare("
            UPDATE turmas 
            SET portal_certificados_modo = :modo,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :turma_id
        ");
        return $stmt->execute(['modo' => $modo, 'turma_id' => $turmaId]);
    }

    /**
     * Localiza uma turma através da chave de acesso ou slug/código amigável.
     */
    public function getTurmaBySlugOrChave(string $identificador): ?array
    {
        $ident = strtolower(trim($identificador));
        if (empty($ident)) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT * FROM turmas 
            WHERE LOWER(TRIM(chave_acesso)) = :id 
               OR LOWER(TRIM(codigo_turma)) = :id 
            LIMIT 1
        ");
        $stmt->execute(['id' => $ident]);
        $turma = $stmt->fetch(PDO::FETCH_ASSOC);

        return $turma ?: null;
    }

    /**
     * Resolve o caminho físico absoluto de um arquivo com proteção estrita contra Path Traversal.
     */
    public function resolveFilePath(string $caminhoRelativo): ?string
    {
        if (str_contains($caminhoRelativo, "\0")) {
            return null;
        }

        $baseCanonical = realpath($this->baseStorageDir);
        if (!$baseCanonical) {
            return null;
        }

        $cleanRelative = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, ltrim($caminhoRelativo, '/\\'));
        $tentativePath = $baseCanonical . DIRECTORY_SEPARATOR . $cleanRelative;

        $realPath = realpath($tentativePath);
        if (!$realPath) {
            return null;
        }

        // Proteção contra Path Traversal
        if (!str_starts_with($realPath, $baseCanonical . DIRECTORY_SEPARATOR) && $realPath !== $baseCanonical) {
            return null;
        }

        return is_file($realPath) ? $realPath : null;
    }

    /**
     * Detecta automaticamente URLs de vídeo e formulários para renderização de players inline.
     */
    public function detectEmbedType(?string $url): array
    {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['is_embeddable' => false, 'embed_type' => null, 'embed_url' => null];
        }

        // 1. YouTube
        if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', $url, $matches)) {
            $videoId = $matches[1];
            return [
                'is_embeddable' => true,
                'embed_type'    => 'youtube',
                'embed_url'     => "https://www.youtube-nocookie.com/embed/{$videoId}",
            ];
        }

        // 2. Vimeo
        if (preg_match('/vimeo\.com\/(?:channels\/(?:\w+\/)?|groups\/([^\/]*)\/videos\/|album\/(\d+)\/video\/|)(\d+)/i', $url, $matches)) {
            $vimeoId = end($matches);
            return [
                'is_embeddable' => true,
                'embed_type'    => 'vimeo',
                'embed_url'     => "https://player.vimeo.com/video/{$vimeoId}",
            ];
        }

        // 3. Microsoft Forms
        if (str_contains($url, 'forms.office.com/')) {
            return [
                'is_embeddable' => true,
                'embed_type'    => 'ms_forms',
                'embed_url'     => $url,
            ];
        }

        // 4. Google Forms
        if (str_contains($url, 'docs.google.com/forms/')) {
            $embedUrl = $url;
            if (!str_contains($embedUrl, 'embedded=true')) {
                $embedUrl .= (str_contains($embedUrl, '?') ? '&' : '?') . 'embedded=true';
            }
            return [
                'is_embeddable' => true,
                'embed_type'    => 'google_forms',
                'embed_url'     => $embedUrl,
            ];
        }

        return [
            'is_embeddable' => false,
            'embed_type'    => null,
            'embed_url'     => null,
        ];
    }

    /**
     * Intermedeia o download seguro de um material com verificação de autorização e streaming.
     *
     * @param int $materialId ID do material
     * @param int|null $studentTurmaId ID da turma do aluno logado (null se operador admin)
     * @param bool $isAdmin Se true, concede acesso com privilégio administrativo
     */
    public function streamMaterialDownload(int $materialId, ?int $studentTurmaId = null, bool $isAdmin = false, bool $terminate = true): int
    {
        $material = $this->getMaterialById($materialId);

        if (!$material) {
            if (!headers_sent()) {
                http_response_code(404);
            }
            echo "Material didático não encontrado.";
            if ($terminate) {
                exit;
            }
            return 404;
        }

        if (!$isAdmin) {
            if ($studentTurmaId === null || (int)$material['turma_id'] !== $studentTurmaId) {
                if (!headers_sent()) {
                    http_response_code(403);
                }
                echo "Acesso negado. Você não possui autorização para baixar materiais desta turma.";
                if ($terminate) {
                    exit;
                }
                return 403;
            }

            if ((int)$material['ativo'] !== 1) {
                if (!headers_sent()) {
                    http_response_code(403);
                }
                echo "Este material didático está temporariamente indisponível.";
                if ($terminate) {
                    exit;
                }
                return 403;
            }
        }

        if (empty($material['caminho_arquivo'])) {
            if (!empty($material['url_externa'])) {
                if (!headers_sent()) {
                    header("Location: {$material['url_externa']}");
                }
                if ($terminate) {
                    exit;
                }
                return 302;
            }
            if (!headers_sent()) {
                http_response_code(404);
            }
            echo "Arquivo não associado a este material.";
            if ($terminate) {
                exit;
            }
            return 404;
        }

        $absPath = $this->resolveFilePath((string)$material['caminho_arquivo']);
        if (!$absPath || !file_exists($absPath)) {
            if (!headers_sent()) {
                http_response_code(404);
            }
            echo "Arquivo físico não encontrado no servidor de arquivos protegidos.";
            if ($terminate) {
                exit;
            }
            return 404;
        }

        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        $mimeType = self::ALLOWED_EXTENSIONS[$ext] ?? 'application/octet-stream';
        $fileSize = filesize($absPath);
        $fileName = basename($absPath);

        if ($terminate) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        if (!headers_sent()) {
            http_response_code(200);
            header("Content-Type: {$mimeType}");
            header("Content-Length: {$fileSize}");
            header("Content-Disposition: attachment; filename=\"{$fileName}\"");
            header("X-Content-Type-Options: nosniff");
            header("Cache-Control: private, no-transform, no-cache, must-revalidate");
            header("Pragma: no-cache");
            header("Expires: 0");
        }

        readfile($absPath);
        if ($terminate) {
            exit;
        }
        return 200;
    }

    /**
     * Enriquecimento auxiliar de metadados para apresentação de materiais.
     */
    private function enrichMaterialData(array $row): array
    {
        $ext = !empty($row['caminho_arquivo']) ? strtolower(pathinfo($row['caminho_arquivo'], PATHINFO_EXTENSION)) : '';
        $tamanhoBytes = (int)($row['tamanho_bytes'] ?? 0);

        $tamanhoFormatado = '';
        if ($tamanhoBytes >= 1048576) {
            $tamanhoFormatado = number_format($tamanhoBytes / 1048576, 1, ',', '') . ' MB';
        } elseif ($tamanhoBytes >= 1024) {
            $tamanhoFormatado = number_format($tamanhoBytes / 1024, 0, ',', '') . ' KB';
        } elseif ($tamanhoBytes > 0) {
            $tamanhoFormatado = $tamanhoBytes . ' B';
        }

        $iconeTipo = match ($row['tipo']) {
            'apostila'  => ($ext === 'pdf' ? 'pdf' : 'doc'),
            'exercicio' => 'xlsx',
            'slide'     => 'slide',
            'video'     => 'video',
            'formulario'=> 'form',
            'outro'     => 'outro',
            default     => 'link',
        };

        $embedInfo = $this->detectEmbedType($row['url_externa'] ?? null);

        return array_merge($row, [
            'extensao'          => $ext,
            'icone_tipo'        => $iconeTipo,
            'tamanho_formatado' => $tamanhoFormatado,
            'is_arquivo'        => !empty($row['caminho_arquivo']),
            'embed_info'        => $embedInfo,
        ]);
    }
}
