<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Flow;

use EmbeddableContent\Spec\AuthorNameList;
use EmbeddableContent\Spec\ItemIdList;
use MediaWiki\User\User;

/**
 * Shared API payload handling for the person-name panels (authors on
 * AddSource, members on AddCollective): parse the pasted names, decode the
 * per-name decisions/details, resolve them against local Persons, create/
 * reuse/update the decided Person items, and merge the ids into the target
 * record field.
 *
 * Used by action=addsource and action=addsemanticentity, so the two flows
 * cannot drift (the same deduplication rule the rest of the entity-mode API
 * follows). No decisions → return the batch resolution and write nothing.
 *
 * @license GPL-2.0-or-later
 */
final class PersonPanelPayload {

	public function __construct(
		private readonly AuthorResolver $resolver,
		private readonly AuthorCreator $creator
	) {
	}

	/**
	 * @param array<string,mixed> $params the API params
	 * @param array<string,mixed> $record the flow record (mutated)
	 * @return array{error?:string,resolution?:array,warnings?:array,result?:array}|null
	 */
	public function process(
		string $prefix,
		array $params,
		array &$record,
		User $user,
		?string $summary,
		string $language = 'en'
	): ?array {
		$namesInput = trim( (string)( $params[$prefix . 'Names'] ?? '' ) );
		if ( $namesInput === '' ) {
			return null;
		}
		$parsed = AuthorNameList::parse(
			$namesInput,
			(string)( $params[$prefix . 'NameFormat'] ?? AuthorNameList::FORMAT_AUTO )
		);
		if ( $parsed['names'] === [] ) {
			return [ 'error' => 'No person names could be parsed from ' . $prefix . 'Names.' ];
		}

		$decisions = $this->decodeJsonArray( (string)( $params[$prefix . 'Decisions'] ?? '' ) );
		if ( $decisions === null ) {
			return [ 'error' => $prefix . 'Decisions must be a JSON array.' ];
		}
		$details = $this->decodeJsonObject( (string)( $params[$prefix . 'Details'] ?? '' ) );
		if ( $details === null ) {
			return [ 'error' => $prefix . 'Details must be a JSON object.' ];
		}
		foreach ( $decisions as &$decision ) {
			if ( !is_array( $decision ) ) {
				continue;
			}
			$index = (int)( $decision['index'] ?? -1 );
			if ( $index < 0 || !isset( $parsed['names'][$index] ) ) {
				continue;
			}
			$provided = $this->detailsFor( $details, $index, $parsed['names'][$index]['original'] );
			if ( $provided === [] ) {
				continue;
			}
			$existing = is_array( $decision['person'] ?? null ) ? $decision['person'] : [];
			$decision['person'] = array_merge( $provided, $existing );
		}
		unset( $decision );

		if ( $decisions === [] ) {
			return [
				'resolution' => $this->resolver->resolveNames( $namesInput, $parsed['names'], $language ),
				'warnings' => $parsed['warnings'],
			];
		}

		$result = $this->creator->applyDecisions(
			$parsed['names'],
			$decisions,
			$user,
			$summary ?? 'Create a person from the Add* person panel',
			!empty( $params[$prefix . 'UpdateExisting'] )
		);
		if ( $result['error'] !== null ) {
			return [ 'error' => $result['error'] ];
		}
		$target = $prefix === 'author' ? 'authors' : 'members';
		$ids = ItemIdList::split( (string)( $record[$target] ?? '' ) );
		foreach ( $result['ids'] as $id ) {
			if ( !in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		$record[$target] = implode( ', ', $ids );
		return [ 'result' => $result ];
	}

	/** @param array<string,mixed> $details */
	private function detailsFor( array $details, int $index, string $original ): array {
		foreach ( [ (string)$index, $original ] as $key ) {
			if ( isset( $details[$key] ) && is_array( $details[$key] ) ) {
				return $details[$key];
			}
		}
		return [];
	}

	/** @return array<int,mixed>|null */
	private function decodeJsonArray( string $json ): ?array {
		if ( trim( $json ) === '' ) {
			return [];
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) && array_is_list( $decoded ) ? $decoded : null;
	}

	/** @return array<string,mixed>|null */
	private function decodeJsonObject( string $json ): ?array {
		if ( trim( $json ) === '' ) {
			return [];
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) && !array_is_list( $decoded ) ? $decoded : null;
	}

}
