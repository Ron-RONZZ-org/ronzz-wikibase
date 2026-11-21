# Vendored: TOAST UI Image Editor

The `Special:Upload` / Add\*/Update\* image editor. Vendored (never loaded
from a CDN) because the instance's frontend assets are self-hosted.

| Asset | Package | Version | Licence |
|---|---|---|---|
| `tui-image-editor.min.js` | `tui-image-editor` | 3.15.3 | MIT (NHN Cloud) |
| `tui-image-editor.min.css` | `tui-image-editor` | 3.15.3 | MIT (NHN Cloud) |
| `tui-color-picker.min.js` | `tui-color-picker` | 2.2.8 | MIT (NHN Cloud) |
| `tui-color-picker.min.css` | `tui-color-picker` | 2.2.8 | MIT (NHN Cloud) |

The editor UMD bundle externalises only `tui-color-picker` (fabric.js is
bundled); the CSS carries its icons as data URIs, so no `dist/svg` directory
is needed.

## Telemetry

The editor bundle contains a Google Analytics "usage statistics" ping. It is
disabled before the bundle loads by `resources/tui-telemetry-off.js`:

```js
window.tui = window.tui || {};
window.tui.usageStatistics = false;
```

Keep that script FIRST in the `ext.embeddableContent.imageeditor` module. The
editor constructor also receives `usageStatistics: false` as a second guard.

## SHA-256

```
1f5b88ac77e5736b964bb916d2ccba88cf75df5f18e9b938a18b4ae73d1692e1  tui-color-picker.min.js
475659f7007c85758b2a235bcb771e49e99019afc94b97e7b1d3dbec2c0764f5  tui-image-editor.min.js
00c66f7ce39f333065af01e5206f8d5b37e08c11c7065b1cb3697b031f0a7d02  tui-color-picker.min.css
0e1b36ee5a82de15e83296b40fdbd39f6686d069c68f2f2075d0284eaa50c5f8  tui-image-editor.min.css
```

## Refresh

```bash
base=https://unpkg.com
curl -fsSL $base/tui-image-editor@3.15.3/dist/tui-image-editor.min.js -o tui-image-editor.min.js
curl -fsSL $base/tui-image-editor@3.15.3/dist/tui-image-editor.min.css -o tui-image-editor.min.css
curl -fsSL $base/tui-color-picker@2.2.8/dist/tui-color-picker.min.js -o tui-color-picker.min.js
curl -fsSL $base/tui-color-picker@2.2.8/dist/tui-color-picker.min.css -o tui-color-picker.min.css
sha256sum *.js *.css
```

Bump the versions here and in `extensions/AGENTS.md` when refreshing.
