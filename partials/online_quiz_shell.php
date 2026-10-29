<?php
// Shared presentation only; authorization and data loading happen before this partial.
function oq_page_begin(string $title, string $subtitle, array $user, bool $secureMode = false): void
{
	header('Cache-Control: no-store'); ?>
	<!DOCTYPE html>
	<html lang="en">

	<head><?php $pageTitle = $title;
			$extraCss = ['assets/online-quiz.css'];
			include __DIR__ . '/head.php'; ?></head>

	<body class="app-body oq-body<?php echo $secureMode ? ' oq-secure-body' : ''; ?>">
		<?php if (!$secureMode) include __DIR__ . '/nav.php'; ?>
		<section class="app-hero oq-hero"><?php if (!$secureMode): include __DIR__ . '/appheader.php';
											else: ?><header class="oq-secure-shell-header"><span class="oq-secure-brand"><?php echo brand_logo(); ?><strong>KWASU LCS</strong></span><span><i class="bi bi-shield-lock-fill" aria-hidden="true"></i> Secure assessment</span></header><?php endif; ?>
			<header class="topbar">
				<div><?php if ($secureMode): ?><p class="oq-secure-context"><i class="bi bi-shield-check" aria-hidden="true"></i> Navigation is locked while this attempt is active</p><?php else: ?><nav class="oq-breadcrumb" aria-label="Breadcrumb"><a href="chat">Assessment/Chat</a><span class="oq-breadcrumb-trail"><span aria-hidden="true">›</span><a href="assignment_tests">Assignments &amp; Tests</a><span aria-hidden="true">›</span><a href="assignment_tests?category=quiz">Quiz/Test</a></span></nav><?php endif; ?><h1><?php echo h($title); ?></h1>
					<p class="muted"><?php echo h($subtitle); ?></p>
				</div>
			</header>
		</section>
		<main class="page oq-page" data-csrf="<?php echo h(assignment_test_csrf_token()); ?>">
			<p id="oq-status" role="status" aria-live="polite"></p>
		<?php }
	function oq_page_end(?string $script = null): void
	{ ?>
		</main>
		<script src="assets/online-quiz-common.js?v=<?php echo filemtime(__DIR__ . '/../assets/online-quiz-common.js'); ?>" defer></script><?php if ($script): ?><script src="assets/<?php echo h($script); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/' . $script); ?>" defer></script><?php endif; ?>
	</body>

	</html><?php }
		function oq_bootstrap(array $data): void
		{
			echo '<script type="application/json" id="oq-data">' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) . '</script>';
		}
