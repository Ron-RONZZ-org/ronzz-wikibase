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

		// The accompanying note field (rich wikitext) renders on the form.
		if (await page.locator('#mw-input-wpnote').count() !== 1) {
			failures.push('Special:AddMath is missing the accompanying note field (#mw-input-wpnote)');
		} else {
			console.log('[ok] accompanying note field present');
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
