# AGENTS.md — Pyodide4MediaWiki extension

## Summary

Standalone house MediaWiki extension that makes `class="pyodide"` code blocks
**executable client-side in the reader's browser** with
[Pyodide](https://pyodide.org/) (CPython compiled to WebAssembly). The usual
form is a SyntaxHighlight block:

```wikitext
<syntaxhighlight lang="python" class="pyodide" copy>
print("hello", 1 + 1)
</syntaxhighlight>
```

The extension adds a **Run** button and an output panel below each such block;
one interpreter is kept alive per page (variables persist across cells, like a
notebook). There is **no server-side execution** — Python never runs on the
wiki host.

A survey found **no maintained MediaWiki extension that executes
client-side Python** (`Extension:Piwo` runs Python server-side;
`Extension:Code` only links blocks to external services). Design rationale:
`../../docs/decisions/pyodide4mediawiki.md`.

## How it works

1. **Detector** (`src/PyodideBlockDetector.php`, pure): reports whether the
   parsed HTML contains a `pyodide` class token. The marker must be an exact
   whitespace-delimited token, so `pyodide-something` does not opt in. Both
   quote styles are accepted (MediaWiki renders double quotes).
2. **Hook** (`src/Hooks.php`): `ParserAfterTidy` runs on the assembled page
   HTML. When a marker is present the extension queues the small
   ResourceLoader module `ext.pyodide4mediawiki.run` and exports its
   site-wide config via `ParserOutput::setJsConfigVar()` — index URL of the
   Pyodide distribution, the package list, and the login-gate flag. Pages
   without the marker load nothing.
3. **Bootstrap** (`resources/ext.pyodide4mediawiki.js`): on `wikipage.content`
   / DOM ready, injects the Run control into each `pyodide` block. On first
   Run it creates a Web Worker from the serialized worker body
   (`resources/pyodide.worker.js`, via `Function.prototype.toString()` + a
   Blob URL) and initialises the interpreter; the reader downloads the
   distribution once, then the browser caches it.
4. **Worker** (`resources/pyodide.worker.js`): a self-contained function that
   `importScripts()` the classic `pyodide.js`, calls
   `loadPyodide({ indexURL, packageBaseUrl })` — **both pinned to the wiki's
   own origin** so nothing is ever fetched from Pyodide's bundled CDN default —
   installs `setStdout`/`setStderr` batched handlers that stream to the page,
   and runs each block with `runPythonAsync`.
5. **Assets** (`tools/install-pyodide.sh`, gitignored): the pinned Pyodide
   core release tarball (sha256-checked) plus the transitive dependency
   closure of the configured packages, whose wheels are fetched from the
   pinned release mirror and verified against the distribution's own
   `pyodide-lock.json` sha256s.

## Constraints and Invariants

- **No server-side Python execution.** The whole point is client-side
  execution; never add a server runner.
- **Never a runtime CDN.** The distribution is served from the wiki's own
  origin (`$wgExtensionAssetsPath/Pyodide4MediaWiki/resources/pyodide/`),
  installed per environment by `tools/install-pyodide.sh`. `packageBaseUrl`
  must stay pinned to that local directory so `loadPackage` cannot fall back
  to Pyodide's bundled jsDelivr default.
- **Never commit the distribution** — it is installed, like the MathJax and
  GeoGebra asset trees.
- **The login gate is client-side** (`wgUserName`), never baked into the
  cached parser output — the parser output is shared across users.
- **Output is rendered as text only** (`textContent`), never `innerHTML`.
- i18n ships en/fr/eo (+ qqq).
- Security: content is trusted on this instance (all editors are vetted) so
  execution is open to anonymous readers by default
  (`$wgPyodide4MediaWikiRequireLogin = false`). If untrusted users ever gain
  edit access, enable the gate and/or move to a sandboxed cross-origin runner
  (the GeoGebra player pattern).
- No DB, seed, manifest or config-map surface — a deploy is an rsync of the
  code + the install script on the server + `wfLoadExtension` + a php-fpm
  restart / parser-cache purge (the hook changes cached parser output).

## Input/Output Expectations

- **Input**: page HTML (the `ParserAfterTidy` string); a `pyodide`-marked code
  block chosen by the reader; the vendored Pyodide distribution.
- **Output**: a Run control + an output panel (client-side DOM); no server
  output.
- **Unit-test surface**: `tests/Unit/PyodideBlockDetectorTest.php` covers the
  pure detector. The MW-bound paths (the hook, module registration, the
  rendered page) are covered by `tests/e2e/run_pyodide_e2e.py` (dev-stack CI +
  live; read-only) and the browser run by
  `tests/e2e/run_pyodide_ux_e2e.mjs` (Playwright).

## Documentation Reference

- `../../docs/decisions/pyodide4mediawiki.md` — the design decision + the
  "no existing extension" survey + the security model.
- `README.md` — usage + configuration.
- On-wiki: `Help:Contributing/code`.
- `RonzzIT:Deployment/Wikibase` + `RonzzIT:Runbook/Wikibase` — instance ops.

## Domain-Specific Rules for Agents

- Keep the marker contract tiny: the `pyodide` class token is the whole
  opt-in. Do not add new per-block attributes without an ADR.
- When bumping the Pyodide version, update **both** the version and the core
  tarball sha256 in `tools/install-pyodide.sh`, and re-run the install; the
  wheel sha256s come from the new lock automatically.
- Do not hard-code package file names or dependencies — they are resolved
  from `pyodide-lock.json`.
