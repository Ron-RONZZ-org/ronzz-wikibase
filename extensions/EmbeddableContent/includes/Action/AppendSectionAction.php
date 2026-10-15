<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Action;

use MediaWiki\Actions\EditAction;

/**
 * The "Add section" page action (`?action=appendsection`) on classic wiki
 * pages, and — with a `wbsection=<n>` parameter (the per-heading "Add section
 * below" control) — the same editor targeting one existing section.
 *
 * This is a thin wrapper around core's `EditAction`: it renders the
 * `AppendSectionEditPage` (an `EditPage` subclass), so the flow gets the
 * built-in edit surface — the wikitext toolbar, server-rendered Preview and
 * Show changes, summary, watch, edit-conflict handling and the full edit
 * filter / AbuseFilter pipeline — with the "This is a minor edit" checkbox
 * removed. The append behaviour lives in the `EditPage` subclass.
 *
 * The permission gate is the core `edit` right.
 *
 * @license GPL-2.0-or-later
 */
class AppendSectionAction extends EditAction {

	public function getName() {
		return 'appendsection';
	}

	public function getRestriction() {
		return 'edit';
	}

	/** {@inheritDoc} */
	public function show() {
		$this->useTransactionalTimeLimit();

		$out = $this->getOutput();
		$out->setRobotPolicy( 'noindex,nofollow' );
		// The editor should always see the latest content (T152462 also relies
		// on this to set cookie blocks).
		$out->disableClientCache();

		$editor = new AppendSectionEditPage( $this->getArticle(), $this->targetSection() );
		$editor->setContextTitle( $this->getTitle() );
		$editor->edit();
	}

	/**
	 * The target section: 0 appends at the end of the page, a positive number
	 * appends below that section. It travels in `wbsection` (NOT core's
	 * `section`, which must stay empty so `EditPage` treats the flow as a
	 * whole-page edit and its `replaceSectionAtRev( '' )` passes our assembled
	 * text straight through).
	 */
	private function targetSection(): int {
		return max( 0, $this->getRequest()->getInt( 'wbsection', 0 ) );
	}
}
