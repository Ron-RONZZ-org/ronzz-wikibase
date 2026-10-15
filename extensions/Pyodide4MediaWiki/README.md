# Pyodide4MediaWiki

A standalone MediaWiki extension that makes `class="pyodide"` code blocks
**executable in the reader's browser**, using
[Pyodide](https://pyodide.org/) — CPython compiled to WebAssembly. No server
execution, no per-request cost, no sandbox escape to the host: the Python runs
in a Web Worker on the reader's machine.

## Usage

Mark any code block with the `pyodide` class. The usual form is a
SyntaxHighlight block:

```wikitext
<syntaxhighlight lang="python" class="pyodide" copy>
print("hello", 1 + 1)
</syntaxhighlight>
```

SyntaxHighlight renders this as

```html
<div class="pyodide mw-highlight mw-highlight-lang-python … mw-highlight-copy">…</div>
```

The extension adds a **Run** button and an output panel below the block. One
interpreter is kept alive per page, so values persist across cells like a
notebook:

```wikitext
<syntaxhighlight lang="python" class="pyodide">
x = 21
</syntaxhighlight>

<syntaxhighlight lang="python" class="pyodide">
x * 2
</syntaxhighlight>
```

`stdout`/`stderr` stream into the output panel; the value of the last
expression is shown as the result; a traceback is shown on error.

Any element carrying the `pyodide` class works (`<pre class="pyodide">`,
`<code class="pyodide">`, …) as long as it contains a `pre`/`code` element (or
is itself one) holding the code.

## How it works

- **Server** (`src/Hooks.php`, `src/PyodideBlockDetector.php`): a
  `ParserAfterTidy` hook scans the parsed HTML for a `pyodide` class token. On
  a page that has one, the small ResourceLoader bootstrap
  (`ext.pyodide4mediawiki.run`) is queued and its site-wide config is
  exported. Pages without the marker load nothing extra.
- **Client** (`resources/ext.pyodide4mediawiki.js`): injects the Run control
  and, on first Run, starts the interpreter in a Web Worker created from the
  serialized worker body (`resources/pyodide.worker.js`). The worker loads
  Pyodide from the wiki's own origin and executes each block.
- **Assets**: the Pyodide distribution is installed per environment by
  `tools/install-pyodide.sh` into `resources/pyodide/` (gitignored — never
  committed, never a runtime CDN). The reader downloads it once; the browser
  then caches it.

## Configuration

| Variable | Default | Purpose |
|----------|---------|---------|
| `$wgPyodide4MediaWikiEnable` | `true` | Master switch. When off, blocks render as ordinary highlighted code. |
| `$wgPyodide4MediaWikiRequireLogin` | `false` | When `true`, only logged-in users see the Run control. |
| `$wgPyodide4MediaWikiPackages` | `[ "numpy", "matplotlib", "pandas", "scipy", "sympy" ]` | Pyodide packages pre-loaded before each block. Only packages vendored by `install-pyodide.sh` are available offline. |

## Security

Client-side execution runs Python **in the reader's browser session** (the DOM
and `fetch` are reachable through Pyodide's `js` module). On this instance
every editor is vetted, so the page content is trusted and execution is open
to all readers. Set `$wgPyodide4MediaWikiRequireLogin = true` if untrusted
users ever gain edit access. A future hardening option is a sandboxed
cross-origin runner (the GeoGebra player pattern); see
`../../docs/decisions/pyodide4mediawiki.md`.

## Tests

- `tests/Unit/PyodideBlockDetectorTest.php` — the pure detector.
- `tests/e2e/run_pyodide_e2e.py` — read-only `action=parse` checks.
- `tests/e2e/run_pyodide_ux_e2e.mjs` — Playwright: click Run, assert output.

License: GPL-2.0-or-later.
