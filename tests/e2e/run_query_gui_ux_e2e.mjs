#!/usr/bin/env node
/**
 * UX E2E for the WDQS query GUI (query.ronzz.org) — Playwright browser suite.
 *
 * Exercises the user-facing query flow end to end against a running GUI:
 *   - ctrl+space entity autocomplete (wd:) and property autocomplete (wdt:)
 *     — the regression test for the entity-autocomplete fix (patch
 *     0002-entity-autocomplete.patch: the stock ENTITY_TYPES only knows
 *     wikidata.org, so instance prefixes never triggered an entity search)
 *   - SPARQL keyword / variable hints (?)
 *   - running a query and rendering results that come from the configured
 *     instance (result links carry the instance's entity URIs)
 *   - the Examples dialog and the Query Builder navbar link
 *   - zero tolerance for page errors and console errors
 *
 * Read-only: never logs in, never writes. Safe against production.
 *
 * Usage (run from a directory where `playwright` is installed):
 *
 *     node run_query_gui_ux_e2e.mjs [--base-url https://query.ronzz.org]
 *         [--entity-base https://wikibase.ronzz.org/entity/]
 *         [--run-query "SELECT ?item WHERE { ?item wdt:P1 wd:Q6 } LIMIT 5"]
 *         [--headed]
 *
 * The `playwright` package must be resolvable from the script's directory
 * (npm scratch dir in CI; `~/node_modules` locally). CHROME_PATH can point
 * at an existing chromium binary (skips the managed-browser download).
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

const BASE_URL = arg('--base-url', 'https://query.ronzz.org');
const ENTITY_BASE = arg('--entity-base', 'https://wikibase.ronzz.org/entity/');
const RUN_QUERY = arg(
	'--run-query',
	'SELECT ?item WHERE { ?item wdt:P1 wd:Q6 } LIMIT 5'
);
const HEADED = process.argv.includes('--headed');
const CHROME_PATH = process.env.CHROME_PATH || undefined;

const failures = [];
const pageErrors = [];
const consoleErrors = [];
const notFoundUrls = [];
// config.js enables a local-debug i18n path (node_modules/jquery.uls/i18n)
// when the hostname is localhost/127.0.0.1 — those files are not in the
// build/, so a localhost-served build logs one 404. Real-host deployments
// (production, CI) never hit it. Filter it only for localhost hosts.
const BASE_HOST = new URL(BASE_URL).hostname;
const IGNORE_CONSOLE_LOCALHOST_ULS = BASE_HOST === 'localhost' || BASE_HOST === '127.0.0.1';

function expect(condition, message) {
	if (!condition) {
		failures.push(message);
	}
}

const browser = await chromium.launch({ headless: !HEADED, executablePath: CHROME_PATH });
const page = await browser.newPage();
page.on('pageerror', (err) => pageErrors.push(err.message));
page.on('response', (resp) => {
	if (resp.status() === 404) {
		notFoundUrls.push(new URL(resp.url()).pathname);
	}
});
page.on('console', (msg) => {
	if (msg.type() !== 'error') return;
	// localhost-only artifact: the local-debug i18n override 404s the ULS
	// language files; its console error is the generic "Failed to load
	// resource" message without a URL, so correlate via the response list.
	const uls404 = IGNORE_CONSOLE_LOCALHOST_ULS &&
		notFoundUrls.some((p) => p.includes('node_modules/jquery.uls/i18n'));
	if (uls404 && /failed to load resource/i.test(msg.text())) {
		return;
	}
	consoleErrors.push(msg.text());
});

async function step(name, fn) {
	const before = failures.length;
	try {
		await fn();
		if (failures.length === before) {
			console.log(`  [ok] ${name}`);
		} else {
			console.log(`  [FAIL] ${name}`);
		}
	} catch (err) {
		failures.push(`${name}: ${err.message}`);
		console.log(`  [FAIL] ${name}: ${err.message}`);
	}
}

const editor = page.locator('.CodeMirror').first();

async function setEditor(text) {
	await editor.click();
	await page.keyboard.press('Control+A');
	await page.keyboard.press('Backspace');
	if (text) {
		await page.keyboard.type(text, { delay: 10 });
	}
}

async function closePopupAndClear() {
	await page.keyboard.press('Escape');
	await page.waitForTimeout(300);
	await setEditor('');
}

async function expectHintPopup(what) {
	await page.waitForTimeout(1600); // async hint round-trip (wbsearchentities)
	const popup = page.locator('.CodeMirror-hints');
	const visible = await popup.isVisible().catch(() => false);
	if (!visible) {
		// debug: what IS in the editor, and is the popup element even present?
		const editorText = await editor.innerText().catch(() => '(cannot read editor)');
		const popupCount = await page.locator('.CodeMirror-hints').count().catch(() => -1);
		const popupStyle = await popup.getAttribute('style').catch(() => '(no style)');
		console.log(`    debug[${what}]: editor=${JSON.stringify(editorText)} hintsInDom=${popupCount} style=${popupStyle}`);
	}
	expect(visible, `hint popup not visible for ${what}`);
	if (visible) {
		const items = await popup.locator('li').allTextContents();
		expect(items.length > 0, `hint popup empty for ${what}`);
	}
}

await page.goto(BASE_URL, { waitUntil: 'networkidle', timeout: 60000 });
console.log(`base: ${BASE_URL}`);

// 1. The GUI loads with the expected brand and an editable editor.
await step('page loads with the editor and brand', async () => {
	// setBrand() runs after the runtime config fetch — wait for it.
	let title = '';
	for (let i = 0; i < 25 && !title; i++) {
		title = await page.title();
		if (!title) await page.waitForTimeout(200);
	}
	expect(title.length > 0, 'page title is empty (config never applied?)');
	expect(title.includes('Query'), `title ${JSON.stringify(title)} does not look like a query service`);
	expect(await editor.isVisible(), 'CodeMirror editor not visible');
});

// 2. ctrl+space entity autocomplete (the regression: wd: + ctrl+space must
//    search the instance's entities, not silently produce nothing).
await step('ctrl+space on "wd:Q9" shows entity hints', async () => {
	await setEditor('wd:Q9');
	await page.keyboard.press('Control+Space');
	await expectHintPopup('wd:Q9');
});

// 3. Property autocomplete (wdt: searches properties).
await step('ctrl+space on "wdt:P1" shows property hints', async () => {
	await closePopupAndClear();
	await setEditor('wdt:P1');
	await page.keyboard.press('Control+Space');
	await expectHintPopup('wdt:P1');
});

// 4. SPARQL keyword / variable hints still work (the non-entity path).
await step('typing "?" auto-shows variable/keyword hints', async () => {
	await closePopupAndClear();
	await setEditor('?');
	await expectHintPopup('?');
});

// 5. Run a query and render results from the configured instance.
await step('run a query and render instance results', async () => {
	await closePopupAndClear();
	await setEditor(RUN_QUERY);
	await page.locator('#execute-button').click();
	const row = page.locator('#query-result table.table tbody tr').first();
	await row.waitFor({ state: 'visible', timeout: 30000 });
	const rowCount = await page.locator('#query-result table.table tbody tr').count();
	expect(rowCount > 0, 'no result rows rendered');
	const resultLinks = await page
		.locator('#query-result a[href*="' + ENTITY_BASE + '"]')
		.count();
	expect(resultLinks > 0, `no result links carry the instance entity base ${ENTITY_BASE}`);
});

// 6. Examples dialog opens (reads the public SPARQL examples page).
await step('examples dialog opens with examples', async () => {
	await page.locator('#open-example').first().click();
	const modal = page.locator('#QueryExamples');
	await modal.waitFor({ state: 'visible', timeout: 15000 });
	const rows = await modal.locator('.exampleTable tr').count();
	expect(rows > 0, 'examples dialog rendered no example rows');
	await page.locator('#QueryExamples .close').first().click().catch(() => {});
});

// 7. Query Builder navbar link points at this instance's builder.
await step('query-builder toggle links to /querybuilder/', async () => {
	const href = await page.locator('#query-builder-toggle').first().getAttribute('href');
	expect(href && href.includes('/querybuilder/'), `query-builder href ${JSON.stringify(href)} does not contain /querybuilder/`);
});

// 8. No JS errors.
await step('no page errors or console errors', async () => {
	expect(pageErrors.length === 0, `page errors: ${pageErrors.join(' | ')}`);
	expect(consoleErrors.length === 0, `console errors: ${consoleErrors.join(' | ')}`);
});

await browser.close();

if (failures.length > 0) {
	console.error(`\nFAILED (${failures.length}):`);
	failures.forEach((f) => console.error('  - ' + f));
	process.exit(1);
}
console.log('\nAll query GUI UX checks passed.');
