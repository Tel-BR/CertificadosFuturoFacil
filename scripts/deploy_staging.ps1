<#
.SYNOPSIS
    Deploy Automatizado Contínuo para Staging Hostinger (ADR-0005)
    Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil

.DESCRIPTION
    1. Executa suítes de teste de segurança e capacidade (Ticket 07b e 08)
    2. Lê credenciais e DEPLOY_TOKEN do arquivo local SECRETS
    3. Empacota pastas src/, public/ (mapeada para public_html/) e database/ em ZIP atômico
    4. Envia para o endpoint HTTPS https://staging.futurofacil.com.br/deploy.php
    5. Executa migrações SQL opcionais via PDO no servidor remoto
#>

[CmdletBinding()]
param(
    [string]$SqlMigration = "",
    [switch]$SkipTests = $false
)

$ErrorActionPreference = "Stop"

# 1. Carregar Segredos Locais
$secretsPath = Join-Path $PSScriptRoot "..\SECRETS"
if (-not (Test-Path $secretsPath)) {
    Write-Error "Arquivo SECRETS não encontrado na raiz do projeto ($secretsPath)."
    exit 1
}

$secrets = @{}
Get-Content $secretsPath | ForEach-Object {
    $line = $_.Trim()
    if ($line -and -not $line.StartsWith("#") -and $line.Contains("=")) {
        $parts = $line.Split("=", 2)
        $secrets[$parts[0].Trim()] = $parts[1].Trim()
    }
}

$deployToken = $secrets["DEPLOY_TOKEN"]
$stagingUrl = $secrets["STAGING_URL"]

if (-not $deployToken) {
    Write-Error "DEPLOY_TOKEN não encontrado no arquivo SECRETS."
    exit 1
}

if (-not $stagingUrl) {
    $stagingUrl = "https://staging.futurofacil.com.br"
}

# 2. Executar testes de segurança e regressão
if (-not $SkipTests) {
    Write-Host "Verificando suíte de segurança e testes de regressão..." -ForegroundColor Cyan
    $testResult = & php "$PSScriptRoot\..\tests\test_ticket_07b_security.php"
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Suíte de segurança (Ticket 07b) falhou! Deploy abortado."
        exit 1
    }
    $testCap = & php "$PSScriptRoot\..\tests\test_ticket_08_advanced_capacity.php"
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Suíte de capacidade (Ticket 08) falhou! Deploy abortado."
        exit 1
    }
    $testSecRef = & php "$PSScriptRoot\..\tests\test_ticket_07c_security_refinements.php"
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Suíte de refinamentos de segurança (Ticket 07c) falhou! Deploy abortado."
        exit 1
    }
    Write-Host "Todas as suítes de testes 100% aprovadas!" -ForegroundColor Green
}

# 3. Empacotar arquivos
Write-Host "Empacotando arquivos para staging..." -ForegroundColor Cyan
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$scratchDir = Join-Path $repoRoot ".scratch"
if (-not (Test-Path $scratchDir)) {
    New-Item -ItemType Directory -Path $scratchDir | Out-Null
}
$zipPath = Join-Path $scratchDir "staging_payload.zip"
if (Test-Path $zipPath) {
    Remove-Item $zipPath -Force
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zipArchive = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)

try {
    # Mapeia src/ -> src/
    $srcDir = Join-Path $repoRoot "src"
    Get-ChildItem -Path $srcDir -Recurse -File | ForEach-Object {
        $rel = $_.FullName.Substring($srcDir.Length + 1).Replace("\", "/")
        if (-not ($rel -match "credentials\.local\.php" -or $rel -match "\.log$")) {
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zipArchive, $_.FullName, "src/$rel") | Out-Null
        }
    }

    # Mapeia public/ -> public_html/
    $publicDir = Join-Path $repoRoot "public"
    Get-ChildItem -Path $publicDir -Recurse -File | ForEach-Object {
        $rel = $_.FullName.Substring($publicDir.Length + 1).Replace("\", "/")
        if (-not ($rel -match "credentials\.local\.php" -or $rel -match "\.log$")) {
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zipArchive, $_.FullName, "public_html/$rel") | Out-Null
        }
    }

    # Mapeia database/ -> database/
    $dbDir = Join-Path $repoRoot "database"
    if (Test-Path $dbDir) {
        Get-ChildItem -Path $dbDir -Recurse -File | ForEach-Object {
            $rel = $_.FullName.Substring($dbDir.Length + 1).Replace("\", "/")
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zipArchive, $_.FullName, "database/$rel") | Out-Null
        }
    }
} finally {
    $zipArchive.Dispose()
}

$zipSizeKb = [math]::Round((Get-Item $zipPath).Length / 1024, 1)
Write-Host "Pacote gerado com sucesso: $zipPath ($zipSizeKb KB)" -ForegroundColor Green

# 4. Envio via HTTPS POST para o Deployer
$endpoint = "$stagingUrl/deploy.php"
Write-Host "Enviando pacote para $endpoint via HTTPS..." -ForegroundColor Cyan

$curlArgs = @(
    "-s", "-S",
    "-X", "POST",
    "-H", "Authorization: Bearer $deployToken",
    "-F", "package=@$zipPath"
)

if ($SqlMigration -and (Test-Path $SqlMigration)) {
    Write-Host "Anexando delta de migração SQL: $SqlMigration" -ForegroundColor Yellow
    $curlArgs += @("-F", "migration_sql=@$SqlMigration")
}

$response = & curl.exe @curlArgs $endpoint
Write-Host "Resposta do Servidor:" -ForegroundColor Green
try {
    $json = $response | ConvertFrom-Json
    $json | Format-List
} catch {
    Write-Output $response
}
