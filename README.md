# Edge Cache Tags — ExpressionEngine add-on

Emits `Surrogate-Key` + `Cache-Tag` response headers so any tag-aware edge
cache (Fastly, Cloudflare Enterprise, Varnish, Nivoli, your own) can tag
each page with what it represents. When a channel entry is saved or
deleted, dispatches the right tag-based purge to whichever backend
you've configured.

The payoff: publishing one entry clears its entry page **and** every
listing it appears on (homepage, its channel index, its category
archives) in a single API call — no URL enumeration.

## Install

Drop the `system/user/addons/edge_cache_tags/` directory into your EE
install (or git-submodule this repo at `system/user/addons/`). Then
**CP → Developer → Add-Ons → Edge Cache Tags → Install Extension**.

## Configure

Edit `system/user/config/config.php`:

```php
// Pick a backend
$config['edge_cache_tags_backend'] = 'fastly'; // or 'nivoli' | 'cloudflare' | 'webhook' | 'none'

// Backend-specific fields below — fill only the ones for your backend.

// Fastly
$config['edge_cache_tags_fastly_service'] = '<service-id>';
$config['edge_cache_tags_fastly_api_key'] = '<api-token-with-purge_select-scope>';

// Cloudflare (Enterprise plan)
$config['edge_cache_tags_cf_zone_id']    = '<zone-id>';
$config['edge_cache_tags_cf_api_token']  = '<api-token-scoped-to-Zone:Cache-Purge>';

// Nivoli
$config['edge_cache_tags_nivoli_endpoint'] = 'https://console.nivoli.com/cache/<token>';

// Generic webhook
$config['edge_cache_tags_webhook_url']    = 'https://your-edge.example.com/purge';
$config['edge_cache_tags_webhook_secret'] = '<optional bearer secret>';
```

## Backends

| Backend | Reads header | Purge API |
|---|---|---|
| `fastly` | `Surrogate-Key` | `POST /service/{id}/purge` with `Surrogate-Key:` header (soft purge) |
| `cloudflare` | `Cache-Tag` | `POST /zones/{id}/purge_cache` with `{tags:[…]}` (**Enterprise only**) |
| `nivoli` | `Surrogate-Key` | `POST <dashboard>/purge-tag` with `{tags:[…]}` |
| `webhook` | (your choice) | `POST <url>` with `{tags:[…]}` JSON, optional `Authorization: Bearer …` |
| `none` | — | Headers only — no purge dispatch (useful for VCL-managed Varnish or testing) |

Purges are coalesced — bulk imports / programmatic batch saves fire
**one** call (per backend) with the merged tag set, not one per entry.
Each call is fire-and-forget with a 5-second timeout, so a slow or
unreachable edge never blocks an EE CP save.

## What's in the headers

For every front-end GET that isn't the CP, the extension emits:

```
Surrogate-Key: tmpl-news-index path-news entry-123 channel-news category-9 all
Cache-Tag:     tmpl-news-index,path-news,entry-123,channel-news,category-9,all
```

The auto-derived keys come from the URI and the matched template:

| Source | Key |
|---|---|
| Empty URI (homepage) | `home` |
| Any URI | `path-<first-segment>` (slugified) |
| Template context | `tmpl-<group>` or `tmpl-<group>-<template>` |
| Always | `all` |
| MSM site_id > 1 | All keys prefixed with `site-<id>-`, plus a network-wide `all` |

**Entry-level keys** are declared explicitly from templates via the
`{exp:edge_cache_tags:key}` plugin tag — the extension can't infer entry
context outside a `channel:entries` loop.

## Declaring entry-level keys in templates

In a single-entry template, register `entry-<id>` / `channel-<name>` /
`category-<id>` so a save on that entry intersects this page:

```html
{exp:channel:entries channel="news" limit="1"}
  {exp:edge_cache_tags:key name="entry-{entry_id} channel-news"}
  {categories}{exp:edge_cache_tags:key name="category-{category_id}"}{/categories}

  <article>
    <h1>{title}</h1>
    {body}
  </article>
{/exp:channel:entries}
```

The plugin tag outputs nothing — it just registers keys in the session
cache, which the extension reads at `template_post_parse` time.

## What gets purged on entry change

The extension hooks into `after_channel_entry_save` and
`after_channel_entry_delete`. Tags it dispatches:

| Source | Tag |
|---|---|
| Always | `home`, `all` |
| Entry id | `entry-<id>` |
| Channel | `channel-<name>`, `path-<name>` |
| Each category | `category-<cat_id>` |
| MSM site_id > 1 | All above prefixed with `site-<id>-` |

Multiple saves in the same request coalesce into one POST. EE's
`register_shutdown_function` fires the dispatch after the CP response
is sent.

## Filters / extension points

The extension doesn't expose its own EE hooks; logic is straight-line
PHP. To customize:

- Override the config items at runtime by setting them earlier in
  request lifecycle (extension reads via `ee()->config->item()`).
- For per-template key shaping, just use `{exp:edge_cache_tags:key}`
  with whatever values your templates can render.

## Tests

```bash
php tests/test-keys.php
```

32 assertions covering:
- `keys_for_request` derivation (homepage, deep URIs, template-context
  keys, explicit plugin-tag keys, MSM prefixing)
- `keys_for_entry` derivation (entry id, channel name, categories, MSM
  prefixing)
- `clean()` sanitization (header-unsafe chars, overlong keys, dedup)
- `backend()` resolution (valid backends, case-insensitive, invalid
  fallback to `none`)

The per-backend dispatch shape (URL, headers, body for Nivoli / Fastly /
Cloudflare / Webhook) is verified in the sister
[wp-edge-cache-tags](https://github.com/calimonk/wp-edge-cache-tags) test
suite — the driver semantics are identical across both plugins; only
host-language plumbing (curl vs `wp_remote_post`) differs.

## Requirements

- ExpressionEngine 6 or 7
- PHP 7.2+ (uses `??` null-coalescing and `Throwable`)
- A tag-aware edge cache **OR** the willingness to write your own purge
  handler against the generic webhook.

## License

MIT. See [LICENSE](LICENSE).
