/* eslint-disable no-unused-vars */
/*
 * UMD export shim for the vendored highlight.js dist build.
 *
 * The dist exports ONLY via the CommonJS branch
 * (`typeof exports === "object" && typeof module !== "undefined"`), but
 * ResourceLoader's module scope provides `module` without `exports` — so the
 * library would be dropped (no window.hljs, no module.exports). Defining
 * `exports` in this shared module scope (scripts run in one function) makes
 * the export branch fire and populates module.exports for
 * mw.loader.require().
 */
var exports = {};
