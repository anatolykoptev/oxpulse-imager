# OXPulse Imager

Optional bring-your-own imgproxy image delivery for WordPress.

Generates signed, deterministic imgproxy URLs for approved local origins while preserving the original URL whenever configuration, source policy, signing, or delivery cannot safely proceed. Disabled by default. No SaaS, no telemetry, no FFI.

## How it works

The plugin rewrites image URLs on the frontend (never in admin) to signed imgproxy URLs. imgproxy fetches the original image, transforms it (resize, format conversion, quality), and serves the result. The original image is never modified.

### Format negotiation (2026 industry standard)

The default output format is `auto`, which uses **Accept header content negotiation** — the same approach used by Cloudflare Images, Cloudinary, Imgix, and Vercel:

1. Browser sends `Accept: image/avif,image/webp,...` on every image request
2. imgproxy inspects the Accept header and serves the best format the browser supports:
   - `image/avif` → AVIF (~50% smaller than JPEG)
   - `image/webp` → WebP (~30% smaller)
   - neither → original format (JPEG fallback)
3. Response includes `Vary: Accept` so caches store one variant per format

**No `<picture>` / `<source>` tags needed.** One URL serves the optimal format per browser.

### Content-Disposition

The plugin adds a `fn:` (filename) option to every imgproxy URL so that "Save As" in browsers produces a meaningful filename. In `auto` mode, the original filename is preserved; in explicit format mode (`avif`/`webp`), the extension is replaced to match.

## Requirements

- PHP 8.3+
- WordPress 6.2+
- imgproxy v4+ endpoint (bring your own)

## imgproxy server configuration

For `auto` format mode (Accept header negotiation), configure your imgproxy server with:

```bash
IMGPROXY_AUTO_AVIF=true
IMGPROXY_AUTO_WEBP=true
```

Without these, `auto` mode will serve the original format (no conversion). Use the "Test Connection" button in the plugin settings to verify that AVIF negotiation is working.

## CDN / caching proxy configuration

**Critical:** When using `auto` format mode, your CDN or caching proxy MUST include the `Accept` header in the cache key. Otherwise, the CDN will serve an AVIF response to a browser that requested WebP (or vice versa).

### Nginx cache key

```nginx
proxy_cache_key "$scheme$proxy_host$request_uri$http_accept";
```

### CDN settings

- Include `Accept` header in cache key
- Do not strip or normalize the `Accept` header
- Honor the `Vary: Accept` response header from imgproxy

### Security note

Headers cannot be signed. An attacker can bypass your CDN cache by varying the `Accept` header. This is a cache-poisoning vector, not a security vulnerability in the plugin — imgproxy will still only serve allowed formats. Configure your CDN to limit the number of distinct `Accept` header variants it will cache.

### Origin fallback when imgproxy is down

When imgproxy is unreachable the plugin stops generating imgproxy URLs (it flips to original URLs), and `proxy_cache_use_stale error timeout` keeps already-cached variants alive. The remaining gap is a page that was cached while imgproxy was up: its HTML still carries imgproxy URLs, and a variant that is not in the nginx cache returns 502. The plugin cannot fix that at request time because the request never reaches PHP. A reverse proxy can: on a 502/503/504 from imgproxy, serve the **original upload** instead.

This only works when imgproxy is reverse-proxied on the same host (relative endpoint such as `/imgproxy`). The trade is availability over fidelity: the visitor gets the original bytes (full size, original format) under the variant URL, for 60 seconds at most.

nginx cannot verify the imgproxy signature, so the recipe only serves paths under `/wp-content/uploads/` on your own host and rejects `..`. Anything else keeps returning 502. Replace `example\.com` and the `root` with your own values.

**Source mode `http` (`/plain/https://...`)** works with stock nginx. Put the `map` blocks in the `http {}` context:

```nginx
# Original upload path, recovered from the imgproxy URL. Tolerates any number
# of processing options (rs:, fn:, cb:, ...) and a trailing @format.
map $request_uri $oxp_plain_path {
    default "";
    ~^/imgproxy/[^/]+/(?:[^/]+/)*?plain/https?://example\.com(/wp-content/uploads/[A-Za-z0-9._/-]+?)(?:@[A-Za-z0-9]+)?(?:\?.*)?$ $1;
}
map $oxp_plain_path $oxp_origin {
    default $oxp_plain_path;
    ~\.\.   "";
}
# No Cache-Control header on errors (an empty value is not emitted).
map $oxp_origin $oxp_cache_control {
    ""      "";
    default "public, max-age=60";
}
```

and in the `server {}` that already proxies imgproxy:

```nginx
location ^~ /imgproxy/ {
    proxy_pass http://127.0.0.1:8080/;
    proxy_intercept_errors on;
    error_page 502 503 504 = @imgproxy_origin_fallback;   # not 403/404: those are bad signatures
    # ... your existing proxy_cache / proxy_cache_use_stale settings ...
}

location @imgproxy_origin_fallback {
    if ($oxp_origin = "") { return 502; }
    add_header Cache-Control $oxp_cache_control always;
    try_files $oxp_origin @imgproxy_origin_missing;
}
location @imgproxy_origin_missing { return 502; }
```

**Source mode `local` (base64url)**: the source is `base64url("local:///wp-content/uploads/...")`, which stock nginx cannot decode. Either switch the source mode to `http`, or use the [njs](https://nginx.org/en/docs/njs/) module (shipped with the official nginx packages and images). Save as `/etc/nginx/oxpulse-origin.js`:

```js
function origin(r) {
    var m = /^\/imgproxy\/[^\/]+\/(?:[^\/]+\/)*?([A-Za-z0-9_-]+)(?:@[A-Za-z0-9]+|\.[A-Za-z0-9]+)?(?:\?.*)?$/
        .exec(r.variables.request_uri);
    if (!m) { return ''; }
    var src;
    try { src = Buffer.from(m[1], 'base64url').toString(); } catch (e) { return ''; }
    var p = /^local:\/\/(\/wp-content\/uploads\/[A-Za-z0-9._\/-]+)$/.exec(src);
    if (!p || p[1].indexOf('..') !== -1) { return ''; }
    return p[1];
}
export default { origin };
```

Then, in the main `nginx.conf` add `load_module modules/ngx_http_js_module.so;` and, in `http {}`, use this instead of the first two `map` blocks above (keep the `$oxp_cache_control` map and the `server {}` part unchanged; keep the `$oxp_plain_path` map too if you want to serve both modes):

```nginx
js_import oxpulse from /etc/nginx/oxpulse-origin.js;
js_set $oxp_local_path oxpulse.origin;
map "$oxp_plain_path$oxp_local_path" $oxp_origin {
    default $oxp_plain_path$oxp_local_path;
    ~\.\.   "";
}
```

Limits: file names with percent-encoded characters are not matched (WordPress sanitises upload names, so this is rare) and fall back to the 502 you had before; the original is served as-is, so `<picture>` AVIF/WebP sources and `Accept` negotiation are not honoured while the fallback is active; when the imgproxy host is a separate domain (absolute endpoint) there is no reverse-proxy location to hook, and the CDN's own "serve stale on origin error" is the only option. The recipe was validated with `nginx -t` and exercised against nginx 1.31 in a container (upstream on a closed port) with URLs generated by the plugin, with and without an extra `cb:` processing option: 200 with the original bytes for both source modes, 502 for a foreign host, `..` traversal and non-upload paths.

The WordPress settings screen shows how often imgproxy flipped between up and down ("Imgproxy flaps"), so an outage you never saw yourself is visible there.

## Local delivery (Phase 6 — standard/shared hosting)

When no imgproxy endpoint is configured, the plugin switches to
**LocalBackend** mode: on-disk WebP delivery using PHP (Imagick→GD)
with no daemon required. Cache files are served as static files;
misses are handled by a self-contained `oxpulse-img.php` endpoint.

### Apache (`.htaccess`)

The plugin generates `.htaccess` rules that rewrite missing cache
files to the miss-endpoint. Requires `mod_rewrite` + `AllowOverride`.
A capability-test auto-detects whether rewrite is available and falls
back to the output-buffer mode if not.

### nginx

nginx does not support `.htaccess`. Use the output-buffer fallback
(automatic) or add this `try_files` snippet to your server block. Also
add a deny-PHP location for the cache dir (belt-and-braces) BEFORE it:

```nginx
# Belt-and-braces: never execute scripts inside the cache dir.
location ~* ^/wp-content/cache/oxpulse/.*\.(php|phtml)$ { deny all; }

# Serve existing cache files directly; on miss, route to the endpoint
# with the key extracted from the filename.
location ~* ^/wp-content/cache/oxpulse/([0-9a-f]+)/(.+)\.(webp|avif)$ {
    add_header Vary Accept;
    try_files $uri /wp-content/oxpulse-img.php?k=$2;
}
```

Note: the location regex uses `[0-9a-f]+` (not a `{16}` quantifier) —
an unquoted regex containing `{` / `}` makes nginx parse the braces as
a block and fail to load (`pcre2_compile() failed`). Battle-tested
plugins (WebP Express, Converter for Media) avoid the quantifier for
this reason.

### Security note (cache-dir hardening)

The `.htaccess` generated by the plugin includes `php_flag engine off`
as defense-in-depth for Apache + mod_php hosts, guarded by
`<IfModule mod_php*.c>` so it only applies when mod_php is actually
loaded. A bare `php_flag` is a mod_php-only directive; under Apache +
php-fpm (mod_proxy_fcgi / SetHandler — the modern default) with
`AllowOverride All` it is an unknown directive and Apache returns 500
on every request served from the cache dir, so the guard is required.
`RemoveHandler`/`RemoveType` are emitted unguarded (valid regardless of
the PHP SAPI), and nginx does not read `.htaccess` at all. **It is not
the security boundary.** The real guard is the signed-key +
format-allowlist invariant in the miss-endpoint: the cache directory
only ever contains `<key>.webp` files where `key` is HMAC-signed and
the format extension is allowlisted to `webp`. An attacker cannot
plant an executable-named file (e.g. `<key>.php`) — the signature check
rejects unknown keys and the format allowlist rejects non-webp
extensions with a 400 before any disk write. nginx hosts should
additionally deny PHP execution inside the cache location block as
belt-and-braces.

## License

GPL-2.0-or-later

Copyright (c) 2026 Anatoly Koptev
