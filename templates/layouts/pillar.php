<?php
/**
 * Pillar layout — for long-form sales/content pages.
 * Components render above the body content in a stacked flow.
 *
 * @var array  $page    Parsed front matter metadata
 * @var string $content HTML body content
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= htmlspecialchars($page['title'] ?? 'Loom') ?></title>
	<meta name="description" content="<?= htmlspecialchars($page['description'] ?? '') ?>">
	<link rel="stylesheet" href="/assets/css/style.css">
	<?php if (!empty($page['favicon'])): ?>
	<link rel="icon" href="<?= htmlspecialchars($page['favicon']) ?>" sizes="32x32">
	<?php endif; ?>
</head>
<body>
	<div class="wp-site-blocks">
		<header class="wp-block-template-part">
			<?php include __DIR__ . '/../partials/nav.php'; ?>
		</header>

		<main class="wp-block-group has-global-padding is-layout-constrained">
			<?php if (!empty($page['components'])): ?>
				<?php foreach ($page['components'] as $component): ?>
					<?php $partial = __DIR__ . '/../partials/' . $component . '.php'; ?>
					<?php if (file_exists($partial)): ?>
						<section class="wp-block-group is-layout-constrained component-<?= htmlspecialchars($component) ?>">
							<?php include $partial; ?>
						</section>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php if (!empty(trim($content))): ?>
				<div class="wp-block-group has-global-padding is-layout-constrained">
					<?= $content ?>
				</div>
			<?php endif; ?>
		</main>

		<?php include __DIR__ . '/../partials/footer.php'; ?>
	</div>
</body>
</html>
