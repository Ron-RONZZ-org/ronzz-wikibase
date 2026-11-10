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

import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

function arg(name, def) {
	const i = process.argv.indexOf(name);
	return i >= 0 ? process.argv[i + 1] : def;
}

const BASE_URL = arg('--base-url', 'https://wikibase.ronzz.org');
const HEADED = process.argv.includes('--headed');
const CHROME_PATH = process.env.CHROME_PATH || undefined;
// Optional login: the search-first create dialog auto-submits the external
// authority search, which is login-gated. Without credentials the run still
// verifies the search STEP is opened (not /manual) and the manual fallback
// link is rendered.
const USER = arg('--user', '');
const PASSWORD_FILE = arg('--password-file', '');

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

async function login(page) {
	if (!USER || !PASSWORD_FILE) {
		return false;
	}
	const password = readFileSync(PASSWORD_FILE, 'utf8').trim();
	if (!password) {
		return false;
	}
	await page.goto(`${BASE_URL}/wiki/Main_Page`, { waitUntil: 'domcontentloaded', timeout: 60000 });
	const ok = await page.evaluate(async ({ user, pass }) => {
		try {
			await new mw.Api().login(user, pass);
			return mw.user.isAnon() === false;
		} catch (e) {
			return false;
		}
	}, { user: USER, pass: password });
	if (!ok) {
		failures.push(`login failed for ${USER}`);
	}
	return ok;
}

/**
 * Run an author search and wait until the results have been REBUILT for it.
 * The search button is disabled while action=authorresolve runs, and the
 * previous search's rows stay in the DOM until then — waiting only for
 * `.wb-author-row` returns the stale row (the first run clicked the old
 * "add one" row during the candidate check).
 */
async function searchAndWaitForRow(page, name) {
	await page.fill('textarea[name="wpauthorNames"]', name);
	await page.click('#wb-author-search');
	await page.waitForFunction(
		(expected) => {
			const button = document.getElementById('wb-author-search');
			const row = document.querySelector('.wb-author-row .wb-author-name');
			return button && !button.disabled && row && row.textContent.trim() === expected;
		},
		name,
		{ timeout: 30000 }
	);
	await page.waitForTimeout(200);
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
		await login(page);
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
		await searchAndWaitForRow(page, unknown);

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
			// SEARCH-FIRST: the iframe runs Special:AddPerson (the search
			// step), never /manual. The row's name rides `?name=` and the
			// external-authority search auto-submits (`?autofetch=1`).
			const iframe = page.locator('.oo-ui-dialog:visible iframe.wb-parentdialog-iframe').first();
			if ((await iframe.count()) === 0) {
				failures.push('the create dialog did not render the parent-dialog iframe');
			} else {
				const src = await iframe.getAttribute('src');
				if (!/Special:AddPerson/.test(src || '') || /\/manual/.test(src || '')) {
					failures.push(`create dialog does not open the search step: ${JSON.stringify(src)}`);
				} else if (!/[?&]name=/.test(src || '') || !/[?&]autofetch=1/.test(src || '')) {
					failures.push(`create dialog did not prefill/auto-search the name: ${JSON.stringify(src)}`);
				} else {
					console.log('[ok] create dialog opens the AddPerson search step with the name + autofetch');
				}
				// The manual fallback must stay reachable: the search page,
				// the zero-hit page and the selection page all render it.
				try {
					await page.frameLocator('iframe.wb-parentdialog-iframe')
						.locator('a[href*="Special:AddPerson/manual"]')
						.first()
						.waitFor({ timeout: 60000 });
					console.log('[ok] create dialog keeps the "create manually instead" link');
				} catch (e) {
					failures.push('create dialog lost the "create manually instead" link');
				}
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
			await searchAndWaitForRow(page, candidateName);

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
