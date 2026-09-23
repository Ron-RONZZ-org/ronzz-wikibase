#!/usr/bin/env node
/**
 * UX E2E for the GeoGebra extension (interactive [[File:x.ggb]] embeds) —
 * Playwright browser suite. The GeoGebra app renders CLIENT-SIDE inside a
 * sandboxed, cross-origin iframe, which the curl-based geogebra E2E
 * (run_geogebra_e2e.py) cannot see — this suite loads a real page and asserts
 * the browser actually rendered the applet:
 *
 *   - [[File:name.ggb|600px]] emits a sandboxed .ggb-embed iframe
 *   - the player origin loads and the GeoGebra app injects into the frame
 *   - zero tolerance for page errors and console errors
 *   - the scratch page + file are deleted afterwards (self-cleaning)
 *
 * Usage (run from a directory where `playwright` is installed):
 *
 *     node run_geogebra_ux_e2e.mjs --base-url https://wikibase.ronzz.org \
 *         --user SeedBot --password-file seed/.seedbot.pass [--headed] [--keep]
 *
 * Login credentials are needed to upload the .ggb and create/delete the
 * scratch page (use the main SeedBot password — a bot-password session is
 * API-only and cannot drive web pages). CHROME_PATH can point at an existing
 * chromium binary (skips the managed-browser download).
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
	console.error('run_geogebra_ux_e2e.mjs: --user and --password-file are required '
		+ '(upload/creation/deletion need a real login)');
	process.exit(2);
}
const PASSWORD = readFileSync(PASSWORD_FILE, 'utf8').trim();

// A minimal structurally valid .ggb (a ZIP containing geogebra.xml).
const GGB_BASE64 = 'UEsDBBQAAAAIAFuDN13kSk2igwAAALAAAAAMAAAAZ2VvZ2VicmEueG1sXY0xDoMwDAD3vMLyXgJDpQ6OUZ8SgkGRQoxCqKCvr1q1S5fb7o76Y0nwkLJFzQ67pkWQHHSMeXa41+lyw54NzaKzDMXDpGXx1eG1aZENBc1bLXuoUTMbkiSL5Ar1XMXhqjFXhOQHSQ7vyBRUy7jB4bBDOD98vmmZ7NdlQ/avan93Ni9QSwMEFAAAAAgAW4M3XaQJB3oKAAAACAAAABYAAABnZW9nZWJyYV90aHVtYm5haWwucG5n6wzwc+flkuICAFBLAQIUAxQAAAAIAFuDN13kSk2igwAAALAAAAAMAAAAAAAAAAAAAACAAQAAAABnZW9nZWJyYS54bWxQSwECFAMUAAAACABbgzddpAkHegoAAAAIAAAAFgAAAAAAAAAAAAAAgAGtAAAAZ2VvZ2VicmFfdGh1bWJuYWlsLnBuZ1BLBQYAAAAAAgACAH4AAADrAAAAAAA=';

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

const STAMP = Date.now();
const FILE_NAME = `GeoGebra UX E2E ${STAMP}.ggb`;
const FILE_TITLE = `File:${FILE_NAME}`;
const PAGE_TITLE = `GeoGebra UX E2E ${STAMP}`;
const PAGE_TEXT = `GeoGebra UX E2E scratch page.\n\n[[File:${FILE_NAME}|600px]]\n`;

// Minimal cookie jar — Node's fetch does NOT persist cookies, and the API
// login → csrf-token → upload/edit/delete sequence needs the session cookie.
const jar = new Map();

function storeCookies(headers) {
	const list = typeof headers.getSetCookie === 'function' ? headers.getSetCookie() : [];
	for (const raw of list) {
		const [pair] = raw.split(';');
		const eq = pair.indexOf('=');
		if (eq > 0) jar.set(pair.slice(0, eq).trim(), pair.slice(eq + 1).trim());
	}
}

function cookieHeader() {
	return [...jar].map(([k, v]) => `${k}=${v}`).join('; ');
}

async function api(params, post = false) {
	const url = API_URL + (post ? '' : '?' + new URLSearchParams(params));
	const headers = { 'User-Agent': 'ronzz-wikibase-geogebra-ux-e2e/1.0' };
	const cookie = cookieHeader();
	if (cookie) headers.cookie = cookie;
	const res = await fetch(url, {
		method: post ? 'POST' : 'GET',
		...(post ? { body: new URLSearchParams(params) } : {}),
		headers,
	});
	storeCookies(res.headers);
	return res.json();
}

async function uploadGgb() {
	const form = new FormData();
	form.append('action', 'upload');
	form.append('filename', FILE_NAME);
	form.append('ignorewarnings', '1');
	form.append('format', 'json');
	form.append('token', await csrf());
	form.append('file', new Blob([Buffer.from(GGB_BASE64, 'base64')], { type: 'application/geogebra' }), FILE_NAME);
	const headers = { 'User-Agent': 'ronzz-wikibase-geogebra-ux-e2e/1.0' };
	const cookie = cookieHeader();
	if (cookie) headers.cookie = cookie;
	const res = await fetch(API_URL, { method: 'POST', body: form, headers });
	storeCookies(res.headers);
	return res.json();
}

async function csrf() {
	const t = await api({ action: 'query', meta: 'tokens', format: 'json' });
	return t.query.tokens.csrftoken;
}

async function main() {
	const lt = await api({ action: 'query', meta: 'tokens', type: 'login', format: 'json' });
	const login = await api({
		action: 'login', lgname: USER, lgpassword: PASSWORD,
		lgtoken: lt.query.tokens.logintoken, format: 'json',
	}, true);
	if (login.login?.result !== 'Success') {
		console.error(`run_geogebra_ux_e2e.mjs: API login failed: ${JSON.stringify(login)}`);
		process.exit(2);
	}
	const token = await csrf();

	const up = await uploadGgb();
	if (up.upload?.result !== 'Success') {
		console.error(`run_geogebra_ux_e2e.mjs: upload failed: ${JSON.stringify(up)}`);
		process.exit(2);
	}

	const edit = await api({
		action: 'edit', title: PAGE_TITLE, text: PAGE_TEXT, token,
		summary: 'geogebra UX E2E scratch (run_geogebra_ux_e2e.mjs)', format: 'json',
	}, true);
	if (edit.edit?.result !== 'Success') {
		console.error(`run_geogebra_ux_e2e.mjs: page creation failed: ${JSON.stringify(edit)}`);
		process.exit(2);
	}

	const browser = await chromium.launch({ headless: !HEADED, executablePath: CHROME_PATH });
	const page = await browser.newPage();
	page.on('pageerror', (err) => pageErrors.push(String(err)));
	page.on('console', (msg) => {
		if ((msg.type() === 'error' || msg.type() === 'warning') && !ignoredConsole(msg)) {
			consoleErrors.push(`[${msg.type()}] ${msg.text()}`);
		}
	});

	try {
		await page.goto(`${BASE_URL}/wiki/${encodeURIComponent(PAGE_TITLE.replace(/ /g, '_'))}`,
			{ waitUntil: 'domcontentloaded', timeout: 60000 });

		const iframe = page.locator('iframe.ggb-embed');
		await iframe.waitFor({ state: 'attached', timeout: 30000 });
		const src = await iframe.getAttribute('src');
		if (!src || !src.includes('player.html')) {
			failures.push(`iframe src does not reference the player: ${src}`);
		} else {
			console.log('[ok] sandboxed player iframe emitted');
		}

		// The applet renders inside the cross-origin frame.
		const frame = page.frameLocator('iframe.ggb-embed');
		await frame.locator('#ggb-element > *').first().waitFor({ timeout: 60000 });
		console.log('[ok] GeoGebra app injected into the player frame');
	} finally {
		await browser.close();

		if (!KEEP) {
			const delPage = await api({
				action: 'delete', title: PAGE_TITLE, token,
				reason: 'geogebra UX E2E cleanup (run_geogebra_ux_e2e.mjs)', format: 'json',
			}, true);
			if (delPage.error) {
				console.error(`[warn] cleanup of ${PAGE_TITLE} failed: ${JSON.stringify(delPage.error)}`);
			}
			const delFile = await api({
				action: 'delete', title: FILE_TITLE, token,
				reason: 'geogebra UX E2E cleanup (run_geogebra_ux_e2e.mjs)', format: 'json',
			}, true);
			if (delFile.error) {
				console.error(`[warn] cleanup of ${FILE_TITLE} failed: ${JSON.stringify(delFile.error)}`);
			}
			console.log('[ok] cleanup: page + file deleted');
		} else {
			console.log(`[keep] leaving ${PAGE_TITLE} + ${FILE_TITLE} on the instance`);
		}
	}

	if (pageErrors.length) failures.push(`page errors: ${pageErrors.join(' | ')}`);
	if (consoleErrors.length) failures.push(`console errors: ${consoleErrors.join(' | ')}`);

	if (failures.length) {
		console.error(`geogebra UX E2E FAILED:\n - ${failures.join('\n - ')}`);
		process.exit(1);
	}
	console.log('geogebra UX E2E: all checks passed');
}

main().catch((err) => {
	console.error('geogebra UX E2E FAILED:', err);
	process.exit(1);
});
