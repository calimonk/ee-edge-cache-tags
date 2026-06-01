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
        // requiring the operator to uninstall+reinstall. v2.3.2 adds the
        // sidebar menu_items row to the same idempotent path.
        $this->ensureModuleRow();
        $this->ensureMenuItem();
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
            'module_version'     => '2.3.2',
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
     * Make sure exp_menu_items has a row pointing at our extension
     * class. EE only invokes cp_custom_menu hooks for classes referenced
     * here; without this row the addon won't appear in the CP sidebar
     * (the hook itself can be registered, but EE never calls it).
     * Set_id=1 is the default sidebar set in stock EE installs.
     * Idempotent.
     */
    private function ensureMenuItem(): void
    {
        if (!ee()->db->table_exists('menu_items')) return;
        $exists = (int) ee()->db->where([
            'type' => 'addon',
            'data' => 'Edge_cache_tags_ext',
        ])->count_all_results('menu_items');
        if ($exists > 0) return;
        ee()->db->insert('menu_items', [
            'parent_id' => 0,
            'set_id'    => 1,
            'name'      => 'Edge Cache Tags',
            'data'      => 'Edge_cache_tags_ext',
            'type'      => 'addon',
            'sort'      => 100,
        ]);
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
            ee()->db->where(['type' => 'addon', 'data' => 'Edge_cache_tags_ext'])
                ->delete('menu_items');
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
