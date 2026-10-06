<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fields;

/**
 * The "Add translation" HTMLForm cloner field — one builder shared by
 * Special:AddQuotation (content items) and Special:AddSource/law (legal
 * provisions): a language combobox + a translated-text textarea per row,
 * submitted as `wptranslations[i][language|content]`. The delete control's
 * confirmation dialog is wired by `resources/translations.js` (loaded by the
 * consuming page).
 *
 * @license GPL-2.0-or-later
 */
final class TranslationCloner {

	/**
	 * @param array<string,string> $languageOptions code => display name
	 * @return array<string,mixed> the cloner field descriptor
	 */
	public static function spec( array $languageOptions ): array {
		return [
			'type' => 'cloner',
			'create-button-message' => 'embeddablecontent-add-translation-add',
			'delete-button-message' => 'embeddablecontent-add-translation-delete',
			'fields' => [
				'language' => [
					'type' => 'combobox',
					'label-message' => 'embeddablecontent-add-translation-language',
					'options' => $languageOptions,
				],
				'content' => [
					'type' => 'textarea',
					'label-message' => 'embeddablecontent-add-translation-text',
					'rows' => 3,
				],
			],
		];
	}
}
