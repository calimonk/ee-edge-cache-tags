<?php

/**
 * Edge Cache Tags — ExpressionEngine extension (EE6 / EE7).
 *
 * Responsibilities:
 *   1. template_post_parse: emit Surrogate-Key + Cache-Tag headers
 *      describing the page. Keys come from two sources, merged:
 *        - Auto: URI-segment + template-group/template tags
 *          (e.g. path-news, tmpl-news-index).
 *        - Explicit: anything templates pushed via {exp:edge_cache_tags:key}
 *          (stored in session cache by pi.edge_cache_tags.php),
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

    public $version = '2.1.0';

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

    /** Resolve a setting key, config.php first then DB then default. */
    private function cfg($key, $dbKey = null) {
        $val = ee()->config->item('edge_cache_tags_' . $key);
        if ($val !== null && $val !== '') return (string) $val;
        $row = $this->settings_row();
        $col = $dbKey ?: $key;
        if (isset($row[$col]) && $row[$col] !== '') return (string) $row[$col];
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
        if ($is_partial) {
            return $final_template;
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method !== 'GET') { return $final_template; }
        if (defined('REQ') && REQ === 'CP') { return $final_template; }

        $keys = $this->keys_for_request();
        if (!empty($keys)) {
            // Emit BOTH headers. Fastly/Varnish/Nivoli read Surrogate-Key
            // (space-separated); Cloudflare Enterprise reads Cache-Tag
            // (comma-separated). Harmless duplication when one cache is
            // in the path and the other isn't.
            ee()->output->set_header('Surrogate-Key: ' . implode(' ', $keys));
            ee()->output->set_header('Cache-Tag: ' . implode(',', $keys));
        }
        return $final_template;
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

        if (isset(ee()->TMPL) && is_object(ee()->TMPL)) {
            $tg = isset(ee()->TMPL->group_name) ? ee()->TMPL->group_name : null;
            $tn = isset(ee()->TMPL->template_name) ? ee()->TMPL->template_name : null;
            if ($tg) {
                $keys[] = 'tmpl-' . $this->slug($tg) . ($tn ? '-' . $this->slug($tn) : '');
            }
        }

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
        $keys = array('home', 'all');

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

    /** POST {tags:[...]} to <dashboard>/purge-tag. */
    private function purge_nivoli($tags) {
        $url = $this->cfg('nivoli_endpoint');
        if (!$url) { return; }
        $url = rtrim($url, '/') . '/purge-tag';
        $this->send_post($url, json_encode(array('tags' => array_values($tags))),
            array('Content-Type: application/json'));
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
        ));
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
        ));
    }

    /** Generic webhook — POST {tags:[...]} to a user-supplied URL. */
    private function purge_webhook($tags) {
        $url    = $this->cfg('webhook_url');
        $secret = $this->cfg('webhook_secret');
        if (!$url) { return; }
        $headers = array('Content-Type: application/json');
        if ($secret) { $headers[] = 'Authorization: Bearer ' . $secret; }
        $this->send_post($url, json_encode(array('tags' => array_values($tags))), $headers);
    }

    /** Fire-and-forget curl. 5s timeout. Output suppressed. */
    private function send_post($url, $body, $headers) {
        $ch = curl_init($url);
        if (!$ch) { return; }
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
        @curl_exec($ch);
        curl_close($ch);
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
