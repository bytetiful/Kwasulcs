<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/routes.php';

const LCS_SIGNED_LINK_TTL_SECONDS = 7776000; // 90 days; reminder horizons are shorter.

function lcs_signed_link_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function lcs_signed_link_base64url_decode(string $value): ?string
{
    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) return null;
    $padding = (4 - strlen($value) % 4) % 4;
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
    return $decoded === false ? null : $decoded;
}

function lcs_signed_link_signing_key(?array $config = null): string
{
    return trim((string)($config['link_signing_key'] ?? env('LCS_LINK_SIGNING_KEY', '')));
}

/** Convert an application-owned action URL into a small, typed target. */
function lcs_signed_link_target(string $actionUrl, string $sourceType = '', int $sourceId = 0): ?array
{
    $actionUrl = trim($actionUrl);
    if ($actionUrl === '' || str_contains($actionUrl, "\n") || str_contains($actionUrl, "\r")) return null;
    $parts = parse_url($actionUrl);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['fragment'])) return null;
    $path = ltrim((string)($parts['path'] ?? ''), '/');
    if ($path === '' || str_contains($path, '/') || str_contains($path, '\\')) return null;
    $path = lcs_navigation_url($path);
    parse_str((string)($parts['query'] ?? ''), $query);
    $positiveId = static function (mixed $value): int {
        $raw = is_scalar($value) ? (string)$value : '';
        return preg_match('/^[1-9][0-9]*$/', $raw) ? (int)$raw : 0;
    };

    return match ($path) {
        'assignment_test' => ($id = $positiveId($query['id'] ?? null)) > 0 ? ['kind' => 'assignment_test', 'id' => $id] : null,
        'online_quiz' => ($id = $positiveId($query['id'] ?? null)) > 0 ? ['kind' => 'online_quiz', 'id' => $id] : null,
        'online_quiz_result' => ($id = $positiveId($query['id'] ?? null)) > 0 ? ['kind' => 'online_quiz_result', 'id' => $id] : null,
        'course' => ($id = $positiveId($query['id'] ?? null)) > 0 ? ['kind' => 'course', 'id' => $id] : null,
        'assignment_tests' => ['kind' => 'assessment_list'],
        'dashboard' => ['kind' => 'dashboard'],
        'calendar' => $sourceType === 'calendar' && $sourceId > 0
            ? ['kind' => 'calendar_event', 'id' => $sourceId,
                'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($query['date'] ?? '')) ? (string)$query['date'] : null]
            : null,
        default => null,
    };
}

function lcs_signed_link_internal_path(
    string $actionUrl,
    int $userId,
    string $sourceType = '',
    int $sourceId = 0,
    ?array $config = null,
    ?int $expiresAt = null
): ?string {
    $key = lcs_signed_link_signing_key($config);
    $target = lcs_signed_link_target($actionUrl, $sourceType, $sourceId);
    if ($userId < 1 || strlen($key) < 32 || $target === null) return null;
    $payload = array_merge(['v' => 1, 'uid' => $userId, 'exp' => $expiresAt ?? (time() + LCS_SIGNED_LINK_TTL_SECONDS)], $target);
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $encoded = lcs_signed_link_base64url_encode($json);
    $signature = lcs_signed_link_base64url_encode(hash_hmac('sha256', $encoded, $key, true));
    return 'go.php?ref=' . rawurlencode($encoded . '.' . $signature);
}

function lcs_signed_link_resolve_reference(string $reference, ?array $config = null, ?int $now = null): ?array
{
    $key = lcs_signed_link_signing_key($config);
    if (strlen($key) < 32 || strlen($reference) > 1600 || substr_count($reference, '.') !== 1) return null;
    [$encoded, $provided] = explode('.', $reference, 2);
    $expected = lcs_signed_link_base64url_encode(hash_hmac('sha256', $encoded, $key, true));
    if (!hash_equals($expected, $provided)) return null;
    $decoded = lcs_signed_link_base64url_decode($encoded);
    if ($decoded === null) return null;
    try { $payload = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR); }
    catch (JsonException) { return null; }
    if (!is_array($payload) || ($payload['v'] ?? null) !== 1 || (int)($payload['uid'] ?? 0) < 1
        || (int)($payload['exp'] ?? 0) < ($now ?? time()) || !is_string($payload['kind'] ?? null)) return null;
    return $payload;
}

/** Resolve only after authentication and repeat the destination's ACL check. */
function lcs_signed_link_authorized_destination(array $payload, array $user): ?string
{
    if ((int)($payload['uid'] ?? 0) !== (int)($user['id'] ?? 0)) return null;
    $id = (int)($payload['id'] ?? 0);
    switch ((string)($payload['kind'] ?? '')) {
        case 'assignment_test':
            require_once dirname(__DIR__) . '/assignment_test.php';
            $item = assignment_test_find($id);
            return $item && assignment_test_can_view($item, $user) ? 'assignment_test?id=' . $id : null;
        case 'online_quiz':
            require_once dirname(__DIR__) . '/online_quiz.php';
            $quiz = oq_find($id);
            return $quiz && oq_visible($quiz, $user) ? 'online_quiz?id=' . $id : null;
        case 'online_quiz_result':
            require_once dirname(__DIR__) . '/online_quiz.php';
            try { oq_result($id, $user); }
            catch (Throwable) { return null; }
            return 'online_quiz_result?id=' . $id;
        case 'course':
            return $id > 0 && course_access_authorized($id, $user) ? 'course?id=' . $id : null;
        case 'assessment_list':
            return 'assignment_tests';
        case 'dashboard':
            return 'dashboard';
        case 'calendar_event':
            require_once dirname(__DIR__) . '/calendar.php';
            $stmt = db()->prepare('SELECT start_datetime,end_datetime FROM calendar_events WHERE id=?');
            $stmt->execute([$id]); $event = $stmt->fetch();
            if (!$event) return null;
            $from = strtotime((string)$event['start_datetime']) - 86400;
            $until = strtotime((string)$event['end_datetime']) + 86400;
            $visible = array_filter(calendar_events_for_window($user, $from, $until), fn(array $row): bool => (int)$row['id'] === $id);
            if (!$visible) return null;
            $date = is_string($payload['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['date'])
                ? $payload['date'] : date('Y-m-d', strtotime((string)$event['start_datetime']));
            return 'calendar?date=' . rawurlencode($date) . '&view=day';
    }
    return null;
}
