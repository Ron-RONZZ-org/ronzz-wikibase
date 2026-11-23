<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Law;

/**
 * One official open-data source of the law corpus.
 *
 * A parser turns one XML file into at most one law/provision unit. The
 * importer owns the store writes, the run bookkeeping and the version
 * policy; the parser only reads XML.
 *
 * @license GPL-2.0-or-later
 */
interface LawSourceParser {

	/**
	 * Whether the file belongs to this source (cheap path test — the
	 * importer walks a whole extracted tree).
	 */
	public function accepts( string $path ): bool;

	/**
	 * @return array{law:array<string,mixed>,sections:array<int,array<string,mixed>>,article:array<string,mixed>}|null
	 *  Null when the document is not citable (no id/reference/text).
	 * @throws LawParseException
	 */
	public function parse( string $xml, string $path ): ?array;
}
