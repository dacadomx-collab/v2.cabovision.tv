<?php

declare(strict_types=1);

// =============================================================================
// api/candidates_update.php — Edición de candidato existente, incluye
// reemplazo opcional de foto (mismo criterio que articles_update.php: si no
// se adjunta archivo nuevo, la foto existente no se toca).
// Endpoint: POST /api/candidates_update.php
// Auth: Bearer JWT + Rol (Admin | Autor | Editor)
// =============================================================================

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/input_sanitizer.php';

const CANDIDATE_TENANT_ID = 1002;
const MAX_PHOTO_BYTES     = 5 * 1024 * 1024;
const ALLOWED_PHOTO_MIME  = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

requireRole(['Admin', 'Autor', 'Editor'], $authPayload);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Método no permitido.', 405);
}

$candidateId = sanitize_int($_POST['id'] ?? null, 0);
if ($candidateId <= 0) {
    send_error('Falta el identificador del candidato.', 422);
}

$name        = sanitize_string((string) ($_POST['name'] ?? ''), 255);
$age         = sanitize_int($_POST['age'] ?? null, 0);
$gender      = sanitize_int($_POST['gender'] ?? null, 0);
$description = sanitize_string((string) ($_POST['description'] ?? ''), 2000);
$facebook    = sanitize_string((string) ($_POST['facebook'] ?? ''), 255);
$twitter     = sanitize_string((string) ($_POST['twitter'] ?? ''), 255);
$youtube     = sanitize_string((string) ($_POST['youtube'] ?? ''), 255);
$instagram   = sanitize_string((string) ($_POST['instagram'] ?? ''), 255);
$webPage     = sanitize_string((string) ($_POST['web_page'] ?? ''), 255);
$entrevista  = sanitize_string((string) ($_POST['entrevista'] ?? ''), 255);
$parties     = sanitize_string((string) ($_POST['parties'] ?? ''), 255);
$position    = sanitize_int($_POST['position'] ?? null, 0);
$district    = sanitize_int($_POST['district'] ?? null, 0);
$houseAddr   = sanitize_string((string) ($_POST['house_address'] ?? ''), 255);
$publicPhone = sanitize_string((string) ($_POST['public_phone'] ?? ''), 255);
$email       = sanitize_email((string) ($_POST['email'] ?? ''));

if ($name === '') {
    send_error('El nombre es requerido.', 422);
}
if ($parties === '') {
    send_error('El partido/agrupación es requerido.', 422);
}

/** Idéntica a candidates_create.php — opcional, retorna null si no se adjuntó archivo. */
function handlePhotoUpload(): ?string
{
    if (empty($_FILES['photo']['tmp_name']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        send_error('Error al recibir la foto.', 422);
    }
    if ($_FILES['photo']['size'] > MAX_PHOTO_BYTES) {
        send_error('La foto excede el límite de 5 MB.', 422);
    }

    $tmpPath = $_FILES['photo']['tmp_name'];
    if (!is_uploaded_file($tmpPath)) {
        send_error('Archivo inválido.', 422);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $tmpPath) ?: '';
    finfo_close($finfo);

    if (!isset(ALLOWED_PHOTO_MIME[$mime])) {
        send_error('Formato de imagen no permitido. Usa JPEG, PNG o WebP.', 422);
    }
    if (@getimagesize($tmpPath) === false) {
        send_error('El archivo no es una imagen válida o está corrupto.', 422);
    }

    $extension    = ALLOWED_PHOTO_MIME[$mime];
    $randomName   = bin2hex(random_bytes(16)) . '.' . $extension;
    $today        = new DateTimeImmutable();
    $relativePath = sprintf(
        '%d/%s/%s/%s/%s',
        CANDIDATE_TENANT_ID,
        $today->format('Y'),
        $today->format('m'),
        $today->format('d'),
        $randomName
    );
    $absolutePath = dirname(__DIR__) . '/' . $relativePath;
    $absoluteDir  = dirname($absolutePath);

    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
        error_log('[' . date('Y-m-d H:i:s') . '] [candidates_update] No se pudo crear el directorio: ' . $absoluteDir);
        send_error('Error interno al procesar la foto.', 500);
    }
    if (!move_uploaded_file($tmpPath, $absolutePath)) {
        error_log('[' . date('Y-m-d H:i:s') . '] [candidates_update] move_uploaded_file falló: ' . $absolutePath);
        send_error('Error interno al guardar la foto.', 500);
    }

    return $relativePath;
}

try {
    $database = new Database();
    $pdo      = $database->getConnection();

    $existing = $pdo->prepare('SELECT `id`, `photo` FROM `candidates` WHERE `id` = :id LIMIT 1');
    $existing->execute([':id' => $candidateId]);
    $row = $existing->fetch(\PDO::FETCH_ASSOC);
    if ($row === false) {
        send_error('El candidato indicado no existe.', 404);
    }

    $newPhoto = handlePhotoUpload();
    $photo    = $newPhoto ?? $row['photo'];

    $update = $pdo->prepare(
        'UPDATE `candidates` SET
            `name` = :name, `age` = :age, `gender` = :gender, `description` = :description,
            `facebook` = :facebook, `twitter` = :twitter, `youtube` = :youtube, `instagram` = :instagram,
            `web_page` = :web_page, `entrevista` = :entrevista, `photo` = :photo, `parties` = :parties,
            `position` = :position, `district` = :district, `house_address` = :house_address,
            `public_phone` = :public_phone, `email` = :email, `updated_at` = NOW()
         WHERE `id` = :id'
    );
    $update->execute([
        ':name'          => $name,
        ':age'           => $age > 0 ? $age : null,
        ':gender'        => $gender,
        ':description'   => $description !== '' ? $description : null,
        ':facebook'      => $facebook !== '' ? $facebook : null,
        ':twitter'       => $twitter !== '' ? $twitter : null,
        ':youtube'       => $youtube !== '' ? $youtube : null,
        ':instagram'     => $instagram !== '' ? $instagram : null,
        ':web_page'      => $webPage !== '' ? $webPage : null,
        ':entrevista'    => $entrevista !== '' ? $entrevista : null,
        ':photo'         => $photo,
        ':parties'       => $parties,
        ':position'      => $position,
        ':district'      => $district > 0 ? $district : null,
        ':house_address' => $houseAddr !== '' ? $houseAddr : null,
        ':public_phone'  => $publicPhone !== '' ? $publicPhone : null,
        ':email'         => $email !== '' ? $email : null,
        ':id'            => $candidateId,
    ]);

    send_success('Candidato actualizado.', ['id' => $candidateId, 'photo' => $photo]);
} catch (\PDOException $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] [candidates_update] ' . $e->getMessage());
    send_error('Error interno al actualizar el candidato.', 500);
}
