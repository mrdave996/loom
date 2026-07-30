<?php
/**
 * FAQ section partial.
 * Expects $page['faq'] as an array of ['question' => '...', 'answer' => '...'].
 * @var array $page Parsed front matter
 */
$faq = $page['faq'] ?? [];
if (empty($faq)) return;
?>
<section class="faq">
	<h2>Frequently Asked Questions</h2>
	<?php foreach ($faq as $item): ?>
		<details>
			<summary><?= htmlspecialchars($item['question'] ?? '') ?></summary>
			<p><?= htmlspecialchars($item['answer'] ?? '') ?></p>
		</details>
	<?php endforeach; ?>
</section>
