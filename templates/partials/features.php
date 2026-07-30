<?php
/**
 * Features section partial.
 * Expects $page['features'] as an array of ['title' => '...', 'description' => '...'].
 * @var array $page Parsed front matter
 */
$features = $page['features'] ?? [];
if (empty($features)) return;
?>
<section class="features">
	<h2>Features</h2>
	<div class="features-grid">
		<?php foreach ($features as $feature): ?>
			<div class="feature-card">
				<h3><?= htmlspecialchars($feature['title'] ?? '') ?></h3>
				<p><?= htmlspecialchars($feature['description'] ?? '') ?></p>
			</div>
		<?php endforeach; ?>
	</div>
</section>
