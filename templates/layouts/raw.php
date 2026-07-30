<?php
/**
 * Raw layout template.
 *
 * Minimal layout with no nav/footer partials — just the content.
 * Useful for scraped pages that need full control over their structure,
 * or for embedding content in iframes.
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
	<main>
		<?= $content ?>
	</main>
</body>
</html>
