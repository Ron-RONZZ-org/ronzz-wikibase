#!/usr/bin/env node
/**
 * Browser UX E2E for the Pyodide4MediaWiki extension — Playwright suite
 * (manual / production, like run_wiki_ux_e2e.mjs).
 *
 * The server-side suite (run_pyodide_e2e.py) asserts the parser contract
 * (the runner module is queued, the asset URL is exported). This suite
 * exercises the actual feature end to end:
 *
 *   1. create a scratch page with three `class="pyodide"` blocks — the first
 *      assigns a variable and prints a marker, the second uses the variable,
 *      the third plots with matplotlib and calls `plt.show()`;
 *   2. open it, click Run on the first block, wait for the interpreter to
 *      load (the first run downloads Pyodide from the wiki's own origin) and
 *      for its output;
 *   3. click Run on the second block and assert it sees the first block's
 *      variable — proving the shared interpreter (notebook semantics);
 *   4. click Run on the matplotlib block and assert a PNG `<img>` rendered in
 *      the output panel (the worker has no `window`, so matplotlib's
 *      Pyodide backend must not be used — see run_pyodide_worker_test.mjs);
 *   5. delete the scratch page (self-cleaning).
 *
 * Zero tolerance for page/console errors.
 *
 * Usage (from a directory with `playwright` installed):
 *
 *     node run_pyodide_ux_e2e.mjs --base-url https://wikibase.ronzz.org \
 *         --user SeedBot --password-file seed/.seedbot.pass [--headed]
 *
 * CI runs it against the dev stack with the stack's admin account.
 *
 * A real login is needed to create/delete the scratch page (execution itself
 * is open to all readers on this instance). CHROME_PATH can point at an
 * existing chromium binary (skips the managed-browser download). The Pyodide
 * assets must be installed (`tools/install-pyodide.sh`).
 *
 * The CI dev stack runs with $wgServer = http://wikibase (container DNS), so
 * CI passes --host-resolver-rules "MAP wikibase 127.0.0.1:8082" and
 * --base-url http://wikibase. Chromium then resolves that host to the
 * published port, and the API helpers below run their fetches inside the page
 * so they share its origin and session cookies.
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
const HOST_RESOLVER_RULES = arg('--host-resolver-rules', process.env.HOST_RESOLVER_RULES || '');

// The interpreter download + init on the first run can take a while.
const LOAD_TIMEOUT = 180000;
const RUN_TIMEOUT = 60000;

if (!USER || !PASSWORD_FILE) {
	console.error('run_pyodide_ux_e2e.mjs: --user and --password-file are required '
		+ '(the scratch page needs a real login)');
	process.exit(2);
}
const PASSWORD = readFileSync(PASSWORD_FILE, 'utf8').trim();

const BLOCK_A = '<syntaxhighlight lang="python" class="pyodide">\nx = 40\nprint("ready")\n</syntaxhighlight>';
const BLOCK_B = '<syntaxhighlight lang="python" class="pyodide">\nprint(x + 2)\n</syntaxhighlight>';
const BLOCK_C = '<syntaxhighlight lang="python" class="pyodide">\n'
	+ 'import matplotlib.pyplot as plt\n'
	+ 'plt.figure(figsize=(4, 3))\n'
	+ 'plt.plot([1, 2, 4, 3], "-o")\n'
	+ 'plt.title("pyodide e2e")\n'
	+ 'plt.show()\n'
	+ 'print("plotted", plt.get_fignums())\n'
	+ '</syntaxhighlight>';
const SCRATCH_TEXT = `${BLOCK_A}\n\n${BLOCK_B}\n\n${BLOCK_C}\n`;

const SCRATCH_TITLE = `User:${USER.replace(/ /g, '_')}/Pyodide4MediaWiki-e2e-scratch`;

const failures = [];
const pageErrors = [];
const consoleErrors = [];

function ignoredConsole(msg) {
	const text = msg.text();
	const url = (msg.location && msg.location().url) || '';
	return text.toLowerCase().includes('favicon.ico')
		|| url.toLowerCase().includes('favicon.ico')
		|| text.includes('No version information available for component')
		|| text.includes('jquery.ui');
}

async function apiPost(page, params) {
	// Fetch from INSIDE the page: MediaWiki's $wgServer host is not resolvable
	// from Node (CI), and a page-context fetch shares the browser origin and
	// session cookies.
	return page.evaluate(async ({ url, form }) => {
		const body = new URLSearchParams({ ...form, format: 'json', formatversion: '2' });
		const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin' });
		return response.json();
	}, { url: API_URL, form: params });
}

async function csrfToken(page) {
	const data = await page.evaluate(async (url) => {
		const query = new URLSearchParams({
			action: 'query', meta: 'tokens', type: 'csrf', format: 'json', formatversion: '2'
		});
		const response = await fetch(`${url}?${query.toString()}`, { credentials: 'same-origin' });
		return response.json();
	}, API_URL);
	return data.query.tokens.csrftoken;
}

async function waitForOutput(page, index, needle, timeout) {
	const output = page.locator('.pyodide4mediawiki-output').nth(index);
	await output.waitFor({ state: 'visible', timeout });
	await page.waitForFunction(
		({ i, text }) => {
			const nodes = document.querySelectorAll('.pyodide4mediawiki-output');
			return nodes[i] && nodes[i].textContent.includes(text);
		},
		{ i: index, text: needle },
		{ timeout }
	);
}

async function waitForFigure(page, index, timeout) {
	await page.waitForFunction(
		(i) => {
			const nodes = document.querySelectorAll('.pyodide4mediawiki-output');
			const image = nodes[i] && nodes[i].querySelector('img.pyodide4mediawiki-figure');
			return !!(image && image.complete && image.naturalWidth > 0);
		},
		index,
		{ timeout }
	);
	return page.evaluate((i) => {
		const nodes = document.querySelectorAll('.pyodide4mediawiki-output');
		const image = nodes[i].querySelector('img.pyodide4mediawiki-figure');
		return { src: image.src, width: image.naturalWidth, height: image.naturalHeight };
	}, index);
}

async function main() {
	const browser = await chromium.launch({
		headless: !HEADED,
		executablePath: CHROME_PATH,
		args: HOST_RESOLVER_RULES ? [`--host-resolver-rules=${HOST_RESOLVER_RULES}`] : []
	});
	const context = await browser.newContext();
	const page = await context.newPage();
	page.setDefaultTimeout(LOAD_TIMEOUT);
	page.on('pageerror', (err) => pageErrors.push(String(err)));
	page.on('console', (msg) => {
		if ((msg.type() === 'error' || msg.type() === 'warning') && !ignoredConsole(msg)) {
			consoleErrors.push(`[${msg.type()}] ${msg.text()}`);
		}
	});

	let token = null;
	try {
		// --- login (the scratch page needs edit rights) -----------------
		await page.goto(`${BASE_URL}/wiki/Special:UserLogin`, { waitUntil: 'domcontentloaded', timeout: 60000 });
		await page.fill('#wpName1', USER);
		await page.fill('#wpPassword1', PASSWORD);
		await Promise.all([
			page.waitForLoadState('domcontentloaded'),
			page.click('#wpLoginAttempt'),
		]);
		token = await csrfToken(page);
		if (!token) {
			failures.push('could not obtain a CSRF token after login');
			return;
		}

		// --- create the scratch page ------------------------------------
		const edit = await apiPost(page, {
			action: 'edit', title: SCRATCH_TITLE, text: SCRATCH_TEXT, token,
		});
		if (edit.error) {
			failures.push(`scratch page edit failed: ${JSON.stringify(edit.error)}`);
			return;
		}
		console.log(`[info] scratch page ${SCRATCH_TITLE} created`);

		// --- run the first cell (loads the interpreter) -----------------
		await page.goto(`${BASE_URL}/wiki/${SCRATCH_TITLE.replace(/ /g, '_')}`,
			{ waitUntil: 'domcontentloaded', timeout: 60000 });
		const runs = page.locator('.pyodide4mediawiki-run');
		await runs.nth(0).waitFor({ state: 'visible', timeout: 15000 });
		if (await runs.count() !== 3) {
			failures.push(`expected 3 Run controls, found ${await runs.count()}`);
		}

		await runs.nth(0).click();
		await waitForOutput(page, 0, 'ready', LOAD_TIMEOUT);
		console.log('[ok] first block ran (interpreter loaded, stdout captured)');

		// --- run the second cell (shares the interpreter) ---------------
		await runs.nth(1).click();
		await waitForOutput(page, 1, '42', RUN_TIMEOUT);
		console.log('[ok] second block sees the first block\'s variable (shared interpreter)');

		// --- run the matplotlib block (Agg backend + PNG output) --------
		await runs.nth(2).click();
		await waitForOutput(page, 2, 'plotted []', LOAD_TIMEOUT);
		const figure = await waitForFigure(page, 2, LOAD_TIMEOUT);
		if (!figure.src.startsWith('data:image/png;base64,')) {
			failures.push(`figure is not a PNG data URL: ${figure.src.slice(0, 40)}`);
		}
		if (figure.width < 100 || figure.height < 100) {
			failures.push(`figure has an implausible size: ${figure.width}×${figure.height}`);
		}
		console.log(`[ok] plt.show() rendered a ${figure.width}×${figure.height} PNG`);
	} catch (e) {
		failures.push(`unexpected error: ${e && e.message ? e.message : e}`);
	} finally {
		// --- cleanup: delete the scratch page ---------------------------
		if (token) {
			try {
				const del = await apiPost(page, {
					action: 'delete', title: SCRATCH_TITLE, token,
				});
				if (del.error) {
					console.error(`[warn] scratch page delete failed: ${JSON.stringify(del.error)}`);
				} else {
					console.log(`[info] scratch page ${SCRATCH_TITLE} deleted`);
				}
			} catch (e) {
				console.error(`[warn] scratch page delete failed: ${e}`);
			}
		}
		await context.close();
		await browser.close();
	}

	for (const err of pageErrors) {
		failures.push(`page error: ${err}`);
	}
	for (const err of consoleErrors) {
		failures.push(`console error: ${err}`);
	}

	if (failures.length > 0) {
		console.error('\nFAILURES:');
		for (const f of failures) {
			console.error(`  - ${f}`);
		}
		process.exit(1);
	}
	console.log('\nall Pyodide4MediaWiki UX checks passed');
}

main().catch((e) => {
	console.error(e);
	process.exit(1);
});
