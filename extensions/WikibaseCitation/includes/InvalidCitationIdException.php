<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

/**
 * The requested entity id is not a valid `Q\d+` item id.
 *
 * @license GPL-2.0-or-later
 */
class InvalidCitationIdException extends CitationException {

	/** @var string */
	private $entityId;

	public function __construct( string $message, string $entityId ) {
		parent::__construct( $message );
		$this->entityId = $entityId;
	}

	/** The raw (unparsable) input the caller gave. */
	public function getEntityId(): string {
		return $this->entityId;
	}
}
