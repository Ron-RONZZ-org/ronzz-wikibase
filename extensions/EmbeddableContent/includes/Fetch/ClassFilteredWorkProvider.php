<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * A work provider that can restrict a search to an ontology class (the
 * AddSource class's Wikidata QID scope).
 *
 * Only the Wikidata hub implements this: an OpenLibrary/OpenAlex/Crossref
 * result is already a book/article by virtue of the provider, so those
 * records are never dropped by the class scope. ProviderClient passes the
 * scope through only to providers implementing this interface.
 *
 * @license GPL-2.0-or-later
 */
interface ClassFilteredWorkProvider {

	/**
	 * @param string $title free-text title
	 * @param string[] $classQids Wikidata class ids (including the family
	 *        extras, e.g. book + literary work)
	 * @return WorkRecord[]
	 */
	public function searchByTitleInClasses( string $title, array $classQids ): array;

	/**
	 * @param string $author free-text author name
	 * @param string $title optional title narrowing
	 * @param string[] $classQids
	 * @return WorkRecord[]
	 */
	public function searchByAuthorNameInClasses( string $author, string $title, array $classQids ): array;

	/**
	 * @param string[] $qids Wikidata author entity ids
	 * @param string $title optional title narrowing
	 * @param string[] $classQids
	 * @return WorkRecord[]
	 */
	public function searchByAuthorEntitiesInClasses( array $qids, string $title, array $classQids ): array;

}
