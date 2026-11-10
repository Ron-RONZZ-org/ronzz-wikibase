<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

use EmbeddableContent\Spec\AuthorNameList;
use MediaWiki\User\User;

/**
 * Applies a rendered person-name panel to a flow record.
 *
 * The panel (resources/authorsearch.js + authorsearch-dialogs.js) parses a
 * pasted author/member list, resolves every name against the local wiki and
 * records one explicit decision per name. This class is the server half: it
 * re-validates those decisions, creates/reuses/updates the decided Person
 * (or collective) items and returns their ids for the caller to merge into
 * its target field.
 *
 * One implementation for every panel:
 *  - the AddSource author panel (target `authors`),
 *  - the AddCollective member panel (target `members`),
 *  - the content Add* author panel (target `attributedTo`).
 *
 * The caller owns the target-field merge and the record shape; this class
 * only parses/validates/applies the panel input, so the browser forms and
 * the shared create pipeline can never drift on "every name needs a
 * decision".
 *
 * @license GPL-2.0-or-later
 */
final class PersonPanel {

	public const ERROR_NAMES_MISSING = 'embeddablecontent-personpanel-error-names-missing';
	public const ERROR_NAMES_EMPTY = 'embeddablecontent-personpanel-error-names-empty';
	public const ERROR_CHOICES_INVALID = 'embeddablecontent-personpanel-error-choices-invalid';

	public function __construct(
		private readonly AuthorCreator $creator
	) {
	}

	/**
	 * Read the panel fields out of $input and, when committing, create the
	 * decided entities.
	 *
	 * @param array<string,mixed> $input the form data (keys
	 *   `{$prefix}Names`, `{$prefix}Choices`, `{$prefix}NamesHarvested`)
	 * @param string[] $allowedKinds person/collective kinds the panel accepts
	 * @return array{
	 *   error: ?string,
	 *   errorKey: ?string,
	 *   harvested: bool,
	 *   ids: list<string>,
	 *   collectiveIds: list<string>,
	 *   warnings: list<string>
	 * }
	 */
	public function apply(
		string $prefix,
		array $input,
		User $user,
		string $summary,
		array $allowedKinds = [ 'person', 'collective' ],
		bool $commit = true
	): array {
		$empty = [ 'error' => null, 'errorKey' => null, 'harvested' => false, 'ids' => [], 'collectiveIds' => [], 'warnings' => [] ];

		$namesInput = trim( (string)( $input[$prefix . 'Names'] ?? '' ) );
		$choicesJson = trim( (string)( $input[$prefix . 'Choices'] ?? '' ) );
		if ( $namesInput === '' && $choicesJson === '' ) {
			return $empty;
		}
		// A harvested names prefill with no decisions: the contributor
		// submitted without confirming them — leave the item without those
		// authors instead of showing a blocking form error. Any input or a
		// Search click clears the marker (resources/authorsearch.js).
		if ( $choicesJson === '' && !empty( $input[$prefix . 'NamesHarvested'] ) ) {
			return [ 'error' => null, 'errorKey' => null, 'harvested' => true, 'ids' => [], 'collectiveIds' => [], 'warnings' => [] ];
		}
		if ( $namesInput === '' ) {
			return [ 'error' => null, 'errorKey' => self::ERROR_NAMES_MISSING, 'harvested' => false, 'ids' => [], 'collectiveIds' => [], 'warnings' => [] ];
		}
		$parsed = AuthorNameList::parse( $namesInput );
		if ( $parsed['names'] === [] ) {
			return [ 'error' => null, 'errorKey' => self::ERROR_NAMES_EMPTY, 'harvested' => false, 'ids' => [], 'collectiveIds' => [], 'warnings' => [] ];
		}
		$choices = [];
		if ( $choicesJson !== '' ) {
			$decoded = json_decode( $choicesJson, true );
			if ( !is_array( $decoded ) ) {
				return [ 'error' => null, 'errorKey' => self::ERROR_CHOICES_INVALID, 'harvested' => false, 'ids' => [], 'collectiveIds' => [], 'warnings' => [] ];
			}
			$choices = $decoded;
		}

		if ( $commit ) {
			$result = $this->creator->applyDecisions(
				$parsed['names'],
				$choices,
				$user,
				$summary,
				false,
				$allowedKinds
			);
		} else {
			$result = $this->creator->validateDecisions( $parsed['names'], $choices, $allowedKinds );
			$result['collectiveIds'] ??= [];
		}
		if ( $result['error'] !== null ) {
			return [ 'error' => $result['error'], 'errorKey' => null, 'harvested' => false, 'ids' => [], 'collectiveIds' => [], 'warnings' => [] ];
		}
		return [
			'error' => null,
			'errorKey' => null,
			'harvested' => false,
			'ids' => array_values( $result['ids'] ),
			'collectiveIds' => array_values( $result['collectiveIds'] ?? [] ),
			'warnings' => array_values( $result['warnings'] ?? [] ),
		];
	}

}
