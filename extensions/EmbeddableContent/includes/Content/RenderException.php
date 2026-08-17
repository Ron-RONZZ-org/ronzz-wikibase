<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Content;

use RuntimeException;

/**
 * Thrown by the renderer when an entity cannot be embedded.
 *
 * @license GPL-2.0-or-later
 */
class RenderException extends RuntimeException {

	/** @var int HTTP status to surface */
	private $httpStatus;

	/** @var string machine-readable error code */
	private $errorCode;

	public function __construct( string $message, string $errorCode, int $httpStatus ) {
		parent::__construct( $message );
		$this->errorCode = $errorCode;
		$this->httpStatus = $httpStatus;
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}

	public function getHttpStatus(): int {
		return $this->httpStatus;
	}
}
