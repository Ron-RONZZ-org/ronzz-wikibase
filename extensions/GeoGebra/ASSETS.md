# GeoGebra app bundle (installed, never committed)

The GeoGebra extension renders worksheets **client-side** by loading the
self-hosted GeoGebra Math Apps app inside a sandboxed, cookie-less iframe. The
app is **not committed** to this repo — it is installed into each environment
(dev/CI checkout, production `/var/www/ggb`) by:

```bash
tools/install-geogebra.sh [--force] [--dest DIR]
```

| | |
|---|---|
| Upstream | GeoGebra Math Apps Bundle — https://download.geogebra.org/package/geogebra-math-apps-bundle |
| Version | `5.4.930.2` (`geogebra-math-apps-bundle-5-4-930-2.zip`) |
| Bundle sha256 | `7e0b7b1dc51cebe1675de15fd296d8ebe7f49e407567656351af42e29f55c871` |
| `deployggb.js` sha256 | `7894ce225cfccf81940c8b35a6b6c4960db5169cb842c20cf99d5877997a335a` |
| `web3d.nocache.js` sha256 | `bdb5e15da87efbc5c39f40a16eec708fa0f085b0211ee601668bfc6625b00d4b` |
| Installed | `GeoGebra/deployggb.js` + `GeoGebra/HTML5/5.0/web3d/` (~48 MB) |
| Licence | **Non-commercial** — https://www.geogebra.org/license (see below) |

Scope of the install: `deployggb.js` and the `web3d` codebase (the
graphing / geometry / 3d / classic app). The other codebases in the bundle
(`web/`, `webSimple/`, `css/`) are not extracted — add them if an embed ever
requests another `appName`.

## Licence — read before deploying

The GeoGebra Math Apps Bundle's own `README.txt` states: *"You are free to
copy, distribute and transmit GeoGebra for non-commercial purposes."* The
ronzz.org instance is a personal/community, non-commercial wiki. The bundle is
**fetched from geogebra.org by the install script and never redistributed in
this repo** (the same pattern as the MathJax assets and the PlantUML jar).
The extension code itself is GPL-2.0-or-later; the GeoGebra app is **not**
GPL and must not be committed.

## Updating

Change `GEOGEBRA_VERSION`, the versioned `BUNDLE_URL`, and the three sha256
values in `tools/install-geogebra.sh` together, re-run with `--force`, and
update this file. Verify `disableJavaScript` + `useBrowserForJS` still exist
in the new bundle (`grep -rl disableJavaScript GeoGebra/HTML5/5.0/web3d/`).
