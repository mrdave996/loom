<?php
/**
 * Pricing section partial.
 * Expects $page['pricing'] as an array of plans.
 * @var array $page Parsed front matter
 */
$pricing = $page['pricing'] ?? [];
if (empty($pricing)) return;
?>
<section class="pricing">
	<h2>Pricing</h2>
	<div class="pricing-grid">
		<?php foreach ($pricing as $plan): ?>
			<div class="pricing-card">
				<h3><?= htmlspecialchars($plan['name'] ?? '') ?></h3>
				<div class="price"><?= htmlspecialchars($plan['price'] ?? '') ?></div>
				<?php if (!empty($plan['features'])): ?>
					<ul>
						<?php foreach ($plan['features'] as $feature): ?>
							<li><?= htmlspecialchars($feature) ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
</section>
