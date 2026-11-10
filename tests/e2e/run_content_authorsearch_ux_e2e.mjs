#!/usr/bin/env node
/**
 * UX E2E for the author-name panel on the content Add* pages and
 * Special:Upload (READ-ONLY: it confirms a match, which only writes the
 * Q-id into the form field — no item is created or saved).
 *
 * Asserts:
 *   - Special:AddQuotation renders the panel; searching a locally resolvable
 *     name and confirming a candidate writes that Q-id into
 *     `wpattributedTo`;
 *   - Special:Upload shows the panel only while "Specify the author as a
 *     semantic entity" is checked;
 *   - zero page/console errors.
 *
 * Data-dependent: the candidate check needs one locally resolvable Person;
 * it is skipped (not failed) when the wiki has none of the sample names.
 *
 * Usage (from a directory where `playwright` is installed):
 *
 *     node run_content_authorsearch_ux_e2e.mjs --base-url https://wikibase.ronzz.org [--headed]
 *
 * CHROME_PATH can point at an existing chromium binary.
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
		|| text.includes('deprecated ResourceLoader module "mediawiki.ui.button"');
}

function randomToken() {
	return 'Qzx' + Math.random().toString(36).slice(2, 10);
}

async function waitForPanel(page, buttonId) {
	await page.waitForSelector('#' + buttonId, { timeout: 30000 });
	await page.waitForFunction(
		() => mw.embeddableContent
			&& mw.embeddableContent.authorsearch
			&& mw.embeddableContent.authorsearch.state,
		{ timeout: 30000 }
	);
}

async function searchAndWaitForRow(page, namesField, buttonId, name) {
	await page.fill(`textarea[name="${namesField}"]`, name);
	await page.click('#' + buttonId);
	await page.waitForFunction(
		({ button, expected }) => {
			const el = document.getElementById(button);
			const row = document.querySelector('.wb-author-row .wb-author-name');
			return el && !el.disabled && row && row.textContent.trim() === expected;
		},
		{ button: buttonId, expected: name },
		{ timeout: 30000 }
	);
	await page.waitForTimeout(200);
}

/** Find a sample name whose action=authorresolve returns a candidate. */
async function findCandidateName(page, samples) {
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
			return name;
		}
	}
	return null;
}

/** Confirm the first candidate of the currently open row dialog. */
async function confirmFirstCandidate(page) {
	await page.waitForSelector('.oo-ui-dialog:visible .oo-ui-radioOptionWidget', { timeout: 15000 });
	await page.locator('.oo-ui-dialog:visible .oo-ui-radioOptionWidget').first().click();
	// The Confirm button is the primary action (first in the action row).
	await page.locator('.oo-ui-dialog:visible .wb-author-dialog-actions .oo-ui-buttonElement').first().click();
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

	const samples = ['Ada Lovelace', 'Albert Einstein', 'Kainan Jarrette', 'Diana Daly'];

	try {
		// -------------------------------------------------- AddQuotation panel
		await page.goto(`${BASE_URL}/wiki/Special:AddQuotation`, {
			waitUntil: 'networkidle',
			timeout: 60000
		});
		await waitForPanel(page, 'wb-author-search');

		if ((await page.locator('textarea[name="wpauthorNames"]').count()) === 0) {
			failures.push('AddQuotation does not render the author-name panel');
		} else if ((await page.locator('input[name="wpattributedTo"]').count()) === 0) {
			failures.push('AddQuotation does not render the attributed-to field');
		} else {
			console.log('[ok] AddQuotation renders the author-name panel + attributed-to field');
		}

		const candidateName = await findCandidateName(page, samples);
		if (!candidateName) {
			console.log('[skip] no locally resolvable sample author — content confirm check skipped');
		} else {
			await searchAndWaitForRow(page, 'wpauthorNames', 'wb-author-search', candidateName);
			await page.locator('.wb-author-row .wb-author-parsed').first().click();
			await page.waitForTimeout(400);
			await confirmFirstCandidate(page);
			const value = await page.inputValue('input[name="wpattributedTo"]');
			if (!/^\s*Q[1-9]\d*\s*$/.test(value)) {
				failures.push(`confirming ${candidateName} did not write an item id into attributedTo: ${JSON.stringify(value)}`);
			} else {
				console.log(`[ok] confirmed content author writes ${value.trim()} into attributedTo`);
			}
		}

		// ------------------------------------------------------ Upload panel
		await page.goto(`${BASE_URL}/wiki/Special:Upload`, {
			waitUntil: 'networkidle',
			timeout: 60000
		});
		const checkbox = page.locator('#wpUploadAuthorIsEntity');
		if ((await checkbox.count()) === 0) {
			failures.push('Special:Upload has no semantic-author checkbox');
		} else {
			const panelButton = page.locator('#wb-UploadAuthor-search');
			if ((await panelButton.count()) === 0) {
				failures.push('Special:Upload does not render the author-name panel');
			} else {
				if (await panelButton.isVisible()) {
					failures.push('the upload author panel is visible before the semantic-entity checkbox is ticked');
				} else {
					console.log('[ok] upload author panel hidden in plain-text author mode');
				}
				await checkbox.check();
				await page.waitForTimeout(300);
				if (!(await panelButton.isVisible())) {
					failures.push('the upload author panel did not appear when the semantic-entity checkbox was ticked');
				} else if (!(await page.locator('textarea[name="wpUploadAuthorNames"]').isVisible())) {
					failures.push('the upload author-name field is not visible in semantic-entity mode');
				} else {
					console.log('[ok] upload author panel appears in semantic-entity mode');
				}
				await checkbox.uncheck();
				await page.waitForTimeout(300);
				if (await panelButton.isVisible()) {
					failures.push('the upload author panel did not hide when the checkbox was unticked');
				} else {
					console.log('[ok] upload author panel hides again in plain-text mode');
				}
			}
		}

		// A nonsense name must not throw (the panel's error path).
		const noise = 'Zztest ' + randomToken();
		await page.goto(`${BASE_URL}/wiki/Special:AddQuotation`, {
			waitUntil: 'networkidle',
			timeout: 60000
		});
		await waitForPanel(page, 'wb-author-search');
		await searchAndWaitForRow(page, 'wpauthorNames', 'wb-author-search', noise);
		const status = await page.locator('.wb-author-row .wb-author-status').first().innerText();
		if (!/No local match found/i.test(status)) {
			failures.push(`expected the no-match status for ${noise}, got ${JSON.stringify(status)}`);
		} else {
			console.log('[ok] content author panel reports an unmatched name without errors');
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
		console.error(`content authorsearch UX E2E FAILED:\n - ${failures.join('\n - ')}`);
		process.exit(1);
	}
	console.log('content authorsearch UX E2E: all checks passed');
}

main().catch((err) => {
	console.error('content authorsearch UX E2E FAILED:', err);
	process.exit(1);
});
