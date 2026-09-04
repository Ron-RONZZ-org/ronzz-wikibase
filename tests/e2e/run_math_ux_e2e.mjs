#!/usr/bin/env node
/**
 * UX E2E for SimpleMathJax ($...$ inline LaTeX math) — Playwright browser
 * suite. MathJax typesets CLIENT-SIDE, which the curl-based math E2E
 * (run_math_e2e.py) cannot see — this suite loads a real page and asserts
 * the browser actually rendered the math:
 *
 *   - `$e^{i\pi}+1=0$` (inline) and `$$…$$` (display) on a scratch page
 *     produce MathJax CHTML `<mjx-container>` output — the client-side
 *     rendering contract of the $…$ delimiters
 *   - a code block (`<syntaxhighlight>`) containing `$` stays untouched
 *     (MathJax skips pre/code — the currency/code false-positive guard)
 *   - zero tolerance for page errors and console errors
 *   - the scratch page is deleted afterwards (self-cleaning)
 *
 * Usage (run from a directory where `playwright` is installed):
 *
 *     node run_math_ux_e2e.mjs --base-url https://wikibase.ronzz.org \
 *         --user SeedBot --password-file seed/.seedbot.pass [--headed] [--keep]
 *
 * Login credentials are needed to create/delete the scratch page (use the
 * main SeedBot password — a bot-password session is API-only and cannot
 * drive web pages). CHROME_PATH can point at an existing chromium binary
 * (skips the managed-browser download).
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
const KEEP = process.argv.includes('--keep');
const CHROME_PATH = process.env.CHROME_PATH || undefined;

if (!USER || !PASSWORD_FILE) {
	console.error('run_math_ux_e2e.mjs: --user and --password-file are required '
		+ '(page creation/deletion needs a real login)');
	process.exit(2);
}
const PASSWORD = readFileSync(PASSWORD_FILE, 'utf8').trim();

const failures = [];
const pageErrors = [];
const consoleErrors = [];

const PAGE_TITLE = `Math E2E UX ${Date.now()}`;
const PAGE_TEXT = `MathJax UX E2E scratch page.

Inline: $e^{i\\pi}+1=0$

Display: $$\\int_0^1 x^2 \\, dx$$

Code stays literal:
<syntaxhighlight lang="bash">
price=\$5.00
echo "total \${price}0"
</syntaxhighlight>
`;

// Minimal cookie jar — Node's fetch does NOT persist cookies, and the API
// login → csrf-token → edit/delete sequence needs the session cookie on
// every request.
const jar = new Map();

function storeCookies(headers) {
	const list = typeof headers.getSetCookie === 'function' ? headers.getSetCookie() : [];
	for (const raw of list) {
		const [pair] = raw.split(';');
		const eq = pair.indexOf('=');
		if (eq > 0) jar.set(pair.slice(0, eq).trim(), pair.slice(eq + 1).trim());
	}
}

async function api(params, post = false) {
	const url = API_URL + (post ? '' : '?' + new URLSearchParams(params));
	const cookie = [...jar].map(([k, v]) => `${k}=${v}`).join('; ');
	const headers = { 'User-Agent': 'ronzz-wikibase-math-ux-e2e/1.0' };
	if (cookie) headers.cookie = cookie;
	const res = await fetch(url, {
		method: post ? 'POST' : 'GET',
		...(post ? { body: new URLSearchParams(params) } : {}),
		headers,
	});
	storeCookies(res.headers);
	return res.json();
}

async function main() {
	// Login (web session via the cookie jar is managed by the browser later;
	// here the API login yields the CSRF token for the edit/delete).
	let token = null;
	{
		const lt = await api({ action: 'query', meta: 'tokens', type: 'login', format: 'json' });
		const login = await api({
			action: 'login', lgname: USER, lgpassword: PASSWORD,
			lgtoken: lt.query.tokens.logintoken, format: 'json',
		}, true);
		if (login.login?.result !== 'Success') {
			console.error(`run_math_ux_e2e.mjs: API login failed: ${JSON.stringify(login)}`);
			process.exit(2);
		}
		const t = await api({ action: 'query', meta: 'tokens', format: 'json' });
		token = t.query.tokens.csrftoken;
	}

	const edit = await api({
		action: 'edit', title: PAGE_TITLE, text: PAGE_TEXT, token,
		summary: 'math UX E2E scratch (run_math_ux_e2e.mjs)', format: 'json',
	}, true);
	if (edit.edit?.result !== 'Success') {
		console.error(`run_math_ux_e2e.mjs: page creation failed: ${JSON.stringify(edit)}`);
		process.exit(2);
	}

	const browser = await chromium.launch({ headless: !HEADED, executablePath: CHROME_PATH });
	const page = await browser.newPage();
	page.on('pageerror', (err) => pageErrors.push(String(err)));
	page.on('console', (msg) => {
		if (msg.type() === 'error' || msg.type() === 'warning') {
			consoleErrors.push(`[${msg.type()}] ${msg.text()}`);
		}
	});

	try {
		await page.goto(`${BASE_URL}/wiki/${encodeURIComponent(PAGE_TITLE.replace(/ /g, '_'))}`,
			{ waitUntil: 'domcontentloaded', timeout: 60000 });

		// MathJax typesets asynchronously after load — wait for CHTML output.
		await page.waitForSelector('mjx-container', { timeout: 30000 });
		const containers = await page.locator('mjx-container').count();
		if (containers < 2) {
			failures.push(`expected >= 2 MathJax containers ($...$ inline + $$...$$ display), found ${containers}`);
		} else {
			console.log(`[ok] MathJax rendered ${containers} mjx-container elements`);
		}

		// The code block must NOT have been typeset (MathJax skips pre/code).
		const codeBlockMath = await page.locator('.mw-highlight mjx-container, pre mjx-container').count();
		if (codeBlockMath !== 0) {
			failures.push(`MathJax typeset inside a code block (${codeBlockMath} containers) — the pre/code skip failed`);
		} else {
			console.log('[ok] syntaxhighlight code block untouched (no MathJax inside)');
		}
	} finally {
		await browser.close();

		if (!KEEP) {
			const del = await api({
				action: 'delete', title: PAGE_TITLE, token,
				reason: 'math UX E2E cleanup (run_math_ux_e2e.mjs)', format: 'json',
			}, true);
			if (del.error) {
				console.error(`[warn] cleanup of ${PAGE_TITLE} failed: ${JSON.stringify(del.error)}`);
			} else {
				console.log(`[ok] cleanup: ${PAGE_TITLE} deleted`);
			}
		} else {
			console.log(`[keep] leaving ${PAGE_TITLE} on the instance`);
		}
	}

	if (pageErrors.length) failures.push(`page errors: ${pageErrors.join(' | ')}`);
	if (consoleErrors.length) failures.push(`console errors: ${consoleErrors.join(' | ')}`);

	if (failures.length) {
		console.error(`math UX E2E FAILED:\n - ${failures.join('\n - ')}`);
		process.exit(1);
	}
	console.log('math UX E2E: all checks passed');
}

main().catch((err) => {
	console.error('math UX E2E FAILED:', err);
	process.exit(1);
});
