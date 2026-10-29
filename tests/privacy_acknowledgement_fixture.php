<?php

/** CLI-only fixture controller for Privacy Policy acknowledgement QA. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('session.save_path', sys_get_temp_dir());
require_once dirname(__DIR__) . '/includes/privacy_policy.php';
session_write_close();

if (DB_HOST !== '127.0.0.1' || DB_NAME !== 'kwasulcs_lcs') {
    throw new RuntimeException('Local database required.');
}

$action = (string) ($argv[1] ?? '');
$pdo = db();

if ($action === 'setup') {
    $sessionDir = (string) ($argv[2] ?? '');
    $sessionId = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($argv[3] ?? ''));
    if ($sessionDir === '' || $sessionId === '') {
        throw new InvalidArgumentException('Fixture session target required.');
    }

    $tag = 'privacy-ack-' . bin2hex(random_bytes(5));
    $password = 'PrivacyQA!234';
    $insert = $pdo->prepare(
        "INSERT INTO users(full_name, matric_no, email, password, role) VALUES (?, ?, ?, ?, 'student')"
    );
    $insert->execute(['Privacy Acknowledgement QA', $tag . '-a', $tag . '-a@example.invalid', password_hash($password, PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();
    $insert->execute(['Privacy Other User QA', $tag . '-b', $tag . '-b@example.invalid', password_hash($password, PASSWORD_DEFAULT)]);
    $otherUserId = (int) $pdo->lastInsertId();

    file_put_contents($sessionDir . '/sess_' . $sessionId, 'user_id|i:' . $userId . ';role|s:7:"student";');
    echo json_encode([
        'userId' => $userId,
        'otherUserId' => $otherUserId,
        'matric' => $tag . '-a',
        'password' => $password,
        'version' => privacy_policy_version(),
    ], JSON_THROW_ON_ERROR);
    exit;
}

$userId = (int) ($argv[2] ?? 0);
if ($userId < 1) {
    throw new InvalidArgumentException('Fixture user required.');
}

if ($action === 'state') {
    $state = (string) ($argv[3] ?? '');
    $pdo->prepare('DELETE FROM privacy_policy_acknowledgements WHERE user_id = ?')->execute([$userId]);
    if ($state === 'clear') {
        exit;
    }
    $version = $state === 'current' ? privacy_policy_version() : '2025-01';
    $pdo->prepare(
        'INSERT INTO privacy_policy_acknowledgements(user_id, policy_version, acknowledged_at, created_at) VALUES (?, ?, NOW(), NOW())'
    )->execute([$userId, $version]);
    exit;
}

if ($action === 'count') {
    $version = (string) ($argv[3] ?? privacy_policy_version());
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM privacy_policy_acknowledgements WHERE user_id = ? AND policy_version = ?');
    $stmt->execute([$userId, $version]);
    echo (string) $stmt->fetchColumn();
    exit;
}

if ($action === 'cleanup') {
    $otherUserId = (int) ($argv[3] ?? 0);
    $ids = array_values(array_filter([$userId, $otherUserId]));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM privacy_policy_acknowledgements WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM users WHERE id IN ({$placeholders})")->execute($ids);
    exit;
}

throw new InvalidArgumentException('Unknown fixture action.');
