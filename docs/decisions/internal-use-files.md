# Decision: internal-use access files (copyright-restricted copies)

- **Status**: Accepted (Nov 2026)
- **Scope**: `wikibase.ronzz.org` — `Special:AddSource` / `Special:UpdateSource` access field,
  `Special:SourceFile`, File: pages, the media-serving nginx gate, the embedded
  `[[File:…]]` renderer, the preseed licence vocabulary
- **Decider**: Rongzhou (`ron@ronzz.org`)

## Context

The AddSource access field stores a copy of a work (`file` / `download` modes)
and links it from the `Source:` page through `Special:SourceFile`. Every
uploaded file was public: the File: page rendered it, `[[File:…]]` embedded it
and `/images/…` served the bytes to anonymous readers.

Some copies are copyright-restricted and may only be viewed by registered
users. The access form needs a **“No public licence (internal use only)”**
choice: no public licence is declared (the licence fact is recorded as
**All rights reserved**), and the bytes are gated to logged-in readers while
the File: page (“file info”) stays public.

`[[File:…]]` and thumbnails must keep working for logged-in readers, and the
gate must not cost a PHP request per image.

## Decision

### 1. Efficient gate: signed media cookie + nginx marker check

Nginx serves the public media exactly as before. For **internal** files it
needs two cheap checks:

1. **Is this file internal?** — a filesystem **marker**, one per file:
   `<markerDir>/images/<hash>/<name>` (mirrors the repo's public-zone layout).
   Nginx derives the same path from the request URI and does a `stat()`.
   Public files have no marker and are untouched (no extra PHP, no cookie).
2. **Is the reader logged in?** — a short-lived signed cookie
   `wb_media_auth=exp.uid.base64url(md5("expuid secret"))`, set by
   `Hooks::onBeforePageDisplay` for registered users (1 h, refreshed on every
   page view, cleared on logout). Nginx validates it with
   `secure_link_md5 "$exp$uid $secret"` — no PHP, no `auth_request`.

The marker directory lives **outside** the web root and the upload directory;
the extension validates that before writing. The `Category:Internal use only
files` line on the File: page is the human-readable marker;
`maintenance/syncInternalFileMarkers.php` rebuilds the marker directory from it
after a restore.

**Bypasses closed:** direct `thumb.php` is blocked (the on-demand 404 handler
now calls the script through FastCGI directly), and `img_auth.php` is blocked
(it streams everything on a public wiki and is unused here). `/w/thumb.php`
and `/w/img_auth.php` therefore fail safe.

### 2. AddSource / UpdateSource UI

- A checkbox **“No public licence (internal use only)”** is shown for both
  `file` and `download` modes (hidden for `url`/`na`).
- Checking it hides the licence field, removes the licence requirement and
  resolves the seeded **“All rights reserved”** licence item by label (never a
  hardcoded Q-id); the item is a `software license` preseed row so the licence
  map/combobox and `Special:SourceFile` can render it.
- `Special:UpdateSource` has the same checkbox (UI parity); the prefilled state
  comes from the existing file's marker. Toggling it adds/removes the marker
  and the File: page category.

### 3. Reading experience

- **Anonymous, `[[File:…]]`**: `ImageBeforeProduceHTML` renders a styled
  placeholder with the exact message
  “Unfortunately, due to copyright restrictions, you must be logged-in to view
  this file.” and a login link — no `<img>`, no media request. Internal files
  mark the parser output uncacheable (the placeholder is user-dependent).
- **Anonymous, File: page**: description and metadata stay visible; the core
  image/original-link/history are hidden by a small house-style CSS module and
  the same message + login link is shown.
- **Anonymous, `Special:SourceFile`**: title and the “All rights reserved”
  licence stay visible; no preview, no download form — the message + login
  link.
- **Anonymous, direct `/images/…`**: nginx returns a 302 to `/media-forbidden`
  (a 403 page with the message + login link); no bytes.
- **Logged in**: File: page, `[[File:…]]`, thumbnails and the
  `Special:SourceFile` preview work normally (the cookie passes the gate); the
  internal download is login-gated with no licence-acceptance checkbox.

### 4. Tests

- Unit: the cookie signature (matches nginx's `secure_link_md5`), the marker
  path and the marker-root safety check.
- Page-flow E2E: the internal upload → category + “All rights reserved”;
  anonymous File:/Special:SourceFile/`[[File:]]` message; logged-in preview;
  UpdateSource checkbox parity. `--media-gate` additionally verifies the real
  nginx gate (anonymous 403 + message / logged-in 200) on production.
- The nginx snippet itself is validated in a throwaway nginx container
  (map/`secure_link`/marker/thumb/archive/transcoded cases) before deployment.

## nginx configuration (production)

At the `http` level of `/etc/nginx/sites-available/wikibase.ronzz.org.conf`:

```nginx
map $cookie_wb_media_auth $wb_media_check {
    default "";
    "~^(?<wb_media_exp>[0-9]+)\.(?<wb_media_uid>[0-9]+)\.(?<wb_media_sig>[A-Za-z0-9_-]+)$"
        "$wb_media_sig,$wb_media_exp";
}
```

Inside the HTTPS `server`:

```nginx
set $wb_media_secret "<the same secret as $wgEmbeddableContentMediaAuthSecret>";
absolute_redirect off;

location = /thumb.php { return 404; }
location = /img_auth.php { return 404; }
location = /media-forbidden {
    error_page 403 /extensions/EmbeddableContent/resources/internal-media-forbidden.html;
    return 403;
}
location = /extensions/EmbeddableContent/resources/internal-media-forbidden.html {
    add_header Cache-Control "no-store" always;
}

# media matched by the internal gate (one marker per file)
location ~ ^/images/(?<wb_rel>[0-9a-f]/[0-9a-f][0-9a-f]/[^/]+)$ {
    error_page 418 = @media_gated;
    if (-f /var/www/wikibase-media-markers/images/$wb_rel) { return 418; }
    expires 14d; add_header Cache-Control "public, immutable";
}
location ~ ^/images/thumb/(?<wb_hash>[0-9a-f]/[0-9a-f][0-9a-f])/(?<wb_file>[^/]+)/(?<wb_thumb>[^/]+)$ {
    error_page 418 = @media_gated_thumb;
    if (-f /var/www/wikibase-media-markers/images/$wb_hash/$wb_file) { return 418; }
    expires 14d; add_header Cache-Control "public, immutable";
    try_files $uri @thumb;
}
location ~ ^/images/archive/(?<wb_ah>[0-9a-f]/[0-9a-f][0-9a-f])/[0-9]+!(?<wb_an>[^/]+)$ {
    error_page 418 = @media_gated;
    if (-f /var/www/wikibase-media-markers/images/$wb_ah/$wb_an) { return 418; }
    expires 14d; add_header Cache-Control "public, immutable";
}
location ~ ^/images/thumb/archive/(?<wb_tah>[0-9a-f]/[0-9a-f][0-9a-f])/[0-9]+!(?<wb_tan>[^/]+)/[^/]+$ {
    error_page 418 = @media_gated;
    if (-f /var/www/wikibase-media-markers/images/$wb_tah/$wb_tan) { return 418; }
    expires 14d; add_header Cache-Control "public, immutable";
}
location ~ ^/images/transcoded/(?<wb_th>[0-9a-f]/[0-9a-f][0-9a-f])/(?<wb_tn>[^/]+)/ {
    error_page 418 = @media_gated;
    if (-f /var/www/wikibase-media-markers/images/$wb_th/$wb_tn) { return 418; }
    expires 14d; add_header Cache-Control "public, immutable";
}
# .ggb keeps its CORS headers (the GeoGebra player)
location ~ ^/images/(?<wb_rel_ggb>[0-9a-f]/[0-9a-f][0-9a-f]/[^/]+\.ggb)$ {
    error_page 418 = @media_gated;
    if (-f /var/www/wikibase-media-markers/images/$wb_rel_ggb) { return 418; }
    expires 14d; add_header Cache-Control "public, immutable";
    add_header Access-Control-Allow-Origin "https://ggb.ronzz.org" always;
    add_header Cross-Origin-Resource-Policy "cross-origin" always;
}

location @media_gated {
    secure_link $wb_media_check;
    secure_link_md5 "$wb_media_exp$wb_media_uid $wb_media_secret";
    if ($secure_link = "") { return 302 /media-forbidden; }
    if ($secure_link = "0") { return 302 /media-forbidden; }
    expires off; add_header Cache-Control "private, no-store" always;
    try_files $uri =404;
}
location @media_gated_thumb {
    secure_link $wb_media_check;
    secure_link_md5 "$wb_media_exp$wb_media_uid $wb_media_secret";
    if ($secure_link = "") { return 302 /media-forbidden; }
    if ($secure_link = "0") { return 302 /media-forbidden; }
    expires off; add_header Cache-Control "private, no-store" always;
    try_files $uri @media_gated_thumb_gen;
}
# Internal on-demand thumbnail: the generated response must stay uncacheable
# too (the public @thumb location has no private cache header).
location @media_gated_thumb_gen {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root/thumb.php;
    fastcgi_param QUERY_STRING "f=$wb_file&thumbName=$wb_thumb";
    add_header Cache-Control "private, no-store" always;
}
location @thumb {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root/thumb.php;
    fastcgi_param QUERY_STRING "f=$wb_file&thumbName=$wb_thumb";
}
```

The `location /images/` prefix stays for paths the regexes do not cover;
remove its nested `.ggb` block (the dedicated regex above now handles it).

## Security model and limitations

- The threat model is the **anonymous reader**. Logged-in users are trusted
  (registration is closed); the marker/category are editable by them, which is
  a deliberate content permission, not a security boundary against them.
- The marker directory must be backed up with the volume. If it is lost,
  restore it from the category with `syncInternalFileMarkers.php` before
  reopening the wiki.
- The signed cookie is a 1-hour media session; rotating the shared secret
  invalidates every cookie (users get a new one on their next page view).
  A stolen cookie is equivalent to a stolen session.
- Internal files are not served by the `imageinfo` URL or the MediaWiki file
  API to anonymous clients; they are for logged-in readers through the normal
  media URLs and `Special:SourceFile`.
- Internal `.ggb` files lose the GeoGebra CORS headers (the gate's response is
  the message); source access files are PDF/EPUB/DjVu/text, not `.ggb`.

## What this does not do

- No per-user or per-file ACL: any logged-in user can view any internal file.
- No PDF/EPUB watermarking or DRM; this is an access gate, not a rights
  management system.
- No change to `Special:Upload` (the checkbox is an AddSource access-field
  feature).
