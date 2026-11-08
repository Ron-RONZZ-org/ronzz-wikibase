<?php

declare( strict_types = 1 );

namespace EmbeddableContent;

/**
 * Pure identity rules for the label-based item reuse in the page machinery
 * (ImageItemCreator for File: pages, PageItemCreator for Main-namespace
 * pages).
 *
 * The bug this encodes: both creators used to reuse ANY item whose English
 * label matched the derived label. Uploading `File:Logic.png` (label
 * "Logic") therefore matched the ARTICLE item Q1838, whose `wikibase`
 * sitelink already pointed at the page "Logic" — the sitelink could not be
 * set, the create mode applied no facts, and the whole itemization silently
 * no-op'd. The File: page stayed unconnected and `{{#image-facts:}}`
 * rendered nothing.
 *
 * The rule is identity, not label: an item is reusable for a File: page only
 * when it already IS that file page's item (same sitelink) or is a legacy
 * link-less item of the image class. Symmetrically, a Main-namespace page
 * must never adopt an image item / a File:-page item.
 *
 * @license GPL-2.0-or-later
 */
final class ItemIdentityPolicy {

	/**
	 * Whether an item found by LABEL may be reused as the image item for a
	 * File: page.
	 *
	 * @param string $filePageName the target "File:Example.png" page
	 * @param string|null $candidateSitelinkPage the candidate's `wikibase`
	 *  sitelink page name, or null when it has no such sitelink
	 * @param bool $candidateIsImageItem the candidate is `instance of` the
	 *  configured image class
	 */
	public static function canAdoptFileLabelMatch(
		string $filePageName,
		?string $candidateSitelinkPage,
		bool $candidateIsImageItem
	): bool {
		if ( $candidateSitelinkPage !== null ) {
			return $candidateSitelinkPage === $filePageName;
		}
		// A legacy image item created before the sitelink was written.
		return $candidateIsImageItem;
	}

	/**
	 * Whether an item found by LABEL may be reused for a Main-namespace page.
	 * An image item (or anything sitelinked to a File: page) belongs to a
	 * file, never to a same-titled article.
	 *
	 * @param bool $candidateIsImageItem the candidate is `instance of` the
	 *  configured image class
	 * @param bool $candidateSitelinkIsFilePage the candidate's `wikibase`
	 *  sitelink points at a File: page
	 */
	public static function canAdoptPageLabelMatch(
		bool $candidateIsImageItem,
		bool $candidateSitelinkIsFilePage
	): bool {
		return !$candidateIsImageItem && !$candidateSitelinkIsFilePage;
	}

}
