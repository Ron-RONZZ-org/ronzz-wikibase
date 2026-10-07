#!/usr/bin/env node
/**
 * Browser UX E2E for the content-item preview + the Add* success popup
 * (Playwright; manual/production, like run_math_ux_e2e.mjs). Asserts the
 * client-side behaviour the curl suites cannot see:
 *
 *   - a math item's Item page preview renders its note as plain wikitext
 *     (no .wb-embed chrome) with inline $…$ typeset by the vendored KaTeX;
 *   - a quotation's Item page preview shows the ORIGINAL, the reader-language
 *     translation, and the attribution line (action=embed&preview=1);
 *   - the "Add more" success popup (Special:AddQuotation?addmore=1&created=Q)
 *     renders the just-added item's preview + the entity actions
 *     (Edit content / Copy Embed code / Copy citation).
 *
 * Self-cleaning: it creates the scratch items via the API and deletes them.
 *
 * Usage (run from a directory where `playwright` is installed):
 *
 *     node run_contentpreview_ux_e2e.mjs --base-url https://wikibase.ronzz.org \
 *         --user SeedBot --password-file seed/.seedbot.pass [--headed] [--keep]
 *
 * CHROME_PATH can point at an existing chromium binary.
 *
 * Exit code 0 = all checks passed. License: GPL-2.0-or-later
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
	console.error('run_contentpreview_ux_e2e.mjs: --user and --password-file are required');
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
	const headers = { 'User-Agent': 'ronzz-wikibase-contentpreview-ux-e2e/1.0' };
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
	// API login (cookie jar) → csrf token for create/delete.
	let token = null;
	{
		const lt = await api({ action: 'query', meta: 'tokens', type: 'login', format: 'json' });
		const login = await api({
			action: 'login', lgname: USER, lgpassword: PASSWORD,
			lgtoken: lt.query.tokens.logintoken, format: 'json',
		}, true);
		if (login.login?.result !== 'Success') {
			console.error(`login failed: ${JSON.stringify(login)}`);
			process.exit(2);
		}
		const t = await api({ action: 'query', meta: 'tokens', format: 'json' });
		token = t.query.tokens.csrftoken;
	}

	async function idOf(label, type) {
		const r = await api({ action: 'wbsearchentities', search: label, language: 'en', type, format: 'json', limit: '5' });
		const hit = (r.search || []).find((x) => x.label === label);
		if (!hit) throw new Error(`entity ${label} (${type}) not found — re-seed required?`);
		return hit.id;
	}

	async function createItem(data, summary) {
		const res = await api({
			action: 'wbeditentity', new: 'item', data: JSON.stringify(data),
			summary, token, format: 'json',
		}, true);
		if (res.error) throw new Error(`createItem: ${JSON.stringify(res.error)}`);
		return res.entity.id;
	}

	const claims = (prop, datavalue) => [ {
		mainsnak: { snaktype: 'value', property: prop, datavalue },
		type: 'statement', rank: 'normal',
	} ];
	const itemValue = (qid) => ({
		value: { 'entity-type': 'item', 'numeric-id': parseInt(qid.slice(1), 10), id: qid },
		type: 'wikibase-entityid',
	});
	const mono = (text, language) => ({ value: { text, language }, type: 'monolingualtext' });
	const str = (value) => ({ value, type: 'string' });

	let mathId = null;
	let quoteId = null;
	const created = [];
	try {
		const instanceOf = await idOf('instance of', 'property');
		const contentText = await idOf('content text', 'property');
		const translation = await idOf('translation', 'property');
		const latexSource = await idOf('LaTeX source', 'property');
		const note = await idOf('note', 'property');
		const attributedTo = await idOf('attributed to', 'property');
		const mathClass = await idOf('mathematical expression', 'item');
		const quotationClass = await idOf('quotation content', 'item');

		const stamp = Date.now();
		mathId = await createItem({
			labels: { en: { language: 'en', value: `E2E preview math ${stamp}` } },
			claims: {
				[instanceOf]: claims(instanceOf, itemValue(mathClass)),
				[latexSource]: claims(latexSource, str('x^2')),
				[note]: claims(note, str('where $a$ is a **constant**')),
			},
		}, 'E2E contentpreview: math');
		created.push(mathId);

		quoteId = await createItem({
			labels: { en: { language: 'en', value: `E2E preview quote ${stamp}` } },
			claims: {
				[instanceOf]: claims(instanceOf, itemValue(quotationClass)),
				[contentText]: claims(contentText, mono('ORIGINAL-TEXT', 'en')),
				[translation]: claims(translation, mono('TRADUCTION-TEXTE', 'fr')),
				[attributedTo]: claims(attributedTo, itemValue(mathId)),
			},
		}, 'E2E contentpreview: quotation');
		created.push(quoteId);

		const browser = await chromium.launch({ headless: !HEADED, executablePath: CHROME_PATH });
		const page = await browser.newPage();
		page.on('pageerror', (err) => pageErrors.push(String(err)));
		page.on('console', (msg) => {
			if ((msg.type() === 'error' || msg.type() === 'warning') && !ignoredConsole(msg)) {
				consoleErrors.push(`[${msg.type()}] ${msg.text()}`);
			}
		});

		try {
			// --- math note: plain wikitext + inline KaTeX -----------------
			await page.goto(`${BASE_URL}/wiki/${mathId}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
			await page.waitForSelector('.wb-content-preview .wb-embed-note', { timeout: 30000 });
			const mathState = await page.evaluate(() => {
				const note = document.querySelector('.wb-content-preview .wb-embed-note');
				return {
					hasEmbedClass: note.classList.contains('wb-embed'),
					hasKaTeX: note.querySelectorAll('.katex').length,
					text: note.textContent,
				};
			});
			if (mathState.hasEmbedClass) {
				failures.push('math note still carries the .wb-embed chrome (the stray line)');
			} else if (mathState.hasKaTeX === 0) {
				failures.push('math note did not typeset its inline $…$ with KaTeX');
			} else if (!mathState.text.includes('constant')) {
				failures.push(`math note missing its wikitext text: ${JSON.stringify(mathState.text)}`);
			} else {
				console.log('[ok] math note: plain wrapper + inline KaTeX');
			}

			// --- quotation preview: original + translation + attribution --
			// ?uselang=fr makes wgUserLanguage fr, so the preview negotiates
			// the fr translation on top of the en original.
			await page.goto(`${BASE_URL}/wiki/${quoteId}?uselang=fr`, { waitUntil: 'domcontentloaded', timeout: 60000 });
			await page.waitForSelector('.wb-content-preview blockquote', { timeout: 30000 });
			const quoteState = await page.evaluate(() => document.querySelector('.wb-content-preview blockquote').textContent);
			if (!quoteState.includes('ORIGINAL-TEXT')) {
				failures.push(`quotation preview missing the original: ${JSON.stringify(quoteState)}`);
			} else if (!quoteState.includes('TRADUCTION-TEXTE')) {
				failures.push(`quotation preview missing the fr translation: ${JSON.stringify(quoteState)}`);
			} else if (!quoteState.includes('E2E preview math')) {
				failures.push(`quotation preview missing the attribution: ${JSON.stringify(quoteState)}`);
			} else {
				console.log('[ok] quotation preview: original + fr translation + attribution');
			}

			// --- Add* success popup --------------------------------------
			await page.goto(
				`${BASE_URL}/wiki/Special:AddQuotation?addmore=1&created=${quoteId}`,
				{ waitUntil: 'domcontentloaded', timeout: 60000 });
			await page.waitForSelector('.wb-addmore-preview blockquote', { timeout: 30000 });
			const popupState = await page.evaluate(() => {
				const actions = document.querySelector('.wb-addmore-actions');
				const editLink = actions ? actions.querySelector('a.wb-update-basic-btn') : null;
				return {
					editHref: editLink ? editLink.getAttribute('href') : null,
					hasEmbed: !!(actions && actions.querySelector('#ca-wb-embed-copy')),
					hasCite: !!(actions && actions.querySelector('#ca-wb-embed-cite')),
					preview: document.querySelector('.wb-addmore-preview').textContent,
				};
			});
			if (!popupState.preview.includes('ORIGINAL-TEXT')) {
				failures.push('Add* popup preview did not show the just-added quotation');
			} else if (!popupState.editHref || !popupState.editHref.includes('UpdateQuotation')) {
				failures.push('Add* popup missing the "Edit content" link');
			} else if (!popupState.hasEmbed || !popupState.hasCite) {
				failures.push('Add* popup missing the Copy Embed / Copy citation actions');
			} else {
				console.log('[ok] Add* success popup: preview + Edit content / Copy Embed / Copy citation');
			}
		} finally {
			await browser.close();
		}
	} finally {
		if (!KEEP) {
			for (const qid of created) {
				try {
					await api({ action: 'delete', title: `Item:${qid}`, token, reason: 'contentpreview UX E2E cleanup', format: 'json' }, true);
				} catch (e) {
					console.error(`[warn] cleanup of ${qid} failed: ${e}`);
				}
			}
			console.log(`[ok] cleanup: deleted ${created.length} scratch item(s)`);
		} else {
			console.log(`[keep] leaving ${created.join(', ')} on the instance`);
		}
	}

	if (pageErrors.length) failures.push(`page errors: ${pageErrors.join(' | ')}`);
	if (consoleErrors.length) failures.push(`console errors: ${consoleErrors.join(' | ')}`);

	if (failures.length) {
		console.error(`contentpreview UX E2E FAILED:\n - ${failures.join('\n - ')}`);
		process.exit(1);
	}
	console.log('contentpreview UX E2E: all checks passed');
}

main().catch((err) => {
	console.error('contentpreview UX E2E FAILED:', err);
	process.exit(1);
});
