<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

/**
 * The requested entity id exists but is not a Wikibase item (or does not
 * exist at all).
 *
 * @license GPL-2.0-or-later
 */
class CitationEntityNotFoundException extends CitationException {

	/** @var string */
	private $entityId;

	public function __construct( string $message, string $entityId ) {
		parent::__construct( $message );
		$this->entityId = $entityId;
	}

	public function getEntityId(): string {
		return $this->entityId;
	}
}
