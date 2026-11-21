/* Disable the TOAST UI Image Editor's Google Analytics usage ping BEFORE the
 * bundle loads (see resources/tui-image-editor/VENDORED.md). */
( function () {
	'use strict';
	window.tui = window.tui || {};
	window.tui.usageStatistics = false;
}() );
