<?php

declare(strict_types=1);

/**
 * Return the learner's newest unexpired secure attempt, if one is still being
 * written. This guard deliberately reads the frozen attempt settings rather
 * than the editable quiz settings so an in-progress attempt keeps its rules.
 */
function secure_quiz_active_attempt(array $user): ?array
{
    static $cache = [];
    $userId = (int) ($user['id'] ?? 0);
    if ($userId < 1) {
        return null;
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    try {
        $stmt = db()->prepare(
            "SELECT id, settings_snapshot_json, expires_at
             FROM online_quiz_attempts
             WHERE student_id = ? AND status = 'writing' AND expires_at > NOW()
             ORDER BY started_at DESC, id DESC"
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $attempt) {
            $settings = json_decode((string) $attempt['settings_snapshot_json'], true);
            if (is_array($settings) && !empty($settings['secure'])) {
                return $cache[$userId] = $attempt;
            }
        }
    } catch (Throwable $e) {
        // The guard must not break installations where the optional quiz
        // migration has not been run yet. The quiz feature itself will report
        // that missing setup on its own pages.
        error_log('Secure quiz guard unavailable: ' . $e->getMessage());
    }

    return $cache[$userId] = null;
}

/**
 * Keep an active secure attempt inside its runner. UI controls are removed in
 * the secure shell, while this request guard closes direct URL/bookmark routes.
 */
function secure_quiz_enforce_route(array $user): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $attempt = secure_quiz_active_attempt($user);
    if (!$attempt) {
        return;
    }

    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH);
    $route = basename($path);
    if ($route === 'online_quiz_api.php') {
        return;
    }
    if ($route === 'online_quiz_take.php' && (int) ($_GET['id'] ?? 0) === (int) $attempt['id']) {
        return;
    }

    try {
        $requestKey = 'nav_' . bin2hex(random_bytes(12));
        $metadata = json_encode([
            'observed_action' => 'internal_navigation_blocked',
            'target' => $route,
            'classification' => 'context',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $stmt = db()->prepare(
            "INSERT INTO online_quiz_integrity_events
                (attempt_id, request_key, event_type, is_violation, server_at, metadata_json)
             VALUES (?, ?, 'focus_exit', 0, NOW(), ?)"
        );
        $stmt->execute([(int) $attempt['id'], $requestKey, $metadata]);
    } catch (Throwable $e) {
        error_log('Secure quiz navigation audit unavailable: ' . $e->getMessage());
    }

    $_SESSION['secure_quiz_navigation_blocked'] = true;
    header('Cache-Control: no-store');
    header('Location: online_quiz_take.php?id=' . (int) $attempt['id'] . '&entry=resume', true, 303);
    exit();
}
