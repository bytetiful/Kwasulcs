<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/privacy_policy.php';

if (!isset($user['id']) || privacy_policy_has_acknowledged((int) $user['id'])) {
    return;
}
?>
<div
    class="privacy-ack-overlay"
    id="privacyAcknowledgementModal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="privacyAcknowledgementTitle"
    aria-describedby="privacyAcknowledgementDescription privacyAcknowledgementNote"
    data-endpoint="privacy_policy_acknowledge.php"
    data-csrf="<?php echo h(privacy_policy_acknowledgement_csrf_token()); ?>"
>
    <section class="privacy-ack-dialog" tabindex="-1">
        <div class="privacy-ack-icon" aria-hidden="true">
            <i class="bi bi-shield-check"></i>
        </div>
        <div class="privacy-ack-copy">
            <p class="privacy-ack-eyebrow">Privacy notice</p>
            <h2 id="privacyAcknowledgementTitle">Privacy Policy</h2>
            <p id="privacyAcknowledgementDescription">We’ve published the KWASU LCS Privacy Policy, which explains how information is collected, used, stored and protected while you use the platform.</p>
            <p class="privacy-ack-note" id="privacyAcknowledgementNote">Please review it before continuing.</p>
        </div>
        <p class="privacy-ack-error" id="privacyAcknowledgementError" role="alert" tabindex="-1" hidden></p>
        <div class="privacy-ack-actions">
            <a class="privacy-ack-button privacy-ack-button--secondary" href="<?php echo h(privacy_policy_url()); ?>" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                Read Privacy Policy
            </a>
            <button class="privacy-ack-button privacy-ack-button--primary" type="button" id="privacyAcknowledgementSubmit">
                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                <span>I Have Read and Understand</span>
            </button>
        </div>
    </section>
</div>
