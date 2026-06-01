<?php

/**
 * Edge Cache Tags — template plugin (EE6 / EE7).
 *
 * Lets templates declare surrogate keys for the current page from inside a
 * channel:entries loop where the entry/channel/category context is known.
 * The extension (ext.edge_cache_tags.php) reads these on template_post_parse
 * and merges them into the Surrogate-Key + Cache-Tag headers.
 *
 * Usage in a template — single entry view:
 *
 *   {exp:channel:entries channel="news" limit="1"}
 *     {exp:edge_cache_tags:key name="entry-{entry_id} channel-news"}
 *     {categories}{exp:edge_cache_tags:key name="category-{category_id}"}{/categories}
 *     ... your entry markup ...
 *   {/exp:channel:entries}
 *
 * The tag outputs nothing; it just registers keys. Space-separate multiple
 * keys in one tag, or call the tag repeatedly. Keys here MUST match what
 * the extension purges on entry save (entry-<id>, channel-<name>,
 * category-<id>) for tag purges to land.
 */

if (!defined('BASEPATH')) { exit('No direct script access allowed'); }

class Edge_cache_tags {

    public $return_data = '';

    /**
     * {exp:edge_cache_tags:key name="entry-123 channel-news"}
     * Registers one or more keys (space/comma separated) for this request.
     */
    public function key() {
        $name = (string) ee()->TMPL->fetch_param('name', '');
        if ($name === '') { $this->return_data = ''; return ''; }

        $keys = preg_split('/[\s,]+/', $name);
        $store = ee()->session->cache('edge_cache_tags', 'keys');
        if (!is_array($store)) { $store = array(); }
        foreach ($keys as $k) {
            $k = preg_replace('/\s+/', '', (string) $k);
            if ($k !== '') { $store[$k] = $k; } // dedupe via assoc keys
        }
        ee()->session->set_cache('edge_cache_tags', 'keys', $store);

        $this->return_data = '';
        return '';
    }
}
