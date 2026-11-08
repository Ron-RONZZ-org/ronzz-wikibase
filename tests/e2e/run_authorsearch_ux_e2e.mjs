#!/usr/bin/env node
/**
 * UX E2E for the Special:AddSource "Search for authors" panel — Playwright
 * browser suite (READ-ONLY: it opens dialogs but never submits a creation).
 *
 * Regression coverage for the broken OOUI dialogs:
 *   - every author row was inert because the dialog used a bare
 *     `OO.ui.Dialog` (no static name, `$body` created only on open), so the
 *     click threw `Cannot read properties of undefined (reading 'append')`
 *     and no dialog appeared;
 *   - the "No local match found — add one" status was a plain <span>, so
 *     clicking the visible action did nothing.
 *
 * Asserts:
 *   - an unmatched name shows the "add one" status, and clicking that text
 *     opens the "Add person" creation dialog;
 *   - clicking a candidate ROW (not just its name button) opens the
 *     "Confirm author" dialog;
 *   - Cancel closes the dialog again;
 *   - zero page errors and console errors.
 *
 * Data-dependent: the candidate check needs one locally resolvable Person;
 * it is skipped (not failed) when the wiki has none of the sample names.
 *
 * Usage (from a directory where `playwright` is installed):
 *
 *     node run_authorsearch_ux_e2e.mjs --base-url https://wikibase.ronzz.org \
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
		|| text.includes('No version information available for component')
		// Pre-existing MediaWiki core/skin deprecation, unrelated to the
		// author panel (it is emitted on the stock manual form).
		|| text.includes('deprecated ResourceLoader module "mediawiki.ui.button"');
}

function randomToken() {
	return 'Qzx' + Math.random().toString(36).slice(2, 10);
}

async function dialogCount(page) {
	return page.locator('.oo-ui-dialog:visible').count();
}

async function dialogText(page) {
	return page.locator('.oo-ui-dialog:visible').first().innerText().catch(() => '');
}

async function closeDialog(page) {
	// The Cancel button is the last one in the body action row for every
	// author dialog (language-independent locator).
	const actions = page.locator('.oo-ui-dialog:visible .wb-author-dialog-actions .oo-ui-buttonElement');
	if (await actions.count()) {
		await actions.last().click();
	}
	await page.waitForTimeout(500);
}

async function main() {
	const browser = await chromium.launch({
		headless: !HEADED,
		executablePath: CHROME_PATH
	});
	const page = await browser.newPage();
	page.on('pageerror', (err) => pageErrors.push(String(err)));
	page.on('console', (msg) => {
		if ((msg.type() === 'error' || msg.type() === 'warning') && !ignoredConsole(msg)) {
			consoleErrors.push(`[${msg.type()}] ${msg.text()}`);
		}
	});

	try {
		await page.goto(`${BASE_URL}/wiki/Special:AddSource/book/manual`, {
			waitUntil: 'networkidle',
			timeout: 60000
		});
		await page.waitForSelector('#wb-author-search', { timeout: 30000 });
		// The panel is wired after ResourceLoader loads the module; clicking
		// the server-rendered button before that is a no-op.
		await page.waitForFunction(
			() => mw.embeddableContent
				&& mw.embeddableContent.authorsearch
				&& mw.embeddableContent.authorsearch.state,
			{ timeout: 30000 }
		);

		// ---------------------------------------------------------- unmatched
		const unknown = 'Zztest ' + randomToken();
		await page.fill('textarea[name="wpauthorNames"]', unknown);
		await page.click('#wb-author-search');
		await page.waitForSelector('.wb-author-row', { timeout: 30000 });

		const status = await page.locator('.wb-author-row .wb-author-status').first().innerText();
		if (!/No local match found/i.test(status)) {
			failures.push(`expected the no-match status for ${unknown}, got ${JSON.stringify(status)}`);
		} else {
			console.log('[ok] unmatched row renders the "add one" status');
		}

		// Click the STATUS text itself — the reported dead affordance.
		await page.locator('.wb-author-row .wb-author-status').first().click();
		await page.waitForTimeout(600);
		if ((await dialogCount(page)) === 0) {
			failures.push('clicking the "add one" status did not open the create dialog');
		} else {
			const text = await dialogText(page);
			if (!/Add person/i.test(text)) {
				failures.push(`the create dialog is not "Add person": ${JSON.stringify(text.slice(0, 160))}`);
			} else {
				console.log('[ok] "add one" opens the create-person dialog');
			}
			await closeDialog(page);
			if ((await dialogCount(page)) !== 0) {
				failures.push('the create dialog did not close on Cancel');
			} else {
				console.log('[ok] Cancel closes the create dialog');
			}
		}

		// ---------------------------------------------------------- candidate
		const samples = [ 'Ada Lovelace', 'Albert Einstein', 'Kainan Jarrette', 'Diana Daly' ];
		let candidateName = null;
		for (const name of samples) {
			const count = await page.evaluate(async (n) => {
				const url = mw.util.wikiScript('api') + '?action=authorresolve&format=json&names='
					+ encodeURIComponent(n);
				const response = await fetch(url, { credentials: 'same-origin' });
				const data = await response.json();
				const row = data.authorresolve && data.authorresolve.names && data.authorresolve.names[0];
				return (row && row.candidates && row.candidates.length) || 0;
			}, name);
			if (count > 0) {
				candidateName = name;
				break;
			}
		}

		if (!candidateName) {
			console.log('[skip] no locally resolvable sample author — candidate-row check skipped');
		} else {
			await page.fill('textarea[name="wpauthorNames"]', candidateName);
			await page.click('#wb-author-search');
			await page.waitForSelector('.wb-author-row', { timeout: 30000 });
			await page.waitForTimeout(400);

			// Click the PARSED span, not the name button: proves the whole
			// row is the activation target.
			await page.locator('.wb-author-row .wb-author-parsed').first().click();
			await page.waitForTimeout(600);
			if ((await dialogCount(page)) === 0) {
				failures.push(`clicking a candidate row (${candidateName}) did not open a dialog`);
			} else {
				const text = await dialogText(page);
				if (!/Confirm author/i.test(text)) {
					failures.push(`the candidate dialog is not "Confirm author": ${JSON.stringify(text.slice(0, 160))}`);
				} else {
					console.log(`[ok] clicking a candidate row opens the confirmation dialog (${candidateName})`);
				}
				await closeDialog(page);
			}
		}
	} finally {
		await browser.close();
	}

	if (pageErrors.length) {
		failures.push(`page errors: ${pageErrors.join(' | ')}`);
	}
	if (consoleErrors.length) {
		failures.push(`console errors: ${consoleErrors.join(' | ')}`);
	}

	if (failures.length) {
		console.error(`authorsearch UX E2E FAILED:\n - ${failures.join('\n - ')}`);
		process.exit(1);
	}
	console.log('authorsearch UX E2E: all checks passed');
}

main().catch((err) => {
	console.error('authorsearch UX E2E FAILED:', err);
	process.exit(1);
});
