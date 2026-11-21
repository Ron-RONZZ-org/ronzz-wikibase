<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Pdf;

/**
 * Apache Tika 4 client for PDF → Markdown extraction.
 *
 * Tika 4's `PUT /tika/json/md` returns a JSON envelope whose content lives
 * under `tk:content` (Tika 3's `X-TIKA:content` is accepted as a fallback).
 * The service already runs with Tesseract OCR for the Open WebUI deployment;
 * the wiki reaches it on host loopback (127.0.0.1:9998).
 *
 * The transport is injectable for unit tests (a callable taking the endpoint,
 * file path and timeout and returning `[httpStatus, body, error]`).
 *
 * @license GPL-2.0-or-later
 */
final class TikaClient {

	public const DEFAULT_ENDPOINT = 'http://127.0.0.1:9998/tika/json/md';
	public const DEFAULT_TIMEOUT = 360.0;

	/** @var callable(string,string,float):array{0:int,1:string,2:string} */
	private $transport;

	public function __construct(
		private string $endpoint = self::DEFAULT_ENDPOINT,
		private float $timeout = self::DEFAULT_TIMEOUT,
		?callable $transport = null
	) {
		$this->transport = $transport ?? [ self::class, 'curlPut' ];
	}

	/**
	 * @return array{markdown:string,partial:bool,status:string,exception:string}
	 * @throws TikaException
	 */
	public function extractMarkdown( string $pdfPath ): array {
		if ( !is_file( $pdfPath ) || !is_readable( $pdfPath ) ) {
			throw new TikaException( "PDF is not readable: {$pdfPath}" );
		}
		[ $status, $body, $error ] = ( $this->transport )( $this->endpoint, $pdfPath, $this->timeout );
		if ( $error !== '' ) {
			throw new TikaException( "Tika request failed: {$error}" );
		}
		$data = json_decode( $body, true );
		if ( !is_array( $data ) ) {
			throw new TikaException( "Tika returned a non-JSON response (HTTP {$status})." );
		}
		$tikaStatus = (string)( $data['status'] ?? '' );
		if ( $status >= 400 || in_array( $tikaStatus, [ 'TIMEOUT', 'OOM', 'UNSPECIFIED_CRASH' ], true ) ) {
			$message = (string)( $data['message'] ?? $tikaStatus );
			throw new TikaException( "Tika failed (HTTP {$status}): {$message}" );
		}
		$markdown = $data['tk:content'] ?? $data['content'] ?? $data['X-TIKA:content'] ?? '';
		if ( !is_string( $markdown ) ) {
			throw new TikaException( 'Tika returned a non-string content field.' );
		}
		$exception = (string)( $data['tk:exception:container-exception']
			?? $data['tk:exception:embedded-exception']
			?? '' );
		$partial = $tikaStatus === 'PARTIAL_TIMEOUT' || $exception !== '';
		return [
			'markdown' => $markdown,
			'partial' => $partial,
			'status' => $tikaStatus,
			'exception' => $exception,
		];
	}

	/**
	 * Default transport: a plain PUT of the PDF bytes. cURL rather than
	 * MediaWiki's HttpRequestFactory (the same reason as Spec\SparqlRunner:
	 * the php-fpm body transport mangles non-trivial payloads).
	 *
	 * @return array{0:int,1:string,2:string} [http status, body, error]
	 */
	public static function curlPut( string $endpoint, string $pdfPath, float $timeout ): array {
		if ( !function_exists( 'curl_init' ) ) {
			return [ 0, '', 'the curl PHP extension is not available' ];
		}
		$fh = fopen( $pdfPath, 'rb' );
		if ( $fh === false ) {
			return [ 0, '', "could not open {$pdfPath}" ];
		}
		$size = filesize( $pdfPath );
		$ch = curl_init( $endpoint );
		if ( $ch === false ) {
			fclose( $fh );
			return [ 0, '', 'curl_init failed' ];
		}
		curl_setopt_array( $ch, [
			CURLOPT_UPLOAD => true,
			CURLOPT_INFILE => $fh,
			CURLOPT_INFILESIZE => $size !== false ? $size : 0,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => (int)$timeout,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/pdf',
				'Accept: application/json',
			],
		] );
		$body = curl_exec( $ch );
		$status = (int)curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error = (string)curl_error( $ch );
		curl_close( $ch );
		fclose( $fh );
		return [ $status, $body === false ? '' : (string)$body, $body === false ? $error : '' ];
	}

}
