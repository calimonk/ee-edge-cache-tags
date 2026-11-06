<?php

/**
 * Edge Cache Tags — ExpressionEngine add-on manifest (EE6 / EE7).
 *
 * Provides:
 *   - An extension that emits Surrogate-Key + Cache-Tag response headers
 *     and dispatches tag-based purges on channel-entry save/delete to
 *     whichever backend the site has configured.
 *   - A template plugin ({exp:edge_cache_tags:key}) so templates can
 *     declare entry-level surrogate keys from inside a channel:entries
 *     loop where the entry/channel/category context is in scope.
 *   - A CP settings + diagnostics page so site admins can configure the
 *     backend, see which hooks are registered, and preview the emitted
 *     keys without dropping to config.php.
 *
 * Hooks register here (modern EE addon.setup.php style) so no
 * activate_extension() bookkeeping is needed.
 */

return array(
    'author'      => 'Edge Cache Tags',
    'author_url'  => 'https://github.com/calimonk/ee-edge-cache-tags',
    'name'        => 'Edge Cache',
    'description' => 'Full-page HTML caching at the edge with surgical tag-based purge. Make every visitor hit Cloudflare, Fastly, Varnish, or Nivoli — not your origin. When an entry changes, only the pages featuring it refresh; never the whole cache.',
    'version'     => '2.5.1',
    'namespace'   => 'EdgeCacheTags',
    'settings_exist' => true,

    'hooks' => array(
        // NOT here: sessions_start (the origin lock). EE registers every hook
        // listed here on install and update, and runs sessions_start on every
        // request, front end and CP alike. Registered on update by the CLI
        // while PHP-FPM still runs the previous ext.edge_cache_tags.php from
        // OPcache (revalidate_freq=300 on the game sites), it made every
        // request fail with "class Edge_cache_tags_ext does not have a method
        // sessions_start" (2.5.0, 2026-11-06). upd.edge_cache_tags.php
        // registers it itself, only when the lock is configured and only from
        // a web request whose loaded class has the method
        // (ensureOriginLockHook).
        // Register the addon in the EE CP sidebar (CUSTOM section). EE
        // only invokes this hook for classes referenced by a row in
        // exp_menu_items; the installer adds that row in ensureMenuItem().
        array(
            'hook'     => 'cp_custom_menu',
            'method'   => 'cp_custom_menu',
            'priority' => 10,
        ),
        // Emit the Surrogate-Key + Cache-Tag headers on the final parsed
        // template.
        array(
            'hook'     => 'template_post_parse',
            'method'   => 'template_post_parse',
            'priority' => 10,
        ),
        // Purge affected tags after a channel entry is saved.
        array(
            'hook'     => 'after_channel_entry_save',
            'method'   => 'after_channel_entry_save',
            'priority' => 10,
        ),
        // Purge after a channel entry is deleted.
        array(
            'hook'     => 'after_channel_entry_delete',
            'method'   => 'after_channel_entry_delete',
            'priority' => 10,
        ),
    ),
);
