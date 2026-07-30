<?php

declare(strict_types=1);

namespace Loom\Importer;

/**
 * Convert WordPress contact forms to Loom FormHandler partials.
 */
class FormConverter
{
	private string $outputDir;

	public function __construct(string $outputDir)
	{
		$this->outputDir = rtrim($outputDir, '/');
	}

	/**
	 * Detect form placeholders in content and generate Loom form partials.
	 *
	 * @param array $forms Form definitions from ContentParser
	 * @param string $content Content with form placeholders
	 * @return string Content with form placeholders replaced
	 */
	public function convert(array $forms, string $content): string
	{
		// Replace form placeholders with partial includes
		$content = preg_replace_callback(
			'/<!-- form:(cf7|wpforms):(\d+):?([^ ]*) -->/',
			function (array $m) use ($forms) {
				$type = $m[1];
				$id = $m[2];
				$name = $m[3] ?: 'contact';

				// Find matching form definition
				$formDef = $this->findForm($forms, $type, $id);

				// Generate form partial
				$partialName = 'form-' . $name;
				$this->generateFormPartial($formDef, $partialName);

				// Return PHP include
				return '<?php include __DIR__ . \'/partials/' . $partialName . '.php\'; ?>';
			},
			$content
		);

		return $content;
	}

	/**
	 * Generate a default contact form partial if none exists.
	 */
	public function generateDefaultContactForm(): string
	{
		$formDef = [
			'name' => 'contact',
			'fields' => [
				['name' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
				['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
				['name' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true],
			],
		];

		return $this->generateFormPartial($formDef, 'form-contact');
	}

	/**
	 * Find a form definition by type and ID.
	 */
	private function findForm(array $forms, string $type, string $id): array
	{
		foreach ($forms as $form) {
			if (($form['type'] ?? '') === $type && ($form['id'] ?? '') === $id) {
				return $form;
			}
		}

		// Return default contact form
		return [
			'name' => 'contact',
			'fields' => [
				['name' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
				['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
				['name' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true],
			],
		];
	}

	/**
	 * Generate a Loom form partial from a form definition.
	 */
	private function generateFormPartial(array $formDef, string $partialName): string
	{
		$php = "<?php\n";
		$php .= "/**\n";
		$php .= " * Contact form partial (auto-generated from WordPress).\n";
		$php .= " * @var array \$page Parsed front matter\n";
		$php .= " */\n";
		$php .= "\$form = new \\Loom\\FormHandler();\n";
		$php .= "\$submitted = false;\n";
		$php .= "\$errors = [];\n";
		$php .= "\$success = false;\n";
		$php .= "\n";
		$php .= "// Handle form submission\n";
		$php .= 'if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\' && !empty($_POST[\'_csrf\'])) {' . "\n";
		$php .= "\t" . '$result = $form->process($_POST);' . "\n";
		$php .= "\t" . '$submitted = true;' . "\n";
		$php .= "\t" . '$errors = $result[\'errors\'];' . "\n";
		$php .= "\t" . '$success = $result[\'success\'];' . "\n";
		$php .= "}\n";
		$php .= "?>\n\n";

		$php .= '<section class="contact-form">' . "\n";
		$php .= "\t" . '<h2>' . htmlspecialchars(ucwords(str_replace('-', ' ', $formDef['name'] ?? 'Contact'))) . '</h2>' . "\n\n";

		$php .= "\t" . '<?php if ($success): ?>' . "\n";
		$php .= "\t\t" . '<p class="success">Thank you! Your message has been sent.</p>' . "\n";
		$php .= "\t" . '<?php else: ?>' . "\n\n";

		$php .= "\t\t" . '<?php if (!empty($errors)): ?>' . "\n";
		$php .= "\t\t\t" . '<ul class="errors">' . "\n";
		$php .= "\t\t\t\t" . '<?php foreach ($errors as $error): ?>' . "\n";
		$php .= "\t\t\t\t\t" . '<li><?= htmlspecialchars($error) ?></li>' . "\n";
		$php .= "\t\t\t\t" . '<?php endforeach; ?>' . "\n";
		$php .= "\t\t\t" . '</ul>' . "\n";
		$php .= "\t\t" . '<?php endif; ?>' . "\n\n";

		$php .= "\t\t" . '<form method="post">' . "\n";
		$php .= "\t\t\t" . '<?= $form->csrfField() ?>' . "\n";

		foreach ($formDef['fields'] as $field) {
			$name = htmlspecialchars($field['name']);
			$label = htmlspecialchars($field['label']);
			$type = htmlspecialchars($field['type'] ?? 'text');
			$required = !empty($field['required']) ? ' required' : '';

			$php .= "\n\t\t\t" . '<div class="form-group">' . "\n";
			$php .= "\t\t\t\t" . '<label for="' . $name . '">' . $label . '</label>' . "\n";

			if ($type === 'textarea') {
				$php .= "\t\t\t\t" . '<textarea id="' . $name . '" name="' . $name . '" rows="5"' . $required . '>' . '<?php echo htmlspecialchars($_POST[\'' . $name . '\'] ?? \'\') ?>' . '</textarea>' . "\n";
			} else {
				$php .= "\t\t\t\t" . '<input type="' . $type . '" id="' . $name . '" name="' . $name . '"' . $required . ' value="' . '<?php echo htmlspecialchars($_POST[\'' . $name . '\'] ?? \'\') ?>' . '">' . "\n";
			}

			$php .= "\t\t\t" . '</div>' . "\n";
		}

		$php .= "\n\t\t\t" . '<button type="submit" class="btn btn-primary">Send</button>' . "\n";
		$php .= "\t\t" . '</form>' . "\n\n";
		$php .= "\t" . '<?php endif; ?>' . "\n";
		$php .= '</section>' . "\n";

		// Write partial
		$partialsDir = $this->outputDir . '/templates/partials';
		if (!is_dir($partialsDir)) {
			mkdir($partialsDir, 0755, true);
		}

		$destPath = $partialsDir . '/' . $partialName . '.php';
		file_put_contents($destPath, $php);

		return $destPath;
	}
}
