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
        // requiring the operator to uninstall+reinstall.
        $this->ensureModuleRow();
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
            'module_version'     => '2.1.2',
            'has_cp_backend'     => 'y',
            'has_publish_fields' => 'n',
        ];
        if ($row) {
            ee()->db->where('module_name', 'Edge_cache_tags')->update('modules', $payload);
        } else {
            ee()->db->insert('modules', array_merge(['module_name' => 'Edge_cache_tags'], $payload));
        }
    }

    public function uninstall()
    {
        parent::uninstall();
        ee()->load->dbforge();
        ee()->dbforge->drop_table('edge_cache_tags_settings', true);
        // Clean up the modules row in case parent::uninstall() didn't
        // (it normally would, but we backfilled it ourselves in update()
        // so the addon claims it on the way out too).
        if (ee()->db->table_exists('modules')) {
            ee()->db->where('module_name', 'Edge_cache_tags')->delete('modules');
        }
        return true;
    }

    /**
     * One row per site. Each EE MSM site picks its own backend +
     * credentials. Single-site installs just use site_id=1.
     */
    private function createTables(): void
    {
        ee()->load->dbforge();
        if (ee()->db->table_exists('edge_cache_tags_settings')) return;

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
