<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

function privacy_policy_version(): string
{
    if (defined('LCS_PRIVACY_POLICY_VERSION')) {
        return (string) LCS_PRIVACY_POLICY_VERSION;
    }

    return trim((string) env('LCS_PRIVACY_POLICY_VERSION', '2026-09')) ?: '2026-09';
}

function privacy_policy_url(): string
{
    return 'privacy-policy';
}

function privacy_policy_has_acknowledged(int $userId, ?string $policyVersion = null): bool
{
    if ($userId < 1) {
        return false;
    }

    $policyVersion = $policyVersion ?? privacy_policy_version();
    try {
        $stmt = db()->prepare(
            'SELECT 1 FROM privacy_policy_acknowledgements WHERE user_id = ? AND policy_version = ? LIMIT 1'
        );
        $stmt->execute([$userId, $policyVersion]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Privacy acknowledgement lookup failed: ' . $e->getCode());
        return false;
    }
}

function privacy_policy_record_acknowledgement(int $userId): void
{
    if ($userId < 1) {
        throw new InvalidArgumentException('A signed-in user is required.');
    }

    $stmt = db()->prepare(
        'INSERT INTO privacy_policy_acknowledgements '
        . '(user_id, policy_version, acknowledged_at, created_at) '
        . 'VALUES (?, ?, NOW(), NOW()) '
        . 'ON DUPLICATE KEY UPDATE id = id'
    );
    $stmt->execute([$userId, privacy_policy_version()]);
}

function privacy_policy_acknowledgement_csrf_token(): string
{
    if (empty($_SESSION['privacy_policy_ack_csrf'])) {
        $_SESSION['privacy_policy_ack_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['privacy_policy_ack_csrf'];
}

function privacy_policy_verify_acknowledgement_csrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['privacy_policy_ack_csrf'])
        && hash_equals((string) $_SESSION['privacy_policy_ack_csrf'], $token);
}
