"""Builds the emitted LocalSettings fragment and the generated seed report.

The config fragment is the D3/D4 contract: instance-specific entity ids keyed
by *label-derived* keys, never hardcoded in extension code. The seed resolves
labels to ids via the API and emits this file for inclusion from
LocalSettings.php.

License: GPL-2.0-or-later
"""

from __future__ import annotations

import os

from typing import Any

# Property label (en) -> short kind used in the config map.
PROPERTY_KINDS = {
    "content text": ("payloadProperties", "quotation"),
    "code source": ("payloadProperties", "code"),
    "LaTeX source": ("payloadProperties", "math"),
    "programming language": ("programmingLanguage", None),
    "attributed to": ("provenance", "attributedTo"),
    "source URL": ("provenance", "sourceUrl"),
    "source": ("provenance", "source"),
    "date": ("provenance", "date"),
    # Issue follow-up: content-subject properties (math 'describes', code
    # 'implementation of'); both align to Wikidata main subject (P921).
    "describes": ("describes", None),
    "implementation of": ("implementationOf", None),
}

# Issue #7: authority ExternalId properties (Special pages write these).
EXTERNAL_ID_KINDS = {
    "Wikidata ID": "wikidata",
    "ORCID": "orcid",
    "VIAF ID": "viaf",
    "ISNI": "isni",
    "DOI": "doi",
    "ISBN-13": "isbn",
    "OpenAlex Work ID": "openalex",
    "PubMed ID": "pubmed",
    # Person OpenAlex author id (P5092-aligned) — Special:AddPerson field.
    "OpenAlex author ID": "openalexAuthor",
}

# Issue #7: citation-metadata properties (full harvest).
CITATION_METADATA_KINDS = {
    "given name": "givenName",
    "family name": "familyName",
    "published in": "publishedIn",
    # Publisher is entity-only on the instance (issue #35): the config key
    # resolves the item-typed "publisher (entity)" property, not the legacy
    # string "publisher" (P23), which the forms no longer write.
    "publisher (entity)": "publisher",
    # Journal is entity-only too (follow-up): the item-typed "journal
    # (entity)" property (P1433-aligned) replaces the string "published in"
    # for scholarly articles.
    "journal (entity)": "journal",
    "page(s)": "pages",
    "volume": "volume",
    "issue": "issue",
}

# Issue #7 (follow-up): person lifecycle properties (Special:AddPerson
# statements — birth/death dates and places, portrait image + license).
PERSON_PROPERTY_KINDS = {
    "date of birth": "dateOfBirth",
    "place of birth": "placeOfBirth",
    "date of death": "dateOfDeath",
    "place of death": "placeOfDeath",
    # Portrait facts: the P18-aligned image (url) + the shared P275 license.
    "image": "image",
    "license": "license",
    # Image attribution (upload enhancements): free-text author + license
    # notes on the portrait/logo, stored as item statements (P2093-aligned
    # author name string + unaligned license info).
    "image author": "imageAuthor",
    "additional license information": "imageLicenseInfo",
}

# Issue follow-up: fictional-character class (Special:AddFictionalCharacter).
FICTIONAL_CHARACTER_CLASS_KINDS = {
    "fictional character": "fictionalCharacter",
}

# Issue follow-up: fictional-character properties (Special:AddFictionalCharacter
# statements): the multi-value `present in work` ("appears in") link.
FICTIONAL_CHARACTER_PROPERTY_KINDS = {
    "present in work": "appearsIn",
}

# Issue follow-up: collective properties (Special:AddCollective statements):
# the optional `parent organization` link (P749-aligned) + the logo facts
# (the shared P18-aligned image + the shared P275 license).
COLLECTIVE_PROPERTY_KINDS = {
    "parent organization": "parentOrganization",
    "image": "image",
    "license": "license",
    # Image attribution (upload enhancements): shared with the person/software
    # image facts (same property ids).
    "image author": "imageAuthor",
    "additional license information": "imageLicenseInfo",
}

CLASS_KINDS = {
    "quotation content": "quotation",
    "code snippet": "code",
    "mathematical expression": "math",
}

# Issue #7: agent classes (AddPerson / AddCollective).
AGENT_CLASS_KINDS = {
    "person": "person",
    "organization": "organization",
    "group of humans": "groupOfHumans",
    # Common collective classes (AddCollective class picker + harvest
    # inference; authors of works may also be classified under these).
    "private company": "privateCompany",
    "public company": "publicCompany",
    "non-profit organization": "nonProfitOrganization",
    "governmental agency": "governmentalAgency",
    "music band": "musicBand",
    "educational institution": "educationalInstitution",
    "research institute": "researchInstitute",
    "political party": "politicalParty",
    "trade union": "tradeUnion",
    "religious organization": "religiousOrganization",
    "sports team": "sportsTeam",
}

# Issue #7: source/work classes (AddSource).
SOURCE_CLASS_KINDS = {
    "book": "book",
    "scholarly article": "scholarlyArticle",
    "website": "website",
    "song": "song",
    "film": "film",
    "video": "video",
    "YouTube channel": "youtubeChannel",
    "YouTube video": "youtubeVideo",
    "web page": "webpage",
    "book excerpt": "bookExcerpt",
}

# Issue #7: source-class parent/child relations (child kind => parent kind).
# Child-class creation requires an existing parent-class item, picked on the
# form and linked via the `part of` statement.
SOURCE_PARENT_KINDS = {
    "book excerpt": ("bookExcerpt", "book"),
    "YouTube video": ("youtubeVideo", "youtubeChannel"),
    "web page": ("webpage", "website"),
}

# Issue #7: source-class specific properties (Special:AddSource statements).
SOURCE_PROPERTY_KINDS = {
    "part of": "partOf",
    "duration": "duration",
    "URL": "url",
    "YouTube channel ID": "youtubeChannelId",
    "YouTube video ID": "youtubeVideoId",
    "chapters": "chapters",
    # Issue #35: access field — the license property is shared with the FOSS
    # vocabulary (same property id, new config key), access URL + file are new.
    "license": "license",
    "access URL": "accessUrl",
    "file": "file",
}

# Issue #26: FOSS software properties (Special:AddSoftware statements).
FOSS_PROPERTY_KINDS = {
    "developer": "developer",
    "license": "license",
    "operating system": "operatingSystem",
    "official website": "officialWebsite",
    "source code repository": "sourceRepository",
    "software version": "softwareVersion",
    "has use": "hasUse",
    "replaces": "replaces",
    "user interface": "userInterface",
    "documentation URL": "documentationUrl",
    "image": "image",
    # Image attribution (upload enhancements): shared with the person
    # portrait vocabulary (same property ids).
    "image author": "imageAuthor",
    "additional license information": "imageLicenseInfo",
}

# Issue #26: FOSS software class (Special:AddSoftware class picker).
FOSS_CLASS_KINDS = {
    "free and open-source software": "foss",
}

# Upload enhancements: the image class + image-fact properties for the
# item-per-upload created by Special:Upload (every uploaded file gets a
# sitelinked image item carrying these statements — same semantic model as
# the Add* portrait/logo facts).
IMAGE_CLASS_KINDS = {
    "image": "image",
}

IMAGE_PROPERTY_KINDS = {
    "image": "image",
    "license": "license",
    "image author": "imageAuthor",
    "additional license information": "imageLicenseInfo",
}


def build_config(
    property_ids: dict[str, str],
    class_ids: dict[str, str],
    lexer_ids: dict[str, str],
    fallback_languages: list[str],
    wikidata_class_qids: dict[str, str] | None = None,
    previous_youtube_api_key: str = "",
    preseed_ids: dict[str, str] | None = None,
    license_ids: dict[str, str] | None = None,
) -> str:
    """Returns a PHP snippet assigning $wgEmbeddableContentConfig and the
    Wikibase settings the seed is responsible for.

    wikidata_class_qids maps an English class label to its Wikidata QID
    (from the classes manifest align.wikidata column); used to derive the
    Wikidata-QID -> local class key maps for harvest class inference.

    previous_youtube_api_key carries the key of the PREVIOUSLY emitted config
    (read by the seed from the existing --config-out file). The YouTube key
    is deploy-injected via the environment and must never be silently lost on
    re-emission: an explicitly exported YOUTUBE_API_KEY (even empty — an
    explicit disable) wins; otherwise the previous key is preserved.

    preseed_ids maps preseed-item English labels to their item ids (the
    common licenses/OSes/UI preseed phase).

    license_ids maps only the LICENSE-class preseed labels to their item ids
    — emitted as the `licenses` map feeding the license combobox options
    (Special:Upload, Add* license fields). Falls back to preseed_ids when not
    provided (backward compat); the seed always passes the filtered map so
    the OS/UI preseed items never appear as license choices.
    """
    wikidata_class_qids = wikidata_class_qids or {}
    preseed_ids = preseed_ids or {}
    license_ids = license_ids or {}

    classes: dict[str, str] = {}
    payload: dict[str, str] = {}
    for label, kind in CLASS_KINDS.items():
        if label in class_ids:
            classes[kind] = class_ids[label]

    agent_classes: dict[str, str] = {}
    for label, kind in AGENT_CLASS_KINDS.items():
        if label in class_ids:
            agent_classes[kind] = class_ids[label]

    source_classes: dict[str, str] = {}
    for label, kind in SOURCE_CLASS_KINDS.items():
        if label in class_ids:
            source_classes[kind] = class_ids[label]

    foss_classes: dict[str, str] = {}
    for label, kind in FOSS_CLASS_KINDS.items():
        if label in class_ids:
            foss_classes[kind] = class_ids[label]

    image_classes: dict[str, str] = {}
    for label, kind in IMAGE_CLASS_KINDS.items():
        if label in class_ids:
            image_classes[kind] = class_ids[label]

    image_props: dict[str, str] = {}
    for label, kind in IMAGE_PROPERTY_KINDS.items():
        if label in property_ids:
            image_props[kind] = property_ids[label]

    fictional_character_classes: dict[str, str] = {}
    for label, kind in FICTIONAL_CHARACTER_CLASS_KINDS.items():
        if label in class_ids:
            fictional_character_classes[kind] = class_ids[label]

    fictional_character_props: dict[str, str] = {}
    for label, kind in FICTIONAL_CHARACTER_PROPERTY_KINDS.items():
        if label in property_ids:
            fictional_character_props[kind] = property_ids[label]

    foss_props: dict[str, str] = {}
    for label, kind in FOSS_PROPERTY_KINDS.items():
        if label in property_ids:
            foss_props[kind] = property_ids[label]

    payload_props: dict[str, str] = {}
    provenance: dict[str, str] = {}
    programming_language = None
    describes = None
    implementation_of = None
    for label, (section, key) in PROPERTY_KINDS.items():
        if label not in property_ids:
            continue
        prop_id = property_ids[label]
        if section == "payloadProperties":
            payload_props[key] = prop_id
        elif section == "provenance":
            provenance[key] = prop_id
        elif section == "programmingLanguage":
            programming_language = prop_id
        elif section == "describes":
            describes = prop_id
        elif section == "implementationOf":
            implementation_of = prop_id

    external_ids: dict[str, str] = {}
    for label, key in EXTERNAL_ID_KINDS.items():
        if label in property_ids:
            external_ids[key] = property_ids[label]

    citation_metadata: dict[str, str] = {}
    for label, key in CITATION_METADATA_KINDS.items():
        if label in property_ids:
            citation_metadata[key] = property_ids[label]

    person_props: dict[str, str] = {}
    for label, key in PERSON_PROPERTY_KINDS.items():
        if label in property_ids:
            person_props[key] = property_ids[label]

    collective_props: dict[str, str] = {}
    for label, key in COLLECTIVE_PROPERTY_KINDS.items():
        if label in property_ids:
            collective_props[key] = property_ids[label]

    source_props: dict[str, str] = {}
    for label, key in SOURCE_PROPERTY_KINDS.items():
        if label in property_ids:
            source_props[key] = property_ids[label]

    # Child class key => parent class key (class keys, not ids — the parent
    # item is looked up at form time, so only the KEY mapping is stable).
    source_parents: dict[str, str] = {}
    for child_label, (child_kind, parent_kind) in SOURCE_PARENT_KINDS.items():
        if child_label in class_ids:
            source_parents[child_kind] = parent_kind

    source_class_by_qid = _class_by_qid(
        wikidata_class_qids, class_ids, SOURCE_CLASS_KINDS
    )
    agent_class_by_qid = _class_by_qid(
        wikidata_class_qids, class_ids, AGENT_CLASS_KINDS
    )

    config: dict[str, Any] = {
        "instanceOf": property_ids.get("instance of"),
        "classes": classes,
        "agentClasses": agent_classes,
        "sourceClasses": source_classes,
        "sourceParents": source_parents,
        "sourceProperties": source_props,
        "fossClasses": foss_classes,
        "fossProperties": foss_props,
        "imageClasses": image_classes,
        "imageProperties": image_props,
        "fictionalCharacterClasses": fictional_character_classes,
        "fictionalCharacterProperties": fictional_character_props,
        "payloadProperties": payload_props,
        "programmingLanguage": programming_language,
        "describes": describes,
        "implementationOf": implementation_of,
        "provenance": provenance,
        "externalIds": external_ids,
        "citationMetadata": citation_metadata,
        "personProperties": person_props,
        "collectiveProperties": collective_props,
        "formatterUrl": property_ids.get("formatter URL"),
        "sourceClassByWikidata": source_class_by_qid,
        "agentClassByWikidata": agent_class_by_qid,
        # Known license items (license-class preseed vocabulary) — the
        # review-form license combobox options (Special:Upload-style list +
        # entity search). Only the license-class items, never the OS/UI
        # preseed items; preseed_ids is the fallback for legacy callers.
        "licenses": dict(license_ids if license_ids else preseed_ids),
        "fallbackLanguages": fallback_languages,
        "lexers": dict(sorted(lexer_ids.items())),
    }

    # YouTube provider (deploy-injected; never committed). Empty key
    # disables the provider; the TTL is 0 (cache off) by default — a
    # config flip for when repeat-query quota ever matters. The key is
    # resolved as: explicit YOUTUBE_API_KEY env (incl. an explicit empty
    # string = disable) > the previous config's key (preservation — a
    # re-emission without the env var must not silently wipe it, hit
    # 2026-08-23) > "".
    env_key = os.environ.get("YOUTUBE_API_KEY")
    youtube_api_key = env_key if env_key is not None else previous_youtube_api_key
    config["youtubeApiKey"] = youtube_api_key
    config["youtubeSearchCacheTtl"] = int(os.environ.get("YOUTUBE_SEARCH_CACHE_TTL", "0") or 0)
    config = {k: v for k, v in config.items() if v not in (None, {}, [])}

    lines = [
        "<?php",
        "// Generated by seed/seed_instance.py (issue #6, D2) — do not edit by hand.",
        "// Re-run the seed after changing the vocabulary manifests.",
        "",
        "$wgEmbeddableContentConfig = " + php_array(config, indent="\t") + ";",
        "",
        "// Wikibase settings emitted by the seed (issue #6, §2):",
        "$wgWBRepoSettings['string-limits']['VT:monolingualtext']['length'] = 50000;",
        "$wgWBRepoSettings['string-limits']['VT:string']['length'] = 50000;",
        "$wgWBClientSettings['wellKnownReferencePropertyIds'] = "
        + php_array(
            {
                "referenceUrl": property_ids.get("source URL"),
                "statedIn": property_ids.get("source"),
                "author": property_ids.get("attributed to"),
                "publicationDate": property_ids.get("date"),
            },
            indent="\t",
        )
        + ";",
        "$wgWikibaseCitationInstanceOf = " + php_scalar(property_ids.get("instance of")) + ";",
        # Issue #24 (cite-by-QID): source classes enable the self-cite
        # behaviour of the citation converter (a source item cited directly
        # is its own source). Values of the EmbeddableContent sourceClasses
        # map, as a plain list.
        "$wgWikibaseCitationSourceClasses = " + php_array(list(source_classes.values())) + ";",
        "$wgWBRepoSettings['sandboxEntityIds'] = [ 'mainItem' => 'Q999999998', 'auxItem' => 'Q999999999' ];",
        # Instance data rights: CC BY-SA 4.0 (fetched page content carries
        # CC BY-SA attributions; contributor content is CC BY-SA licensed).
        "$wgWBRepoSettings['dataRightsUrl'] = 'https://creativecommons.org/licenses/by-sa/4.0/';",
        "$wgWBRepoSettings['rdfDataRightsUrl'] = 'https://creativecommons.org/licenses/by-sa/4.0/';",
        "",
    ]
    return "\n".join(line for line in lines if "=> None" not in line and "=>null" not in line)


def _class_by_qid(
    wikidata_class_qids: dict[str, str],
    class_ids: dict[str, str],
    kinds: dict[str, str],
) -> dict[str, str]:
    """Derives {wikidata QID: local class key} for the classes in `kinds`
    that exist locally and carry a Wikidata alignment in the manifest."""
    result: dict[str, str] = {}
    for label, kind in kinds.items():
        qid = wikidata_class_qids.get(label)
        if qid and label in class_ids:
            result[qid] = kind
    return result


def build_report(
    property_ids: dict[str, str],
    class_ids: dict[str, str],
    lexer_ids: dict[str, str],
    dogfood_ids: dict[str, str],
    languages: list[str],
) -> str:
    """Returns the seed report as MediaWiki wikitext (self-documenting docs)."""
    lines = [
        "The '''ronzz-wikibase seed report''' documents the vocabulary created by the",
        "one-time bootstrap (issue #6, D2). Re-generated on every seed run.",
        "",
        "Instance languages: " + ", ".join(f"''{lang}''" for lang in languages) + "",
        "",
        "== Properties ==",
        '{| class="wikitable"',
        "! Label (en) !! Entity",
    ]
    for label in sorted(property_ids):
        lines.append(f"| {label} || {property_ids[label]}")
    lines += ["|}", "", "== Classes ==", '{| class="wikitable"', "! Label (en) !! Entity"]
    for label in sorted(class_ids):
        lines.append(f"| {label} || {class_ids[label]}")
    lines += ["|}", "", "== Programming languages ==", '{| class="wikitable"', "! Lexer !! Entity"]
    for lexer in sorted(lexer_ids):
        lines.append(f"| {lexer} || {lexer_ids[lexer]}")
    lines += ["|}", "", "== Dogfood entities ==", '{| class="wikitable"', "! Kind !! Entity"]
    for kind in sorted(dogfood_ids):
        lines.append(f"| {kind} || {dogfood_ids[kind]}")
    lines += [
        "|}",
        "",
        "== Config map ==",
        "The emitted <code>$wgEmbeddableContentConfig</code> is generated from the",
        "IDs above; see <code>seed/generated/</code> for the LocalSettings fragment.",
        "",
    ]
    return "\n".join(lines)


def php_array(data: Any, indent: str = "\t", level: int = 0) -> str:
    """Serializes a dict/list into a PHP array literal (single-quoted strings)."""
    pad = indent * level
    inner_pad = indent * (level + 1)
    if isinstance(data, dict):
        if not data:
            return "[]"
        items = []
        for key, value in data.items():
            k = php_scalar(key)
            v = php_array(value, indent, level + 1) if isinstance(value, (dict, list)) else php_scalar(value)
            items.append(f"{inner_pad}{k} => {v},")
        return "[\n" + "\n".join(items) + f"\n{pad}]"
    if isinstance(data, list):
        if not data:
            return "[]"
        items = [f"{inner_pad}{php_scalar(value)}," for value in data]
        return "[\n" + "\n".join(items) + f"\n{pad}]"
    return php_scalar(data)


def php_scalar(value: Any) -> str:
    if value is None:
        return "null"
    if value is True:
        return "true"
    if value is False:
        return "false"
    if isinstance(value, int):
        return str(value)
    escaped = str(value).replace("\\", "\\\\").replace("'", "\\'")
    return f"'{escaped}'"
