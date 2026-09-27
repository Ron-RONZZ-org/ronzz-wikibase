#!/usr/bin/env node
/**
 * Browser UX E2E for the Sep-2026 UX batch B — Playwright suite (manual /
 * production, like run_math_ux_e2e.mjs). The curl E2E asserts the wiring;
 * this suite exercises the client-side behaviour:
 *
 *   - /File:xxx — the two copy buttons (inline right of the title) copy
 *     `[[File:xxx]]` and the direct media URL to the clipboard;
 *   - Special:AddSource — the source-type picker is alphabetical;
 *   - Special:AddCollective/manual — the class select shows human labels,
 *     alphabetical;
 *   - Special:Upload — the file picker is disabled unless the "Source
 *     filename" (File) radio is selected; an empty Author/License shows a
 *     cancellable warning; the "Submit and upload another" button carries
 *     name=wpUpload value=another and opens the upload in a new tab.
 *
 * Usage (from a directory with `playwright` installed):
 *
 *     node run_wiki_ux_e2e.mjs --base-url https://wikibase.ronzz.org \
 *         --user SeedBot --password-file seed/.seedbot.pass [--headed]
 *
 * A real login is needed for Special:Upload. CHROME_PATH can point at an
 * existing chromium binary (skips the managed-browser download).
 *
 * Exit code 0 = all checks passed.
 *
 * License: GPL-2.0-or-later
 */

import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';

function arg(name, def) {
	const i = process.argv.indexOf(name);
	return i >= 0 ? process.argv[i + 1] : def;
}

const BASE_URL = arg('--base-url', 'https://wikibase.ronzz.org');
const API_URL = arg('--api-url', BASE_URL + '/api.php');
const USER = arg('--user');
const PASSWORD_FILE = arg('--password-file');
const HEADED = process.argv.includes('--headed');
const CHROME_PATH = process.env.CHROME_PATH || undefined;

if (!USER || !PASSWORD_FILE) {
	console.error('run_wiki_ux_e2e.mjs: --user and --password-file are required '
		+ '(Special:Upload needs a real login)');
	process.exit(2);
}
const PASSWORD = readFileSync(PASSWORD_FILE, 'utf8').trim();

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

async function api(params) {
	const res = await fetch(API_URL + '?' + new URLSearchParams(params), {
		headers: { 'User-Agent': 'ronzz-wikibase-wiki-ux-e2e/1.0' },
	});
	return res.json();
}

async function main() {
	// Pick an existing file for the /File: page checks.
	const images = await api({ action: 'query', list: 'allimages', ailimit: '1', format: 'json' });
	const file = images.query?.allimages?.[0];
	if (!file) {
		console.error('run_wiki_ux_e2e.mjs: no File: page on the instance to test');
		process.exit(2);
	}
	const fileName = file.name;
	console.log(`[info] testing with File:${fileName}`);

	const browser = await chromium.launch({ headless: !HEADED, executablePath: CHROME_PATH });
	const context = await browser.newContext();
	await context.grantPermissions([ 'clipboard-read', 'clipboard-write' ], { origin: BASE_URL });
	const page = await context.newPage();
	page.on('pageerror', (err) => pageErrors.push(String(err)));
	page.on('console', (msg) => {
		if ((msg.type() === 'error' || msg.type() === 'warning') && !ignoredConsole(msg)) {
			consoleErrors.push(`[${msg.type()}] ${msg.text()}`);
		}
	});

	try {
		// --- /File:xxx copy buttons -------------------------------------
		await page.goto(`${BASE_URL}/wiki/File:${encodeURIComponent(fileName.replace(/ /g, '_'))}`,
			{ waitUntil: 'domcontentloaded', timeout: 60000 });
		await page.waitForSelector('#ca-wb-file-copyembed', { timeout: 15000 });
		if (await page.locator('#ca-wb-file-copylink').count() !== 1) {
			failures.push('File: page missing the "Copy direct link" button');
		}
		// The buttons are inline inside the title (not a toolbar row below).
		const inTitle = await page.locator('#firstHeading #ca-wb-file-copyembed').count();
		if (inTitle !== 1) {
			failures.push('File: page copy buttons are not inline inside the title');
		}
		await page.locator('#ca-wb-file-copyembed').click();
		const copiedSnippet = await page.evaluate(() => navigator.clipboard.readText());
		if (copiedSnippet !== `[[File:${fileName}]]`) {
			failures.push(`copy-embed copied ${JSON.stringify(copiedSnippet)}, expected [[File:${fileName}]]`);
		} else {
			console.log('[ok] File: copy-embed button copies the [[File:…]] snippet');
		}
		await page.locator('#ca-wb-file-copylink').click();
		const copiedUrl = await page.evaluate(() => navigator.clipboard.readText());
		if (!copiedUrl.includes('/images/')) {
			failures.push(`copy-link copied ${JSON.stringify(copiedUrl)}, expected a /images/ media URL`);
		} else {
			console.log('[ok] File: copy-link button copies the direct media URL');
		}

		// --- Special:AddSource picker order -----------------------------
		await page.goto(`${BASE_URL}/wiki/Special:AddSource`, { waitUntil: 'domcontentloaded', timeout: 60000 });
		// The radio options are rendered CLIENT-SIDE by the OOUI auto-infusion
		// (the server HTML only carries data-ooui) — wait for an infused
		// option, not the pre-infusion wrapper.
		await page.waitForSelector('#mw-input-wpclass .oo-ui-radioOptionWidget', { timeout: 15000 });
		const sourceLabels = await page.locator('#mw-input-wpclass .oo-ui-radioOptionWidget .oo-ui-labelElement-label')
			.allInnerTexts();
		const sourceLabelsTrimmed = sourceLabels.map((s) => s.trim()).filter(Boolean);
		if (sourceLabelsTrimmed.length < 10) {
			failures.push(`AddSource picker rendered only ${sourceLabelsTrimmed.length} options`);
		} else if (JSON.stringify(sourceLabelsTrimmed) !== JSON.stringify([...sourceLabelsTrimmed].sort((a, b) => a.toLowerCase().localeCompare(b.toLowerCase())))) {
			failures.push(`AddSource picker not alphabetical: ${JSON.stringify(sourceLabelsTrimmed)}`);
		} else {
			console.log('[ok] Special:AddSource picker is alphabetical');
		}

		// --- Special:AddCollective class select -------------------------
		await page.goto(`${BASE_URL}/wiki/Special:AddCollective/manual`, { waitUntil: 'domcontentloaded', timeout: 60000 });
		await page.waitForSelector('#mw-input-wpclass option', { timeout: 15000 });
		const classLabels = (await page.locator('#mw-input-wpclass option').allInnerTexts())
			.map((s) => s.trim()).filter(Boolean);
		if (classLabels.includes('groupOfHumans')) {
			failures.push('AddCollective class select still shows raw camelCase keys');
		} else if (!classLabels.includes('Intergovernmental organization')) {
			failures.push(`AddCollective class select missing human labels: ${JSON.stringify(classLabels)}`);
		} else if (JSON.stringify(classLabels) !== JSON.stringify([...classLabels].sort((a, b) => a.toLowerCase().localeCompare(b.toLowerCase())))) {
			failures.push(`AddCollective class select not alphabetical: ${JSON.stringify(classLabels)}`);
		} else {
			console.log('[ok] Special:AddCollective class select: human labels, alphabetical');
		}

		// --- Special:Upload (login) -------------------------------------
		await page.goto(`${BASE_URL}/wiki/Special:UserLogin`, { waitUntil: 'domcontentloaded', timeout: 60000 });
		await page.fill('#wpName1', USER);
		await page.fill('#wpPassword1', PASSWORD);
		await Promise.all([
			page.waitForLoadState('domcontentloaded'),
			page.click('#wpLoginAttempt'),
		]);
		await page.goto(`${BASE_URL}/wiki/Special:Upload`, { waitUntil: 'domcontentloaded', timeout: 60000 });
		await page.waitForSelector('#mw-upload-form', { timeout: 15000 });

		// Source gating: Url is the fresh-load default → the file picker is
		// disabled; selecting the File radio enables it.
		if (await page.locator('#wpUploadFile').isEnabled()) {
			failures.push('Special:Upload file picker is enabled while Url is selected');
		} else {
			console.log('[ok] Special:Upload file picker disabled under the Url source');
		}
		await page.check('#wpSourceTypeFile');
		if (!(await page.locator('#wpUploadFile').isEnabled())) {
			failures.push('Special:Upload file picker did not enable after selecting the File radio');
		} else {
			console.log('[ok] Special:Upload file picker enables with the File source');
		}

		// Empty Author/License warning: cancelling the confirm must keep the
		// page (no navigation). A persistent dialog handler accepts later
		// dialogs (the "upload another" click may warn again).
		await page.check('#wpSourceTypeurl');
		await page.fill('#wpUploadAuthor', '');
		let dialogCount = 0;
		let dialogAction = 'dismiss';
		page.on('dialog', (d) => {
			dialogCount++;
			if (dialogAction === 'accept') {
				d.accept();
			} else {
				d.dismiss();
			}
		});
		dialogCount = 0;
		await page.click('input[name="wpUpload"]');
		await page.waitForTimeout(600);
		if (dialogCount === 0) {
			failures.push('Special:Upload did not warn on an empty Author/License submit');
		} else if (!page.url().includes('Special:Upload')) {
			failures.push('Special:Upload navigated despite cancelling the empty-field warning');
		} else {
			console.log('[ok] Special:Upload warns (cancellable) on empty Author/License');
		}

		// "Submit and upload another": the button carries name=wpUpload
		// value=another and opens the upload in a new tab (window.open), so
		// the current tab stays on the form.
		const anotherName = await page.locator('#wpUploadAnother').getAttribute('name');
		const anotherValue = await page.locator('#wpUploadAnother').getAttribute('value');
		if (anotherName !== 'wpUpload' || anotherValue !== 'another') {
			failures.push(`"upload another" button carries name=${anotherName} value=${anotherValue}, expected wpUpload/another`);
		}
		dialogAction = 'accept';
		const popupPromise = page.waitForEvent('popup', { timeout: 10000 }).catch(() => null);
		await page.click('#wpUploadAnother');
		const popup = await popupPromise;
		if (!popup) {
			failures.push('"upload another" did not open the upload in a new tab');
		} else {
			console.log('[ok] "upload another" opens the upload in a new tab');
			await popup.close();
		}
	} finally {
		await browser.close();
	}

	if (pageErrors.length) failures.push(`page errors: ${pageErrors.join(' | ')}`);
	if (consoleErrors.length) failures.push(`console errors: ${consoleErrors.join(' | ')}`);

	if (failures.length) {
		console.error(`wiki UX E2E FAILED:\n - ${failures.join('\n - ')}`);
		process.exit(1);
	}
	console.log('wiki UX E2E: all checks passed');
}

main().catch((err) => {
	console.error('wiki UX E2E FAILED:', err);
	process.exit(1);
});
