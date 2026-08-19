# Translation glossary — en / fr / eo

Canonical translations of instance terms used by LLM page translation (see
`AGENTS-translation.md` and `docs/decisions/static-llm-translation.md`).

**Reference for data-model terms**: Wikidata's own terminology (fr = Wikibase/Wikidata French,
eo = Wikidata Esperanto labels — items are *eroj*, properties *ecoj*). For MediaWiki UI terms,
use the MediaWiki fr/eo interface translations.

Keep this list small; add a term when it recurs and the LLM's choice would otherwise drift.
Mark uncertain entries with `(verify)` until confirmed on the instance.

| en | fr | eo | notes |
|---|---|---|---|
| entity | entité | ento | |
| item | élément | ero | Wikidata eo |
| property | propriété | eco | Wikidata eo |
| statement | déclaration | aserto | Wikidata eo |
| instance of | instance de | ekzemplero de | P31 label (Wikidata eo) |
| subclass of | sous-classe de | subklaso de | P279 |
| qualifier | qualificatif | kvalifikilo | Wikidata eo |
| rank | rang | rango | |
| reference | référence | referenco | |
| label | libellé | etikedo | MediaWiki eo |
| description | description | priskribo | MediaWiki eo |
| alias | alias | aliaso | |
| query | requête | demando | Wikidata eo ("SPARQL-demando") |
| code block | bloc de code | kodbloko | |
| syntax highlighting | coloration syntaxique | sintaksa kolorigo | |
| cheatsheet | aide-mémoire | memorigilo | informal fr "antisèche" — avoid |
| house rules | règles internes | domreguloj | literal fr "règles de la maison" — avoid |
| upload | téléversement | alŝuto | MediaWiki fr/eo UI |
| file | fichier | dosiero | |
| license | licence | permesilo | MediaWiki eo |
| special page | page spéciale | speciala paĝo | |
| namespace | espace de noms | nomspaco | |
| revision | révision | revizio | |
| edit summary | résumé de modification | redakta resumo | |
| bot | robot | roboto | MediaWiki eo |
| main page | page d'accueil | ĉefpaĝo | |

## Proper nouns (never translate)

`Wikibase`, `SeedBot`, `Rongzhou`, `Ronzz`, `wikibase.ronzz.org`, `Cheatsheets:` (namespace
prefix), `Help:Contributing` (page-title prefix), entity IDs (`Q\d+`, `P\d+`), property names
as rendered (`instance of`, `subclass of` — keep in the position they play in statements).