<?php
/**
 * Edge Cache Tags — installer (EE6 / EE7).
 *
 * Handles: extension hook registration (via parent::install which reads
 * addon.setup.php), CP backend availability, the settings table, and
 * the sidebar menu entry that lets admins find the addon at all.
 *
 * Why a table when v2.0 was config.php-only:
 * v2.1 adds a CP page so non-developers can configure the backend
 * without editing config.php. The table holds CP-saved values; config.php
 * still wins when both are present (extension reads config first, table
 * second).
 */

if (!defined('BASEPATH')) { exit('No direct script access allowed'); }

use ExpressionEngine\Service\Addon\Installer;

class Edge_cache_tags_upd extends Installer
{
    public $has_cp_backend = 'y';
    public $has_publish_fields = 'n';

    public function install()
    {
        parent::install();
        $this->ensureModuleRow();
        $this->ensureMenuItem();
        $this->ensureExtensionHooks();
        $this->createTables();
        $this->seedSettings();
        return true;
    }

    public function update($current = '')
    {
        parent::update($current);
        // v2.0.0 was extension-only (no upd.*.php), so exp_modules has no
        // row for us. Without that row, EE doesn't show the settings
        // gear on the Add-Ons card. Backfill on every update() so an
        // upgrade from v2.0.0 (or any pre-CP version) self-heals without
        // requiring the operator to uninstall+reinstall. v2.3.5 adds the
        // sidebar menu_items row, v2.4.1 adds the extension hook rows
        // (some upgrade paths landed with 0 rows in exp_extensions — no
        // hooks register, no headers emit, no purges dispatch — so we
        // belt-and-suspenders backfill those too).
        $this->ensureModuleRow();
        $this->ensureMenuItem();
        $this->ensureExtensionHooks();
        $this->createTables();
        $this->seedSettings();
        return true;
    }

    /**
     * Make sure exp_modules has the row EE needs to render the settings
     * cog on the Add-Ons card. Idempotent: inserts when missing, updates
     * the version + has_cp_backend flag if the row exists but is stale.
     */
    private function ensureModuleRow(): void
    {
        if (!ee()->db->table_exists('modules')) return; // EE core table, should always exist
        $row = ee()->db->where('module_name', 'Edge_cache_tags')
            ->get('modules')->row_array();
        $payload = [
            'module_version'     => '2.4.22',
            'has_cp_backend'     => 'y',
            'has_publish_fields' => 'n',
        ];
        if ($row) {
            ee()->db->where('module_name', 'Edge_cache_tags')->update('modules', $payload);
        } else {
            ee()->db->insert('modules', array_merge(['module_name' => 'Edge_cache_tags'], $payload));
        }
    }

    /**
     * Make sure exp_menu_items has a row pointing at our addon. For
     * type='addon' EE uses the `data` field as the URL slug for the
     * sidebar link — `?/cp/addons/settings/<data>` — so it MUST be the
     * addon's directory shortname `edge_cache_tags`, NOT the extension
     * class name. v2.3.5 / v2.4.0 shipped with `Edge_cache_tags_ext` in
     * this field, which made the sidebar link 404 / land on a wrong
     * settings URL. v2.4.1 migrates existing rows to the correct slug.
     * Idempotent.
     */
    private function ensureMenuItem(): void
    {
        if (!ee()->db->table_exists('menu_items')) return;

        // Migrate any legacy row that used the class name as the slug.
        ee()->db->where([
            'type' => 'addon',
            'data' => 'Edge_cache_tags_ext',
        ])->update('menu_items', ['data' => 'edge_cache_tags']);

        // v2.4.13: rename the sidebar label from "Edge Cache Tags" to
        // "Edge Cache" — matches the new manifest name + the WP plugin's
        // top-level menu label. Skipped via WHERE so an operator who
        // manually customized the label keeps theirs.
        ee()->db->where([
            'type' => 'addon',
            'data' => 'edge_cache_tags',
            'name' => 'Edge Cache Tags',
        ])->update('menu_items', ['name' => 'Edge Cache']);

        $exists = (int) ee()->db->where([
            'type' => 'addon',
            'data' => 'edge_cache_tags',
        ])->count_all_results('menu_items');
        if ($exists > 0) return;
        ee()->db->insert('menu_items', [
            'parent_id' => 0,
            'set_id'    => 1,
            'name'      => 'Edge Cache',
            'data'      => 'edge_cache_tags',
            'type'      => 'addon',
            'sort'      => 100,
        ]);
    }

    /**
     * Backfill exp_extensions rows for every hook declared in
     * addon.setup.php. EE's parent::install() / parent::update() is
     * supposed to do this from the manifest, but some upgrade paths
     * (most notably: dropping new files over an older install without
     * clicking "Update" in the CP) leave 0 rows registered. Result:
     * no headers emit, no purges dispatch — the addon is silent.
     *
     * This method reads the manifest itself and inserts whatever's
     * missing. Idempotent: existing rows get their version bumped, new
     * rows get inserted. Each insert sets enabled='y' so the hook is
     * live immediately. Safe to call on every install/update.
     */
    private function ensureExtensionHooks(): void
    {
        if (!ee()->db->table_exists('extensions')) return;
        $manifest = include __DIR__ . '/addon.setup.php';
        $hooks = (array) ($manifest['hooks'] ?? []);
        $class = 'Edge_cache_tags_ext';
        $version = (string) ($manifest['version'] ?? '0');
        foreach ($hooks as $h) {
            $hook   = (string) ($h['hook']   ?? '');
            $method = (string) ($h['method'] ?? $hook);
            $prio   = (int)    ($h['priority'] ?? 10);
            if ($hook === '') continue;
            $existing = ee()->db->where([
                'class'  => $class,
                'method' => $method,
                'hook'   => $hook,
            ])->get('extensions')->row_array();
            $payload = [
                'class'    => $class,
                'method'   => $method,
                'hook'     => $hook,
                'settings' => '',
                'priority' => $prio,
                'version'  => $version,
                'enabled'  => 'y',
            ];
            if ($existing) {
                ee()->db->where([
                    'class' => $class, 'method' => $method, 'hook' => $hook,
                ])->update('extensions', [
                    'version' => $version,
                    'enabled' => 'y',
                    'priority' => $prio,
                ]);
            } else {
                ee()->db->insert('extensions', $payload);
            }
        }
    }

    public function uninstall()
    {
        parent::uninstall();
        ee()->load->dbforge();
        ee()->dbforge->drop_table('edge_cache_tags_settings', true);
        ee()->dbforge->drop_table('edge_cache_tags_purge_log', true);
        // Clean up the modules + menu_items rows in case parent::uninstall()
        // didn't (it normally would, but we backfilled them ourselves in
        // update() so we own removal too).
        if (ee()->db->table_exists('modules')) {
            ee()->db->where('module_name', 'Edge_cache_tags')->delete('modules');
        }
        if (ee()->db->table_exists('menu_items')) {
            // Both the v2.4.0 (class-name) and v2.4.1+ (shortname) slugs,
            // so reinstall after upgrade doesn't leave a stale row behind.
            ee()->db->where('type', 'addon')
                ->where_in('data', ['edge_cache_tags', 'Edge_cache_tags_ext'])
                ->delete('menu_items');
        }
        if (ee()->db->table_exists('extensions')) {
            ee()->db->where('class', 'Edge_cache_tags_ext')->delete('extensions');
        }
        return true;
    }

    /**
     * One row per site for settings. One row per dispatched purge in
     * the log. Both idempotent — re-running this is safe.
     */
    private function createTables(): void
    {
        ee()->load->dbforge();

        if (!ee()->db->table_exists('edge_cache_tags_settings')) {
            ee()->dbforge->add_field([
                'site_id'              => ['type' => 'INT',     'unsigned' => true, 'null' => false],
                'backend'              => ['type' => 'VARCHAR', 'constraint' => 16,  'null' => false, 'default' => 'none'],
                'nivoli_endpoint'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false, 'default' => ''],
                'fastly_service'       => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => false, 'default' => ''],
                'fastly_api_key'       => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false, 'default' => ''],
                'cf_zone_id'           => ['type' => 'VARCHAR', 'constraint' => 64,  'null' => false, 'default' => ''],
                'cf_api_token'         => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false, 'default' => ''],
                'webhook_url'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false, 'default' => ''],
                'webhook_secret'       => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false, 'default' => ''],
                'updated_at'           => ['type' => 'INT',     'unsigned' => true, 'null' => false, 'default' => 0],
            ]);
            ee()->dbforge->add_key('site_id', true);
            ee()->dbforge->create_table('edge_cache_tags_settings', true);
        }

        // Purge log — one row per dispatched purge. The extension writes
        // here on every send_post() call (whatever backend). The CP page
        // surfaces the last N rows under "Recent activity" so an admin
        // can see purges fire in real time, plus capture status + curl
        // errors for debugging. Auto-pruned to 500 rows per site on each
        // insert.
        if (!ee()->db->table_exists('edge_cache_tags_purge_log')) {
            ee()->dbforge->add_field([
                'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'site_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
                'created_at' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
                'backend'    => ['type' => 'VARCHAR', 'constraint' => 16,  'null' => false, 'default' => ''],
                'tags'       => ['type' => 'TEXT',                       'null' => false],
                'tag_count'  => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
                'target_url' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => false, 'default' => ''],
                'http_status'=> ['type' => 'INT', 'null' => false, 'default' => 0],
                'duration_ms'=> ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
                'response_excerpt' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => false, 'default' => ''],
                'error_msg'  => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false, 'default' => ''],
            ]);
            ee()->dbforge->add_key('id', true);
            ee()->dbforge->add_key(['site_id', 'created_at']);
            ee()->dbforge->create_table('edge_cache_tags_purge_log', true);
        }
    }

    /**
     * Insert a default backend='none' row for every known site so the
     * settings page has something to render on first load. Idempotent.
     */
    private function seedSettings(): void
    {
        foreach ($this->knownSiteIds() as $siteId) {
            $exists = ee()->db->where('site_id', $siteId)->count_all_results('edge_cache_tags_settings');
            if ($exists == 0) {
                ee()->db->insert('edge_cache_tags_settings', [
                    'site_id'    => $siteId,
                    'backend'    => 'none',
                    'updated_at' => time(),
                ]);
            }
        }
    }

    private function knownSiteIds(): array
    {
        if (!ee()->db->table_exists('sites')) {
            return [(int) ee()->config->item('site_id') ?: 1];
        }
        $rows = ee()->db->select('site_id')->order_by('site_id', 'asc')->get('sites')->result_array();
        $ids = array_map(fn($r) => (int) $r['site_id'], $rows);
        return $ids ?: [(int) ee()->config->item('site_id') ?: 1];
    }
}
