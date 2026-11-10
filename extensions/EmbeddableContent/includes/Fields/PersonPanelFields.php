<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fields;

use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Context\IContextSource;

/**
 * Builds the person-name search panel fields shared by the AddSource author
 * panel, the content Add* author panel and Special:Upload's semantic-entity
 * author section.
 *
 * The markup is one contract with resources/authorsearch.js:
 *
 *   #wb-<panel>-search            the Search button (data-target = the field
 *                                 the confirmed item ids are written into)
 *   #wb-<panel>-results           the per-name result rows
 *   wp<prefix>Names               the pasted name list
 *   wp<prefix>Choices             the per-name decisions (JSON, JS-owned)
 *   wp<prefix>NamesHarvested      the harvested-prefill marker
 *
 * The HTMLForm field keys are the JS contract — renaming one silently
 * disables the panel.
 *
 * @license GPL-2.0-or-later
 */
final class PersonPanelFields {

	/**
	 * @param string $panel the DOM id fragment (#wb-<panel>-search/-results)
	 * @param string $prefix the form field-key prefix (wp<prefix>Names)
	 * @param string $target the field name the confirmed ids are injected into
	 * @param array<string,string> $messages label/help/button message keys
	 * @param array<string,mixed> $record the record (prefills the name list)
	 * @param array<string,mixed> $extra merged over every field (e.g. the
	 *   Upload form's `section`)
	 * @return array<string,array<string,mixed>>
	 */
	public static function spec(
		string $panel,
		string $prefix,
		string $target,
		array $messages,
		IContextSource $context,
		array $record = [],
		array $extra = []
	): array {
		$searchButton = '<button type="button" id="wb-' . htmlspecialchars( $panel ) . '-search"'
			. ' data-target="' . htmlspecialchars( $target ) . '"'
			. ' class="mw-ui-button mw-ui-progressive">'
			. htmlspecialchars( $context->msg( $messages['search'] )->text() )
			. '</button>';
		return [
			$prefix . 'Names' => array_merge( [
				'type' => 'textarea',
				'rows' => 3,
				'label-message' => $messages['names'],
				'help-message' => $messages['namesHelp'],
				'default' => (string)( $record[$prefix . 'Names'] ?? '' ),
			], $extra ),
			$prefix . 'NamesHarvested' => array_merge( [
				'type' => 'hidden',
				'default' => (string)( $record[$prefix . 'NamesHarvested'] ?? '' ),
			], $extra ),
			$prefix . 'Search' => array_merge( [
				// RawHtmlField — NOT HTMLInfoField: an OOUI info field is a
				// LabelWidget, and clicking a result-row button would fire
				// the label's activation on the Search button.
				'type' => 'info',
				'class' => RawHtmlField::class,
				'raw' => true,
				'default' => $searchButton
					. '<div id="wb-' . htmlspecialchars( $panel ) . '-results" class="wb-author-results"></div>',
			], $extra ),
			// The popup writes the per-name decisions here (JSON). A hidden
			// field so the form round-trips them; the server re-validates.
			$prefix . 'Choices' => array_merge( [
				'type' => 'hidden',
				'default' => (string)( $record[$prefix . 'Choices'] ?? '' ),
			], $extra ),
		];
	}

	/**
	 * The collective class picker data for the quick-create dialog
	 * (`wbAuthorCollectiveClasses`). "person" and "group of humans" are
	 * excluded: a person is not a collective, and a group needs members and
	 * is created through Special:AddCollective.
	 *
	 * @param callable(string):string $message message resolver
	 * @return list<array{key:string,id:string,label:string}>
	 */
	public static function collectiveClasses( EmbeddableContentConfig $config, callable $message ): array {
		$classes = [];
		foreach ( $config->agentClasses() as $key => $id ) {
			if ( $key === 'person' || $key === 'groupOfHumans' || $id === '' ) {
				continue;
			}
			$classes[] = [
				'key' => $key,
				'id' => $id,
				'label' => $message( 'embeddablecontent-agent-class-' . $key ),
			];
		}
		return $classes;
	}

}
