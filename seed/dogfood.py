"""Dogfood (example) entities created by the seed, plus claim builders.

The dogfood entities are the acceptance targets of the self-verification
stage: one quotation, one code snippet, one math item, one person, one book
(issue #6, §2). Values-as-claims; everything keyed by the manifest *labels* of
the vocabulary properties, resolved to ids by the orchestrator.

License: GPL-2.0-or-later
"""

from __future__ import annotations

from typing import Any

WIKIDATA_GREGORIAN = "http://www.wikidata.org/entity/Q1985727"

PERSON_LABELS = {
    "en": "Ada Lovelace",
    "fr": "Ada Lovelace",
    "eo": "Ada Lovelace",
}
PERSON_DESCRIPTIONS = {
    "en": "English mathematician and writer (1815-1852)",
    "fr": "Mathématicienne et écrivaine anglaise (1815-1852)",
    "eo": "Angla matematikistino kaj verkistino (1815-1852)",
}

BOOK_LABELS = {
    "en": "Notes by the Translator",
    "fr": "Notes du traducteur",
    "eo": "Notoj de la tradukinto",
}
BOOK_DESCRIPTIONS = {
    "en": "Ada Lovelace's translation notes on Menabrea's Sketch of the Analytical Engine (1843)",
    "fr": "Notes de traduction d'Ada Lovelace sur l'Esquisse de la machine analytique de Menabrea (1843)",
    "eo": "Traduknotoj de Ada Lovelace pri la Skizo de la analiza maŝino de Menabrea (1843)",
}

QUOTATION_LABELS = {
    "en": "The Analytical Engine has no pretensions whatever to originate anything",
    "fr": "La machine analytique ne prétend nullement être à l'origine de quoi que ce soit",
    "eo": "La analiza maŝino tute ne pretendas origini ion ajn",
}
QUOTATION_DESCRIPTIONS = {
    "en": "Quotation by Ada Lovelace about the Analytical Engine",
    "fr": "Citation d'Ada Lovelace à propos de la machine analytique",
    "eo": "Citaĵo de Ada Lovelace pri la analiza maŝino",
}
QUOTATION_TEXT = {
    "en": (
        "The Analytical Engine has no pretensions whatever to originate anything. "
        "It can do whatever we know how to order it to perform."
    ),
    "fr": (
        "La machine analytique ne prétend nullement être à l'origine de quoi que ce soit. "
        "Elle peut faire tout ce que nous savons lui ordonner d'exécuter."
    ),
    "eo": (
        "La analiza maŝino tute ne pretendas origini ion ajn. "
        "Ĝi povas fari ĉion, kion ni scias ordoni al ĝi plenumi."
    ),
}

CODE_LABELS = {
    "en": "Factorial in Python",
    "fr": "Factorielle en Python",
    "eo": "Faktorialo en Python",
}
CODE_DESCRIPTIONS = {
    "en": "Recursive factorial function in Python",
    "fr": "Fonction factorielle récursive en Python",
    "eo": "Rekursia faktoriala funkcio en Python",
}
CODE_TEXT = "def factorial(n):\n    return 1 if n <= 1 else n * factorial(n - 1)\n"

MATH_LABELS = {
    "en": "Euler's identity",
    "fr": "Identité d'Euler",
    "eo": "Idento de Euler",
}
MATH_DESCRIPTIONS = {
    "en": "Mathematical expression e^(i pi) + 1 = 0",
    "fr": "Expression mathématique e^(i pi) + 1 = 0",
    "eo": "Matematika esprimo e^(i pi) + 1 = 0",
}
MATH_LATEX = "e^{i\\pi} + 1 = 0"


def url_claim(property_id: str, url: str) -> dict[str, Any]:
    """Statement with a URL value."""
    return {
        "mainsnak": {
            "snaktype": "value",
            "property": property_id,
            "datavalue": {"value": url, "type": "string"},
        },
        "type": "statement",
        "rank": "normal",
    }


def entity_claim(property_id: str, entity_id: str) -> dict[str, Any]:
    """Statement with a wikibase-item value."""
    return {
        "mainsnak": {
            "snaktype": "value",
            "property": property_id,
            "datavalue": {
                "value": {
                    "entity-type": "item",
                    "numeric-id": int(entity_id[1:]),
                    "id": entity_id,
                },
                "type": "wikibase-entityid",
            },
        },
        "type": "statement",
        "rank": "normal",
    }


def monolingual_claim(property_id: str, text: str, language: str) -> dict[str, Any]:
    """Statement with a monolingual-text value."""
    return {
        "mainsnak": {
            "snaktype": "value",
            "property": property_id,
            "datavalue": {"value": {"text": text, "language": language}, "type": "monolingualtext"},
        },
        "type": "statement",
        "rank": "normal",
    }


def time_claim(property_id: str, iso_date: str, precision: int = 9) -> dict[str, Any]:
    """Statement with a time value (precision: 9=year, 10=month, 11=day)."""
    return {
        "mainsnak": {
            "snaktype": "value",
            "property": property_id,
            "datavalue": {
                "value": {
                    "time": iso_date,
                    "timezone": 0,
                    "before": 0,
                    "after": 0,
                    "precision": precision,
                    "calendarmodel": WIKIDATA_GREGORIAN,
                },
                "type": "time",
            },
        },
        "type": "statement",
        "rank": "normal",
    }
