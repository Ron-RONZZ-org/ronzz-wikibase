"""Thin MediaWiki/Wikibase API client for the seed orchestrator.

Stdlib-only (urllib). Implements the classic token flow: login token ->
action=login -> CSRF token, then write ops. All write ops raise
:class:`WikibaseApiError` on failure; all methods are idempotent by design
(skip-existing-label checks live in the orchestrator).

License: GPL-2.0-or-later
"""

from __future__ import annotations

import http.cookiejar
import json
import urllib.error
import urllib.parse
import urllib.request
from typing import Any, Optional


class WikibaseApiError(Exception):
    """Raised when the Wikibase API rejects a request."""


class WikibaseApi:
    def __init__(
        self,
        api_url: str,
        user: Optional[str] = None,
        password: Optional[str] = None,
        timeout: int = 60,
    ) -> None:
        self.api_url = (
            api_url if api_url.endswith("/api.php") else api_url.rstrip("/") + "/api.php"
        )
        self.user = user
        self.password = password
        self.timeout = timeout
        self.csrf_token: Optional[str] = None
        self.logged_in = False
        # MediaWiki binds tokens to a session: all requests must share cookies.
        self._opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor( http.cookiejar.CookieJar() )
        )

    # ------------------------------------------------------------------ auth

    def login(self) -> None:
        """Authenticates via the classic login-token flow (works with bot passwords)."""
        if not self.user or not self.password:
            raise WikibaseApiError("login requires --user and --password (bot password recommended)")
        if self.logged_in:
            return

        login_token = self._get("action=query&meta=tokens&type=login").get("query", {}).get(
            "tokens", {}
        ).get("logintoken")
        if not login_token:
            raise WikibaseApiError("no login token returned")

        result = self._post(
            "action=login",
            lgname=self.user,
            lgpassword=self.password,
            lgtoken=login_token,
        ).get("login", {})
        if result.get("result") != "Success":
            raise WikibaseApiError(f"login failed: {result.get('reason', result)}")

        self.logged_in = True
        self.csrf_token = self._get("action=query&meta=tokens&type=csrf").get("query", {}).get(
            "tokens", {}
        ).get("csrftoken")

    def require_csrf(self) -> str:
        if not self.csrf_token:
            self.login()
        if not self.csrf_token:
            raise WikibaseApiError("no CSRF token available; log in first")
        return self.csrf_token

    # ---------------------------------------------------------------- reads

    def search_entities(self, label: str, entity_type: str, language: str) -> list[dict[str, Any]]:
        """wbsearchentities — used for the skip-existing-label check."""
        data = self._get(
            f"action=wbsearchentities&search={urllib.parse.quote(label)}"
            f"&language={urllib.parse.quote(language)}&type={entity_type}&limit=5"
        )
        return data.get("search", [])

    def get_entity(self, entity_id: str) -> dict[str, Any]:
        """Returns the raw entity body (labels/descriptions/claims) for an id."""
        data = self._get(f"action=wbgetentities&ids={entity_id}&props=labels|descriptions|claims")
        try:
            return data["entities"][entity_id]
        except KeyError as exc:
            raise WikibaseApiError(f"wbgetentities returned no entity {entity_id}") from exc

    def get_claims(self, entity_id: str) -> dict[str, list[dict[str, Any]]]:
        return self.get_entity(entity_id).get("claims", {})

    # --------------------------------------------------------------- writes

    def create_property(
        self,
        labels: dict[str, str],
        descriptions: dict[str, str],
        datatype: str,
        summary: str,
    ) -> str:
        result = self._post(
            "action=wbcreateproperty",
            token=self.require_csrf(),
            datatype=datatype,
            labels=json.dumps(labels, ensure_ascii=False),
            descriptions=json.dumps(descriptions, ensure_ascii=False),
            summary=summary,
        )
        prop = result.get("property", {})
        entity_id = prop.get("id")
        if not entity_id:
            raise WikibaseApiError(f"wbcreateproperty failed: {result}")
        return entity_id

    def create_item(
        self,
        labels: dict[str, str],
        descriptions: dict[str, str],
        summary: str,
    ) -> str:
        result = self._post(
            "action=wbcreateitem",
            token=self.require_csrf(),
            labels=json.dumps(labels, ensure_ascii=False),
            descriptions=json.dumps(descriptions, ensure_ascii=False),
            summary=summary,
        )
        item = result.get("item", {})
        entity_id = item.get("id")
        if not entity_id:
            raise WikibaseApiError(f"wbcreateitem failed: {result}")
        return entity_id

    def add_claims(self, entity_id: str, claims: dict[str, list[dict[str, Any]]], summary: str) -> None:
        """Appends claims to an entity, preserving labels/descriptions and
        skipping claims that are already present (idempotent)."""
        entity = self.get_entity(entity_id)
        current = entity.get("claims", {})

        merged: dict[str, list[dict[str, Any]]] = {k: list(v) for k, v in current.items()}
        added = 0
        for prop_id, new_claims in claims.items():
            for claim in new_claims:
                if self._claim_exists(current, prop_id, claim):
                    continue
                merged.setdefault(prop_id, []).append(claim)
                added += 1

        if added == 0:
            return

        data = {
            "labels": entity.get("labels", {}),
            "descriptions": entity.get("descriptions", {}),
            "claims": merged,
        }
        result = self._post(
            "action=wbeditentity",
            token=self.require_csrf(),
            id=entity_id,
            data=json.dumps(data, ensure_ascii=False),
            summary=summary,
        )
        if "entity" not in result:
            raise WikibaseApiError(f"wbeditentity failed for {entity_id}: {result}")

    def edit_page(self, title: str, text: str, summary: str) -> None:
        """Creates or updates a wiki page (used for the generated seed report)."""
        result = self._post(
            "action=edit",
            token=self.require_csrf(),
            title=title,
            text=text,
            summary=summary,
        )
        if result.get("edit", {}).get("result") != "Success":
            raise WikibaseApiError(f"action=edit failed for {title}: {result}")

    # ------------------------------------------------------------- helpers

    @staticmethod
    def _claim_exists(
        claims: dict[str, list[dict[str, Any]]], prop_id: str, candidate: dict[str, Any]
    ) -> bool:
        want = candidate.get("mainsnak", {}).get("datavalue")
        if want is None:
            return False
        for claim in claims.get(prop_id, []):
            if claim.get("mainsnak", {}).get("datavalue") == want:
                return True
        return False

    def _get(self, query: str) -> dict[str, Any]:
        url = f"{self.api_url}?{query}&format=json"
        with self._opener.open(url, timeout=self.timeout) as resp:
            return json.loads(resp.read().decode("utf-8"))

    def _post(self, query: str, **params: Any) -> dict[str, Any]:
        params["format"] = "json"
        body = urllib.parse.urlencode(params).encode("utf-8")
        request = urllib.request.Request(f"{self.api_url}?{query}", data=body, method="POST")
        try:
            with self._opener.open(request, timeout=self.timeout) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except urllib.error.HTTPError as exc:
            raise WikibaseApiError(f"HTTP {exc.code} from {self.api_url}: {exc.read()[:500]!r}") from exc
