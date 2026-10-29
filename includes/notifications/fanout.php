<?php

declare(strict_types=1);

require_once __DIR__ . '/types.php';

/** Create a neutral description of an already-authorized logical notice. */
function lcs_notification_envelope(
    string $notificationType,
    int $userId,
    array $context,
    string $sourceType,
    int $sourceId,
    string $logicalKey
): ?array {
    $sourceType = trim($sourceType);
    $logicalKey = trim($logicalKey);
    if (!lcs_notification_type_is_supported($notificationType) || $userId < 1 || $sourceType === ''
        || $sourceId < 1 || $logicalKey === '') return null;
    return [
        'notification_type' => $notificationType,
        'category' => lcs_notification_category($notificationType),
        'user_id' => $userId,
        'context' => $context,
        'source_type' => $sourceType,
        'source_id' => $sourceId,
        'logical_key' => $logicalKey,
    ];
}

/**
 * Invoke optional adapters independently so one cannot fail another channel or
 * the authoritative web/email delivery path.
 *
 * @param array<string,callable(array):array> $channelHandlers
 */
function lcs_notification_fanout_optional(array $notification, array $channelHandlers): array
{
    $type = (string)($notification['notification_type'] ?? '');
    if (!lcs_notification_type_is_supported($type)) {
        return ['status' => 'skipped', 'reason' => 'unsupported_notification_type', 'channels' => []];
    }

    $results = [];
    foreach ($channelHandlers as $channel => $handler) {
        $channel = is_string($channel) ? trim($channel) : '';
        if ($channel === '' || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $channel) || !is_callable($handler)) {
            if ($channel !== '') $results[$channel] = ['status' => 'skipped', 'reason' => 'invalid_channel_handler'];
            continue;
        }
        try {
            $result = $handler($notification);
            $results[$channel] = is_array($result) && is_string($result['status'] ?? null)
                ? $result
                : ['status' => 'handled'];
        } catch (Throwable) {
            $results[$channel] = ['status' => 'skipped', 'reason' => 'optional_channel_failed'];
        }
    }

    return ['status' => 'complete', 'channels' => $results];
}

/**
 * Registered optional-channel boundary for already-authorized domain notices.
 * Adapters receive the same neutral envelope and are independently contained.
 */
function lcs_notification_fanout_registered(
    array $notification,
    ?string $nextAttemptAt = null,
    ?array $retiredChannelConfig = null,
    ?array $telegramConfig = null
): array {
    require_once dirname(__DIR__) . '/telegram/notifications.php';

    return lcs_notification_fanout_optional($notification, [
        'telegram' => static function (array $envelope) use ($nextAttemptAt, $telegramConfig): array {
            $result = telegram_fanout_notification(
                (string)$envelope['notification_type'],
                (int)$envelope['user_id'],
                (array)$envelope['context'],
                (string)$envelope['source_type'],
                (int)$envelope['source_id'],
                (string)$envelope['logical_key'],
                $nextAttemptAt,
                $telegramConfig
            );
            return ['status' => (string)($result['status'] ?? 'skipped')];
        },
    ]);
}

function lcs_notification_fanout(
    string $notificationType,
    int $userId,
    array $context,
    string $sourceType,
    int $sourceId,
    string $logicalKey,
    ?string $nextAttemptAt = null,
    ?array $retiredChannelConfig = null,
    ?array $telegramConfig = null
): array {
    $envelope = lcs_notification_envelope($notificationType, $userId, $context, $sourceType, $sourceId, $logicalKey);
    if ($envelope === null) return ['status' => 'skipped', 'reason' => 'invalid_notification', 'channels' => []];
    // Keep the optional-channel argument position for existing callers.
    return lcs_notification_fanout_registered($envelope, $nextAttemptAt, $retiredChannelConfig, $telegramConfig);
}
