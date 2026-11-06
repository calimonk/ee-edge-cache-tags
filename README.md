# Edge Cache Tags — ExpressionEngine add-on

> Surgical tag-based purges for any tag-aware edge cache. One entry save → only the affected pages evict. MSM-aware out of the box.

[![Latest Release](https://img.shields.io/github/v/release/calimonk/ee-edge-cache-tags?label=release)](https://github.com/calimonk/ee-edge-cache-tags/releases/latest)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)
[![ExpressionEngine](https://img.shields.io/badge/ExpressionEngine-7%2B-fb6e1e)](https://expressionengine.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)](https://www.php.net/)

Drop-in EE 7+ add-on that:

1. **Tags every front-end response** with a `Surrogate-Key` (and `Cache-Tag` if you're on Cloudflare Enterprise) header listing what's on the page — entry id, channel name, category ids, path, page type.
2. **Fires a surgical purge** to your edge cache the moment a channel entry is saved, updated, or deleted — listing only the tags that actually changed. No URL list to maintain, no whole-site nukes.

Works with **[Fastly](https://www.fastly.com/)**, **[Cloudflare Enterprise](https://developers.cloudflare.com/cache/how-to/purge-cache/purge-cache-tags/)**, **[Nivoli](https://nivoli.com/)** (free tier — managed CF edge), Varnish/xkey, or any custom webhook handler.

> **Pairs natively with [Nivoli](https://nivoli.com/)** — managed full-page caching on Cloudflare's edge, built by the same team. Drop the dashboard URL into the Setup tab and you're done. Free tier: 1 domain, ~100k req/mo, no credit card. [Sign up free →](https://console.nivoli.com/signup)

## Highlights

- **Surgical**: saving one entry evicts the entry page + homepage + its channel index + its category archives. Nothing else.
- **MSM-aware**: every tag is prefixed with `site-<id>-` so sites in the same install never evict each other's pages. Install-wide "purge all" stays available.
- **Top-level Edge Cache CP module**: live cache stats, recent activity log (last 50 purges), built-in diagnostics, trace mode.
- **Non-blocking**: entry save returns immediately; purge fires via curl multi with a short timeout — slow edge never hangs the CP.
- **GitHub auto-updates**: future versions show as a regular "Update available" in the CP. Daily off-request poll.
- **Token-scope verification**: with Nivoli, the Setup tab confirms the current site is in the token's allowed hostnames — catches misconfigs at render time, not in the activity log.
- **MIT-licensed**, native PHP files, no Composer / build step.

## Quick start

```bash
# 1. Download the latest release zip
curl -LO https://github.com/calimonk/ee-edge-cache-tags/releases/latest/download/edge-cache-tags.zip

# 2. Extract into system/user/addons/edge_cache_tags/

# 3. CP → Developer → Add-Ons → install Edge Cache Tags

# 4. CP → Edge Cache → Setup → pick backend → save credentials.
```

Done. Channel-entry saves now fire surgical purges. See **[codebit.nl/edge-cache-ee](https://codebit.nl/edge-cache-ee/)** for the full product page.

---

## Install

Drop the `system/user/addons/edge_cache_tags/` directory into your EE
install (or git-submodule this repo at `system/user/addons/`). Then
**CP → Developer → Add-Ons → Edge Cache Tags → Install**. The installer
creates one settings row per MSM site (default `backend=none`) and
registers the three hooks (`template_post_parse`,
`after_channel_entry_save`, `after_channel_entry_delete`).

## Configure

Two equivalent ways — pick whichever fits your workflow:

### CP settings page (recommended for non-developers)

**CP → Edge Cache** (top-level sidebar entry, added in v2.4.13) opens a
tabbed module with four panels: **Setup** (backend + credentials + live
stats + token scope), **Status** (hook registration / addon files / MSM
detection / backend creds OK probes + config-resolution panel),
**Activity** (last 50 purges with status, duration, trigger), and
**Documentation**.

On MSM installs, switch the EE site picker first to configure each
site — each MSM site stores its own row.

### config.php (developers / config-as-code)

If you prefer, set the values in `system/user/config/config.php`:

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

// Nivoli — don't have an account yet? Sign up free at
// https://console.nivoli.com/signup (1 domain, ~100k req/month).
$config['edge_cache_tags_nivoli_endpoint'] = 'https://console.nivoli.com/cache/<token>';

// Generic webhook
$config['edge_cache_tags_webhook_url']    = 'https://your-edge.example.com/purge';
$config['edge_cache_tags_webhook_secret'] = '<optional bearer secret>';
```

**config.php beats the CP form.** When both are set, the addon uses
config.php values and the CP form shows a 🔒 lock indicator next to
the pinned fields so admins know why their input isn't taking effect.

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

## Multi-Site Manager (MSM)

The add-on is MSM-aware:

**On the default site (`site_id=1`):** keys are emitted and purged
unprefixed (`home`, `entry-123`, `channel-news`, …). Works the same as
on a single-site install.

**On secondary sites (`site_id > 1`):** keys are prefixed with
`site-<id>-` for cross-site isolation:

- A page on site 2 emits `site-2-home site-2-entry-123 … site-2-all`
  **plus** an unprefixed `all` (so an admin can still do a network-wide
  nuke by purging the `all` tag).
- A save on site 2 purges only `site-2-*` tags — it does **not** touch
  the unprefixed `all`. Saving a news article on site 2 doesn't blow
  away site 1's cache.

This means one EE install with N sites uses **one purge backend** (one
Fastly service / one Cloudflare zone / one Nivoli token) — the prefixing
keeps the tag namespaces separate.

**For the Nivoli backend on MSM:** each MSM hostname is a separate
Nivoli tenant by default, each with its own token. To share a single
endpoint across MSM sites, ask Nivoli support to link your tokens — one
dashboard URL then accepts purges for all linked hostnames, and the
`site-<id>-` prefixing routes them to the right hostname automatically.

**For Fastly / Cloudflare / webhook backends on MSM:** no extra setup
needed — one service / zone / endpoint serves all MSM sites; tag
prefixing keeps them apart.

## Origin lock (Nivoli, v2.5.0)

The Nivoli edge stamps every request it forwards with your site's secret in
an `x-nivoli-origin` header. With the origin lock on, EE refuses requests
that do not carry it, so nobody can skip the edge (its shields and Gate
doors) by talking to your server directly. It runs in `sessions_start`,
before EE resolves a member, a control panel route or an `ACT` action.

Set it in `system/user/config/config.php`. The secrets are per **host**
(one EE install can serve several MSM hosts; a host without a secret is
not checked):

```php
$config['edge_cache_tags_origin_secrets'] = [
    'www.example.com' => '<32 hex characters, from the Nivoli console>',
    // several are allowed: a rotation, or a second edge host that forwards here
    'shop.example.com' => ['<current>', '<previous>'],
];
$config['edge_cache_tags_origin_lock'] = 'monitor'; // then 'enforce'; 'off' disables
// Optional: more addresses of this server (127.0.0.1, ::1 and SERVER_ADDR
// are always included) whose own requests WITHOUT the header pass.
$config['edge_cache_tags_origin_local'] = ['203.0.113.10'];
```

- **monitor** (the default when secrets are set and no mode is): a request
  without the right secret is served and logged to PHP's error log
  (`[edge_cache_tags] origin lock would refuse (monitor): ...`). Run it for
  a day and read the log before you enforce.
- **enforce**: such a request gets `421 Misdirected Request`, not cached.
- **Never checked:** the command line (cron jobs, `eecli.php`, import and
  sitemap scripts that bootstrap EE), hosts without a secret, and requests
  from the server itself that carry no header (loopback health checks). A
  local request with a *wrong* header is refused, which is how you test it.
- `REMOTE_ADDR` must be the real peer, or what nginx/Apache real-IP handling
  makes of it when it trusts only Cloudflare's ranges.
- The same rules as the nginx `map` snippet Nivoli hands out, so both can
  run at once. EE's `?ACT=css` / `?ACT=js` stylesheet requests are served
  before EE starts a session and are not checked here.
- The **Status** tab shows the mode, the hosts with a secret (never the
  secret), and whether the page you are looking at came through the edge
  with the right secret.
- Nivoli Gate's signed per-request assertion (a later Gate phase) will be
  verified at the same place, after the secret (`OriginLock::decide`,
  `$cfg['assertion']`).

### Token scope

The Setup tab asks Nivoli what the token in your dashboard URL may do.
A **purge token** (Nivoli console, Purge tokens) is the healthy setup for
this add-on: it purges and nothing else, so the page says so and does not
fetch statistics (they live in the console). The site's **full-access
token** gets a warning with the steps to swap it.

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
php tests/test-origin-lock.php
```

`test-origin-lock.php` covers the origin lock's decisions (the edge, host
spellings, the command line, loopback, config mistakes, the assertion hook,
the log line).

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

- ExpressionEngine 7+
- PHP 7.4+
- A tag-aware edge cache **OR** the willingness to write your own purge
  handler against the generic webhook.

## Get help / connect

- **Product page**: [codebit.nl/edge-cache-ee](https://codebit.nl/edge-cache-ee/) — feature tour, install guide, backend matrix.
- **Sister WP plugin**: [calimonk/wp-edge-cache-tags](https://github.com/calimonk/wp-edge-cache-tags) — same surgical-purge model, for WordPress.
- **Need a managed edge?**: [Nivoli](https://nivoli.com/) free tier wires up in ~90 seconds — 1 domain, ~100k req/mo, no credit card.
- **Issues / PRs**: welcome on this repo.

## License

MIT. See [LICENSE](LICENSE).
