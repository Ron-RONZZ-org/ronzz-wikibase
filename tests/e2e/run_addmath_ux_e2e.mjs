#!/usr/bin/env node
/**
 * UX E2E for the Special:AddMath KaTeX live preview — Playwright browser
 * suite. The preview renders CLIENT-SIDE (vendored KaTeX), which the
 * curl-based suites cannot see; this suite loads the real page and asserts:
 *
 *   - the Preview button exists and window.katex is loaded
 *   - the delimiter auto-strip works for $…$, $$…$$, \(…\) and \[…\] —
 *     INCLUDING a payload with surrounding whitespace (a pasted leading /
 *     trailing blank line), matching the server-side submit normalization
 *   - a malformed expression shows the TeX renderer's own error message
 *     (throwOnError) instead of a silently blank/stale box
 *   - zero tolerance for page errors and console errors
 *
 * No login is needed: page loads are open and the preview is client-side
 * only (the submit is login-gated, so this suite never creates an item).
 *
 * Usage (run from a directory where `playwright` is installed):
 *
 *     node run_addmath_ux_e2e.mjs --base-url https://wikibase.ronzz.org \
 *         [--headed]
 *
 * CHROME_PATH can point at an existing chromium binary (skips the managed
 * browser download).
 *
 * Exit code 0 = all checks passed.
 *
 * License: GPL-2.0-or-later
 */

import { chromium } from 'playwright';

function arg(name, def) {
	const i = process.argv.indexOf(name);
	return i >= 0 ? process.argv[i + 1] : def;
}

const BASE_URL = arg('--base-url', 'https://wikibase.ronzz.org');
const HEADED = process.argv.includes('--headed');
const CHROME_PATH = process.env.CHROME_PATH || undefined;

const failures = [];
const pageErrors = [];
const consoleErrors = [];

function ignoredConsole(msg) {
	const text = msg.text();
	const url = (msg.location && msg.location().url) || '';
	return text.toLowerCase().includes('favicon.ico')
		|| url.toLowerCase().includes('favicon.ico')
		|| text.includes('jquery.ui')
		|| text.includes('No version information available for component');
}

const INPUT = '#mw-input-wppayload textarea, #mw-input-wppayload input';

/**
 * Type a payload into the form, click Preview, and return the preview state.
 */
async function preview(page, tex) {
	const input = page.locator(INPUT).first();
	await input.fill(tex);
	await page.click('#wb-math-preview');
	await page.waitForTimeout(400);
	return page.evaluate(() => {
		const box = document.getElementById('wb-math-preview-box');
		const content = document.getElementById('wb-math-preview-content');
		return {
			hidden: box.hidden,
			isError: box.classList.contains('wb-math-preview-error'),
			html: content.innerHTML,
			text: content.textContent,
		};
	});
}

async function main() {
	const browser = await chromium.launch({ headless: !HEADED, executablePath: CHROME_PATH });
	const page = await browser.newPage();
	page.on('pageerror', (err) => pageErrors.push(String(err)));
	page.on('console', (msg) => {
		if ((msg.type() === 'error' || msg.type() === 'warning') && !ignoredConsole(msg)) {
			consoleErrors.push(`[${msg.type()}] ${msg.text()}`);
		}
	});

	try {
		await page.goto(`${BASE_URL}/wiki/Special:AddMath`, { waitUntil: 'domcontentloaded', timeout: 60000 });
		await page.waitForSelector('#wb-math-preview', { timeout: 30000 });

		const katex = await page.evaluate(() => typeof window.katex !== 'undefined');
		if (!katex) {
			failures.push('window.katex is not loaded on Special:AddMath');
		}
		console.log('[ok] Preview button + KaTeX present');

		// The two note fields (rich wikitext) surround the Content field:
		// the Pre-content note sits ABOVE, the Post-content note BELOW.
		const preNoteCount = await page.locator('#mw-input-wppreNote').count();
		const postNoteCount = await page.locator('#mw-input-wpnote').count();
		if (preNoteCount !== 1 || postNoteCount !== 1) {
			failures.push(`Special:AddMath is missing a note field (pre=${preNoteCount}, post=${postNoteCount})`);
		} else {
			console.log('[ok] pre/post-content note fields present');
			const order = await page.evaluate(() => {
				const pre = document.getElementById('mw-input-wppreNote');
				const payload = document.getElementById('mw-input-wppayload');
				const note = document.getElementById('mw-input-wpnote');
				if (!pre || !payload || !note) {
					return null;
				}
				const follows = (a, b) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
				return {
					preBeforeContent: follows(pre, payload),
					contentBeforeNote: follows(payload, note),
				};
			});
			if (!order || !order.preBeforeContent) {
				failures.push('the Pre-content note field does not sit above the Content field');
			} else if (!order.contentBeforeNote) {
				failures.push('the Post-content note field does not sit below the Content field');
			} else {
				console.log('[ok] Pre-content note above Content, Post-content note below');
			}
		}

		// (label, payload, forbidden substrings in the rendered text)
		const cases = [
			['dollar-inline', '$x^2 + y^2$', ['$']],
			['dollar-display', '$$x^2 + y^2$$', ['$']],
			['paren', '\\(x^2 + y^2\\)', ['\\']],
			['bracket', '\\[x^2 + y^2\\]', ['\\']],
			['surrounding whitespace', '\n$$x^2 + y^2$$\n', ['$']],
		];
		for (const [name, tex, forbidden] of cases) {
			const before = failures.length;
			const state = await preview(page, tex);
			if (state.hidden) {
				failures.push(`${name}: preview box stayed hidden`);
			}
			if (state.isError) {
				failures.push(`${name}: rendered as an error (${state.text})`);
			}
			if (!state.html.includes('katex')) {
				failures.push(`${name}: KaTeX did not render (${state.html.slice(0, 120)})`);
			}
			for (const needle of forbidden) {
				if (state.text.includes(needle)) {
					failures.push(`${name}: delimiter ${JSON.stringify(needle)} survived in the preview`);
				}
			}
			if (failures.length === before) {
				console.log(`[ok] preview ${name}: delimiters stripped + KaTeX rendered`);
			}
		}

		const bad = await preview(page, '$\\frac{1}{$');
		if (!bad.isError) {
			failures.push('malformed TeX: expected the error state (.wb-math-preview-error)');
		}
		if (!/parse error|parseerror|undefined control sequence/i.test(bad.text)) {
			failures.push(`malformed TeX: no renderer error message shown (${bad.text.slice(0, 160)})`);
		}
		console.log(`[ok] malformed TeX shows the renderer error: ${bad.text.slice(0, 80)}`);

		// The preview shows the PRE-content Note (above), the Content
		// (KaTeX) and the POST-content Note (below); both notes render as
		// WIKITEXT (through action=parse) and their inline $…$ is typeset.
		await page.evaluate(() => {
			const note = document.querySelector('#mw-input-wpnote textarea, #mw-input-wpnote input');
			if (note) {
				note.value = "where $a$ is a '''constant''' and a [[Main Page|reference]]";
				note.dispatchEvent(new Event('input', { bubbles: true }));
			}
			const pre = document.querySelector('#mw-input-wppreNote textarea, #mw-input-wppreNote input');
			if (pre) {
				pre.value = 'above the expression with $b$';
				pre.dispatchEvent(new Event('input', { bubbles: true }));
			}
		});
		await page.locator(INPUT).first().fill('$a^2 + b^2 = c^2$');
		await page.click('#wb-math-preview');
		// The note HTML arrives asynchronously (action=parse).
		await page.waitForFunction(() => {
			const note = document.getElementById('wb-math-preview-note');
			const pre = document.getElementById('wb-math-preview-prenote');
			return note && note.textContent.includes('constant')
				&& pre && pre.textContent.includes('above');
		}, { timeout: 15000 });
		const noteState = await page.evaluate(() => {
			const wrap = document.getElementById('wb-math-preview-note-wrap');
			const note = document.getElementById('wb-math-preview-note');
			const preWrap = document.getElementById('wb-math-preview-prenote-wrap');
			const pre = document.getElementById('wb-math-preview-prenote');
			const content = document.getElementById('wb-math-preview-content');
			const follows = (a, b) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
			return {
				hidden: wrap ? wrap.hidden : true,
				text: note ? note.textContent : '',
				katex: note ? note.querySelectorAll('.katex').length : 0,
				bold: note ? note.querySelectorAll('b').length : 0,
				link: note ? note.querySelectorAll('a').length : 0,
				preHidden: preWrap ? preWrap.hidden : true,
				preText: pre ? pre.textContent : '',
				preKatex: pre ? pre.querySelectorAll('.katex').length : 0,
				preAboveContent: (pre && content) ? follows(pre, content) : false,
			};
		});
		if (noteState.hidden) {
			failures.push('the note preview block stayed hidden');
		} else if (!noteState.text.includes('constant')) {
			failures.push(`the note preview did not show the note (${JSON.stringify(noteState.text)})`);
		} else if (noteState.katex === 0) {
			failures.push('the note preview did not typeset the inline $…$ math');
		} else if (noteState.bold === 0) {
			failures.push('the note preview did not render wikitext (no <b> from the bold markup)');
		} else if (noteState.link === 0) {
			failures.push('the note preview did not render the [[Main Page|reference]] link');
		} else {
			console.log('[ok] preview shows the Content and the wikitext Note');
		}
		if (noteState.preHidden) {
			failures.push('the pre-content note preview block stayed hidden');
		} else if (!noteState.preText.includes('above')) {
			failures.push(`the pre-content note preview did not show the note (${JSON.stringify(noteState.preText)})`);
		} else if (noteState.preKatex === 0) {
			failures.push('the pre-content note preview did not typeset its inline $…$ math');
		} else if (!noteState.preAboveContent) {
			failures.push('the pre-content note preview is not above the expression');
		} else {
			console.log('[ok] preview shows the pre-content Note above the expression');
		}

		// Regression (note `$$…$$` report): a DISPLAY delimiter inside the
		// Note must render as one display KaTeX span, not be mis-read as the
		// inner `$…$` plus two literal `$` (the inline-only matcher bug).
		await page.evaluate(() => {
			const note = document.querySelector('#mw-input-wpnote textarea, #mw-input-wpnote input');
			if (note) {
				note.value = 'inline $a$ and display $$ b = c $$';
				note.dispatchEvent(new Event('input', { bubbles: true }));
			}
		});
		await page.locator(INPUT).first().fill('$x^2$');
		await page.click('#wb-math-preview');
		await page.waitForFunction(() => {
			const note = document.getElementById('wb-math-preview-note');
			return note && note.querySelectorAll('.katex').length >= 2;
		}, { timeout: 15000 });
		const noteDelims = await page.evaluate(() => {
			const note = document.getElementById('wb-math-preview-note');
			return {
				text: note ? note.textContent : '',
				display: note ? note.querySelectorAll('.katex-display').length : 0,
			};
		});
		if (noteDelims.display < 1) {
			failures.push(`note $$…$$ did not render as display math (display spans: ${noteDelims.display})`);
		} else if (noteDelims.text.includes('$')) {
			failures.push(`note $$…$$ left a literal $ in the preview (${JSON.stringify(noteDelims.text)})`);
		} else {
			console.log('[ok] note $$…$$ renders as display math with no literal $');
		}
	} finally {
		await browser.close();
	}

	if (pageErrors.length) failures.push(`page errors: ${pageErrors.join(' | ')}`);
	if (consoleErrors.length) failures.push(`console errors: ${consoleErrors.join(' | ')}`);

	if (failures.length) {
		console.error(`addmath UX E2E FAILED:\n - ${failures.join('\n - ')}`);
		process.exit(1);
	}
	console.log('addmath UX E2E: all checks passed');
}

main().catch((err) => {
	console.error('addmath UX E2E FAILED:', err);
	process.exit(1);
});
