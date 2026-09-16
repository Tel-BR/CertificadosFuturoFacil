<?php
/**
 * Script de Carga Inicial do Operador Institucional (Admin Seed)
 * Cria ou atualiza o operador administrativo único com senha bcrypt.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/Database.php';

use FuturoFacil\Config\Database;

$options = getopt('', ['username:', 'password:', 'nome:', 'email:']);

$username = $options['username'] ?? getenv('ADMIN_USERNAME') ?: 'admin';
$password = $options['password'] ?? getenv('ADMIN_PASSWORD') ?: 'Admin@FuturoFacil2026';
$nome = $options['nome'] ?? getenv('ADMIN_NOME') ?: 'Instrutor Futuro Fácil';
$email = $options['email'] ?? getenv('ADMIN_EMAIL') ?: 'contato@futurofacil.com.br';

$passwordHash = password_hash($password, PASSWORD_BCRYPT);

try {
    $pdo = Database::getConnection();

    // Verifica se usuário já existe
    $stmt = $pdo->prepare("SELECT id FROM usuarios_admin WHERE username = :username LIMIT 1");
    $stmt->execute(['username' => $username]);
    $existing = $stmt->fetch();

    if ($existing) {
        $updateStmt = $pdo->prepare("
            UPDATE usuarios_admin 
            SET password_hash = :password_hash,
                nome = :nome,
                email = :email,
                updated_at = datetime('now')
            WHERE id = :id
        ");
        // Ajusta para compatibilidade MySQL / SQLite
        try {
            $updateStmt->execute([
                'password_hash' => $passwordHash,
                'nome'          => $nome,
                'email'         => $email,
                'id'            => $existing['id'],
            ]);
        } catch (PDOException $e) {
            // Em caso de MariaDB onde datetime('now') gera erro de sintaxe, usa CURRENT_TIMESTAMP
            $updateStmtMysql = $pdo->prepare("
                UPDATE usuarios_admin 
                SET password_hash = :password_hash,
                    nome = :nome,
                    email = :email,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $updateStmtMysql->execute([
                'password_hash' => $passwordHash,
                'nome'          => $nome,
                'email'         => $email,
                'id'            => $existing['id'],
            ]);
        }
        echo "[OK] Operador administrativo '{$username}' atualizado com sucesso!\n";
    } else {
        $insertStmt = $pdo->prepare("
            INSERT INTO usuarios_admin (username, password_hash, nome, email)
            VALUES (:username, :password_hash, :nome, :email)
        ");
        $insertStmt->execute([
            'username'      => $username,
            'password_hash' => $passwordHash,
            'nome'          => $nome,
            'email'         => $email,
        ]);
        echo "[OK] Operador administrativo '{$username}' criado com sucesso!\n";
    }

} catch (Exception $e) {
    echo "[ERRO] Falha ao semear operador administrativo: " . $e->getMessage() . "\n";
    exit(1);
}
