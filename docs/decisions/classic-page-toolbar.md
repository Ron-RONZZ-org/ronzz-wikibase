# Decision: classic-page toolbar parity with the Item: page

- **Status**: Accepted (Sep 2026)
- **Scope**: `wikibase.ronzz.org` — the classic per-kind pages
  (`Source:`/`FOSS:`/`Person:`/`Collective:`/`Software:`) and the item action
  toolbar (`includes/Hooks.php` + `resources/gadget.js`)
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

An item created through an Add* flow gets a classic page in its per-kind
namespace. The Item: page renders the item action toolbar — "Update basic
information" (or "Edit content"), "Copy internal citation" (source classes)
and the embed/citation gadget — but the classic pages did not match:

- `Source:` pages rendered only the "Copy internal citation" button;
- `FOSS:`/`Person:`/`Collective:`/`Software:` pages rendered no toolbar at
  all.

Editors reaching a classic page (the natural entry point from a content page)
had to open the Item: page to update the item or copy its citation.

## Decision

The classic per-kind pages render the **same** action toolbar as the item's
Item: page. `Hooks::wireItemToolbar( $out, $itemId )` is the single wiring,
called for:

- the Item: namespace (the item id parsed from the title), and
- the classic namespaces (`NS_FOSS`, `NS_PERSON`, `NS_SOURCE`,
  `NS_COLLECTIVE`, `NS_SOFTWARE`) when the page is sitelinked to an item —
  the page → item id comes from the site-link store (the same resolution the
  parser functions and the Sitelink tab use), so no client API roundtrip.

The wiring loads the embed/citation gadget, the "Update basic information" /
"Edit content" button (when the item's class has a `Special:Update*`
counterpart) and the "Copy internal citation" button (source-class items).
Buttons that do not apply render nothing (the gadget's embed/citation buttons
appear only when the API confirms they can be built).

`gadget.js` takes the item id from the new `wbEmbedItem` config var — on a
classic page `wgTitle` is the page title, not the Q-id — falling back to
`wgTitle` for an entity page rendered without the config var.

## Consequences

- A `/fr` or `/eo` translation subpage of a classic page has no sitelink of
  its own and therefore renders no toolbar (the site-link lookup misses) —
  correct: the toolbar acts on the item, and the translation copy is a
  separate page.
- The classic pages now make the same `action=embed` / `action=citation`
  probes the Item pages do; for a source item only the citation button
  applies, for a person/FOSS item neither does.
- No vocabulary / config-map / DB change. Regression coverage:
  `flow_classic_page_toolbar` in `run_pages_e2e.py` (a Source: and a FOSS:
  page assert the `wbEmbedItem` / `wbUpdateBasicInfoUrl` / module wiring).
