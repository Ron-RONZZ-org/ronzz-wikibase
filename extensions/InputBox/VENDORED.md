# InputBox (vendored)

Third-party MediaWiki extension, vendored as a plain file copy (the instance
deploy model — extensions are file copies rsynced from the repo, not git
checkouts).

| | |
|---|---|
| Upstream | https://gerrit.wikimedia.org/r/mediawiki/extensions/InputBox (GitHub mirror: https://github.com/wikimedia/mediawiki-extensions-InputBox) |
| Version | 0.3.0 |
| Vendored commit | `fbfd2c1faf5edc6a1da451a7755577f7b04a4f25` (REL1_46 branch, 2026-08-24) |
| License | MIT (`COPYING`) |
| MW requirement | `>= 1.46` (instance runs 1.46) |
| DB changes | none |

Scope of the vendor: runtime files only (`includes/`, `resources/`, `i18n/`,
`extension.json`, `composer.json`, `COPYING`). Upstream dev tooling
(`Gruntfile.js`, `package.json`, `package-lock.json`, `tests/`,
`CODE_OF_CONDUCT.md`) is intentionally not vendored.

InputBox provides the `<inputbox type=create>` widget — the free-text
page-title field used to start forum threads (boards in the `Forum:`
namespace; see `docs/decisions/forum-dplforum.md`). No auto topic creation.
