#!/usr/bin/env node
/**
 * Browser UX E2E for the Sep-2026 UX batch B — Playwright suite (manual /
 * production, like run_math_ux_e2e.mjs). The curl E2E asserts the wiring;
 * this suite exercises the client-side behaviour:
 *
 *   - /File:xxx — the two copy buttons (inline right of the title) copy
 *     `[[File:xxx]]` and the direct media URL to the clipboard;
 *   - a content item's Item: page renders the fetched embed fragment
 *     directly below the entity toolbar (the dogfood math item);
 *   - Special:AddSource — the source-type picker is alphabetical;
 *   - Special:AddCollective/manual — the class select shows human labels,
 *     alphabetical;
 *   - Special:Upload — the file picker is disabled unless the "Source
 *     filename" (File) radio is selected; an empty Author/License shows a
 *     cancellable warning; the "Submit and upload another" button carries
 *     name=wpUpload value=another and opens the upload in a new tab; a
 *     `wbsourcetype` param restores the remembered source radio; a dropped
 *     or pasted image fills the picker + switches to File;
 *   - Printable version — a "Print this page" button opens the print popup
 *     (cover-page option); the printed title drops the namespace + subpage
 *     parent, the cover carries the centered title + authors, and the core
 *     sidebar "Printable version" link opens the same popup.
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

		// --- classic content page: "Copy internal mention" ---------------
		// The toolbar is inline inside the title (the File: page pattern) and
		// copies a [[Page name]] internal link. In the Main namespace the
		// first letter is lowercased unless the title is a proper name
		// (every word capitalized): "Main Page" stays as-is, while
		// "Classical mechanics" becomes [[classical mechanics]].
		const mentionCases = [
			[ 'Main_Page', '[[Main Page]]' ],
			[ 'Classical_mechanics', '[[classical mechanics]]' ],
		];
		for ( const [ pageName, expected ] of mentionCases ) {
			await page.goto(`${BASE_URL}/wiki/${pageName}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
			await page.waitForSelector('#ca-wb-content-copymention', { timeout: 15000 });
			if (await page.locator('#firstHeading #ca-wb-content-copymention').count() !== 1) {
				failures.push(`content-page copy-mention button is not inline inside the title (${pageName})`);
			}
			await page.locator('#ca-wb-content-copymention').click();
			const copiedRef = await page.evaluate(() => navigator.clipboard.readText());
			if (copiedRef !== expected) {
				failures.push(`copy-mention on ${pageName} copied ${JSON.stringify(copiedRef)}, expected ${expected}`);
			} else {
				console.log(`[ok] content-page copy-mention button copies ${expected} (${pageName})`);
			}
		}

		// --- classic content page: "Copy citation" popup -----------------
		// A classic content page offers "Copy citation" (citing the PAGE —
		// title + URL + last-revision date); clicking it opens the shared
		// citation popup with the APA (default) preview.
		await page.goto(`${BASE_URL}/wiki/Main_Page`, { waitUntil: 'domcontentloaded', timeout: 60000 });
		await page.waitForSelector('#ca-wb-content-copycite', { timeout: 15000 });
		await page.locator('#ca-wb-content-copycite').click();
		try {
			await page.waitForSelector('.wb-citation-popup', { state: 'attached', timeout: 15000 });
			await page.waitForFunction(() => {
				const el = document.querySelector('.wb-citation-popup-preview');
				return el && /http/.test(el.textContent);
			}, { timeout: 15000 });
			const citeText = await page.locator('.wb-citation-popup-preview').innerText();
			if (!citeText.includes('Main Page')) {
				failures.push(`citation popup preview missing the page title: ${JSON.stringify(citeText)}`);
			} else {
				console.log('[ok] content-page Copy citation popup shows the APA citation');
			}
		} catch (e) {
			failures.push('content-page Copy citation popup did not show a citation preview');
		}

		// --- Item: content-item rendered preview -------------------------
		// A content item (quotation/math/code) has no classic page; its
		// Item page must render the fetched embed fragment directly BELOW
		// the entity toolbar. The dogfood math item ("Euler's identity") is
		// the fixture.
		const found = await api({
			action: 'wbsearchentities', search: "Euler's identity", language: 'en',
			type: 'item', format: 'json',
		});
		const mathItem = (found.search || []).find((r) => r.label === "Euler's identity");
		if (!mathItem) {
			failures.push('content preview: dogfood math item "Euler\'s identity" not found');
		} else {
			await page.goto(`${BASE_URL}/wiki/${mathItem.id}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
			try {
				await page.waitForSelector('.wb-content-preview', { timeout: 20000 });
				const preview = await page.evaluate(() => {
					const el = document.querySelector('.wb-content-preview');
					const toolbar = document.querySelector('.wb-embed-toolbar');
					const below = toolbar && el
						? (toolbar.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0
						: false;
					return {
						embed: el ? el.querySelectorAll('.wb-embed').length : 0,
						below,
					};
				});
				if (preview.embed === 0) {
					failures.push('Item: content preview did not render the embed fragment');
				} else if (!preview.below) {
					failures.push('Item: content preview is not below the entity toolbar');
				} else {
					console.log(`[ok] ${mathItem.id} renders the embed preview below the entity toolbar`);
				}
			} catch (e) {
				failures.push('Item: content preview block never appeared');
			}
		}

		// --- File: page upload hand-off fallback -------------------------
		// Without clipboard-write permission the page-load auto-copy is
		// blocked, so the hand-off must render a persistent one-click copy
		// notice instead of silently doing nothing. This context grants NO
		// clipboard permission (the granted context above would mask it).
		const plainContext = await browser.newContext();
		const plainPage = await plainContext.newPage();
		plainPage.on('pageerror', (err) => pageErrors.push(String(err)));
		try {
			await plainPage.goto(
				`${BASE_URL}/wiki/File:${encodeURIComponent(fileName.replace(/ /g, '_'))}?wbuploadcopy=1`,
				{ waitUntil: 'domcontentloaded', timeout: 60000 });
			await plainPage.waitForSelector('#wb-uploadcopy-notice', { timeout: 15000 });
			if (await plainPage.locator('#wb-uploadcopy-copy').count() !== 1) {
				failures.push('upload hand-off fallback notice missing its copy button');
			} else {
				console.log('[ok] upload hand-off renders the one-click copy fallback when the clipboard is blocked');
			}
		} catch (e) {
			failures.push('upload hand-off did not render the clipboard-blocked copy fallback notice');
		} finally {
			await plainContext.close();
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
		// OOUI DropdownInputWidget = a VISUALLY HIDDEN native <select> (the
		// visible handle is a <span>), so its <option>s are never "visible"
		// to Playwright — wait for them ATTACHED (allInnerTexts reads them
		// regardless of visibility).
		await page.waitForSelector('#mw-input-wpclass option', { state: 'attached', timeout: 15000 });
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

		// Source memory ("upload another image from same author" hand-off):
		// a wbsourcetype query param restores the user's previous source
		// radio choice instead of resetting to the fresh-load default.
		await page.goto(`${BASE_URL}/wiki/Special:Upload?wbsourcetype=url`, { waitUntil: 'domcontentloaded', timeout: 60000 });
		if (!(await page.locator('#wpSourceTypeurl').isChecked())) {
			failures.push('Special:Upload?wbsourcetype=url did not restore the Url source');
		} else {
			console.log('[ok] Special:Upload restores the remembered Url source');
		}
		await page.goto(`${BASE_URL}/wiki/Special:Upload?wbsourcetype=file`, { waitUntil: 'domcontentloaded', timeout: 60000 });
		if (!(await page.locator('#wpSourceTypeFile').isChecked())) {
			failures.push('Special:Upload?wbsourcetype=file did not restore the File source');
		} else {
			console.log('[ok] Special:Upload restores the remembered File source');
		}
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

		// --- Image tools: local preview + resize + extension auto-correct ---
		// Purely client-side (nothing is submitted): the two new option
		// checkboxes default ON; selecting a large local file renders a
		// preview, downscales it to <= 2000 px, and rewrites wpDestFile's
		// extension from the file's real MIME type.
		const resizeChecked = await page.locator('#wpUploadResize').isChecked();
		const autoExtChecked = await page.locator('#wpUploadAutoExt').isChecked();
		if (!resizeChecked || !autoExtChecked) {
			failures.push(`new upload options not default-checked (resize=${resizeChecked}, autoExt=${autoExtChecked})`);
		} else {
			console.log('[ok] resize + auto-correct extension options present, default ON');
		}

		await page.evaluate(async () => {
			const canvas = document.createElement('canvas');
			canvas.width = 3000;
			canvas.height = 2000;
			const ctx = canvas.getContext('2d');
			ctx.fillStyle = '#36c';
			ctx.fillRect(0, 0, 3000, 2000);
			const blob = await new Promise((r) => canvas.toBlob(r, 'image/png'));
			const file = new File([blob], 'Wrong Name.JPG', { type: 'image/png' });
			const dt = new DataTransfer();
			dt.items.add(file);
			const input = document.querySelector('#wpUploadFile');
			input.files = dt.files;
			input.dispatchEvent(new Event('change', { bubbles: true }));
		});
		await page.waitForTimeout(1200);
		const imageState = await page.evaluate(() => {
			const preview = document.querySelector('.wb-image-preview');
			const input = document.querySelector('#wpUploadFile');
			const dest = document.querySelector('#wpDestFile');
			return {
				hasImg: !!(preview && preview.querySelector('img')),
				text: preview ? preview.textContent : '',
				dest: dest ? dest.value : '',
			};
		});
		if (!imageState.hasImg) {
			failures.push('local-file preview did not render an image');
		} else if (!imageState.text.includes('2000')) {
			failures.push(`resize did not downscale to 2000 px (preview: ${JSON.stringify(imageState.text)})`);
		} else {
			console.log('[ok] local-file preview + resize to 2000 px');
		}
		if (!/\.png$/i.test(imageState.dest)) {
			failures.push(`auto-correct extension did not set the .png destination (${imageState.dest})`);
		} else {
			console.log('[ok] auto-correct extension set the destination name');
		}

		// Clear the fixture so the later submit-path checks never upload it.
		await page.evaluate(() => {
			const input = document.querySelector('#wpUploadFile');
			if (input) {
				input.value = '';
				input.dispatchEvent(new Event('change', { bubbles: true }));
			}
		});

		// Drag-and-drop / clipboard paste onto the source area: dropping an
		// image (or pasting one) must switch to the File source and fill the
		// picker (then the shared change handler previews it). Start in Url
		// mode so the mode switch is exercised.
		await page.check('#wpSourceTypeurl');
		await page.evaluate(async () => {
			const canvas = document.createElement('canvas');
			canvas.width = 40; canvas.height = 40;
			canvas.getContext('2d').fillRect(0, 0, 40, 40);
			const blob = await new Promise((r) => canvas.toBlob(r, 'image/png'));
			const dt = new DataTransfer();
			dt.items.add(new File([blob], 'dropped.png', { type: 'image/png' }));
			const input = document.querySelector('#wpUploadFile');
			const wrap = input.closest('.mw-htmlform-field, .oo-ui-fieldLayout, tr') || input;
			wrap.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt }));
			wrap.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }));
		});
		let uploadState = await page.evaluate(() => ({
			files: document.querySelector('#wpUploadFile').files.length,
			fileChecked: document.querySelector('#wpSourceTypeFile').checked,
		}));
		if (uploadState.files < 1 || !uploadState.fileChecked) {
			failures.push(`drop did not fill the picker + switch to File (files=${uploadState.files}, fileChecked=${uploadState.fileChecked})`);
		} else {
			console.log('[ok] drag-and-drop onto the source area fills the picker + switches to File');
		}

		// Reset, then the clipboard-paste path (the same setFile contract).
		await page.evaluate(() => {
			const input = document.querySelector('#wpUploadFile');
			input.value = '';
			input.dispatchEvent(new Event('change', { bubbles: true }));
		});
		await page.check('#wpSourceTypeurl');
		await page.evaluate(async () => {
			const canvas = document.createElement('canvas');
			canvas.width = 40; canvas.height = 40;
			canvas.getContext('2d').fillRect(0, 0, 40, 40);
			const blob = await new Promise((r) => canvas.toBlob(r, 'image/png'));
			const dt = new DataTransfer();
			dt.items.add(new File([blob], 'pasted.png', { type: 'image/png' }));
			const ev = new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData: dt });
			document.querySelector('#mw-upload-form').dispatchEvent(ev);
		});
		uploadState = await page.evaluate(() => ({
			files: document.querySelector('#wpUploadFile').files.length,
			fileChecked: document.querySelector('#wpSourceTypeFile').checked,
		}));
		if (uploadState.files < 1 || !uploadState.fileChecked) {
			failures.push(`paste did not fill the picker + switch to File (files=${uploadState.files}, fileChecked=${uploadState.fileChecked})`);
		} else {
			console.log('[ok] clipboard paste onto the upload form fills the picker + switches to File');
		}

		// Clear both fixtures so the later submit-path checks never upload.
		await page.evaluate(() => {
			const input = document.querySelector('#wpUploadFile');
			if (input) {
				input.value = '';
				input.dispatchEvent(new Event('change', { bubbles: true }));
			}
		});

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

		// --- Printable version: clean title + authors + cover popup ------
		// A scratch User: subpage exercises the namespace + subpage-parent
		// strip (Title::getSubpageText); the fixture is deleted afterwards.
		const printTitle = `User:${USER}/Print UX ${Date.now()}`;
		await page.evaluate(async (title) => {
			await new Promise((r) => mw.loader.using('mediawiki.api', r));
			await new mw.Api().postWithToken('csrf', {
				action: 'edit', title, text: 'Printable-version UX fixture.', createonly: true,
			});
		}, printTitle);
		try {
			await page.goto(`${BASE_URL}/wiki/${encodeURIComponent(printTitle.replace(/ /g, '_'))}`,
				{ waitUntil: 'domcontentloaded', timeout: 60000 });
			await page.waitForSelector('#ca-wb-print', { timeout: 15000 });

			const leaf = printTitle.split('/').pop();
			const cfgTitle = await page.evaluate(() => mw.config.get('wbPrintTitle'));
			if (cfgTitle !== leaf) {
				failures.push(`wbPrintTitle is ${JSON.stringify(cfgTitle)}, expected ${JSON.stringify(leaf)}`);
			} else {
				console.log(`[ok] print title drops the namespace + subpage parent (${leaf})`);
			}

			// Stub window.print so the dialog never blocks the test.
			await page.evaluate(() => { window.__printed = 0; window.print = () => { window.__printed++; }; });

			// The toolbar button opens the popup with the cover-page option.
			await page.click('#ca-wb-print');
			await page.waitForSelector('.wb-print-popup', { timeout: 10000 });
			if (await page.locator('.wb-print-popup input[type="checkbox"]').count() !== 1) {
				failures.push('print popup lacks the "Add a cover page" option');
			} else {
				console.log('[ok] print popup offers the cover-page option');
			}

			// Cover-path print: a centered cover page carrying title + authors.
			await page.check('.wb-print-popup input[type="checkbox"]');
			await page.click('.wb-print-popup .wb-print-popup-actions .oo-ui-buttonElement-button');
			await page.waitForFunction(() => window.__printed === 1, null, { timeout: 10000 });
			await page.waitForSelector('.wb-print-cover .wb-print-title', { timeout: 10000 });
			const coverText = await page.locator('.wb-print-cover .wb-print-title').innerText();
			const coverAuthors = await page.locator('.wb-print-cover .wb-print-authors').count();
			if (coverText !== leaf) {
				failures.push(`print cover title is ${JSON.stringify(coverText)}, expected ${JSON.stringify(leaf)}`);
			} else if (coverAuthors !== 1) {
				failures.push('print cover lacks the authors line');
			} else {
				console.log('[ok] print cover page prints the title + authors');
			}
			if (!(await page.evaluate(() => document.body.classList.contains('wb-printing')))) {
				failures.push('print flow did not add the wb-printing body class');
			}

			// The core sidebar "Printable version" link opens the same popup.
			await page.evaluate(() => document.querySelectorAll('.wb-print-header, .wb-print-cover').forEach((n) => n.remove()));
			await page.click('#t-print a');
			await page.waitForSelector('.wb-print-popup', { timeout: 10000 });
			console.log('[ok] the sidebar "Printable version" link opens the print popup');
		} finally {
			await page.evaluate(async (title) => {
				await new Promise((r) => mw.loader.using('mediawiki.api', r));
				try {
					await new mw.Api().postWithToken('csrf', { action: 'delete', title });
				} catch (e) { /* best-effort cleanup */ }
			}, printTitle).catch(() => {});
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
