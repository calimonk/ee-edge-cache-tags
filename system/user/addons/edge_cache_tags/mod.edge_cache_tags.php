<?php
/**
 * Edge Cache Tags — module (EE6 / EE7).
 *
 * Two reasons this file exists:
 *
 * 1. EE treats addons WITHOUT a `mod.*.php` as extension-only and won't
 *    render the settings gear on the Add-Ons card regardless of
 *    `settings_exist=true` or the presence of an Mcp controller. The
 *    Module base class is what makes the addon a "first-class" addon
 *    with a CP backend.
 *
 * 2. {exp:edge_cache_tags:key} is the template tag that lets channel
 *    templates register entry-level surrogate keys for the current
 *    request. Previously it lived in pi.edge_cache_tags.php (legacy
 *    plugin pattern). Module + plugin tags can technically coexist,
 *    but EE addons are cleaner with one tag namespace per type. So we
 *    moved the tag here and removed the pi file.
 */

if (!defined('BASEPATH')) { exit('No direct script access allowed'); }

use ExpressionEngine\Service\Addon\Module;

class Edge_cache_tags extends Module
{
    protected $addon_name = 'edge_cache_tags';

    public $return_data = '';

    /**
     * {exp:edge_cache_tags:key name="entry-123 channel-news"}
     *
     * Registers one or more keys (space- or comma-separated) for this
     * request. The extension (ext.edge_cache_tags.php) reads them on
     * template_post_parse and merges into the Surrogate-Key + Cache-Tag
     * response headers.
     *
     * Outputs nothing. Call multiple times if useful.
     *
     * Usage in a single-entry template:
     *   {exp:channel:entries channel="news" limit="1"}
     *     {exp:edge_cache_tags:key name="entry-{entry_id} channel-news"}
     *     {categories}{exp:edge_cache_tags:key name="category-{category_id}"}{/categories}
     *     ...
     *   {/exp:channel:entries}
     */
    public function key()
    {
        $name = (string) ee()->TMPL->fetch_param('name', '');
        if ($name === '') { $this->return_data = ''; return ''; }

        $keys = preg_split('/[\s,]+/', $name);
        $store = ee()->session->cache('edge_cache_tags', 'keys');
        if (!is_array($store)) { $store = []; }
        foreach ($keys as $k) {
            $k = preg_replace('/\s+/', '', (string) $k);
            if ($k !== '') { $store[$k] = $k; } // dedupe via assoc keys
        }
        ee()->session->set_cache('edge_cache_tags', 'keys', $store);

        $this->return_data = '';
        return '';
    }
}
