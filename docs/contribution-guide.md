# Wikibase contribution guide — wikibase.ronzz.org

How to edit the structured-data wiki on `wikibase.ronzz.org`. For server/ops details
see `README.md`; this guide is for **content editors**.

## 1. Access model (what you can do)

| Capability | Anonymous | Registered user | Admin (Rongzhou) |
|---|---|---|---|
| Read pages, search, SPARQL | ✅ | ✅ | ✅ |
| Create/edit items & properties | ❌ | ✅ | ✅ |
| Create accounts | ❌ | ❌ | ✅ (only sysops) |
| Delete/merge/block | ❌ | ❌ | ✅ |

- **Log in**: https://wikibase.ronzz.org/wiki/Special:UserLogin
- **First letter of usernames is case-insensitive** — typing `rongzhou` logs into `Rongzhou`.
- **Accounts are created only by the admin** (`Special:CreateAccount` as `Rongzhou`).
  Ask the admin if you need one.

## 2. Data model in 5 minutes

Wikibase is the software behind Wikidata. Two entity types exist on this instance:

| Entity | ID | Namespace | URL form | Example |
|---|---|---|---|---|
| **Item** | `Q1`, `Q2`, … | 120 | `/wiki/Item:Q1` | a book, a person, a place |
| **Property** | `P1`, `P2`, … | 122 | `/wiki/Property:P1` | "instance of", "author" |

Each entity has:
- **Terms**: multilingual *label* (unique per language per type), *description*, *aliases*
- **Statements**: `property → value`, optionally with *qualifiers* (details) and
  *references* (provenance) and a *rank* (preferred / normal / deprecated)

**Order of work**: define **Properties first** (the schema), then create **Items** (the data).

## 3. Before you create anything

1. **Search first** (`Special:Search`, or the search box) — don't create a duplicate
   item or property.
2. **Reuse existing properties** — browse
   [`Special:ListProperties`](https://wikibase.ronzz.org/wiki/Special:ListProperties)
   (sidebar → **Semantic tools** → "Browse properties") before proposing a
   new one. A property is expensive (its datatype is permanent).
3. **Labels are unique per language** — an Item cannot have two labels for the same language.
   Use a description to disambiguate.

## 4. Creating a Property

1. Go to **[`Special:NewProperty`](https://wikibase.ronzz.org/wiki/Special:NewProperty)**
   (also linked in the sidebar → **Semantic tools** → "Create new property").
2. Fill in at least a **label** (e.g. `instance of`) and a **description**
   (e.g. `that class of which this subject is a particular example and member`).
   Add aliases (`is-a`, `isa`).
3. **Choose the datatype** — this is the most important decision and
   **cannot be changed after creation**:

   | Datatype | Use for | RDF/example value |
   |----------|---------|--------------------|
   | `Item` | link to another entity | `Q5` (person) |
   | `Property` | link to another property | `P31` |
   | `String` | free text | `"ISBN-13"` |
   | `ExternalId` | identifier in another system | `Q1234` → `https://www.wikidata.org/wiki/Q1234` |
   | `URL` | web address | `https://example.org` |
   | `Monolingual text` | text with a language tag | `"hello"@en` |
   | `Quantity` | numbers with units | `3` (unit Q11573, kg) |
   | `Time` | dates | `2026-08-15T00:00:00Z` |
   | `Globe coordinate` | lat/lon | `48.85N 2.35E` |
   | `Commons media` | a file name | `File:Foo.jpg` |

   The dropdown in `Special:NewProperty` shows the full available set.
4. **For `ExternalId`** set the **Formatter URL** (formatter-url field): e.g.
   `https://www.wikidata.org/wiki/$1` — makes values dereferenceable URIs in RDF.
5. Save → the property gets its permanent ID (`P1`, `P2`, …). Other editors can now
   use it in statements.

## 5. Creating an Item

1. Go to **[`Special:NewItem`](https://wikibase.ronzz.org/wiki/Special:NewItem)**
   (also linked in the sidebar → **Semantic tools** → "Create new item").
2. Fill in **label** (English at minimum — `en`; other languages welcome), a
   **description**, and optional aliases.
3. Save → the item gets its permanent ID (`Q1`, `Q2`, …).
4. **Add statements** (next section). An item with no statements is just a stub.

## 6. Adding & editing statements

On an item page (`/wiki/Item:Q1`):

1. Click **`+ add statement`** (or "+ add value" for more values of one property).
2. **Property**: start typing `P1` or its label → autocomplete.
3. **Value**: depends on the datatype (item → start typing another entity's label).
4. **Qualifiers** (optional): refine the statement, e.g. property "point in time"
   `P585` on "population → 2.1 million".
5. **References** (recommended for facts — this is the provenance layer):
   "Add reference" → e.g. property "reference URL" `P854`, "stated in" `P248`,
   "retrieved" `P813`. Every fact should say *where it comes from*.
6. **Rank**: mark conflicting/outdated values as `deprecated`, best value as `preferred`.

Editing tips:
- Statement values are editable by clicking them; remove a statement with the
  ✕ control.
- Terms (label/description/aliases) are edited directly in the entity header —
  click the pencil icon, choose the language tab.
- Undo/rollback via the page **history** tab (every change is a wiki revision).

## 7. Deleting & merging (admin actions)

- **Merge** two duplicate items: use "merge" on the item page
  (rights: `item-merge`, granted to registered users).
- **Delete** an entity page:
  [`Special:DeletePage`](https://wikibase.ronzz.org/wiki/Special:DeletePage)
  (`Item:Q12`) — admin only.
  Deleting a property is rare and breaks statements using it — prefer deprecating.

## 8. API & bulk editing

The full Wikibase API is available at `https://wikibase.ronzz.org/api.php`.
(Internal mirror `http://127.0.0.1:8081/api.php` exists **only on the server
`ronzz-linux-server-2` itself** — from anywhere else, always use the public
`https://wikibase.ronzz.org` URL.) Requires login + CSRF token:

```bash
# 1) Log in (adjust host/credentials)
curl -s -c /tmp/wb.cookies "https://wikibase.ronzz.org/api.php" \
  --data-urlencode "action=query" --data-urlencode "meta=tokens" \
  --data-urlencode "type=login" --data-urlencode "format=json"
# …POST action=login with lgname/lgpassword/lgtoken, then:
TOKEN=$(curl -s -b /tmp/wb.cookies "https://wikibase.ronzz.org/api.php?action=query&meta=tokens&format=json" \
  | python3 -c "import json,sys; print(json.load(sys.stdin)['query']['tokens']['csrftoken'])")

# 2) Create an item (wbeditentity)
curl -s -b /tmp/wb.cookies "https://wikibase.ronzz.org/api.php" \
  --data-urlencode "action=wbeditentity" --data-urlencode "new=item" \
  --data-urlencode "token=$TOKEN" \
  --data-urlencode 'data={"labels":{"en":{"language":"en","value":"My first item"}}}' \
  --data-urlencode "format=json"
```

Key API actions: `wbeditentity` (create/update), `wbgetentities` (read),
`wbcreateclaim`/`wbsetclaim` (statements), `wbsearchentities` (find by label),
`wbremoveclaims`. REST API also available under `/w/rest.php/wikibase/`.

**Bulk tools** (not installed — run from your own machine):
- [WikibaseIntegrator](https://github.com/LeMyst/WikibaseIntegrator) (Python)
- [Pywikibot](https://www.mediawiki.org/wiki/Manual:Pywikibot)
- [QuickStatements](https://www.wikidata.org/wiki/Help:QuickStatements) (spreadsheet-style batch)

## 9. Querying (SPARQL)

Public endpoint: `https://wikibase.ronzz.org/sparql`

```sparql
# All books in the knowledge base
SELECT ?book ?bookLabel WHERE {
  ?book wdt:P31 wd:Q571 .            # instance of (P31) → book (Q571)
  SERVICE wikibase:label { bd:serviceParam wikibase:language "en". }
}
```

See `README.md` for curl examples; the
[Wikidata SPARQL tutorial](https://www.wikidata.org/wiki/Wikidata:SPARQL_tutorial)
works verbatim here.

## 10. House rules for this instance

1. **One concept per item** — merge duplicates instead of creating near-copies.
2. **Reuse properties** — propose a new property only when nothing fits.
3. **Cite your sources** — add at least one reference per factual statement
   (that's what makes it a knowledge base, not just a wiki).
4. **English labels are mandatory**; additional languages are welcome
   (site language: `en`).
5. **Datatype is forever** — double-check the datatype before creating a property.
6. **Ask the admin** (`Rongzhou`) for an account, for deletions, or to install
   bulk tools.

## 11. Importing authors, sources and collectives from external authorities (issue #7)

Instead of hand-creating an item per author or source, use the external-authority
pages (semantic-tools sidebar). They fetch metadata from public services
(Wikidata, dblp, OpenAlex, Crossref, Open Library, ORCID), let you pick the
right record, and create a local stub item with the authority identifiers
(ORCID, VIAF, ISNI, DOI, ISBN, …) and citation metadata (given/family name,
published in, publisher, pages, volume, issue) — every imported statement
carries an `imported from <authority>` reference.

| Page | Creates | Input |
|---|---|---|
| **`Special:AddPerson`** | a `person` item | ORCID iD, Wikidata Q, or a name |
| **`Special:AddSource`** | a work item (book / scholarly article / website / song / film / video) | DOI, ISBN-13, or a title |
| **`Special:AddCollective`** | a non-person agent item (organization, company, band, collective, institution) | a name |

Workflow: search → review the candidates (same-name disambiguation) → confirm
the class (pre-inferred from the authority) → create. Repeating a lookup for an
already-imported label reuses the existing item (create-or-skip). English label
comes from the authority; add fr/eo labels afterwards. Fetch failures degrade
to manual item creation (`Special:NewItem`).

Notes:

- The authority lookups run **only when you click search** — never on page load.
- The provenance form on `Special:AddQuotation` (and the other content pages)
  has entity **search + autofill** for `attributed to` / `source`: type a name,
  pick the entity, the item id is filled in. Manually typed ids still work.
- Citations use the source item's harvested metadata (journal name, publisher,
  volume/issue/pages, DOI, ISBN) and cite the source **type** correctly (a book
  quote cites as a book, a song quote as a song).

## 12. Multilingual content & translating pages (Aug 18 2026)

The knowledge base is multilingual by design (see on-wiki
**`Help:Contributing/languages`** for the full house rules).

**Entity terms** (labels/descriptions/aliases): stored per language *inside* each
entity — open the entity in edit mode, use the "In more languages" section, pick a
language code (`en`, `fr`, `de`, `eo`, …) and enter the value. No page translation
involved.

**Wiki pages**: marked for translation by a translation administrator
(`pagetranslation` right), then translated through
[`Special:Translate`](https://wikibase.ronzz.org/wiki/Special:Translate):

1. Pick a page group (e.g. `page-Help:Contributing/languages`) and a language.
2. Translate unit by unit; save each unit.
3. Translated pages appear at `/lang` subpages — readers using
   `Special:MyLanguage/…` links get their language automatically.

House rules: translate only the human-readable text (never `tvar` placeholders or
markup); keep terminology consistent with the entity terms used in the data layer;
machine translation is a starting point, always review it before saving.

## 13. Official docs

- Wikibase: https://www.mediawiki.org/wiki/Wikibase
- Wikibase API: https://www.wikidata.org/w/api.php (action=wb…)
- Wikidata SPARQL tutorial: https://www.wikidata.org/wiki/Wikidata:SPARQL_tutorial
- SPARQL 1.1 spec: https://www.w3.org/TR/sparql11-query/
- WikibaseIntegrator: https://github.com/LeMyst/WikibaseIntegrator
