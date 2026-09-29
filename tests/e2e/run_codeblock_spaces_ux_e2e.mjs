#!/usr/bin/env node
/**
 * Browser UX E2E for the CodeBlockSpaces fix — Playwright suite
 * (manual / production, like run_wiki_ux_e2e.mjs).
 *
 * The server-side suite (run_codeblock_spaces_e2e.py) asserts the invariant
 * the SyntaxHighlight copy button reads: code blocks carry plain spaces, no
 * `&#160;`. This suite exercises the actual reported flow end to end:
 *
 *   1. create a scratch page carrying a `<syntaxhighlight lang="text" copy>`
 *      block with the katex command from User:Rongzhou/Nvim_Regex;
 *   2. open it in a browser (clipboard permissions granted);
 *   3. click the copy button;
 *   4. read the clipboard and assert it is byte-for-byte the command — no
 *      U+00A0 non-breaking space (a single NBSP made nvim abort the `\=`
 *      expression with E488 and wipe the match);
 *   5. delete the scratch page (self-cleaning).
 *
 * Usage (from a directory with `playwright` installed):
 *
 *     node run_codeblock_spaces_ux_e2e.mjs --base-url https://wikibase.ronzz.org \
 *         --user SeedBot --password-file seed/.seedbot.pass [--headed]
 *
 * A real login is needed to create/delete the scratch page. CHROME_PATH can
 * point at an existing chromium binary (skips the managed-browser download).
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
	console.error('run_codeblock_spaces_ux_e2e.mjs: --user and --password-file are required '
		+ '(the scratch page needs a real login)');
	process.exit(2);
}
const PASSWORD = readFileSync(PASSWORD_FILE, 'utf8').trim();

// The command from User:Rongzhou/Nvim_Regex#Katex — the reported breakage.
const KATEX_COMMAND = String.raw`:%s/\(\\(\(.\{-}\)\\)\)\|\(\\\[\(\_.\{-}\)\\\]\)/\=submatch(1) != '' ? '$'.submatch(2).'$' : '$$'.submatch(4).'$$'/g`;
const NBSP = '\u00a0';

const SCRATCH_TITLE = `User:${USER.replace(/ /g, '_')}/CodeBlockSpaces-e2e-scratch`;
const SCRATCH_TEXT = `<syntaxhighlight lang="text" copy>\n${KATEX_COMMAND}\n</syntaxhighlight>\n`;

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

async function apiPost(request, params) {
	const res = await request.post(API_URL, { form: { ...params, format: 'json', formatversion: '2' } });
	return res.json();
}

async function csrfToken(request) {
	const res = await request.get(`${API_URL}?action=query&meta=tokens&type=csrf&format=json&formatversion=2`);
	const data = await res.json();
	return data.query.tokens.csrftoken;
}

async function main() {
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
		token = await csrfToken(page.request);
		if (!token) {
			failures.push('could not obtain a CSRF token after login');
			return;
		}

		// --- create the scratch page ------------------------------------
		const edit = await apiPost(page.request, {
			action: 'edit', title: SCRATCH_TITLE, text: SCRATCH_TEXT, token,
		});
		if (edit.error) {
			failures.push(`scratch page edit failed: ${JSON.stringify(edit.error)}`);
			return;
		}
		console.log(`[info] scratch page ${SCRATCH_TITLE} created`);

		// --- the reported flow: copy the code block ---------------------
		await page.goto(`${BASE_URL}/wiki/${SCRATCH_TITLE.replace(/ /g, '_')}`,
			{ waitUntil: 'domcontentloaded', timeout: 60000 });
		await page.waitForSelector('.mw-highlight-copy-button', { timeout: 15000 });
		await page.locator('.mw-highlight-copy-button').click();
		const copied = await page.evaluate(() => navigator.clipboard.readText());

		if (copied.includes(NBSP)) {
			failures.push('the copy button copied a U+00A0 non-breaking space (the bug)');
		} else if (copied !== KATEX_COMMAND) {
			failures.push(`the copy button copied ${JSON.stringify(copied)}, `
				+ `expected ${JSON.stringify(KATEX_COMMAND)}`);
		} else {
			console.log('[ok] the SyntaxHighlight copy button copies the command byte-for-byte (no NBSP)');
		}
	} catch (e) {
		failures.push(`unexpected error: ${e && e.message ? e.message : e}`);
	} finally {
		// --- cleanup: delete the scratch page ---------------------------
		if (token) {
			try {
				const del = await apiPost(page.request, {
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
	console.log('\nall CodeBlockSpaces UX checks passed');
}

main().catch((e) => {
	console.error(e);
	process.exit(1);
});
