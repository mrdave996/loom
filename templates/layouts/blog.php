<?php
/**
 * Blog listing layout template.
 *
 * Lists posts from content/posts/ with title, date, and description.
 *
 * @var array  $page    Parsed front matter metadata
 * @var string $content HTML body content
 */
$postsDir = dirname(__DIR__, 2) . '/content/posts';
$posts = [];

if (is_dir($postsDir)) {
	$files = glob($postsDir . '/*.md');
	foreach ($files as $file) {
		$raw = file_get_contents($file);
		if (preg_match('/^---\s*\n(.+?)\n---/s', $raw, $m)) {
			$meta = yaml_parse($m[1]);
			if ($meta) {
				$slug = pathinfo($file, PATHINFO_FILENAME);
				$posts[] = [
					'title' => $meta['title'] ?? $slug,
					'date' => $meta['date'] ?? '',
					'description' => $meta['description'] ?? '',
					'url' => '/' . $slug,
				];
			}
		}
	}
}

// Sort by date descending
usort($posts, fn($a, $b) => strcmp($b['date'], $a['date']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= htmlspecialchars($page['title'] ?? 'Blog') ?></title>
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
			<div class="wp-block-group has-global-padding is-layout-constrained">
				<?= $content ?>

				<?php if (!empty($posts)): ?>
				<div class="wp-block-post-template">
					<?php foreach ($posts as $post): ?>
					<article class="wp-block-post">
						<h2><a href="<?= htmlspecialchars($post['url']) ?>"><?= htmlspecialchars($post['title']) ?></a></h2>
						<?php if (!empty($post['date'])): ?>
						<time><?= htmlspecialchars($post['date']) ?></time>
						<?php endif; ?>
						<?php if (!empty($post['description'])): ?>
						<p><?= htmlspecialchars($post['description']) ?></p>
						<?php endif; ?>
					</article>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
			</div>
		</main>

		<?php include __DIR__ . '/../partials/footer.php'; ?>
	</div>
</body>
</html>
