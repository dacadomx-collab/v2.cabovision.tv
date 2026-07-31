<?php

declare(strict_types=1);

// =============================================================================
// ⚠️ DEPRECADO (2026-07-17) — NO EJECUTAR. Referencia columnas (`username`,
// `password_hash`) y una tabla (`user_roles`) que no existen en el schema
// real de `cabovision_local` (que tiene `users.password` y `model_has_roles`
// polimórfico). cabovision_local ya tiene 17 cuentas reales con rol Admin —
// no se necesita sembrar un super_admin nuevo. Ver api/auth_login.php y
// knowledge/02_CODEX_Y_SCHEMA_MAESTRO.md para el schema efectivamente en uso.
// =============================================================================
//
// scripts/seed_super_admin.php — Crea el primer usuario super_admin
// Uso: php scripts/seed_super_admin.php <email> <username> <password>
// Ejemplo:
//   php scripts/seed_super_admin.php arquitecto@cabovision.tv arquitecto "Cl4v3-Larga-Segura!"
//
// Password hasheado con password_hash() (bcrypt, cost 10 por defecto de PHP).
// Solo corre en local — rechaza ejecución si APP_ENV=production (usar SSH
// directo + este mismo script en el servidor real, nunca exponerlo como
// endpoint HTTP).
// =============================================================================

require_once __DIR__ . '/../api/conexion.php';

$root = dirname(__DIR__);
$env  = parse_ini_file($root . '/.env', false, INI_SCANNER_RAW) ?: [];

if (($env['APP_ENV'] ?? '') === 'production') {
    fwrite(STDERR, "Error: este script no debe correr con APP_ENV=production vía este flujo interactivo.\n");
    fwrite(STDERR, "Ejecuta manualmente por SSH en el servidor si es realmente necesario.\n");
    exit(1);
}

[$email, $username, $password] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];

if ($email === '' || $username === '' || $password === '') {
    fwrite(STDERR, "Uso: php scripts/seed_super_admin.php <email> <username> <password>\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Error: email inválido.\n");
    exit(1);
}

if (!preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{8,}$/', $password)) {
    fwrite(STDERR, "Error: password debe tener 8+ caracteres, mayúscula, minúscula y dígito.\n");
    exit(1);
}

$database = new Database();
$pdo      = $database->getConnection();

$exists = $pdo->prepare('SELECT id FROM users WHERE email = :email OR username = :username LIMIT 1');
$exists->execute([':email' => $email, ':username' => $username]);

if ($exists->fetch() !== false) {
    fwrite(STDERR, "Error: ya existe un usuario con ese email o username.\n");
    exit(1);
}

$pdo->beginTransaction();

try {
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    $ins = $pdo->prepare(
        'INSERT INTO users (name, email, username, password_hash, status)
         VALUES (:name, :email, :username, :hash, :status)'
    );
    $ins->execute([
        ':name'     => 'Arquitecto',
        ':email'    => $email,
        ':username' => $username,
        ':hash'     => $hash,
        ':status'   => 'activo',
    ]);

    $userId = (int) $pdo->lastInsertId();

    $roleStmt = $pdo->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
    $roleStmt->execute([':name' => 'super_admin']);
    $roleId = $roleStmt->fetchColumn();

    if ($roleId === false) {
        throw new RuntimeException("El rol 'super_admin' no existe. Aplica primero database/schema_v1_auth.sql.");
    }

    $mapStmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:uid, :rid)');
    $mapStmt->execute([':uid' => $userId, ':rid' => $roleId]);

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "✓ Super admin creado: id={$userId}, email={$email}, username={$username}, rol=super_admin\n";
echo "  Prueba de login:\n";
echo "  curl -X POST http://localhost/CaboVision.tv/api/auth_login.php \\\n";
echo "    -H \"Content-Type: application/json\" \\\n";
echo "    -d '{\"email\":\"{$email}\",\"password\":\"<tu_password>\"}'\n";
