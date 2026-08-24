# DPLforum (vendored)

Third-party MediaWiki extension, vendored as a plain file copy (the instance
deploy model — extensions are file copies rsynced from the repo, not git
checkouts).

| | |
|---|---|
| Upstream | https://gerrit.wikimedia.org/r/mediawiki/extensions/DPLforum (GitHub mirror: https://github.com/wikimedia/mediawiki-extensions-DPLforum) |
| Version | 3.7.3 |
| Vendored commit | `18df74f0c05d013c89e8e07a91a3d0545a6be2c3` (master, 2026-08-24) |
| License | GPL-2.0-or-later (declared in `extension.json` + file headers; no standalone LICENSE file upstream) |
| MW requirement | `>= 1.39.4` (instance runs 1.46) |
| DB changes | none |

Scope of the vendor: runtime files only (`src/`, `i18n/`, `extension.json`,
`DPLforum.i18n.magic.php`, `DPLforum.namespaces.php`, `composer.json`,
`README.md`). Upstream dev tooling (`Gruntfile.js`, `package.json`,
`package-lock.json`, `CODE_OF_CONDUCT.md`) is intentionally not vendored.

DPLforum registers the `Forum:` (NS 110) / `Forum_talk:` (NS 111)
namespaces via `extension.json` and provides the `<forum>` parser tag
(board/thread listings) + `#forumlink`. Threads and boards are ordinary wiki
pages — watchlists, history, search and permissions all work natively. No
auto topic creation.

See `docs/decisions/forum-dplforum.md` (repo ADR) for the choice rationale.
