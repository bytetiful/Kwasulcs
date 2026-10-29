<?php

/** CLI-only synthetic fixture controller for Telegram Settings browser QA. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.save_path', sys_get_temp_dir());
require_once dirname(__DIR__) . '/includes/telegram/settings.php';
require_once dirname(__DIR__) . '/includes/privacy_policy.php';
if (DB_HOST !== '127.0.0.1' || DB_NAME !== 'kwasulcs_lcs') throw new RuntimeException('Local database required.');

$action = $argv[1] ?? '';
$pdo = db();

if ($action === 'setup') {
    $sessionDir = (string)($argv[2] ?? '');
    $sessionId = preg_replace('/[^a-zA-Z0-9]/', '', (string)($argv[3] ?? ''));
    if ($sessionDir === '' || $sessionId === '') throw new InvalidArgumentException('Fixture session target required.');
    $tag = 'tg4browser-' . bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO users(full_name,matric_no,email,password,role) VALUES (?,?,?,'not-a-login-hash','student')")
        ->execute(['Telegram Browser QA', $tag, $tag . '@example.invalid']);
    $userId = (int)$pdo->lastInsertId();
    privacy_policy_record_acknowledgement($userId);
    file_put_contents($sessionDir . '/sess_' . $sessionId, 'user_id|i:' . $userId . ';role|s:7:"student";');
    echo json_encode(compact('userId', 'tag'), JSON_THROW_ON_ERROR);
    exit;
}

$userId = (int)($argv[2] ?? 0);
if ($userId < 1) throw new InvalidArgumentException('Fixture user required.');

if ($action === 'state') {
    $state = (string)($argv[3] ?? '');
    $connection = telegram_connection_by_user($userId);
    if ($state === 'not_connected') {
        if ($connection) $pdo->prepare('DELETE FROM telegram_connections WHERE id=?')->execute([$connection['id']]);
        exit;
    }
    if (!$connection) {
        $identity = '70000000' . str_pad((string)($userId % 1000000000), 9, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO telegram_connections
            (user_id,telegram_user_id,telegram_chat_id,chat_type,display_name,username,enabled,consent_status,consented_at,connected_at)
            VALUES (?,?,?,'private','Telegram Browser QA','telegram_browser_qa',1,'consented',NOW(),NOW())")
            ->execute([$userId, $identity, $identity]);
        $connection = telegram_connection_by_user($userId);
        telegram_initialize_preferences((int)$connection['id']);
    }
    if ($state === 'connected') {
        $pdo->prepare("UPDATE telegram_connections SET enabled=1,consent_status='consented',consented_at=NOW(),connected_at=NOW(),disconnected_at=NULL,blocked_at=NULL WHERE id=?")
            ->execute([$connection['id']]);
        $pdo->prepare('UPDATE telegram_notification_preferences SET enabled=1 WHERE connection_id=?')
            ->execute([$connection['id']]);
    } elseif ($state === 'disabled') {
        $pdo->prepare("UPDATE telegram_connections SET enabled=0,consent_status='consented',consented_at=NOW(),connected_at=NOW(),disconnected_at=NULL,blocked_at=NULL WHERE id=?")
            ->execute([$connection['id']]);
    } elseif ($state === 'blocked') {
        $pdo->prepare("UPDATE telegram_connections SET enabled=0,consent_status='consented',consented_at=NOW(),connected_at=NOW(),disconnected_at=NULL,blocked_at=NOW() WHERE id=?")
            ->execute([$connection['id']]);
    } elseif ($state === 'disconnected') {
        $pdo->prepare("UPDATE telegram_connections SET enabled=0,consent_status='revoked',disconnected_at=NOW(),blocked_at=NULL WHERE id=?")
            ->execute([$connection['id']]);
    } else {
        throw new InvalidArgumentException('Unknown fixture state.');
    }
    exit;
}

if ($action === 'inspect') {
    $connection = telegram_connection_by_user($userId);
    echo json_encode([
        'state' => telegram_settings_state($userId, ['enabled' => true, 'bot_username' => 'KwasuLcsBot'])['state'],
        'enabled' => (bool)($connection['enabled'] ?? false),
        'preferences' => telegram_settings_preferences((int)($connection['id'] ?? 0)),
        'active_tokens' => (int)$pdo->query('SELECT COUNT(*) FROM telegram_link_tokens WHERE user_id=' . $userId
            . ' AND consumed_at IS NULL AND invalidated_at IS NULL')->fetchColumn(),
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ($action === 'cleanup') {
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
    exit;
}

throw new InvalidArgumentException('Unknown fixture action.');
