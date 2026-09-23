# GeoGebra (house extension)

Interactive GeoGebra worksheets on classic wiki pages: upload a `.ggb` and
embed it with `[[File:name.ggb|600px]]`.

Standalone MediaWiki extension (`type: media`, GPL-2.0-or-later, MW ≥ 1.46).
See [`AGENTS.md`](AGENTS.md) for the module contract, [`ASSETS.md`](ASSETS.md)
for the app-bundle provenance/licence, and
`../../docs/decisions/geogebra.md` for the design rationale.

## Components

| File | Role |
|------|------|
| `extension.json` | MIME + media-handler registration, hooks, config, RL module |
| `src/Hooks.php` | `MimeMagicInit` + `MimeMagicImproveFromExtension` (`.ggb` → `application/geogebra`) |
| `src/GeoGebraHandler.php` | `ImageHandler` subclass — client-side transform |
| `src/GeoGebraOutput.php` | `MediaTransformOutput` — emits the sandboxed player iframe |
| `src/GeoGebraEmbed.php` | pure URL/attribute builders (unit-tested) |
| `resources/player/` | the cookie-less player page (`player.html`/`.js`/`.css`) |
| `resources/player/GeoGebra/` | the app bundle — installed by `tools/install-geogebra.sh` (gitignored) |

## Install (each environment)

```bash
tools/install-geogebra.sh                      # into the repo player dir (dev/CI)
tools/install-geogebra.sh --dest /var/www/ggb  # production static root
```

## Configure

```php
wfLoadExtension( 'GeoGebra' );
$wgGeoGebraPlayerUrl = 'https://ggb.ronzz.org/player.html'; // '' disables embedding
$wgFileExtensions[] = 'ggb';
$wgTrustedMediaFormats[] = 'application/geogebra';
```

The wiki must also serve `.ggb` with `Access-Control-Allow-Origin` for the
player origin (the applet fetches the file cross-origin).
