# ADR — Client-side Python execution via Pyodide (`extensions/Pyodide4MediaWiki`)

Status: accepted (2026-10-15). Owner: ronzz.org.

## Context

Editors want Python code snippets to be **runnable** — the Jupyter/Quarto
notebook experience — not merely syntax-highlighted. The expected authoring
form keeps the existing SyntaxHighlight surface and marks the block:

```wikitext
<syntaxhighlight lang="python" class="pyodide" copy>
print("hello", 1 + 1)
</syntaxhighlight>
```

SyntaxHighlight already accepts a `class` attribute (verified on the instance:
`class="pyodide"` renders as `<div class="pyodide mw-highlight
mw-highlight-lang-python … mw-highlight-copy">`) and the `copy` feature is
already in use, so no tag surface is invented.

### Survey — no existing MediaWiki extension does this

| Extension / project | What it does | Why it does not fit |
|---------------------|--------------|---------------------|
| `Extension:Piwo` | runs Python **server-side** (Python In / Wikitext Out) via a `Gram:` namespace | server execution; not client-side; not the `<syntaxhighlight>` flow |
| `Extension:Code` | "code actions" — links a block to an external service (e.g. a SPARQL block → WDQS `run=`) | redirects elsewhere; does not execute locally |
| `Extension:SyntaxHighlight` | highlighting only (`class`/`copy` attributes) | no execution |
| WikiLambda "Jupyter kernel proposal" | **server-side** kernels | not client-side, never shipped |
| JupyterLite / Pyodide / Open WebUI / VS Code for the Web | browser Pyodide integration | not MediaWiki; no `<syntaxhighlight>` integration |

Pyodide itself is the standard browser Python (CPython compiled to
WebAssembly) and self-hosts cleanly (a pinned release core tarball plus
per-package wheels).

## Decision

Build a small **house extension, `extensions/Pyodide4MediaWiki/`**, following
the CodeBlockSpaces (server-side detection) and GeoGebra/SimpleMathJax
(per-environment asset install) patterns:

1. **Server** — `Hooks::onParserAfterTidy()` scans the assembled page HTML for
   a `pyodide` class token (`PyodideBlockDetector`, pure — the marker must be
   an exact whitespace-delimited token). On a page that has one it queues the
   small ResourceLoader module `ext.pyodide4mediawiki.run` and exports
   **site-wide** config (`ParserOutput::setJsConfigVar`: the local asset base
   URL, the package list, the login-gate flag). Pages without the marker load
   nothing extra. The legacy parser is the render path for page views; the
   Parsoid/VisualEditor live-preview path does not fire `ParserAfterTidy`
   (documented limitation).
2. **Client** — `resources/ext.pyodide4mediawiki.js` injects a Run control
   (reusing the SyntaxHighlight copy-button styling) and an output panel below
   each `pyodide` block, then runs the block in a **module Web Worker** (the
   worker body is serialized via `Function.prototype.toString()` and
   instantiated from a Blob URL with a module-level `import { loadPyodide }
   from "<indexUrl>pyodide.mjs"`; Pyodide 314 dropped the classic-worker
   build). **One interpreter per page**, so variables persist across cells.
   `stdout`/`stderr` stream to the panel; the last expression's value is
   shown; a traceback is shown on error. Output is rendered as text only.
3. **Assets** — `tools/install-pyodide.sh` installs the pinned Pyodide core
   release tarball (sha256-checked) plus the **transitive dependency closure**
   of the configured packages, whose wheels are verified against the
   distribution's own `pyodide-lock.json` sha256s. Served from the wiki's own
   origin (`$wgExtensionAssetsPath/Pyodide4MediaWiki/resources/pyodide/`) —
   **never a runtime CDN** (repo rule), gitignored.

## Security model

Client-side execution runs Python **in the reader's browser session**: via
Pyodide's `js` module the code can reach the DOM and `fetch`. On this instance
every editor is vetted, so page content is trusted and execution is **open to
all readers** (`$wgPyodide4MediaWikiRequireLogin = false` by default). The
login gate is a configurable option (`true` → only logged-in users see the Run
control) and is applied **client-side** against `wgUserName` — never baked into
the cached parser output, which is shared across users.

If untrusted users ever gain edit access, the documented hardening path is a
sandboxed **cross-origin** runner (the GeoGebra cookie-less-player pattern,
e.g. a `py.ronzz.org` origin behind a `sandbox="allow-scripts"` iframe), which
removes the reader's session from the interpreter's reach.

## Consequences

- No server execution cost; the wiki host serves static assets only.
- The first Run downloads the interpreter (~7 MB core + the package wheels)
  once; the browser caches it. Subsequent Runs are instant.
- Deploy = rsync the extension + run `tools/install-pyodide.sh` on the server
  (or into the deployed extension dir with `--dest`) + `wfLoadExtension` +
  php-fpm restart / parser-cache purge (the hook changes cached parser output).
- Re-vendoring a new Pyodide version updates the pinned version + core sha256
  in the install script; the wheel sha256s come from the new lock file.
