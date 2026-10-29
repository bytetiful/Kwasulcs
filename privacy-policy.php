<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$configuredPrivacyEmail = filter_var(LCS_PRIVACY_EMAIL, FILTER_VALIDATE_EMAIL)
    ? LCS_PRIVACY_EMAIL
    : '';
$copyrightYear = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KWASU LCS Privacy Policy</title>
    <meta name="description" content="Privacy Policy for the KWASU Lecture Capturing System (LCS), including information about academic data, notifications, AI-assisted features, WhatsApp communications and user privacy rights.">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/favicon/apple-touch-icon.png">
    <script>(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme:dark)').matches))document.documentElement.setAttribute('data-theme','dark')})();</script>
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/style.css?v=<?php echo asset_version(); ?>">
    <link rel="stylesheet" href="assets/privacy-policy.css?v=<?php echo (int) @filemtime(__DIR__ . '/assets/privacy-policy.css'); ?>">
</head>
<body class="privacy-page">
    <a class="privacy-skip-link" href="#main-content">Skip to main content</a>
    <header class="privacy-header">
        <div class="privacy-header-inner">
            <a class="privacy-brand" href="index.php" aria-label="KWASU LCS home">
                <?php echo brand_logo(); ?>
                <span>KWASU LCS</span>
            </a>
            <a class="privacy-back-link" href="index.php">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                <span>Back to LCS</span>
            </a>
        </div>
    </header>

    <main class="privacy-main" id="main-content" tabindex="-1">
        <header class="privacy-intro">
            <p class="privacy-eyebrow">Privacy and data protection</p>
            <h1>KWASU Lecture Capturing System (LCS) Privacy Policy</h1>
            <p class="privacy-intro-copy">This policy explains how personal and academic information is handled when authorised users access KWASU LCS.</p>
        </header>

        <article class="privacy-document" aria-label="KWASU LCS Privacy Policy">
            <section class="privacy-section" id="introduction">
                <h2><span class="privacy-section-number">1.</span> Introduction</h2>
                <p>The KWASU Lecture Capturing System (LCS) is an academic learning and lecture-content platform developed for use within Kwara State University (KWASU), Malete, Nigeria.</p>
                <p>The platform is designed to support the organisation and delivery of academic activities, including lecture materials, course resources, assessments, academic schedules, communications, notifications and AI-assisted learning features.</p>
                <p>KWASU LCS is recognised by the Faculty of Information and Communication Technology (ICT), Kwara State University, Malete.</p>
                <p>This Privacy Policy explains how personal information is obtained, used, stored, disclosed and protected when students, lecturers, administrators and other authorised users use KWASU LCS.</p>
                <p>The platform is committed to handling personal data responsibly and in accordance with applicable Nigerian data-protection requirements, including the Nigeria Data Protection Act 2023.</p>
            </section>

            <section class="privacy-section" id="information-we-process">
                <h2><span class="privacy-section-number">2.</span> Information We Process</h2>
                <p>The information processed by KWASU LCS depends on a user's role and interaction with the platform.</p>
                <p>This may include:</p>
                <ul>
                    <li>Full name</li>
                    <li>Matriculation number or other university identifier</li>
                    <li>University or other authorised email address</li>
                    <li>Department</li>
                    <li>Faculty</li>
                    <li>Academic level</li>
                    <li>Course registrations and enrolments</li>
                    <li>Lecturer and course information</li>
                    <li>Account and authentication information</li>
                    <li>Academic submissions</li>
                    <li>Assignment and test submissions</li>
                    <li>Online quiz and test responses</li>
                    <li>Scores, grades and lecturer feedback</li>
                    <li>Uploaded academic files and documents</li>
                    <li>Course-related messages and communications</li>
                    <li>Calendar and academic scheduling information</li>
                    <li>Notification preferences</li>
                    <li>Information generated through interaction with AI-assisted study features</li>
                    <li>Technical and security information necessary to operate and protect the platform</li>
                </ul>
                <p>KWASU LCS does not necessarily obtain all student information directly from students.</p>
                <p>Certain student identity, enrolment and academic information may be obtained from records supplied or authorised by the University or relevant academic authorities.</p>
            </section>

            <section class="privacy-section" id="how-we-use-information">
                <h2><span class="privacy-section-number">3.</span> How We Use Personal Information</h2>
                <p>Personal information processed through KWASU LCS is used for legitimate academic, administrative, operational and security purposes associated with the platform.</p>
                <p>This includes using information to:</p>
                <ul>
                    <li>identify and authenticate authorised users</li>
                    <li>provide students with access to appropriate courses and academic materials</li>
                    <li>organise lecture videos, documents and other course resources</li>
                    <li>manage course enrolment and access permissions</li>
                    <li>administer assignments, tests and quizzes</li>
                    <li>record submissions, attempts, scores and academic feedback</li>
                    <li>provide academic calendars, deadlines and reminders</li>
                    <li>facilitate course-related communication</li>
                    <li>provide AI-assisted learning and study functionality</li>
                    <li>send academic and system notifications</li>
                    <li>maintain platform security and prevent unauthorised access</li>
                    <li>diagnose technical problems and maintain service reliability</li>
                    <li>maintain appropriate academic and system records</li>
                    <li>improve the functionality and usability of KWASU LCS</li>
                </ul>
                <p>Personal information will not knowingly be used for purposes incompatible with the academic and operational purposes described in this Privacy Policy without an appropriate basis for doing so.</p>
            </section>

            <section class="privacy-section" id="source-of-information">
                <h2><span class="privacy-section-number">4.</span> Source of Student Information</h2>
                <p>Some student information used by KWASU LCS originates from Kwara State University academic or administrative records, rather than being entered directly by the student.</p>
                <p>This may include information such as a student's:</p>
                <ul>
                    <li>name</li>
                    <li>matriculation number</li>
                    <li>department</li>
                    <li>academic level</li>
                    <li>course or enrolment information</li>
                    <li>other information required to establish or manage authorised academic access</li>
                </ul>
                <p>Users may also provide additional information directly when interacting with the platform, including submissions, messages, uploaded files, assessment responses and notification preferences.</p>
                <p>Where a user believes information supplied to KWASU LCS is inaccurate, the user may request that the information be reviewed or corrected.</p>
            </section>

            <section class="privacy-section" id="academic-content">
                <h2><span class="privacy-section-number">5.</span> Academic Content and User-Generated Information</h2>
                <p>Users may create, submit or upload information through KWASU LCS.</p>
                <p>Examples include:</p>
                <ul>
                    <li>assignments</li>
                    <li>test responses</li>
                    <li>quiz answers</li>
                    <li>academic documents</li>
                    <li>course messages</li>
                    <li>lecturer feedback</li>
                    <li>other educational materials</li>
                </ul>
                <p>Access to this information is controlled according to the user's role and the relevant course.</p>
                <p>For example, a student's private assignment or test submission is not intended to be visible to unrelated students.</p>
                <p>Authorised lecturers and administrators may access information where necessary to perform their academic or administrative responsibilities.</p>
            </section>

            <section class="privacy-section" id="ai-assisted-features">
                <h2><span class="privacy-section-number">6.</span> AI-Assisted Features</h2>
                <p>KWASU LCS may provide AI-assisted educational features, including study assistance, document-based question answering, quiz generation, academic content analysis and assistance with assessment-related functionality.</p>
                <p>When a user interacts with an AI-assisted feature, relevant prompts, course content or other information required to fulfil the request may be processed by the systems providing that functionality.</p>
                <p>Where third-party AI services are used, only information reasonably necessary to provide the requested functionality should be transmitted to those services.</p>
                <p>AI-generated educational content may contain errors and should not automatically be treated as an authoritative academic decision.</p>
                <p>Where assessment marking or academic decisions involve automated assistance, appropriate lecturer or authorised human oversight may be used where required.</p>
            </section>

            <section class="privacy-section" id="notifications">
                <h2><span class="privacy-section-number">7.</span> Notifications and Communications</h2>
                <p>KWASU LCS may send notifications relating to:</p>
                <ul>
                    <li>lectures</li>
                    <li>assignments</li>
                    <li>tests and quizzes</li>
                    <li>submission deadlines</li>
                    <li>examination-related information</li>
                    <li>course announcements</li>
                    <li>academic calendar events</li>
                    <li>reminders</li>
                    <li>other relevant platform activities</li>
                </ul>
                <p>Depending on the functionality available, notifications may be delivered through the LCS platform, email, WhatsApp or other authorised communication channels.</p>
                <p>Users can enable or disable available notification channels through the Settings section of KWASU LCS.</p>
                <p>Disabling optional notifications does not necessarily remove information that remains available within the platform itself.</p>
            </section>

            <section class="privacy-section" id="whatsapp-notifications">
                <h2><span class="privacy-section-number">8.</span> WhatsApp Notifications</h2>
                <p>KWASU LCS may use the WhatsApp Business Platform to deliver notifications that a user has enabled.</p>
                <p>Where WhatsApp notifications are enabled, information necessary to deliver the communication may be processed through Meta's WhatsApp Business Platform.</p>
                <p>This may include the recipient's telephone number and the content or necessary details of the notification.</p>
                <p>WhatsApp notifications are intended primarily for academic and service-related communications from KWASU LCS.</p>
                <p>Users may disable WhatsApp notifications through their LCS notification settings where that option is available.</p>
                <p>Use of WhatsApp is also subject to the applicable privacy terms and policies of Meta and WhatsApp.</p>
            </section>

            <section class="privacy-section" id="third-party-services">
                <h2><span class="privacy-section-number">9.</span> Third-Party Service Providers</h2>
                <p>KWASU LCS may rely on third-party technology providers where necessary to operate particular features of the platform.</p>
                <p>These may include providers used for:</p>
                <ul>
                    <li>website and database hosting</li>
                    <li>email delivery</li>
                    <li>WhatsApp communications</li>
                    <li>artificial-intelligence processing</li>
                    <li>video or educational-content delivery</li>
                    <li>infrastructure or security services</li>
                </ul>
                <p>Information is shared with such providers only where reasonably necessary for the relevant functionality or another legitimate operational purpose.</p>
                <p>Third-party services process information according to their respective terms, privacy policies and applicable data-protection obligations.</p>
            </section>

            <section class="privacy-section" id="data-security">
                <h2><span class="privacy-section-number">10.</span> Data Security</h2>
                <p>KWASU LCS uses reasonable technical and organisational safeguards intended to protect personal and academic information against unauthorised access, alteration, disclosure, loss or misuse.</p>
                <p>These measures may include:</p>
                <ul>
                    <li>authenticated user accounts</li>
                    <li>role-based access controls</li>
                    <li>course-level authorisation</li>
                    <li>restricted access to private academic submissions</li>
                    <li>password protection</li>
                    <li>server-side access controls</li>
                    <li>database access controls</li>
                    <li>other appropriate application-security measures</li>
                </ul>
                <p>However, no internet-connected information system can guarantee absolute security.</p>
                <p>Users are therefore also responsible for protecting their account credentials and should not knowingly disclose their passwords to other people.</p>
            </section>

            <section class="privacy-section" id="data-retention">
                <h2><span class="privacy-section-number">11.</span> Data Retention</h2>
                <p>Personal and academic information may be retained for as long as reasonably necessary to:</p>
                <ul>
                    <li>provide the academic services of KWASU LCS</li>
                    <li>maintain academic records</li>
                    <li>support legitimate University academic or administrative requirements</li>
                    <li>maintain assessment and submission records</li>
                    <li>resolve disputes or technical issues</li>
                    <li>protect the integrity and security of the platform</li>
                    <li>satisfy applicable legal or regulatory obligations</li>
                </ul>
                <p>Information should not be retained longer than reasonably necessary for the purposes for which it is processed, subject to legitimate academic, administrative, security and legal retention requirements.</p>
            </section>

            <section class="privacy-section" id="account-deletion">
                <h2><span class="privacy-section-number">12.</span> Account Deletion and Requests for Erasure</h2>
                <p>Student accounts are associated with University-provided or University-authorised academic information.</p>
                <p>For this reason, students cannot currently delete their LCS accounts directly from the platform.</p>
                <p>A user who wishes to request deletion or erasure of personal information may contact the LCS administrator using the contact information provided in this Privacy Policy.</p>
                <p>A deletion request will be reviewed taking into consideration applicable data-protection rights and legitimate academic, administrative, security and legal record-keeping requirements.</p>
                <p>A request for account or data deletion therefore does not necessarily mean that every academic record can be immediately or completely erased.</p>
                <p>Where information must legitimately be retained, access may instead be restricted or the information may be handled in another manner appropriate to the request and applicable requirements.</p>
            </section>

            <section class="privacy-section" id="privacy-rights">
                <h2><span class="privacy-section-number">13.</span> Your Privacy Rights</h2>
                <p>Subject to applicable law and the circumstances of the processing, users may have rights concerning their personal information, including rights to:</p>
                <ul>
                    <li>be informed about the processing of their personal information</li>
                    <li>request access to personal information held about them</li>
                    <li>request correction of inaccurate or incomplete information</li>
                    <li>object to certain processing</li>
                    <li>request restriction of certain processing</li>
                    <li>request deletion or erasure where applicable</li>
                    <li>request data portability where applicable</li>
                    <li>withdraw consent where processing is based on consent</li>
                    <li>request appropriate human consideration concerning qualifying automated decisions</li>
                    <li>lodge a complaint with the appropriate data-protection authority</li>
                </ul>
                <p>Requests may require reasonable verification of the requester's identity before personal information is disclosed, corrected or erased.</p>
            </section>

            <section class="privacy-section" id="childrens-privacy">
                <h2><span class="privacy-section-number">14.</span> Children's Privacy</h2>
                <p>KWASU LCS is an academic platform intended for authorised members of the Kwara State University community.</p>
                <p>The platform is not designed as a general-purpose service directed at children.</p>
                <p>Where personal information concerning a person who is legally considered a child is processed, it should be handled in accordance with applicable data-protection requirements and University procedures.</p>
            </section>

            <section class="privacy-section" id="cookies">
                <h2><span class="privacy-section-number">15.</span> Cookies and Technical Information</h2>
                <p>KWASU LCS may use cookies, sessions or similar technologies that are necessary for functionality such as:</p>
                <ul>
                    <li>keeping users securely signed in</li>
                    <li>maintaining authenticated sessions</li>
                    <li>remembering appropriate preferences</li>
                    <li>protecting platform security</li>
                    <li>enabling essential website functionality</li>
                </ul>
                <p>Where non-essential tracking or analytics technologies are introduced in the future, this Privacy Policy may be updated and any additional consent mechanisms required by applicable law may be implemented.</p>
            </section>

            <section class="privacy-section" id="policy-changes">
                <h2><span class="privacy-section-number">16.</span> Changes to This Privacy Policy</h2>
                <p>KWASU LCS may update this Privacy Policy where necessary to reflect:</p>
                <ul>
                    <li>changes to the platform</li>
                    <li>introduction or modification of features</li>
                    <li>changes to third-party services</li>
                    <li>University requirements</li>
                    <li>applicable legal or regulatory requirements</li>
                </ul>
                <p>Users are encouraged to review this Privacy Policy periodically.</p>
            </section>

            <section class="privacy-section" id="contact">
                <h2><span class="privacy-section-number">17.</span> Contact and Privacy Requests</h2>
                <p>Questions, concerns or requests relating to personal information processed through KWASU LCS may be directed to the LCS administrator.</p>
                <div class="privacy-contact">
                    <address class="privacy-contact-address">
                        <strong>KWASU Lecture Capturing System (LCS)</strong><br>
                        Kwara State University<br>
                        Malete, Kwara State, Nigeria
                    </address>
                    <?php if ($configuredPrivacyEmail !== ''): ?>
                        <a class="privacy-email" href="mailto:<?php echo h($configuredPrivacyEmail); ?>?subject=<?php echo rawurlencode('KWASU LCS Privacy Request'); ?>">
                            <?php echo h($configuredPrivacyEmail); ?>
                        </a>
                    <?php else: ?>
                        <p class="privacy-contact-note">A privacy contact email will be displayed here when configured by the LCS administrator.</p>
                    <?php endif; ?>
                </div>
                <p>Users may use this contact to request access, correction, restriction or deletion of personal information or to raise a privacy concern.</p>
                <p>If a privacy concern cannot be resolved through the platform's internal process, users may also contact the appropriate Nigerian data-protection authority.</p>
            </section>
        </article>
    </main>

    <footer class="privacy-footer">
        <div class="privacy-footer-inner">
            <div>
                <span class="privacy-footer-title">KWASU Lecture Capturing System</span>
                <span>Kwara State University, Malete</span>
            </div>
            <div>
                <span class="privacy-footer-policy">Privacy Policy</span><br>
                <span>&copy; <?php echo h($copyrightYear); ?></span>
            </div>
        </div>
    </footer>
</body>
</html>
