<?php

/**
 * Edge Cache Tags — ExpressionEngine extension (EE6 / EE7).
 *
 * Responsibilities:
 *   1. template_post_parse: emit Surrogate-Key + Cache-Tag headers
 *      describing the page. Keys come from two sources, merged:
 *        - Auto: URI-segment tag (e.g. path-news), plus `all` and the
 *          conditional `home` tag on the front controller.
 *        - Explicit: anything templates pushed via {exp:edge_cache_tags:key}
 *          (stored in session cache by mod.edge_cache_tags.php),
 *          typically entry-level keys like entry-123 / channel-news /
 *          category-9.
 *   2. after_channel_entry_save / delete: enqueue affected keys; flush
 *      on shutdown so a programmatic batch save coalesces to one HTTP
 *      call per backend.
 *
 * Configuration — set in system/user/config/config.php:
 *
 *   $config['edge_cache_tags_backend'] = 'none' | 'nivoli' | 'fastly' |
 *                                        'cloudflare' | 'webhook';
 *
 *   // Backend-specific:
 *   $config['edge_cache_tags_nivoli_endpoint'] = 'https://console.nivoli.com/cache/<token>';
 *   $config['edge_cache_tags_fastly_service']  = '<service-id>';
 *   $config['edge_cache_tags_fastly_api_key']  = '<api-token>';
 *   $config['edge_cache_tags_cf_zone_id']      = '<zone-id>';
 *   $config['edge_cache_tags_cf_api_token']    = '<api-token>';
 *   $config['edge_cache_tags_webhook_url']     = 'https://your-edge.example.com/purge';
 *   $config['edge_cache_tags_webhook_secret']  = '<optional bearer secret>';
 */

if (!defined('BASEPATH')) { exit('No direct script access allowed'); }

class Edge_cache_tags_ext {

    public $version = '2.4.18';

    const MAX_KEYS    = 50;
    const MAX_KEY_LEN = 64;

    /** @var array<string,true> coalesced purge queue */
    private static $pending = array();
    /** @var bool */
    private static $shutdown_hooked = false;
    /** @var array|null lazy-loaded settings row for the current MSM site */
    private static $cached_settings = null;
    /** @var int|null site_id the cache was built for */
    private static $cached_settings_site = null;

    public function __construct() {
        // EE instantiates extensions without args.
    }

    // ---- Config ----------------------------------------------------------
    //
    // Resolution order for every setting:
    //   1. config.php item (edge_cache_tags_<key>) — wins if non-empty
    //   2. exp_edge_cache_tags_settings row for the current MSM site_id
    //   3. default ('none' for backend, '' for everything else)
    //
    // The CP settings page writes to (2); developers who prefer config-
    // as-code keep using (1). The CP page surfaces a "locked" indicator
    // when (1) overrides (2) so admins don't get confused by their form
    // input not applying.

    /** Lazy-load the settings row for the current site, once per request. */
    private function settings_row(): array {
        if (!function_exists('ee') || !isset(ee()->config)) return [];
        $siteId = (int) ee()->config->item('site_id');
        if (self::$cached_settings !== null && self::$cached_settings_site === $siteId) {
            return self::$cached_settings;
        }
        $row = [];
        try {
            if (ee()->db->table_exists('edge_cache_tags_settings')) {
                $row = ee()->db->where('site_id', $siteId)
                    ->get('edge_cache_tags_settings')
                    ->row_array() ?: [];
            }
        } catch (\Throwable $e) { /* DB may be unavailable on early boot */ }
        self::$cached_settings = $row;
        self::$cached_settings_site = $siteId;
        return $row;
    }

    /**
     * Resolve a setting key, config.php first then DB then default.
     *
     * v2.4.15 critical fix: cast-first-then-empty-check rather than
     * `$val !== null && $val !== ''`. On some EE installs,
     * ee()->config->item() returns boolean false for missing keys
     * instead of null. The old `!==` check let false fall through,
     * then (string)false = '' was returned BEFORE the DB-row lookup
     * could see the actual stored value.
     *
     * Net effect on affected installs: backend() always returned
     * 'none', auto-purges silently bailed in flush(), no log entries
     * were ever written, manual purge errored with 'Backend is none'
     * — even when the CP form clearly showed Nivoli configured and
     * the DB row had backend='nivoli'. Headers still emitted (that's
     * a separate code path) so the addon looked installed at the
     * surface but did zero work below the surface.
     *
     * Mirrors the WP-side fix in v2.4.3's configOverrides().
     */
    private function cfg($key, $dbKey = null) {
        $val = ee()->config->item('edge_cache_tags_' . $key);
        $valStr = trim((string) ($val ?? ''));
        if ($valStr !== '') return $valStr;
        $row = $this->settings_row();
        $col = $dbKey ?: $key;
        if (isset($row[$col]) && trim((string) $row[$col]) !== '') return (string) $row[$col];
        return '';
    }

    /** Resolve the currently selected backend identifier. */
    private function backend() {
        $b = strtolower($this->cfg('backend'));
        if (!in_array($b, array('none', 'nivoli', 'fastly', 'cloudflare', 'webhook'), true)) {
            return 'none';
        }
        return $b;
    }

    // ---- Sidebar menu ----------------------------------------------------

    /**
     * cp_custom_menu($menu) — gets called by EE when rendering the CP
     * sidebar IF exp_menu_items has a row with type='addon' and
     * data='Edge_cache_tags_ext'. The installer guarantees that row
     * exists. Adds an "Edge Cache Tags" entry under the CUSTOM section
     * linking to our Index route.
     */
    public function cp_custom_menu($menu) {
        if (!defined('REQ') || REQ !== 'CP') return true;
        try {
            $menu->addItem('Edge Cache',
                ee('CP/URL')->make('addons/settings/edge_cache_tags/index'));
        } catch (\Throwable $e) { /* don't break the CP on render error */ }
    }

    // ---- Header emission -------------------------------------------------

    /**
     * template_post_parse($final_template, $is_partial, $site_id)
     * Returns the (possibly modified) template string. We don't modify
     * the body — we only set response headers on the final, non-partial
     * parse for GET.
     */
    public function template_post_parse($final_template, $is_partial, $site_id) {
        if (ee()->extensions->last_call !== false) {
            $final_template = ee()->extensions->last_call;
        }

        // Trace mode — activated with ?ect_trace=1 (or X-Edge-Cache-Tags-Trace
        // request header). Injects an HTML comment into the body recording
        // EVERY decision the hook makes for this request, including any
        // early-bail reason. Lets an operator see whether the hook fired,
        // what is_partial / REQUEST_METHOD looked like, and whether keys
        // were produced. Costs nothing in the no-trace path.
        $trace = $this->isTraceRequested();
        $traceBag = $trace ? array(
            'is_partial' => $is_partial ? 'true' : 'false',
            'site_id' => (int) $site_id,
            'method' => isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'unknown',
            'req_const' => defined('REQ') ? REQ : '(undefined)',
        ) : null;

        if ($is_partial) {
            if ($trace) { return $this->traceComment($final_template, $traceBag, 'bail:is_partial'); }
            return $final_template;
        }
        // GET and HEAD both get headers. RFC 7231: HEAD responses must
        // carry the same headers as the equivalent GET. Without this,
        // `curl -I` (which uses HEAD) skips the addon entirely, leaving
        // admins to think their install is broken.
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method !== 'GET' && $method !== 'HEAD') {
            if ($trace) { return $this->traceComment($final_template, $traceBag, 'bail:method=' . $method); }
            return $final_template;
        }
        if (defined('REQ') && REQ === 'CP') {
            if ($trace) { return $this->traceComment($final_template, $traceBag, 'bail:cp_request'); }
            return $final_template;
        }

        $keys = $this->keys_for_request();
        if (!empty($keys)) {
            // Surrogate-Key is the universal contract — Fastly, Varnish,
            // Nivoli, Akamai, Bunny and most custom edges all read it.
            // Always emit.
            //
            // Cache-Tag is Cloudflare Enterprise's proprietary header
            // and ONLY useful when CF Enterprise's purge API is the
            // backend. Pre-v2.4.10 we always emitted it as a defensive
            // measure, which meant every non-CF-Enterprise user paid
            // ~60 bytes/response for a header nothing in their pipeline
            // reads. v2.4.10 ties it to the configured backend.
            $sk = 'Surrogate-Key: ' . implode(' ', $keys);
            $emit_cache_tag = ($this->backend() === 'cloudflare');
            $ct = $emit_cache_tag ? ('Cache-Tag: ' . implode(',', $keys)) : null;

            ee()->output->set_header($sk);
            if ($ct !== null) ee()->output->set_header($ct);

            // v2.4.8 fallback: also emit via plain header(). On some EE
            // installs (CI Output overrides, FastCGI peculiarities, custom
            // template-cache fast paths) the ee()->output buffer is
            // bypassed during response finalize — set_header() queues
            // values that never flush. header() writes straight to
            // PHP-FPM's outgoing response and reliably reaches the wire.
            // On a healthy install the values come out once either way
            // (PHP normalizes duplicate header names). On a broken-Output
            // install this is the only thing that actually delivers the
            // tags. Trace mode (v2.4.7) was what isolated this in the
            // wild on a customer deployment.
            if (!headers_sent()) {
                @header($sk);
                if ($ct !== null) @header($ct);
            }
            // Trace-only marker so an operator can prove via curl which
            // path is delivering. If X-Edge-Cache-Tags-Direct shows up in
            // a response but Surrogate-Key/Cache-Tag don't, the user is
            // on an Output-buffer-broken install AND ran a pre-v2.4.8
            // version (because v2.4.8 also emits Surrogate-Key/Cache-Tag
            // via header() now).
            if ($trace) {
                @header('X-Edge-Cache-Tags-Direct: ran keys=' . count($keys) . ' cache_tag=' . ($emit_cache_tag ? 'on' : 'off'));
            }
        }
        if ($trace) {
            $traceBag['keys'] = count($keys);
            $traceBag['sample'] = implode(',', array_slice($keys, 0, 5)) . (count($keys) > 5 ? ',…' : '');
            @header('X-Edge-Cache-Tags-Trace: ' . $this->encodeTrace($traceBag));
            return $this->traceComment($final_template, $traceBag, $keys ? 'emitted' : 'no_keys');
        }
        return $final_template;
    }

    private function isTraceRequested() {
        if (isset($_GET['ect_trace']) && $_GET['ect_trace'] !== '') return true;
        if (!empty($_SERVER['HTTP_X_EDGE_CACHE_TAGS_TRACE'])) return true;
        return false;
    }

    private function encodeTrace(array $bag) {
        $parts = array();
        foreach ($bag as $k => $v) {
            $v = (string) $v;
            $v = preg_replace('/[\r\n\0]+/', '_', $v);
            $parts[] = $k . '=' . $v;
        }
        return substr(implode(' ', $parts), 0, 500);
    }

    private function traceComment($body, array $bag, $outcome) {
        $bag['outcome'] = $outcome;
        $bag['version'] = $this->version;
        $line = "\n<!-- ECT trace: " . $this->encodeTrace($bag) . " -->\n";
        // Append at end — safe regardless of body shape. Some setups
        // truncate at </html> so prepend a copy at the start too.
        return $line . $body . $line;
    }

    /**
     * Auto-derived keys for the current request + any explicit keys
     * templates registered via the plugin tag.
     */
    private function keys_for_request() {
        $keys = array('all');

        $uri = ee()->uri->uri_string();
        $uri = trim((string) $uri, '/');
        if ($uri === '') {
            $keys[] = 'home';
        } else {
            $segs = explode('/', $uri);
            if (!empty($segs[0])) { $keys[] = 'path-' . $this->slug($segs[0]); }
        }

        // v2.4.9: dropped the tmpl-<group>-<template> auto-tag. It read
        // ee()->TMPL->{group_name,template_name} at emission time, but
        // template_post_parse fires multiple times per page (URL template,
        // embeds, layout) — by the final emit pass the TMPL state reflects
        // the LAYOUT, not the URL-resolved template. The resulting tag
        // (e.g. tmpl-layouts-_html-wrapper) was emitted on every page,
        // making it functionally equivalent to `all`. Other tags (entry-N,
        // channel-X, path-Y, category-Z, home, all) cover the realistic
        // purge use cases; if a user genuinely wants "purge all pages
        // rendered by template X" they can push an explicit
        // {exp:edge_cache_tags:key name="tmpl-X"} at the top of the
        // template itself.

        // Explicit keys pushed by {exp:edge_cache_tags:key}.
        $explicit = ee()->session->cache('edge_cache_tags', 'keys');
        if (is_array($explicit)) {
            foreach ($explicit as $k) { $keys[] = $k; }
        }

        // MSM site prefix.
        if (function_exists('ee') && isset(ee()->config)) {
            $site_id = (int) ee()->config->item('site_id');
            if ($site_id > 1) {
                $prefixed = array();
                foreach ($keys as $k) { $prefixed[] = 'site-' . $site_id . '-' . $k; }
                $prefixed[] = 'all';
                $keys = $prefixed;
            }
        }
        return $this->clean($keys);
    }

    // ---- Purge triggers --------------------------------------------------

    public function after_channel_entry_save($entry, $values) {
        $this->enqueue($this->keys_for_entry($entry, $values));
    }

    public function after_channel_entry_delete($entry) {
        $this->enqueue($this->keys_for_entry($entry, array()));
    }

    private function keys_for_entry($entry, $values) {
        // v2.4.14 — DELIBERATELY does NOT include 'all'.
        //
        // 'all' lives on every emitted page (see keys_for_request) so an
        // admin can use the CP's manual purge tool to nuke the entire
        // cache with one tag. But firing 'all' on EVERY entry save would
        // evict every cached page on the site on every publish — turns
        // surgical tag-purge into "purge the whole edge whenever any
        // editor saves anything," which is exactly the failure mode
        // tag-based caching is supposed to avoid.
        //
        // No filter in EE for the per-entry tag list (yet), but you can
        // call Edge_cache_tags_ext::manual_purge_tags(['all']) from a
        // custom hook into after_channel_entry_save if you genuinely
        // want nuke-on-save behavior.
        $keys = array('home');

        $entry_id = null;
        if (is_object($entry) && isset($entry->entry_id)) { $entry_id = $entry->entry_id; }
        elseif (is_array($values) && isset($values['entry_id'])) { $entry_id = $values['entry_id']; }
        if ($entry_id) { $keys[] = 'entry-' . $entry_id; }

        // Channel/Categories are exposed via EE's model __get magic, which
        // looks like ordinary property access ($entry->Channel) — not
        // method_exists()-detectable. Try the property; catch any error
        // (Throwable covers both Exception and Error in PHP 7+) if the
        // relationship isn't loaded.
        $channel_name = null;
        try {
            $channel = is_object($entry) ? ($entry->Channel ?? null) : null;
            if ($channel && isset($channel->channel_name)) {
                $channel_name = $channel->channel_name;
            } elseif (is_object($entry) && isset($entry->channel_name)) {
                $channel_name = $entry->channel_name;
            }
        } catch (\Throwable $e) { /* ignore */ }
        if ($channel_name) {
            $keys[] = 'channel-' . $this->slug($channel_name);
            $keys[] = 'path-' . $this->slug($channel_name);
        }

        try {
            $cats = is_object($entry) ? ($entry->Categories ?? null) : null;
            if ($cats) {
                foreach ($cats as $cat) {
                    if (isset($cat->cat_id)) { $keys[] = 'category-' . $cat->cat_id; }
                }
            }
        } catch (\Throwable $e) { /* ignore */ }

        if (function_exists('ee') && isset(ee()->config)) {
            $site_id = (int) ee()->config->item('site_id');
            if ($site_id > 1) {
                $prefixed = array();
                foreach ($keys as $k) { $prefixed[] = 'site-' . $site_id . '-' . $k; }
                $keys = $prefixed;
            }
        }
        return $this->clean($keys);
    }

    // ---- Public API for manual purge from the CP -------------------------

    /**
     * Manually dispatch a purge for a caller-supplied tag list. Used by
     * the CP "Quick actions" panel so an admin can refresh specific
     * pages without saving a channel entry. Bypasses the coalesced
     * flush queue — runs synchronously, returns success info that the
     * caller can show in the UI immediately.
     *
     * Returns: ['ok' => bool, 'backend' => string, 'tags' => array,
     *          'dispatched' => int (chunks sent), 'error' => string|null].
     */
    public function manual_purge_tags(array $tags): array {
        $tags = $this->clean($tags);
        if (empty($tags)) {
            return ['ok' => false, 'backend' => 'none', 'tags' => [],
                'dispatched' => 0, 'error' => 'No valid tags to purge.'];
        }
        $backend = $this->backend();
        if ($backend === 'none') {
            return ['ok' => false, 'backend' => 'none', 'tags' => $tags,
                'dispatched' => 0,
                'error' => "Backend is 'none' — nowhere to dispatch. Pick a backend first."];
        }
        $chunks = 0;
        foreach (array_chunk($tags, self::MAX_KEYS) as $chunk) {
            $this->dispatch_purge($backend, $chunk);
            $chunks++;
        }
        return ['ok' => true, 'backend' => $backend, 'tags' => $tags,
            'dispatched' => $chunks, 'error' => null];
    }

    // ---- Coalesced flush --------------------------------------------------

    private function enqueue($keys) {
        foreach ($keys as $k) { self::$pending[$k] = true; }
        if (!self::$shutdown_hooked) {
            self::$shutdown_hooked = true;
            register_shutdown_function(array($this, 'flush'));
        }
    }

    public function flush() {
        if (empty(self::$pending)) { return; }
        $keys = array_keys(self::$pending);
        self::$pending = array();
        $backend = $this->backend();
        if ($backend === 'none') { return; }
        foreach (array_chunk($keys, self::MAX_KEYS) as $chunk) {
            $this->dispatch_purge($backend, $chunk);
        }
    }

    private function dispatch_purge($backend, $tags) {
        switch ($backend) {
            case 'nivoli':     $this->purge_nivoli($tags);     break;
            case 'fastly':     $this->purge_fastly($tags);     break;
            case 'cloudflare': $this->purge_cloudflare($tags); break;
            case 'webhook':    $this->purge_webhook($tags);    break;
        }
    }

    // ---- Drivers ---------------------------------------------------------

    /**
     * POST {tags:[...]} to <dashboard>/purge-tag?host=<current-site-host>.
     *
     * The `?host=` parameter scopes the purge to a specific hostname when
     * the dashboard token is "linked" across multiple MSM hostnames on
     * the Nivoli side (one token, several sites under the same account).
     * Without it Nivoli defaults to the FIRST hostname in the linked set
     * — which means a save on MSM site B sends its purge to site A's
     * cache, and site B's cache never invalidates.
     *
     * Single-tenant tokens ignore `?host=` (the param is allowed-list-
     * validated server-side; a single-host token just matches the only
     * entry). So it's safe to always emit.
     */
    private function purge_nivoli($tags) {
        $url = $this->cfg('nivoli_endpoint');
        if (!$url) { return; }
        $url = rtrim($url, '/') . '/purge-tag' . $this->nivoli_host_qs();
        $this->send_post($url, json_encode(array('tags' => array_values($tags))),
            array('Content-Type: application/json'),
            array('backend' => 'nivoli', 'tags' => $tags));
    }

    /**
     * Build `?host=<host>` from EE's site_url for the current MSM site.
     * Empty string if the host can't be determined (some early-boot
     * paths) — caller's URL stays as-is and Nivoli falls back to the
     * first linked host.
     */
    private function nivoli_host_qs() {
        if (!function_exists('ee') || !isset(ee()->config)) return '';
        $site_url = (string) ee()->config->item('site_url');
        if ($site_url === '') return '';
        $host = parse_url($site_url, PHP_URL_HOST);
        if (!$host || $host === '') return '';
        return '?host=' . rawurlencode(strtolower($host));
    }

    /**
     * Fastly Surrogate-Key purge. Uses the bulk endpoint with the
     * Surrogate-Key header carrying space-separated keys.
     * Soft purge by default — origin can revalidate stale content.
     */
    private function purge_fastly($tags) {
        $service = $this->cfg('fastly_service');
        $key     = $this->cfg('fastly_api_key');
        if (!$service || !$key) { return; }
        $url = 'https://api.fastly.com/service/' . rawurlencode($service) . '/purge';
        $this->send_post($url, '', array(
            'Fastly-Key: ' . $key,
            'Accept: application/json',
            'Surrogate-Key: ' . implode(' ', $tags),
        ), array('backend' => 'fastly', 'tags' => $tags));
    }

    /** Cloudflare Cache-Tag purge — Enterprise plan only. */
    private function purge_cloudflare($tags) {
        $zone  = $this->cfg('cf_zone_id');
        $token = $this->cfg('cf_api_token');
        if (!$zone || !$token) { return; }
        $url = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($zone) . '/purge_cache';
        $this->send_post($url, json_encode(array('tags' => array_values($tags))), array(
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ), array('backend' => 'cloudflare', 'tags' => $tags));
    }

    /** Generic webhook — POST {tags:[...]} to a user-supplied URL. */
    private function purge_webhook($tags) {
        $url    = $this->cfg('webhook_url');
        $secret = $this->cfg('webhook_secret');
        if (!$url) { return; }
        $headers = array('Content-Type: application/json');
        if ($secret) { $headers[] = 'Authorization: Bearer ' . $secret; }
        $this->send_post($url, json_encode(array('tags' => array_values($tags))), $headers,
            array('backend' => 'webhook', 'tags' => $tags));
    }

    /**
     * Fire curl, capture status + duration + body excerpt, log to the
     * purge log table. 5s timeout. Errors are logged but never thrown —
     * a slow/broken edge must not block an editor CP save.
     *
     * $logCtx (optional): ['backend' => string, 'tags' => array]. When
     * present, this method writes one row to exp_edge_cache_tags_purge_log
     * after the curl call completes.
     */
    private function send_post($url, $body, $headers, $logCtx = null) {
        $ch = curl_init($url);
        if (!$ch) {
            if ($logCtx) $this->log_purge($logCtx + array(
                'url' => $url, 'http_status' => 0, 'duration_ms' => 0,
                'response_excerpt' => '', 'error_msg' => 'curl_init failed',
            ));
            return;
        }
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT      => 'edge-cache-tags-ee/' . $this->version,
            CURLOPT_FAILONERROR    => false,
        ));
        $started = microtime(true);
        $response = @curl_exec($ch);
        $duration_ms = (int) ((microtime(true) - $started) * 1000);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($logCtx) {
            $this->log_purge($logCtx + array(
                'url' => $url,
                'http_status' => $status,
                'duration_ms' => $duration_ms,
                'response_excerpt' => is_string($response) ? substr($response, 0, 500) : '',
                'error_msg' => (string) $error,
            ));
        }
    }

    /**
     * Insert one row into exp_edge_cache_tags_purge_log so the CP page
     * can show the user what just got dispatched. Auto-prunes the log to
     * the last 500 rows per site after each insert. Errors swallowed so
     * a logging hiccup never breaks a dispatch.
     */
    private function log_purge($entry) {
        try {
            if (!ee()->db->table_exists('edge_cache_tags_purge_log')) return;
            $siteId = (int) ee()->config->item('site_id');
            $tags = array_values((array) ($entry['tags'] ?? array()));
            ee()->db->insert('edge_cache_tags_purge_log', array(
                'site_id'    => $siteId,
                'created_at' => time(),
                'backend'    => substr((string) ($entry['backend'] ?? ''), 0, 16),
                'tags'       => substr((string) json_encode($tags), 0, 65000),
                'tag_count'  => count($tags),
                'target_url' => substr((string) ($entry['url'] ?? ''), 0, 500),
                'http_status'=> (int) ($entry['http_status'] ?? 0),
                'duration_ms'=> (int) ($entry['duration_ms'] ?? 0),
                'response_excerpt' => substr((string) ($entry['response_excerpt'] ?? ''), 0, 500),
                'error_msg'  => substr((string) ($entry['error_msg'] ?? ''), 0, 200),
            ));
            // Prune. One delete per insert is fine for small data; we'd
            // need to revisit if log volume crosses tens-of-thousands.
            $count = (int) ee()->db->where('site_id', $siteId)->count_all_results('edge_cache_tags_purge_log');
            if ($count > 500) {
                $oldest = ee()->db->select('id')
                    ->where('site_id', $siteId)
                    ->order_by('created_at', 'asc')
                    ->limit($count - 500)
                    ->get('edge_cache_tags_purge_log')
                    ->result_array();
                $ids = array();
                foreach ($oldest as $r) { $ids[] = (int) $r['id']; }
                if ($ids) ee()->db->where_in('id', $ids)->delete('edge_cache_tags_purge_log');
            }
        } catch (\Throwable $e) { /* don't fail dispatch on log error */ }
    }

    // ---- helpers ---------------------------------------------------------

    private function slug($s) {
        $s = strtolower((string) $s);
        $s = preg_replace('/[^a-z0-9\-_]+/', '-', $s);
        return trim($s, '-');
    }

    /**
     * Sanitize keys: enforce header-safe charset, length, dedupe, cap count.
     * Same shape as the WP plugin's clean() so integrations land on
     * identical tag identifiers across CMS layers.
     */
    private function clean($keys) {
        $out = array();
        foreach ($keys as $k) {
            $k = (string) $k;
            $k = preg_replace('/[^A-Za-z0-9._:\-]+/', '-', $k);
            $k = trim($k, '-');
            if ($k === '') { continue; }
            if (strlen($k) > self::MAX_KEY_LEN) { $k = substr($k, 0, self::MAX_KEY_LEN); }
            $out[$k] = true;
        }
        $result = array_keys($out);
        if (count($result) > self::MAX_KEYS) {
            $result = array_slice($result, 0, self::MAX_KEYS);
        }
        return $result;
    }
}
