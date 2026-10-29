<?php

declare(strict_types=1);

const LCS_NOTIFICATION_CATEGORY_CALENDAR_LECTURE = 'calendar_lecture';
const LCS_NOTIFICATION_CATEGORY_ASSIGNMENT_TEST = 'assignment_test';
const LCS_NOTIFICATION_CATEGORY_ONLINE_QUIZ_TEST = 'online_quiz_test';

/**
 * Stable logical notification names already emitted by the application.
 * This is a vocabulary layer, not a replacement for domain-specific rules.
 */
function lcs_notification_type_definitions(): array
{
    return [
        'calendar_reminder' => ['category' => LCS_NOTIFICATION_CATEGORY_CALENDAR_LECTURE, 'domain' => 'calendar'],
        'lecture_reminder' => ['category' => LCS_NOTIFICATION_CATEGORY_CALENDAR_LECTURE, 'domain' => 'calendar'],
        'assignment_test_published' => ['category' => LCS_NOTIFICATION_CATEGORY_ASSIGNMENT_TEST, 'domain' => 'assignment_test'],
        'assignment_test_reminder' => ['category' => LCS_NOTIFICATION_CATEGORY_ASSIGNMENT_TEST, 'domain' => 'assignment_test'],
        'assignment_test_closed' => ['category' => LCS_NOTIFICATION_CATEGORY_ASSIGNMENT_TEST, 'domain' => 'assignment_test'],
        'assignment_test_reopened' => ['category' => LCS_NOTIFICATION_CATEGORY_ASSIGNMENT_TEST, 'domain' => 'assignment_test'],
        'assignment_test_returned' => ['category' => LCS_NOTIFICATION_CATEGORY_ASSIGNMENT_TEST, 'domain' => 'assignment_test'],
        'assignment_test_result_available' => ['category' => LCS_NOTIFICATION_CATEGORY_ASSIGNMENT_TEST, 'domain' => 'assignment_test'],
        'assignment_test_submission_received' => ['category' => LCS_NOTIFICATION_CATEGORY_ASSIGNMENT_TEST, 'domain' => 'assignment_test'],
        'online_quiz_published' => ['category' => LCS_NOTIFICATION_CATEGORY_ONLINE_QUIZ_TEST, 'domain' => 'online_quiz'],
        'online_quiz_reminder' => ['category' => LCS_NOTIFICATION_CATEGORY_ONLINE_QUIZ_TEST, 'domain' => 'online_quiz'],
        'online_quiz_result_available' => ['category' => LCS_NOTIFICATION_CATEGORY_ONLINE_QUIZ_TEST, 'domain' => 'online_quiz'],
    ];
}

function lcs_notification_type_definition(string $notificationType): ?array
{
    return lcs_notification_type_definitions()[$notificationType] ?? null;
}

function lcs_notification_type_is_supported(string $notificationType): bool
{
    return lcs_notification_type_definition($notificationType) !== null;
}

function lcs_notification_category_map(): array
{
    $categories = [
        LCS_NOTIFICATION_CATEGORY_CALENDAR_LECTURE => [],
        LCS_NOTIFICATION_CATEGORY_ASSIGNMENT_TEST => [],
        LCS_NOTIFICATION_CATEGORY_ONLINE_QUIZ_TEST => [],
    ];
    foreach (lcs_notification_type_definitions() as $notificationType => $definition) {
        $categories[$definition['category']][] = $notificationType;
    }
    return $categories;
}

function lcs_notification_category(string $notificationType): ?string
{
    return lcs_notification_type_definition($notificationType)['category'] ?? null;
}
