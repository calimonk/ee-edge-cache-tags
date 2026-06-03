<?php
namespace EdgeCacheTags\ControlPanel\Routes;

use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;

/**
 * Edge Cache Tags — Settings + Diagnostics + Docs (all in one page).
 *
 * URL: ?/cp/addons/settings/edge_cache_tags  (bare = index route)
 *
 * The class name + filename + route_path are all 'index' because EE's CP
 * gear icon on the Add-Ons card links to the BARE addon URL with no
 * sub-path. v2.1.0–v2.1.2 named this 'settings' / Settings.php, which
 * meant the gear redirected back to the Add-Ons list — EE couldn't find
 * an index handler.
 *
 * Three blocks on the page:
 *   1. Settings form — backend dropdown + per-backend credential fields,
 *      saved into exp_edge_cache_tags_settings. config.php items still
 *      win over the form (we show a "set via config.php" lock when they
 *      do, so admins know why their form input isn't taking effect).
 *
 *   2. Diagnostics — hook registration, addon files present, current
 *      MSM site id, backend resolved, sample emitted keys for the
 *      current page context.
 *
 *   3. Inline docs — what tags get emitted, what gets purged, link to
 *      the README for filter hooks and the template plugin.
 */
class Index extends AbstractRoute
{
    protected $route_path = 'index';
    protected $cp_page_title = 'Edge Cache Tags';

    private const BACKENDS = ['none', 'nivoli', 'fastly', 'cloudflare', 'webhook'];

    public function process($id = false)
    {
        $this->addBreadcrumb('index', 'Edge Cache Tags');

        $siteId = (int) ee()->config->item('site_id');
        $msg = null;
        $action = ee('CP/URL')->make('addons/settings/edge_cache_tags/index')->compile();

        // Two POST shapes share this route: settings save (default) and
        // manual tag purge (action=purge_tags). Distinguish via hidden
        // form field so we know which handler to run.
        if (ee('Request')->method() === 'POST') {
            $postAction = (string) ee()->input->post('ect_action');
            if ($postAction === 'purge_tags') {
                $msg = $this->handleManualPurge();
            } elseif ($postAction === 'reinstall_hooks') {
                $msg = $this->handleReinstallHooks();
            } else {
                $this->save($siteId);
                $msg = 'Settings saved.';
            }
        }

        $row = $this->loadSettings($siteId);
        $configOverrides = $this->configOverrides();
        $diag = $this->runDiagnostics($siteId, $row, $configOverrides);

        $this->setBody($this->render($siteId, $row, $configOverrides, $action, $msg, $diag));
        return $this;
    }

    /**
     * Re-run the installer's idempotent ensure* methods on demand. The
     * primary use case: an upgrade landed without going through EE's
     * "Update" button (e.g. files copied in by ssh) so exp_extensions /
     * exp_menu_items / exp_modules can be stale or missing rows. The
     * diag block surfaces a button when it detects 0 extension hooks;
     * this handler runs the same backfill the upd.*.php would.
     */
    private function handleReinstallHooks(): string
    {
        try {
            require_once SYSPATH . 'user/addons/edge_cache_tags/upd.edge_cache_tags.php';
            $upd = new \Edge_cache_tags_upd();
            // update() is the idempotent self-heal path; calls all four
            // ensure* methods. Pass an empty current-version so EE doesn't
            // try to run real version-to-version migration logic — we
            // just want the backfills.
            $upd->update('');
            return 'Reinstall complete — hooks, sidebar entry, modules row, and tables verified.';
        } catch (\Throwable $e) {
            return 'Reinstall failed: ' . $e->getMessage();
        }
    }

    /**
     * Run a manual tag purge against whatever backend is configured.
     * Tags are space- or comma-separated in the form field. Returns a
     * message string to surface above the settings card.
     */
    private function handleManualPurge(): string
    {
        $raw = (string) ee()->input->post('purge_tags_input');
        $tags = preg_split('/[\s,]+/', trim($raw)) ?: [];
        $tags = array_values(array_filter($tags, function ($t) { return $t !== ''; }));
        if (empty($tags)) {
            return 'Enter at least one tag to purge.';
        }
        // Instantiate the extension and ask it to dispatch.
        if (!class_exists('Edge_cache_tags_ext')) {
            require_once SYSPATH . 'user/addons/edge_cache_tags/ext.edge_cache_tags.php';
        }
        $ext = new \Edge_cache_tags_ext();
        $res = $ext->manual_purge_tags($tags);
        if (!$res['ok']) {
            return 'Purge failed: ' . $res['error'];
        }
        return 'Purge dispatched: ' . implode(', ', $res['tags']) .
            ' (' . $res['backend'] . ', ' . $res['dispatched'] . ' call' .
            ($res['dispatched'] === 1 ? '' : 's') . '). See Recent activity below for the result.';
    }

    // ---- Persistence -----------------------------------------------------

    private function loadSettings(int $siteId): array
    {
        $row = ee()->db->where('site_id', $siteId)
            ->get('edge_cache_tags_settings')
            ->row_array();
        if (!$row) {
            return [
                'site_id'         => $siteId,
                'backend'         => 'none',
                'nivoli_endpoint' => '',
                'fastly_service'  => '',
                'fastly_api_key'  => '',
                'cf_zone_id'      => '',
                'cf_api_token'    => '',
                'webhook_url'     => '',
                'webhook_secret'  => '',
                'updated_at'      => 0,
            ];
        }
        return $row;
    }

    private function save(int $siteId): void
    {
        $backend = (string) ee()->input->post('backend');
        if (!in_array($backend, self::BACKENDS, true)) $backend = 'none';

        $row = [
            'site_id'         => $siteId,
            'backend'         => $backend,
            'nivoli_endpoint' => trim((string) ee()->input->post('nivoli_endpoint', true)),
            'fastly_service'  => trim((string) ee()->input->post('fastly_service', true)),
            'fastly_api_key'  => trim((string) ee()->input->post('fastly_api_key', true)),
            'cf_zone_id'      => trim((string) ee()->input->post('cf_zone_id', true)),
            'cf_api_token'    => trim((string) ee()->input->post('cf_api_token', true)),
            'webhook_url'     => trim((string) ee()->input->post('webhook_url', true)),
            'webhook_secret'  => trim((string) ee()->input->post('webhook_secret', true)),
            'updated_at'      => time(),
        ];

        $exists = ee()->db->where('site_id', $siteId)->count_all_results('edge_cache_tags_settings');
        if ($exists > 0) {
            ee()->db->where('site_id', $siteId)->update('edge_cache_tags_settings', $row);
        } else {
            ee()->db->insert('edge_cache_tags_settings', $row);
        }
    }

    /**
     * Detect which settings keys are pinned via config.php. The CP form
     * displays those fields as read-only with a "set via config" lock so
     * the admin understands their form input won't take effect.
     */
    /**
     * Map of CP form field name → config.php / $assign_to_config item
     * key. Used by configOverrides() to detect locks and by the
     * "Config resolution" probe panel to surface what EE returned.
     */
    private const CONFIG_KEYS = [
        'backend'         => 'edge_cache_tags_backend',
        'nivoli_endpoint' => 'edge_cache_tags_nivoli_endpoint',
        'fastly_service'  => 'edge_cache_tags_fastly_service',
        'fastly_api_key'  => 'edge_cache_tags_fastly_api_key',
        'cf_zone_id'      => 'edge_cache_tags_cf_zone_id',
        'cf_api_token'    => 'edge_cache_tags_cf_api_token',
        'webhook_url'     => 'edge_cache_tags_webhook_url',
        'webhook_secret'  => 'edge_cache_tags_webhook_secret',
    ];

    private function configOverrides(): array
    {
        $out = [];
        foreach (self::CONFIG_KEYS as $local => $configKey) {
            $val = ee()->config->item($configKey);
            // CI's Config::item() returns null for missing keys, but in
            // some EE versions / install setups it can return false (a
            // sentinel from an older code path). Cast first, trim, then
            // empty-check — this collapses null / false / "" / "   " all
            // into "no override set" without losing literal values like
            // "0" or "none" (which DO count as a real pin).
            $valStr = trim((string) ($val ?? ''));
            if ($valStr !== '') $out[$local] = $valStr;
        }
        return $out;
    }

    // ---- Diagnostics -----------------------------------------------------

    /**
     * Inspect the runtime state: which hooks are enabled, whether the
     * settings table exists + has a row for this site, whether all addon
     * files exist on disk, and (for the resolved backend) whether the
     * credentials needed to dispatch a purge are present.
     */
    private function runDiagnostics(int $siteId, array $row, array $overrides): array
    {
        $checks = [];

        // 1. Settings row exists for this site.
        $checks[] = [
            'label'  => 'Settings row for site #' . $siteId,
            'ok'     => $row['updated_at'] > 0 || $row['backend'] !== 'none' || !empty($overrides),
            'detail' => $row['updated_at'] > 0
                ? 'updated ' . date('Y-m-d H:i:s', $row['updated_at'])
                : (empty($overrides) ? 'using defaults' : 'all values pinned via config.php'),
        ];

        // 2. Extension hooks registered + enabled. Should be 4:
        //    cp_custom_menu, template_post_parse, after_channel_entry_save,
        //    after_channel_entry_delete.
        //
        // When this comes back 0 (which has happened — some upgrade
        // paths leave exp_extensions empty), the front end emits no
        // headers and saves dispatch no purges. The CP fixHooks action
        // below lets the operator backfill without uninstall+reinstall.
        $hookRows = (int) ee()->db->where([
            'class'   => 'Edge_cache_tags_ext',
            'enabled' => 'y',
        ])->count_all_results('extensions');
        $hookDetail = $hookRows . ' enabled row(s) — expected 4 (cp_custom_menu, template_post_parse, after_channel_entry_save, after_channel_entry_delete)';
        if ($hookRows < 4) {
            $hookDetail .= ' — headers will not emit and purges will not dispatch. Click "Reinstall hooks" below, or run Update on the Add-Ons listing.';
        }
        $checks[] = [
            'label'  => 'Extension hooks',
            'ok'     => $hookRows >= 4,
            'detail' => $hookDetail,
            // Tells the renderer to append a reinstall-hooks button row
            // when this check is failing.
            'action' => $hookRows < 4 ? 'reinstall_hooks' : null,
        ];

        // 2b. Sidebar menu item — type='addon' rows are rendered directly
        // by core EE; the row's `data` field is the URL slug. Must be the
        // addon shortname `edge_cache_tags`. v2.4.0 and earlier used the
        // class name `Edge_cache_tags_ext`, which produced a wrong URL;
        // installer migrates on update.
        $menuRows = (int) ee()->db->where('type', 'addon')
            ->where_in('data', ['edge_cache_tags', 'Edge_cache_tags_ext'])
            ->count_all_results('menu_items');
        $menuLegacy = (int) ee()->db->where('type', 'addon')
            ->where('data', 'Edge_cache_tags_ext')
            ->count_all_results('menu_items');
        $menuDetail = $menuRows > 0
            ? 'present — addon shows in the CP sidebar'
            : 'missing — addon won\'t appear in the CP sidebar (re-run Update on the Add-Ons listing)';
        if ($menuLegacy > 0) {
            $menuDetail = 'legacy slug detected (Edge_cache_tags_ext) — sidebar URL is broken. Re-run Update on the Add-Ons listing to migrate to the correct slug.';
        }
        $checks[] = [
            'label'  => 'Sidebar menu entry',
            'ok'     => $menuRows > 0 && $menuLegacy === 0,
            'detail' => $menuDetail,
        ];

        // 3. Addon files on disk.
        $files = [
            'addon.setup.php', 'ext.edge_cache_tags.php', 'mod.edge_cache_tags.php',
            'mcp.edge_cache_tags.php', 'upd.edge_cache_tags.php',
            'ControlPanel/Routes/Index.php',
        ];
        $base = SYSPATH . 'user/addons/edge_cache_tags/';
        $missing = [];
        foreach ($files as $f) {
            if (!is_file($base . $f)) $missing[] = $f;
        }
        $checks[] = [
            'label'  => 'Addon files',
            'ok'     => empty($missing),
            'detail' => empty($missing) ? count($files) . ' files present' : 'missing: ' . implode(', ', $missing),
        ];

        // 4. Backend credentials. Empty + sensible only when backend=none.
        $effective = $this->effectiveSettings($row, $overrides);
        $credCheck = $this->credentialCheck($effective);
        $checks[] = [
            'label'  => 'Backend credentials',
            'ok'     => $credCheck['ok'],
            'detail' => $credCheck['detail'],
        ];

        // 5. MSM site count — informational only, never "fails".
        $siteCount = 1;
        if (ee()->db->table_exists('sites')) {
            $siteCount = (int) ee()->db->count_all_results('sites');
        }
        $checks[] = [
            'label'  => 'Multi-Site Manager',
            'ok'     => true,
            'detail' => $siteCount > 1
                ? $siteCount . ' sites — keys prefixed with site-<id>- on sites > 1 for isolation'
                : 'single-site install (keys not prefixed)',
        ];

        return ['checks' => $checks, 'effective' => $effective, 'sample_keys' => $this->sampleKeys($siteId)];
    }

    private function effectiveSettings(array $row, array $overrides): array
    {
        $eff = $row;
        foreach ($overrides as $k => $v) $eff[$k] = $v;
        return $eff;
    }

    private function credentialCheck(array $eff): array
    {
        switch ($eff['backend']) {
            case 'none':
                return ['ok' => true, 'detail' => 'backend=none — headers emit, no purge dispatch (intentional)'];
            case 'nivoli':
                return $eff['nivoli_endpoint']
                    ? ['ok' => true,  'detail' => 'dashboard URL configured']
                    : ['ok' => false, 'detail' => 'backend=nivoli but nivoli_endpoint is empty'];
            case 'fastly':
                $have = $eff['fastly_service'] && $eff['fastly_api_key'];
                return $have
                    ? ['ok' => true,  'detail' => 'service ' . $eff['fastly_service'] . ' + API key set']
                    : ['ok' => false, 'detail' => 'backend=fastly but service ID or API key is empty'];
            case 'cloudflare':
                $have = $eff['cf_zone_id'] && $eff['cf_api_token'];
                return $have
                    ? ['ok' => true,  'detail' => 'zone ' . $eff['cf_zone_id'] . ' + API token set']
                    : ['ok' => false, 'detail' => 'backend=cloudflare but zone ID or API token is empty'];
            case 'webhook':
                return $eff['webhook_url']
                    ? ['ok' => true,  'detail' => 'POST -> ' . $eff['webhook_url']]
                    : ['ok' => false, 'detail' => 'backend=webhook but webhook URL is empty'];
        }
        return ['ok' => false, 'detail' => 'unknown backend "' . $eff['backend'] . '" — falling back to none'];
    }

    /**
     * A snapshot of what keys the extension WOULD emit on a typical
     * front-end page. We use a stand-in "home" URI here; templates can
     * use {exp:edge_cache_tags:key} to register more.
     */
    private function sampleKeys(int $siteId): array
    {
        $keys = ['all', 'home'];
        if ($siteId > 1) {
            return [
                'home page'       => ['site-' . $siteId . '-all', 'site-' . $siteId . '-home', 'all'],
                'news/article'    => ['site-' . $siteId . '-all', 'site-' . $siteId . '-path-news', 'all'],
                'entry save (id=42, channel=news, category=9)' => [
                    'site-' . $siteId . '-home', 'site-' . $siteId . '-all',
                    'site-' . $siteId . '-entry-42',
                    'site-' . $siteId . '-channel-news', 'site-' . $siteId . '-path-news',
                    'site-' . $siteId . '-category-9',
                ],
            ];
        }
        return [
            'home page'    => ['all', 'home'],
            'news/article' => ['all', 'path-news'],
            'entry save (id=42, channel=news, category=9)' => [
                'home', 'all', 'entry-42', 'channel-news', 'path-news', 'category-9',
            ],
        ];
    }

    // ---- Render ----------------------------------------------------------

    private function render(int $siteId, array $r, array $overrides, string $action, ?string $msg, array $diag): string
    {
        $h  = fn($v) => htmlspecialchars((string) $v);
        $eff = $diag['effective'];
        $effBackend = $eff['backend'] ?: 'none';
        $alert = $msg ? '<div class="ect-alert">' . $h($msg) . '</div>' : '';
        // When the backend is config-pinned, the override value wins.
        // Without this, the dropdown rendered the DB row's value (often
        // 'none') even though config.php had a different pinned value,
        // which made the lock indicator look wrong / confusing.
        $backendShown = isset($overrides['backend']) ? (string) $overrides['backend'] : (string) $r['backend'];
        $backendSelect = $this->renderBackendSelect($backendShown, isset($overrides['backend']));

        // Live stats hero (Nivoli only). Hoisted out of the cfgPanel
        // body so it stays visible when the configuration form below is
        // collapsed. One outbound fetch per CP request, memoized in
        // fetchNivoliStats(). Fails silently if endpoint unreachable.
        $statsHero = '';
        $hostScopeBanner = '';
        if ($effBackend === 'nivoli') {
            $effEndpoint = $overrides['nivoli_endpoint'] ?? ($r['nivoli_endpoint'] ?? '');
            if ($effEndpoint !== '') {
                $stats = $this->fetchNivoliStats($effEndpoint);
                if ($stats) $statsHero = $this->renderNivoliStatsWidget($stats);
                // v2.4.18: surface the token's host scope so a misconfig
                // (wrong dashboard URL pasted) is obvious at render time
                // rather than diagnosed via empty stats / failing purges.
                $hostScopeBanner = $this->renderHostScopeBanner($effEndpoint);
            }
        }

        // Whether to show the configuration form expanded by default.
        // Open when: no backend chosen yet (initial setup), credentials
        // missing (need attention), or a save just happened (echo what
        // they just saved). Otherwise collapsed — once it's working, you
        // rarely revisit the form.
        $credIssue = false;
        foreach (($diag['checks'] ?? []) as $check) {
            if (!($check['ok'] ?? true) && ($check['label'] ?? '') === 'Backend credentials') {
                $credIssue = true; break;
            }
        }
        $configOpen = ($effBackend === 'none') || $credIssue || ($msg !== null);
        $configOpenAttr = $configOpen ? ' open' : '';
        $configSummaryLabel = $effBackend === 'none'
            ? 'Configure a backend'
            : 'Backend: ' . ucfirst($effBackend) . ' · click to edit';
        $configBlocks = $this->renderBackendConfigBlocks($r, $overrides, $effBackend);
        $diagBlock = $this->renderDiagBlock($diag);
        $versionBanner = $this->renderVersionCheck();
        $configProbeBlock = $this->renderConfigProbe();
        $activityBlock = $this->renderActivityBlock($siteId);
        $toolsBlock = $this->renderToolsBlock($diag['effective'], $action, $siteId);
        $docsBlock = $this->renderDocsBlock();

        // Tab badges. Status tab shows a green tick if all diag checks
        // pass, a red dot if any fail — saves the operator one click to
        // know whether anything's broken.
        $diagOk = true;
        foreach (($diag['checks'] ?? []) as $check) {
            if (empty($check['ok'])) { $diagOk = false; break; }
        }
        $diagBadge = $diagOk
            ? '<span class="ect-tab-badge" style="background:#d1fae5;color:#065f46">OK</span>'
            : '<span class="ect-tab-badge" style="background:#fee2e2;color:#991b1b">!</span>';

        return <<<HTML
<style>
/* Width cap so paragraphs don't stretch to wide-monitor unreadability.
   Cards stay full-width visually but their text wraps at a sensible
   line length. */
.ect { font-size:14px; line-height:1.65; color:#0f172a; max-width:980px; }
.ect h2 { margin:0 0 6px; font-size:17px; font-weight:600; color:#0f172a; line-height:1.3; }
.ect .sub { color:#64748b; font-size:13.5px; margin:0 0 16px; line-height:1.6; }
.ect-alert { background:#d1fae5; color:#065f46; padding:11px 14px; border-radius:6px; margin-bottom:14px; font-weight:500; }
.ect-card { background:white; border:1px solid #e2e8f0; border-radius:8px; padding:22px 26px 24px; margin-bottom:16px; }
.ect-card > p, .ect-card > ul, .ect-card > ol { max-width:760px; }
.ect-row { display:grid; grid-template-columns:200px 1fr; gap:14px 22px; align-items:start; margin-bottom:14px; padding-bottom:14px; border-bottom:1px solid #f1f5f9; }
.ect-row:last-of-type { margin-bottom:0; padding-bottom:0; border-bottom:0; }
.ect-row > label.ect-lbl { font-weight:600; color:#1e293b; font-size:14px; padding-top:7px; }
.ect-row > label.ect-lbl small { display:block; font-weight:400; color:#94a3b8; font-size:12px; margin-top:2px; }
.ect-field input[type=text], .ect-field input[type=url], .ect-field input[type=password], .ect-field select { width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13.5px; box-sizing:border-box; background:white; color:#0f172a; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
.ect-field input:focus, .ect-field select:focus { outline:2px solid #1d4ed8; outline-offset:-1px; border-color:#1d4ed8; }
.ect-field .help { color:#64748b; font-size:12.5px; margin-top:6px; line-height:1.6; }
.ect-field .locked { background:#f1f5f9; color:#64748b; cursor:not-allowed; }
/* Lock detail box — shown under a config-pinned field. Surfaces the
   actual pinned value (truncated) plus instructions to unlock. */
.ect-field .lock-detail { margin-top:8px; padding:10px 12px; background:#fef3c7; border:1px solid #fde68a; border-radius:6px; }
.ect-field .lock-tag { display:inline-block; background:#92400e; color:#fef3c7; padding:1px 7px; border-radius:3px; font-size:10px; font-weight:700; letter-spacing:0.05em; }
.ect-field .lock-current { color:#78350f; font-family:ui-monospace,Menlo,monospace; font-size:12.5px; font-weight:600; word-break:break-all; }
.ect-field .lock-howto { display:block; color:#78350f; font-size:12px; line-height:1.6; margin-top:6px; }
.ect-field .lock-howto code { background:rgba(120,53,15,0.10); color:#78350f; }
.ect-btn { display:inline-block; padding:9px 20px; border-radius:6px; text-decoration:none; font-weight:600; font-size:13px; background:#1d4ed8; color:white !important; border:1px solid transparent; cursor:pointer; }
.ect-btn:hover { background:#1e40af; }
.ect-save { margin-top:20px; }
.ect-backend-cfg { display:none; }
.ect-backend-cfg.active { display:block; }
.ect-diag-summary { display:inline-block; padding:3px 10px; border-radius:4px; font-size:12px; font-weight:600; margin-left:8px; }
.ect-diag-summary.ok { background:#d1fae5; color:#065f46; }
.ect-diag-summary.bad { background:#fee2e2; color:#991b1b; }
.ect-diag-row { display:grid; grid-template-columns:28px 240px 1fr; align-items:center; padding:9px 10px; border-bottom:1px solid #f1f5f9; gap:12px; font-size:13px; }
.ect-diag-row:last-child { border-bottom:0; }
.ect-diag-row.bad { background:#fef2f2; }
.ect-diag-tick { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; font-weight:700; font-size:13px; }
.ect-diag-tick.ok { background:#d1fae5; color:#065f46; }
.ect-diag-tick.bad { background:#fee2e2; color:#b91c1c; }
.ect-diag-label { color:#1e293b; font-weight:500; }
.ect-diag-detail { color:#64748b; font-size:12.5px; font-family:ui-monospace,Menlo,monospace; word-break:break-word; }
.ect-sample { background:#0f172a; color:#e2e8f0; padding:14px 16px; border-radius:6px; font-family:ui-monospace,Menlo,monospace; font-size:12px; line-height:1.7; margin-top:10px; }
.ect-sample .label { color:#94a3b8; }
.ect-sample .tag { color:#7dd3fc; }
.ect code { background:#f1f5f9; padding:1px 6px; border-radius:3px; font-size:12.5px; color:#1e293b; }
.ect pre code { background:transparent; padding:0; color:inherit; }
.ect pre { color:#e2e8f0; max-width:760px; }
/* Docs typography — wider line-height, narrower text column, real
   heading hierarchy. Was a cramped wall before. */
.ect-docs { padding:24px 28px 28px; }
.ect-docs > p, .ect-docs > ul, .ect-docs > ol { max-width:720px; }
.ect-docs h3 { margin:24px 0 8px; font-size:15px; font-weight:600; color:#0f172a; line-height:1.35; }
.ect-docs h3:first-child { margin-top:4px; }
.ect-docs h4 { margin:18px 0 6px; font-size:13.5px; font-weight:600; color:#1e293b; line-height:1.4; }
.ect-docs p { margin:8px 0 12px; color:#475569; line-height:1.7; }
.ect-docs ul { margin:8px 0 14px 22px; color:#475569; line-height:1.7; }
.ect-docs ol { margin:8px 0 14px 22px; color:#475569; line-height:1.7; }
.ect-docs li { margin:4px 0; }
.ect-docs a { color:#1d4ed8; }

/* Tab nav — 3 pages: Setup / Status / Docs. JS-toggles which content
   block is visible. Tab state lives on data-active so a future server-
   rendered initial state could restore it from a query param. */
.ect-tabs { display:flex; gap:2px; border-bottom:2px solid #e2e8f0; margin-bottom:18px; }
.ect-tab { background:none; border:0; padding:11px 18px; font-size:13.5px; font-weight:600; color:#64748b; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px; font-family:inherit; }
.ect-tab:hover { color:#1e293b; }
.ect-tab.active { color:#1d4ed8; border-bottom-color:#1d4ed8; }
.ect-tab-badge { display:inline-block; margin-left:6px; padding:1px 7px; border-radius:99px; background:#e2e8f0; color:#64748b; font-size:10.5px; font-weight:700; }
.ect-tab.active .ect-tab-badge { background:#dbeafe; color:#1d4ed8; }
.ect-tab-panel { display:none; }
.ect-tab-panel.active { display:block; }

/* Collapsed configuration block. Used on the Setup tab so the stats
   hero + Quick actions stay top-of-page once the backend is wired up
   and rarely-edited. Native <details>/<summary> for zero JS. */
.ect-config-details { margin-bottom:14px; }
.ect-config-summary { list-style:none; cursor:pointer; padding:13px 18px; background:white; border:1px solid #e2e8f0; border-radius:8px; display:flex; align-items:center; gap:10px; font-weight:600; color:#1e293b; font-size:13.5px; transition:border-color 0.15s; }
.ect-config-summary::-webkit-details-marker { display:none; }
.ect-config-summary:hover { border-color:#cbd5e1; }
.ect-config-details[open] .ect-config-summary { border-color:#1d4ed8; border-bottom-left-radius:0; border-bottom-right-radius:0; }
.ect-config-summary-icon { font-size:15px; opacity:0.65; }
.ect-config-summary-label { flex:1; }
.ect-config-summary-hint { font-size:11.5px; font-weight:500; color:#94a3b8; text-transform:uppercase; letter-spacing:0.05em; }
.ect-config-details[open] .ect-config-summary-hint::after { content:""; }
.ect-config-details:not([open]) .ect-config-summary-hint::before { content:"▾ "; }
.ect-config-details[open] .ect-config-summary-hint::before { content:"▴ "; }
.ect-config-details[open] .ect-config-summary .ect-config-summary-hint { content:"collapse"; }
.ect-config-details[open] .ect-card { border-top-left-radius:0; border-top-right-radius:0; border-top:0; margin-top:0 !important; }
</style>

<div class="ect">

<h2>Edge Cache Tags · site #{$siteId}</h2>
<p class="sub" style="font-size:14px;line-height:1.6;color:#334155;max-width:760px">
  <strong style="color:#0f172a">Surgical cache invalidation for ExpressionEngine.</strong>
  Every page gets tagged with what it actually contains — the entry ID, channel name, category IDs, template group. When an editor publishes an entry, only the pages featuring it (the entry page, the homepage, its channel index, its category archives) get cleared from your edge cache. Not the whole site.
  <span style="color:#64748b">Works with Fastly, Cloudflare Enterprise, Nivoli, or your own edge via webhook.</span>
</p>

{$alert}

<nav class="ect-tabs" role="tablist" aria-label="Edge Cache Tags sections">
  <button type="button" class="ect-tab active" data-tab="setup" role="tab" aria-selected="true">Setup</button>
  <button type="button" class="ect-tab" data-tab="status" role="tab" aria-selected="false">Status{$diagBadge}</button>
  <button type="button" class="ect-tab" data-tab="activity" role="tab" aria-selected="false">Activity</button>
  <button type="button" class="ect-tab" data-tab="docs" role="tab" aria-selected="false">Documentation</button>
</nav>

<section class="ect-tab-panel active" data-panel="setup">
  {$statsHero}

  {$hostScopeBanner}

  {$toolsBlock}

  <details class="ect-config-details"{$configOpenAttr}>
    <summary class="ect-config-summary">
      <span class="ect-config-summary-icon">⚙</span>
      <span class="ect-config-summary-label">{$configSummaryLabel}</span>
      <span class="ect-config-summary-hint">expand</span>
    </summary>
    <form method="POST" action="{$action}">
    <div class="ect-card" style="margin-top:10px">
      <div class="ect-row">
        <label class="ect-lbl">Edge cache</label>
        <div class="ect-field">
          {$backendSelect}
          <div class="help">Which cache should receive the purge calls when entries change. Headers emit regardless — the cache reads them either way; this just picks who gets pinged about updates.</div>
        </div>
      </div>

      {$configBlocks}

      <div class="ect-save"><button type="submit" class="ect-btn">Save settings</button></div>
    </div>
    </form>
  </details>
</section>

<section class="ect-tab-panel" data-panel="status">
  {$versionBanner}
  {$diagBlock}
  {$configProbeBlock}
</section>

<section class="ect-tab-panel" data-panel="activity">
  {$activityBlock}
</section>

<section class="ect-tab-panel" data-panel="docs">
  {$docsBlock}
</section>

</div>

<script>
(function () {
  // Backend select → show only the matching credential block.
  var sel = document.getElementById('ect-backend');
  if (sel) {
    function syncCfgVisibility() {
      var v = sel.value;
      document.querySelectorAll('.ect-backend-cfg').forEach(function (el) {
        el.classList.toggle('active', el.id === 'cfg-' + v);
      });
    }
    sel.addEventListener('change', syncCfgVisibility);
    syncCfgVisibility();
  }

  // Tab nav. Persist last-active tab to localStorage so a save/POST
  // round-trip lands the operator back on the same tab they were on.
  var tabs = document.querySelectorAll('.ect-tab');
  var panels = document.querySelectorAll('.ect-tab-panel');
  function activate(name) {
    tabs.forEach(function (t) {
      var on = t.getAttribute('data-tab') === name;
      t.classList.toggle('active', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    panels.forEach(function (p) {
      p.classList.toggle('active', p.getAttribute('data-panel') === name);
    });
    try { localStorage.setItem('ect_tab', name); } catch (e) {}
  }
  tabs.forEach(function (t) {
    t.addEventListener('click', function () { activate(t.getAttribute('data-tab')); });
  });
  try {
    var saved = localStorage.getItem('ect_tab');
    if (saved && document.querySelector('.ect-tab[data-tab="' + saved + '"]')) {
      activate(saved);
    }
  } catch (e) {}
})();
</script>
HTML;
    }

    private function renderBackendSelect(string $current, bool $locked): string
    {
        $labels = [
            'none'       => 'Headers only — no purge dispatch',
            'nivoli'     => 'Nivoli',
            'fastly'     => 'Fastly',
            'cloudflare' => 'Cloudflare (Enterprise plan)',
            'webhook'    => 'Custom webhook',
        ];
        $lockedAttr = $locked ? 'disabled class="locked"' : '';
        $opts = '';
        foreach ($labels as $val => $label) {
            $sel = $current === $val ? ' selected' : '';
            $opts .= '<option value="' . htmlspecialchars($val) . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
        }
        $select = '<select id="ect-backend" name="backend" ' . $lockedAttr . '>' . $opts . '</select>';
        if ($locked) {
            // Submit the locked value so save() still gets it.
            $select .= '<input type="hidden" name="backend" value="' . htmlspecialchars($current) . '">';
            // Same lock-detail UI as the per-backend credential fields:
            // surface the actual pinned value + how to unlock. The user
            // needs to know WHICH file is pinning it, not just that
            // *something* is.
            $shown = $labels[$current] ?? $current;
            $select .= '<div class="lock-detail">'
                . '<span class="lock-tag">🔒 PINNED VIA CONFIG</span> '
                . '<span class="lock-current">' . htmlspecialchars($shown) . ' (' . htmlspecialchars($current) . ')</span>'
                . '<span class="lock-howto">'
                . 'Pinned by <code>$config[\'edge_cache_tags_backend\']</code> or <code>$assign_to_config[\'edge_cache_tags_backend\']</code>. '
                . 'Likely files: <code>system/user/config/config.php</code>, the site\'s <code>index.php</code>, or <code>admin.php</code>. '
                . 'Grep your install with:<br>'
                . '<code style="display:inline-block;margin-top:4px">grep -rn edge_cache_tags_backend system/user/config/ index.php admin.php</code>'
                . '</span>'
                . '</div>';
        }
        return $select;
    }

    private function renderBackendConfigBlocks(array $r, array $overrides, string $effBackend): string
    {
        return $this->cfgPanel('none', 'Headers only', $r, $overrides, $effBackend === 'none')
            . $this->cfgPanel('nivoli', 'Nivoli', $r, $overrides, $effBackend === 'nivoli')
            . $this->cfgPanel('fastly', 'Fastly', $r, $overrides, $effBackend === 'fastly')
            . $this->cfgPanel('cloudflare', 'Cloudflare (Enterprise)', $r, $overrides, $effBackend === 'cloudflare')
            . $this->cfgPanel('webhook', 'Custom webhook', $r, $overrides, $effBackend === 'webhook');
    }

    /**
     * Shared Nivoli pitch — the dark-gradient hero shown when backend=none.
     * v2.4.11 trim: hero + 3-pill row + CTA, half the height of the previous
     * incarnation. Same visual asset is mirrored in the WordPress plugin so
     * the marketing surface stays consistent across the two integrations.
     */
    private static function renderNivoliPitch(): string
    {
        return '<div style="background:linear-gradient(135deg,#1e1b4b 0%,#7c3aed 55%,#db2777 100%);color:white;border-radius:10px;margin-top:18px;overflow:hidden;box-shadow:0 6px 24px rgba(124,58,237,0.22)">'

              // Hero
              . '<div style="padding:24px 28px 18px">'
              . '<div style="font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:#fbbf24;margin-bottom:10px">⚡ Full-page HTML caching on Cloudflare</div>'
              . '<h3 style="margin:0 0 12px;color:white;font-size:23px;font-weight:700;line-height:1.2;letter-spacing:-0.015em">Cloudflare doesn\'t cache HTML.<br><span style="color:#fbbf24">Nivoli does.</span></h3>'
              . '<p style="margin:0 0 8px;font-size:14px;line-height:1.6;opacity:0.95">'
              . 'Out of the box, Cloudflare caches CSS, JS, and images at the edge — every HTML pageview still round-trips to origin. '
              . '<strong>Nivoli adds the missing layer:</strong> full-page HTML caching on Cloudflare\'s global network. '
              . 'Every visitor hits Cloudflare; only <strong style="color:#fbbf24">cache MISSes touch origin</strong>.</p>'
              . '<p style="margin:0;font-size:13.5px;line-height:1.55;opacity:0.92">'
              . '<strong>And it stays correct.</strong> This addon fires tag purge on entry save — only affected pages refresh, never the whole cache.</p>'
              . '</div>'

              // 3-pill differentiator row
              . '<div style="padding:0 28px 20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px">'

              . '<div style="background:rgba(52,211,153,0.14);border:1px solid rgba(52,211,153,0.38);padding:11px 14px;border-radius:7px;font-size:12.5px;line-height:1.5">'
              .   '<strong style="color:#6ee7b7;display:block;margin-bottom:3px;font-size:12.5px">🛡 Attack blackholing</strong>'
              .   '<span style="opacity:0.88">.env / .git / xmlrpc / wp-admin probes return 404 at the edge. Origin never sees them.</span>'
              . '</div>'

              . '<div style="background:rgba(192,132,252,0.16);border:1px solid rgba(192,132,252,0.42);padding:11px 14px;border-radius:7px;font-size:12.5px;line-height:1.5">'
              .   '<strong style="color:#d8b4fe;display:block;margin-bottom:3px;font-size:12.5px">⏱ Stale-on-error</strong>'
              .   '<span style="opacity:0.88">Origin down? Cache keeps serving visitors for up to 7 days. Site stays up while you fix things.</span>'
              . '</div>'

              . '<div style="background:rgba(251,191,36,0.14);border:1px solid rgba(251,191,36,0.45);padding:11px 14px;border-radius:7px;font-size:12.5px;line-height:1.5">'
              .   '<strong style="color:#fcd34d;display:block;margin-bottom:3px;font-size:12.5px">⚠ 404 + 5xx dashboards</strong>'
              .   '<span style="opacity:0.88">Live broken-URL clustering with redirect suggestions. Captured origin failures. Block patterns at the edge.</span>'
              . '</div>'

              . '</div>'

              // CTA
              . '<div style="padding:18px 28px;background:rgba(0,0,0,0.32);display:flex;align-items:center;gap:18px;flex-wrap:wrap">'
              . '<a href="https://console.nivoli.com/signup" target="_blank" rel="noopener" '
              . 'style="background:#fbbf24;color:#1e1b4b !important;padding:11px 24px;border-radius:7px;text-decoration:none;font-weight:700;font-size:14.5px;letter-spacing:-0.01em;box-shadow:0 2px 12px rgba(251,191,36,0.40)">Start free →</a>'
              . '<div style="font-size:13px;opacity:0.95;line-height:1.5">'
              .   '<strong style="color:#34d399">Free tier:</strong> 1 domain, ~100k req/mo. '
              .   '<strong style="color:#93c5fd">No credit card.</strong> '
              .   '<strong style="color:#fcd34d">~90 seconds</strong> from signup to first cached page.'
              . '</div>'
              . '</div>'

              . '</div>';
    }

    private function cfgPanel(string $kind, string $title, array $r, array $overrides, bool $active): string
    {
        $cls = 'ect-backend-cfg' . ($active ? ' active' : '');
        $body = '';
        switch ($kind) {
            case 'none':
                // The "headers-only" mode is also the upsell surface — most
                // people see this panel first (it's the default). The pitch
                // leads with FULL-PAGE HTML CACHING (Nivoli's actual
                // product), not tag purge (which is what the addon already
                // does without the upsell).
                //
                // v2.4.11 trim: previous version was hero + 4-pill right
                // column + dual 404/5xx hero cards + CTA — roughly 700px
                // tall, dense enough to overwhelm. Now: hero + 3-pill row +
                // CTA, ~350px. Same dark-gradient design language, half the
                // height. The killer ops features (404 mgmt, 5xx triage)
                // are folded into the third pill rather than getting their
                // own row.
                $body = '<p style="margin:0 0 18px;color:#334155;font-size:14px;line-height:1.6">'
                      . 'Pages keep emitting <code>Surrogate-Key</code> headers — your edge reads them. '
                      . 'The addon just doesn\'t fire purges; whatever wires up your cache handles invalidation. '
                      . 'Good fit for Varnish/VCL setups or "headers first, purges later" rollouts.</p>'

                      . self::renderNivoliPitch();
                break;
            case 'nivoli':
                // Description + URL field. The live stats widget that used
                // to sit at the top of this body got hoisted to render() so
                // it appears OUTSIDE the (now-collapsed-by-default) settings
                // form. See $statsHero in render().
                $body = '<p style="margin:0 0 14px;color:#334155;font-size:13.5px;line-height:1.55">'
                      . 'Managed full-page caching on Cloudflare with a 404 dashboard, attack blackholing, '
                      . 'and tag purge baked in. Paste the dashboard URL from your Nivoli account — every '
                      . 'entry save will POST the affected tags to <code>&lt;url&gt;/purge-tag</code> and the '
                      . 'edge clears just those pages.</p>'
                      . $this->field('nivoli_endpoint', 'Dashboard URL', 'url',
                            'https://console.nivoli.com/cache/&lt;token&gt;', $r, $overrides,
                            'The token in the URL is the auth — treat it like a secret. Don\'t have an account yet? <a href="https://console.nivoli.com/signup" target="_blank" rel="noopener">Sign up free</a> (1 domain, ~100k req/mo on the free tier).');
                break;
            case 'fastly':
                $body = '<p style="margin:0 0 14px;color:#334155;font-size:13.5px;line-height:1.55">'
                      . 'Fastly\'s <code>Surrogate-Key</code> purge — the gold standard for tag-based '
                      . 'cache invalidation. Every entry save POSTs to '
                      . '<code>/service/&lt;id&gt;/purge</code> with the touched keys in the header. '
                      . 'Soft purge by default, so origin gets a chance to revalidate stale content.</p>'
                      . $this->field('fastly_service', 'Service ID', 'text', '', $r, $overrides,
                            'The Fastly service that serves this site.')
                      . $this->field('fastly_api_key', 'API token', 'password', '', $r, $overrides,
                            'Needs the <code>purge_select</code> permission. Generate at <a href="https://manage.fastly.com/account/personal/tokens" target="_blank" rel="noopener">manage.fastly.com</a>.');
                break;
            case 'cloudflare':
                $body = '<div style="background:#fef3c7;color:#78350f;padding:10px 14px;border-radius:6px;margin-bottom:14px;font-size:13px;line-height:1.5">'
                      . '⚠ <strong>Cloudflare Enterprise plan required.</strong> <code>Cache-Tag</code>-based purge isn\'t available on Free, Pro, or Business plans. If you\'re not on Enterprise, look at Nivoli or Fastly above.'
                      . '</div>'
                      . '<p style="margin:0 0 14px;color:#334155;font-size:13.5px;line-height:1.55">'
                      . 'Cloudflare\'s native <code>Cache-Tag</code> purge — fastest path if you\'re already paying for Enterprise. '
                      . 'Entry saves POST to <code>/zones/&lt;id&gt;/purge_cache</code> with all affected tags in one call.</p>'
                      . $this->field('cf_zone_id',   'Zone ID',   'text',     '', $r, $overrides,
                            'Find it on the Cloudflare dashboard\'s zone Overview page, right sidebar.')
                      . $this->field('cf_api_token', 'API token', 'password', '', $r, $overrides,
                            'Scoped to <code>Zone → Cache Purge → Purge</code>. Generate at <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener">dash.cloudflare.com/profile/api-tokens</a>.');
                break;
            case 'webhook':
                $body = '<p style="margin:0 0 14px;color:#334155;font-size:13.5px;line-height:1.55">'
                      . 'Bring your own edge. Every entry save POSTs <code>{"tags":[…]}</code> to '
                      . 'your URL — wire it up to a Varnish purge script, a Squid handler, your own '
                      . 'CDN\'s API, or anything else that speaks HTTP. Fire-and-forget with a 5s '
                      . 'timeout, so a slow endpoint never blocks a CP save.</p>'
                      . $this->field('webhook_url', 'Webhook URL', 'url',
                            'https://your-edge.example.com/purge', $r, $overrides,
                            'Receives an HTTP POST with <code>{"tags":[…]}</code> JSON body.')
                      . $this->field('webhook_secret', 'Bearer secret', 'password', '', $r, $overrides,
                            'Optional. When set, sent as <code>Authorization: Bearer …</code> — use it to authenticate the addon\'s POSTs to your endpoint.');
                break;
        }
        return '<div id="cfg-' . $kind . '" class="' . $cls . '">'
            . '<h3 style="margin:14px 0 8px;font-size:14px;color:#0f172a">' . htmlspecialchars($title) . '</h3>'
            . $body
            . '</div>';
    }

    private function field(string $name, string $label, string $type, string $placeholder, array $r, array $overrides, string $help): string
    {
        $h = fn($v) => htmlspecialchars((string) $v);
        $locked = isset($overrides[$name]);
        $val = $locked ? $overrides[$name] : ($r[$name] ?? '');
        $cls = $locked ? 'locked' : '';
        $attr = $locked ? 'disabled' : '';
        // Mask password-type values regardless — never echo secrets back
        // to the page. For url/text, show the actual pinned value so the
        // admin can confirm it's what they expect.
        $displayVal = $val;
        if ($type === 'password' && $locked && $val !== '') {
            $displayVal = str_repeat('•', min(strlen($val), 24));
        }
        $input = '<input type="' . $h($type) . '" name="' . $h($name) . '" value="' . $h($displayVal) . '" placeholder="' . $placeholder . '" class="' . $cls . '" ' . $attr . '>';
        $lockNote = '';
        if ($locked) {
            // Show the actual pinned value (truncated for long ones) so
            // the admin can spot which MSM site / which config file the
            // value came from. Plus a one-liner on how to override.
            $shown = $type === 'password' ? '••••' : (strlen($val) > 60 ? substr($val, 0, 57) . '…' : $val);
            $lockNote = '<div class="lock-detail">'
                . '<span class="lock-tag">🔒 PINNED VIA CONFIG</span> '
                . '<span class="lock-current">' . $h($shown) . '</span><br>'
                . '<span class="lock-howto">Set in <code>system/user/config/config.php</code> or via <code>$assign_to_config</code> in this site\'s <code>index.php</code>. Edit / remove there to unlock this field. The CP form can\'t override config.</span>'
                . '</div>';
        }
        $helpHtml = $help ? '<div class="help">' . $help . '</div>' : '';
        return '<div class="ect-row" style="border:0;padding:6px 0;margin:0">'
            . '<label class="ect-lbl">' . $h($label) . '</label>'
            . '<div class="ect-field">' . $input . $helpHtml . $lockNote . '</div>'
            . '</div>';
    }

    private function renderDiagBlock(array $diag): string
    {
        $h = fn($v) => htmlspecialchars((string) $v);
        $action = ee('CP/URL')->make('addons/settings/edge_cache_tags/index')->compile();
        $allOk = true;
        $rowsHtml = '';
        foreach ($diag['checks'] as $c) {
            if (!$c['ok']) $allOk = false;
            $icon = $c['ok']
                ? '<span class="ect-diag-tick ok">✓</span>'
                : '<span class="ect-diag-tick bad">!</span>';
            $actionHtml = '';
            if (($c['action'] ?? null) === 'reinstall_hooks') {
                $actionHtml = '<form method="POST" action="' . htmlspecialchars($action, ENT_QUOTES) . '" style="grid-column:2 / span 2;margin:6px 0 2px;padding:0">'
                    . '<input type="hidden" name="ect_action" value="reinstall_hooks">'
                    . '<button type="submit" class="ect-btn" style="background:#b91c1c;padding:7px 14px;font-size:12.5px">Reinstall hooks</button>'
                    . '<span style="margin-left:10px;font-size:12px;color:#475569">Idempotent — safe to click; settings + log are preserved.</span>'
                    . '</form>';
            }
            $rowsHtml .= '<div class="ect-diag-row ' . ($c['ok'] ? 'ok' : 'bad') . '">'
                . $icon
                . '<div class="ect-diag-label">' . $c['label'] . '</div>'
                . '<div class="ect-diag-detail">' . $h($c['detail']) . '</div>'
                . $actionHtml
                . '</div>';
        }
        $summary = $allOk
            ? '<span class="ect-diag-summary ok">All checks passed</span>'
            : '<span class="ect-diag-summary bad">'
              . count(array_filter($diag['checks'], fn($c) => !$c['ok'])) . ' issue(s)</span>';

        $sampleRows = '';
        foreach ($diag['sample_keys'] as $label => $keys) {
            $tags = '';
            foreach ($keys as $k) $tags .= '<span class="tag">' . $h($k) . '</span> ';
            $sampleRows .= '<div><span class="label">' . $h($label) . ':</span> ' . $tags . '</div>';
        }

        return '<div class="ect-card">'
            . '<h2 style="display:inline-block">Diagnostics</h2>' . $summary
            . '<p class="sub" style="margin:6px 0 14px">What the addon would do on this site right now.</p>'
            . $rowsHtml
            . '<h3 style="margin:18px 0 4px;font-size:14px">Sample tags this site would emit</h3>'
            . '<p class="sub" style="margin:0 0 6px">Keys are emitted in the <code>Surrogate-Key</code> header (space-separated). The matching <code>Cache-Tag</code> header (comma-separated) is added <em>only</em> when the backend is set to <code>cloudflare</code> — that\'s the only edge that reads it.</p>'
            . '<div class="ect-sample">' . $sampleRows . '</div>'
            . '</div>';
    }

    /**
     * "Config resolution" probe — what `ee()->config->item()` actually
     * returns for each of the addon's 8 config keys, with the raw value
     * type. Lets an operator see exactly where a CP form lock is coming
     * from when grep across the obvious files comes back empty. Most
     * common surprise sources (in our experience):
     *
     *   - system/user/config/config.<env>.php (env-specific override)
     *   - A custom config-bootstrap file included from config.php
     *   - exp_config rows written by another addon or by EE core
     *   - $assign_to_config in admin.php (not just index.php)
     *
     * Each row links to the corresponding form field so an operator can
     * scroll back to the lock detail box for the exact field.
     */
    /**
     * "New version available" banner — checks the GitHub releases API
     * for a tag newer than the installed addon version. Cached 12h via
     * a file under system/user/cache/. EE doesn't have a native add-on
     * update mechanism (the way WordPress.org does for plugins), so this
     * surfaces the existence of a newer release with a link to the
     * release page; the operator still does the file-drop manually.
     *
     * Quiet by design: only emits markup when an upgrade is genuinely
     * available. No notice on up-to-date installs, no error if the
     * GitHub fetch fails — the panel just doesn't show.
     */
    private function renderVersionCheck(): string
    {
        $latest = $this->fetchLatestRelease();
        if (!$latest || empty($latest['tag'])) return '';
        $installed = $this->installedVersion();
        if (!$installed) return '';
        if (version_compare($latest['tag'], $installed, '<=')) return '';
        $h = fn($v) => htmlspecialchars((string) $v);
        $body = isset($latest['body']) ? trim((string) $latest['body']) : '';
        // Trim release notes to first ~600 chars in the banner — the
        // operator clicks through for the full notes.
        $excerpt = $body !== '' ? (strlen($body) > 600 ? substr($body, 0, 597) . '…' : $body) : '';
        return '<div class="ect-card" style="border-left:4px solid #f59e0b;background:linear-gradient(90deg,#fffbeb,#ffffff 50%)">'
            . '<div style="display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap">'
            . '<div style="flex:1;min-width:280px">'
            . '<h2 style="display:inline-block;margin:0 8px 0 0">⬆ Update available</h2>'
            . '<span style="background:#f59e0b;color:#fff;padding:2px 8px;border-radius:4px;font-size:11.5px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase">v' . $h($latest['tag']) . '</span>'
            . '<p class="sub" style="margin:6px 0 8px">You\'re on v' . $h($installed) . '. Latest GitHub release is v' . $h($latest['tag']) . '.</p>'
            . ($excerpt ? '<details style="font-size:12.5px;color:#475569"><summary style="cursor:pointer;font-weight:600;color:#1e293b;margin-bottom:6px">Release notes</summary><pre style="white-space:pre-wrap;font-family:inherit;background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;margin:6px 0 0;max-height:300px;overflow-y:auto">' . $h($excerpt) . '</pre></details>' : '')
            . '</div>'
            . '<div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;font-size:12px">'
            . '<a href="' . $h($latest['html']) . '" target="_blank" rel="noopener" class="ect-btn" style="background:#f59e0b">View on GitHub →</a>'
            . '<span style="color:#94a3b8">Manual file drop — EE has no auto-install</span>'
            . '</div>'
            . '</div>'
            . '</div>';
    }

    /** Current version from addon.setup.php — single source of truth. */
    private function installedVersion(): string
    {
        $manifest = include SYSPATH . 'user/addons/edge_cache_tags/addon.setup.php';
        return is_array($manifest) ? (string) ($manifest['version'] ?? '') : '';
    }

    /**
     * Fetch the latest GitHub release. Cached 12h via a flat file under
     * system/user/cache/. Returns null on failure (no banner shown on
     * network errors — quiet by design).
     */
    private function fetchLatestRelease(): ?array
    {
        $repo = 'calimonk/ee-edge-cache-tags';
        $cacheDir = SYSPATH . 'user/cache/edge_cache_tags';
        $cacheFile = $cacheDir . '/latest_release.json';
        $ttl = 12 * 3600; // 12 hours

        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            $cached = @file_get_contents($cacheFile);
            $decoded = $cached !== false ? json_decode($cached, true) : null;
            if (is_array($decoded)) {
                return isset($decoded['_empty']) ? null : $decoded;
            }
        }

        $url = 'https://api.github.com/repos/' . $repo . '/releases/latest';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_USERAGENT      => 'edge-cache-tags-ee-cp/' . $this->installedVersion(),
            CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github.v3+json'],
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = null;
        if ($code === 200 && is_string($resp) && $resp !== '') {
            $decoded = json_decode($resp, true);
            if (is_array($decoded) && !empty($decoded['tag_name'])) {
                $data = [
                    'tag'       => ltrim((string) $decoded['tag_name'], 'v'),
                    'name'      => $decoded['name']         ?? $decoded['tag_name'],
                    'body'      => $decoded['body']         ?? '',
                    'html'      => $decoded['html_url']     ?? '',
                    'published' => $decoded['published_at'] ?? '',
                ];
            }
        }

        // Best-effort write to the cache directory. Sentinel value when
        // the fetch fails so we don't hammer GitHub on every CP load.
        if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0775, true); }
        @file_put_contents($cacheFile, json_encode($data ?: ['_empty' => time()]));

        return $data;
    }

    private function renderConfigProbe(): string
    {
        $h = fn($v) => htmlspecialchars((string) $v);
        $rows = '';
        $anySet = false;
        foreach (self::CONFIG_KEYS as $local => $configKey) {
            $raw = ee()->config->item($configKey);
            $type = gettype($raw);
            $isSet = $raw !== null && $raw !== false && trim((string) $raw) !== '';
            if ($isSet) $anySet = true;
            $valueCell = $isSet
                ? '<code style="background:#fef3c7;color:#78350f;padding:2px 6px;border-radius:3px">' . $h(var_export($raw, true)) . '</code>'
                : '<span style="color:#94a3b8">(not set)</span>';
            // Hide the PHP-level type for unset rows — EE returns `false`
            // (boolean) as its missing-key sentinel, which surfaced as a
            // misleading "boolean" tag on every row. Show the type only
            // when we actually have a stored value.
            $typeCell = $isSet
                ? '<code style="background:#f1f5f9;color:#475569;padding:2px 6px;border-radius:3px;font-size:11.5px">' . $h($type) . '</code>'
                : '<span style="color:#cbd5e1">—</span>';
            $rows .= '<tr style="border-top:1px solid #f1f5f9">'
                . '<td style="padding:8px 12px;font-family:ui-monospace,Menlo,monospace;font-size:12.5px;color:' . ($isSet ? '#78350f' : '#1e293b') . '">' . $h($configKey) . '</td>'
                . '<td style="padding:8px 12px">' . $valueCell . '</td>'
                . '<td style="padding:8px 12px">' . $typeCell . '</td>'
                . '</tr>';
        }
        $banner = $anySet
            ? '<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:6px;padding:10px 14px;margin:0 0 12px;font-size:13px;color:#78350f"><strong>One or more keys are pinned via EE\'s config registry.</strong> If grep on <code>config.php</code> / <code>index.php</code> / <code>admin.php</code> comes back empty, check: <code>system/user/config/config.&lt;env&gt;.php</code>, any custom config-loader included from <code>config.php</code>, and the <code>exp_config</code> DB table (<code>SELECT site_id, key, value FROM exp_config WHERE key LIKE \'edge_cache_tags_%\'</code>).</div>'
            : '<div style="background:#d1fae5;border:1px solid #a7f3d0;border-radius:6px;padding:10px 14px;margin:0 0 12px;font-size:13px;color:#065f46"><strong>No config-level pins detected.</strong> Form fields are fully editable; values come from the database row for this MSM site.</div>';

        return '<div class="ect-card">'
            . '<h2>Config resolution</h2>'
            . '<p class="sub">Exact values <code>ee()->config->item()</code> returns for the addon\'s 8 config keys, as seen by this CP request. If a row shows a value, the corresponding form field on the Setup tab will be locked.</p>'
            . $banner
            . '<table style="width:100%;border-collapse:collapse;font-size:13px;border:1px solid #e2e8f0;border-radius:6px;overflow:hidden">'
            . '<thead><tr style="background:#f8fafc;text-align:left;color:#1e293b;font-weight:600"><th style="padding:9px 12px;font-size:12px;letter-spacing:0.03em;text-transform:uppercase">Config key</th><th style="padding:9px 12px;font-size:12px;letter-spacing:0.03em;text-transform:uppercase">Value returned</th><th style="padding:9px 12px;font-size:12px;letter-spacing:0.03em;text-transform:uppercase">Type</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>'
            . '<p class="sub" style="margin-top:10px;font-size:12px">SQL probe (run in the EE DB) — surfaces hidden pins from <code>exp_config</code> if grep is empty:</p>'
            . '<pre style="background:#0f172a;color:#e2e8f0;padding:10px 14px;border-radius:6px;font-size:12px;font-family:ui-monospace,Menlo,monospace;line-height:1.6;overflow-x:auto;white-space:pre-wrap">SELECT site_id, `key`, value FROM exp_config WHERE `key` LIKE \'edge_cache_tags_%\';</pre>'
            . '</div>';
    }

    /**
     * Recent purge activity from exp_edge_cache_tags_purge_log. One row
     * per dispatched purge with status icon, backend, tag count,
     * relative timestamp, and optional click-through for failed calls.
     * Hidden gracefully if the table doesn't exist yet (pre-v2.2 install
     * that hasn't run update() yet).
     */
    private function renderActivityBlock(int $siteId): string
    {
        if (!ee()->db->table_exists('edge_cache_tags_purge_log')) {
            return '';
        }
        $rows = ee()->db->where('site_id', $siteId)
            ->order_by('created_at', 'desc')
            ->limit(50)
            ->get('edge_cache_tags_purge_log')
            ->result_array();

        $h = fn($v) => htmlspecialchars((string) $v);
        $relTime = function (int $ts): string {
            $delta = time() - $ts;
            if ($delta < 60)    return $delta . 's ago';
            if ($delta < 3600)  return floor($delta / 60) . 'm ago';
            if ($delta < 86400) return floor($delta / 3600) . 'h ago';
            return floor($delta / 86400) . 'd ago';
        };

        if (empty($rows)) {
            return '<div class="ect-card">'
                . '<h2 style="margin:0 0 8px">Recent activity</h2>'
                . '<p style="margin:0;color:#64748b;font-size:13px">No purges dispatched yet. '
                . 'Edit and save a channel entry to fire one — it\'ll appear here on the next page load.</p>'
                . '</div>';
        }

        $rowsHtml = '';
        foreach ($rows as $r) {
            $status = (int) $r['http_status'];
            $err = (string) ($r['error_msg'] ?? '');
            $isOk   = ($status >= 200 && $status < 300);
            $isWarn = (!$isOk && $status > 0); // 4xx/5xx — got a response, but bad
            $isErr  = ($status === 0 || $err !== ''); // network error / no response
            if ($isErr) {
                $icon  = '<span style="color:#ef4444;font-weight:700">!</span>';
                $iconBg = '#fee2e2';
                $statusLabel = $err !== ''
                    ? '<span style="color:#b91c1c">' . $h($err) . '</span>'
                    : '<span style="color:#b91c1c">no response</span>';
            } elseif ($isWarn) {
                $icon  = '<span style="color:#b45309;font-weight:700">⚠</span>';
                $iconBg = '#fef3c7';
                $statusLabel = '<span style="color:#b45309">HTTP ' . $status . '</span>';
            } else {
                $icon  = '<span style="color:#15803d;font-weight:700">✓</span>';
                $iconBg = '#d1fae5';
                $statusLabel = '<span style="color:#15803d">HTTP ' . $status . '</span>';
            }

            $tagsArr = json_decode((string) $r['tags'], true);
            if (!is_array($tagsArr)) $tagsArr = array();
            $tagPreview = '';
            $shown = array_slice($tagsArr, 0, 4);
            foreach ($shown as $t) {
                $tagPreview .= '<code style="background:#f1f5f9;padding:1px 6px;border-radius:3px;font-size:11.5px;color:#334155;margin-right:3px">' . $h($t) . '</code>';
            }
            if (count($tagsArr) > 4) {
                $tagPreview .= '<span style="color:#94a3b8;font-size:11.5px">+' . (count($tagsArr) - 4) . ' more</span>';
            }

            $hostFromUrl = parse_url((string) $r['target_url'], PHP_URL_HOST) ?: '(unknown)';
            $backend = $h(strtoupper((string) $r['backend']));
            $backendChip = '<span style="display:inline-block;padding:2px 7px;background:#e0e7ff;color:#4338ca;font-size:11px;font-weight:600;border-radius:3px;letter-spacing:0.04em">' . $backend . '</span>';

            $detail = '';
            if ($isErr || $isWarn) {
                $excerpt = (string) ($r['response_excerpt'] ?? '');
                if ($excerpt !== '') {
                    $detail = '<div style="grid-column:2 / -1;margin-top:6px;padding:8px 10px;background:#0f172a;color:#fca5a5;border-radius:5px;font-family:ui-monospace,Menlo,monospace;font-size:11.5px;line-height:1.5;white-space:pre-wrap;word-break:break-word;max-height:120px;overflow:auto">' . $h(substr($excerpt, 0, 500)) . '</div>';
                }
            }

            $rowsHtml .= '<div style="display:grid;grid-template-columns:32px 1fr auto;gap:10px;padding:10px 0;border-bottom:1px solid #f1f5f9;align-items:center">'
                . '<div style="width:28px;height:28px;border-radius:50%;background:' . $iconBg . ';display:flex;align-items:center;justify-content:center">' . $icon . '</div>'
                . '<div style="font-size:13px;line-height:1.5">'
                .   '<div style="margin-bottom:3px">' . $backendChip
                .       ' <span style="color:#475569">' . (int) $r['tag_count'] . ' tag' . ($r['tag_count'] == 1 ? '' : 's') . '</span>'
                .       ' <span style="color:#94a3b8;margin:0 6px">·</span>'
                .       ' ' . $statusLabel
                .       ' <span style="color:#94a3b8;margin:0 6px">·</span>'
                .       ' <span style="color:#64748b;font-size:12px">' . (int) $r['duration_ms'] . 'ms</span>'
                .   '</div>'
                .   '<div>' . $tagPreview . '</div>'
                . '</div>'
                . '<div style="text-align:right;font-size:12px;color:#94a3b8;white-space:nowrap" title="' . $h(date('Y-m-d H:i:s', (int) $r['created_at'])) . '">'
                .   $h($relTime((int) $r['created_at']))
                .   '<div style="font-size:11px;margin-top:2px">' . $h($hostFromUrl) . '</div>'
                . '</div>'
                . $detail
                . '</div>';
        }

        $total = count($rows);
        return '<div class="ect-card">'
            . '<div style="display:flex;align-items:baseline;gap:12px;margin-bottom:6px">'
            .   '<h2 style="margin:0">Recent activity</h2>'
            .   '<span style="font-size:12px;color:#64748b">last ' . $total . ' purge' . ($total == 1 ? '' : 's') . ' for this site</span>'
            . '</div>'
            . '<p style="margin:0 0 14px;color:#64748b;font-size:13px">Every purge dispatched by this addon. Use it to confirm saves are firing, debug a misconfigured backend, or see what your edge is being told to evict.</p>'
            . $rowsHtml
            . '</div>';
    }

    /**
     * Quick actions card — manual tag purge + (when backend=nivoli)
     * an "Open dashboard" link to the live traffic view.
     *
     * Tag purge form posts back to /index with ect_action=purge_tags.
     * The route handler picks that up, calls
     * Edge_cache_tags_ext::manual_purge_tags(), and the result lands
     * in the Recent activity log below.
     */
    private function renderToolsBlock(array $eff, string $action, int $siteId): string
    {
        $h = fn($v) => htmlspecialchars((string) $v);
        $backend = $eff['backend'] ?: 'none';

        // Dashboard link is only meaningful for Nivoli (it's the only
        // backend with a customer-facing dashboard at the URL itself).
        $dashboardBlock = '';
        if ($backend === 'nivoli' && !empty($eff['nivoli_endpoint'])) {
            $dashboardBlock = '<div style="background:#f0f9ff;border:1px solid #bfdbfe;border-radius:7px;padding:14px 16px;margin-bottom:14px">'
                . '<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">'
                . '<div style="flex:1 1 240px">'
                .   '<strong style="display:block;color:#0c4a6e;margin-bottom:2px">Live traffic dashboard</strong>'
                .   '<span style="color:#0369a1;font-size:13px">Hit rate, cache savings, request timeline, 404 / 5xx triage, URL rules — all on your Nivoli dashboard.</span>'
                . '</div>'
                . '<a href="' . $h($eff['nivoli_endpoint']) . '" target="_blank" rel="noopener" '
                . 'style="background:#0284c7;color:white !important;padding:8px 18px;border-radius:5px;text-decoration:none;font-weight:600;font-size:13px;white-space:nowrap">Open dashboard →</a>'
                . '</div></div>';
        }

        // Tag purge form — works for every backend that has credentials
        // configured. When backend=none, render disabled with an
        // explanatory hint.
        $disabled = ($backend === 'none');
        // v2.4.16: dropped `all` from the casual suggestion list. It
        // looks innocuous next to `home` and `channel-news` but actually
        // nukes the entire network's cache (every MSM site for an
        // MSM install). Operators who want "wipe everything" should use
        // the explicit danger button below, not type `all` into the
        // free-form field.
        $hint = $disabled
            ? '<span style="color:#b45309;font-weight:500">Pick a backend above before this form can dispatch.</span>'
            : 'Space- or comma-separated. Common: <code>home</code>, <code>channel-news</code>, <code>entry-123</code>, <code>category-9</code>, <code>path-news</code>. For "purge everything" use the danger buttons below.';
        $btnAttr = $disabled ? 'disabled' : '';
        $btnStyle = $disabled
            ? 'background:#cbd5e1;color:#64748b !important;padding:9px 22px;border-radius:6px;border:0;font-weight:600;font-size:13px;cursor:not-allowed'
            : 'background:#1d4ed8;color:white !important;padding:9px 22px;border-radius:6px;border:0;font-weight:600;font-size:13px;cursor:pointer';

        $purgeForm = '<form method="POST" action="' . $h($action) . '" style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;margin-bottom:6px">'
            . '<input type="hidden" name="ect_action" value="purge_tags">'
            . '<input type="text" name="purge_tags_input" placeholder="home channel-news entry-42" '
            . 'style="flex:1 1 280px;padding:9px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;font-family:ui-monospace,Menlo,monospace" ' . $btnAttr . '>'
            . '<button type="submit" style="' . $btnStyle . '" ' . $btnAttr . '>Purge tags</button>'
            . '</form>'
            . '<div style="font-size:12.5px;color:#64748b;line-height:1.5">' . $hint . '</div>';

        // ---- Danger zone: site-wide and network-wide nukes -------------
        //
        // Confirmation prompts use onsubmit so a misclick on the button
        // doesn't immediately fire the purge. Pages will rebuild on
        // next request — no data loss — but cache hit rate temporarily
        // tanks. Worth a moment of friction.
        //
        // MSM topology:
        //   - site_id  == 1 → emits unprefixed tags. "all" is the only
        //                     scope available, which IS the whole site
        //                     (and on an MSM install also the master).
        //                     We render just ONE button labeled
        //                     accordingly.
        //   - site_id   > 1 → emits `site-<id>-` prefixed tags AND an
        //                     unprefixed `all` (network nuke from sub-
        //                     sites). Two buttons: per-site + network.
        $dangerZone = '';
        if (!$disabled) {
            if ($siteId > 1) {
                $thisSiteTag = 'site-' . $siteId . '-all';
                $thisSiteLabel = 'Purge this site\'s cache (site #' . $siteId . ')';
                $thisSiteConfirm = 'Purge EVERY cached page for this site (site #' . $siteId . ')? '
                    . 'Other MSM sites are not affected. Pages will rebuild on next request — '
                    . 'cache hit rate temporarily drops to 0% for this site. Continue?';

                $networkConfirm = 'Purge EVERY cached page across ALL MSM sites in the network? '
                    . 'This wipes the cache for every site that shares this EE install. '
                    . 'Every site\'s hit rate drops to 0% until the cache rebuilds. Are you sure?';

                $dangerZone = '<div style="margin-top:18px;padding:14px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:7px">'
                    . '<h3 style="margin:0 0 4px;font-size:14px;color:#991b1b">⚠ Danger zone</h3>'
                    . '<p style="margin:0 0 12px;font-size:12.5px;color:#7f1d1d;line-height:1.5">'
                    .   'Site-wide and network-wide nukes. Use sparingly — they wipe everything and force the edge to rebuild the cache from origin.'
                    . '</p>'
                    . '<div style="display:flex;gap:10px;flex-wrap:wrap">'
                    .   $this->dangerButton($action, $thisSiteTag, $thisSiteLabel, $thisSiteConfirm, '#b45309')
                    .   $this->dangerButton($action, 'all', 'Purge ALL sites (network)', $networkConfirm, '#991b1b')
                    . '</div>'
                    . '</div>';
            } else {
                // site_id == 1 — single-site install OR the MSM master.
                // In both cases the only nuke tag is unprefixed `all`,
                // which IS the whole site (or the whole network if MSM
                // is active and the master also serves traffic).
                $confirm = 'Purge EVERY cached page? Pages will rebuild on next request — '
                    . 'cache hit rate temporarily drops to 0% until the cache refills. Continue?';
                $dangerZone = '<div style="margin-top:18px;padding:14px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:7px">'
                    . '<h3 style="margin:0 0 4px;font-size:14px;color:#991b1b">⚠ Danger zone</h3>'
                    . '<p style="margin:0 0 12px;font-size:12.5px;color:#7f1d1d;line-height:1.5">'
                    .   'Site-wide nuke. Use sparingly — wipes everything and forces the edge to rebuild the cache from origin.'
                    . '</p>'
                    . '<div>'
                    .   $this->dangerButton($action, 'all', 'Purge entire cache', $confirm, '#991b1b')
                    . '</div>'
                    . '</div>';
            }
        }

        return '<div class="ect-card">'
            . '<h2 style="margin:0 0 4px">Quick actions</h2>'
            . '<p class="sub" style="margin:0 0 16px;color:#64748b">Manual purges when you change something outside an entry save (template edit, asset update, fix to a hand-rolled URL).</p>'
            . $dashboardBlock
            . '<h3 style="margin:0 0 8px;font-size:14px;color:#1e293b">Purge tags manually</h3>'
            . $purgeForm
            . $dangerZone
            . '</div>';
    }

    /**
     * Build a single danger-button form. Each is its own <form> so the
     * tag is baked in at render time (no JS-set values that could be
     * tampered with). Confirmation via onsubmit so a misclick doesn't
     * immediately nuke.
     */
    private function dangerButton(string $action, string $tag, string $label, string $confirm, string $color): string
    {
        $h = fn($v) => htmlspecialchars((string) $v);
        // Single-quote the JS confirm string + escape single quotes
        // and newlines safely. ENT_QUOTES on the htmlspecialchars
        // would double-escape; use addslashes + htmlspecialchars in
        // sequence so the rendered attribute is clean.
        $js = addslashes($confirm);
        return '<form method="POST" action="' . $h($action) . '" style="margin:0" '
            . 'onsubmit="return confirm(\'' . $h($js) . '\');">'
            . '<input type="hidden" name="ect_action" value="purge_tags">'
            . '<input type="hidden" name="purge_tags_input" value="' . $h($tag) . '">'
            . '<button type="submit" style="background:' . $color . ';color:white;padding:9px 18px;border-radius:6px;border:0;font-weight:600;font-size:12.5px;cursor:pointer;letter-spacing:-0.01em">'
            . $h($label) . ' <span style="opacity:0.75;font-weight:500;font-family:ui-monospace,Menlo,monospace">(' . $h($tag) . ')</span>'
            . '</button>'
            . '</form>';
    }

    /**
     * Fetch live stats from a Nivoli endpoint. Returns null on any
     * failure — caller decides whether to fall back to a placeholder.
     * One curl per CP page load; we memoize within the request via
     * static cache. Short timeout so a slow Nivoli doesn't hold up CP
     * rendering.
     */
    private static $stats_cache = null;
    private static $stats_cache_key = null;
    private function fetchNivoliStats(string $endpoint): ?array
    {
        if (!$endpoint) return null;
        // v2.4.17: cache key now includes the current MSM site's host so
        // a single linked Nivoli token (one dashboard URL, multiple
        // hostnames) returns the right per-site stats. Without this the
        // CP for rpggamers shows platformgamers's stats (or vice versa)
        // because the per-request stats_cache static was keyed only on
        // the endpoint, and the endpoint is identical across linked
        // sites.
        $hostQs = $this->nivoliHostQs();
        $cacheKey = $endpoint . '|' . $hostQs;
        if (self::$stats_cache_key === $cacheKey && self::$stats_cache !== null) {
            return self::$stats_cache ?: null;
        }
        // ?host=<current-site-host> tells Nivoli which hostname to
        // resolve stats for when the token grants access to multiple.
        // Single-host tokens just ignore the param.
        $url = rtrim($endpoint, '/') . '/stats?hours=720' . ($hostQs ? '&host=' . $hostQs : '');
        $ch = curl_init($url);
        if (!$ch) return null;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FAILONERROR    => false,
            CURLOPT_USERAGENT      => 'edge-cache-tags-ee-cp/2.3.1',
        ]);
        $body = @curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = null;
        if ($status === 200 && is_string($body)) {
            $parsed = json_decode($body, true);
            if (is_array($parsed)) $data = $parsed;
        }
        self::$stats_cache = $data ?: [];
        self::$stats_cache_key = $cacheKey;
        return $data;
    }

    /**
     * Return `<host>` (raw, not URL-encoded) for the current MSM site,
     * derived from EE's site_url. Empty string if undetermined. Caller
     * decides whether to wrap in `?host=<...>` or `&host=<...>`.
     *
     * Mirrors purge_nivoli's nivoli_host_qs() in the extension class;
     * keeping them in sync (rather than calling across class
     * boundaries) avoids a require_once at CP-render time.
     */
    private function nivoliHostQs(): string
    {
        $site_url = (string) ee()->config->item('site_url');
        if ($site_url === '') return '';
        $host = parse_url($site_url, PHP_URL_HOST);
        if (!$host) return '';
        return rawurlencode(strtolower($host));
    }

    /**
     * Return the current MSM site's host (unencoded, lowercased).
     * Empty string when undetermined.
     */
    private function currentSiteHost(): string
    {
        $site_url = (string) ee()->config->item('site_url');
        if ($site_url === '') return '';
        $host = parse_url($site_url, PHP_URL_HOST);
        return $host ? strtolower($host) : '';
    }

    /**
     * Fetch the list of hostnames the dashboard token grants access to.
     * Returns array<string> or null on any failure. Cached per-request
     * via a static (same lifetime as the stats cache).
     *
     * Nivoli exposes /cache/<token>/hostnames as a no-auth-required
     * endpoint that just returns the token's allowedHosts set. Used
     * here to detect "wrong dashboard URL pasted" misconfig — the
     * exact mistake the operator just hit: pasted a token whose scope
     * didn't include the current EE site's host.
     */
    private static $hostnames_cache = null;
    private static $hostnames_cache_key = null;
    private function fetchNivoliHostnames(string $endpoint): ?array
    {
        if (!$endpoint) return null;
        if (self::$hostnames_cache_key === $endpoint && self::$hostnames_cache !== null) {
            return self::$hostnames_cache ?: null;
        }
        $url = rtrim($endpoint, '/') . '/hostnames';
        $ch = curl_init($url);
        if (!$ch) return null;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FAILONERROR    => false,
            CURLOPT_USERAGENT      => 'edge-cache-tags-ee-cp/' . $this->installedVersion(),
        ]);
        $body = @curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $hosts = null;
        if ($status === 200 && is_string($body)) {
            $parsed = json_decode($body, true);
            if (is_array($parsed) && isset($parsed['hostnames']) && is_array($parsed['hostnames'])) {
                $hosts = array_values(array_filter(array_map(
                    fn($h) => strtolower((string) $h),
                    $parsed['hostnames']
                )));
            }
        }
        self::$hostnames_cache = $hosts ?: [];
        self::$hostnames_cache_key = $endpoint;
        return $hosts;
    }

    /**
     * Render a small banner showing which hostnames the configured
     * Nivoli dashboard URL is scoped to, plus a ✓ / ⚠ check that the
     * current EE site is in that scope.
     *
     * Three render states:
     *   - Fetch failed (network blip, bad token): empty string, no banner
     *   - Current site IS in scope: small green confirmation
     *   - Current site is NOT in scope: prominent amber warning with
     *     remediation steps
     */
    private function renderHostScopeBanner(string $endpoint): string
    {
        $hosts = $this->fetchNivoliHostnames($endpoint);
        if (!$hosts) return ''; // unreachable / bad URL — fail quiet, stats hero will also be empty
        $h = fn($v) => htmlspecialchars((string) $v);
        $currentHost = $this->currentSiteHost();
        $inScope = $currentHost !== '' && in_array($currentHost, $hosts, true);

        $listHtml = '';
        foreach ($hosts as $hn) {
            $isCurrent = ($hn === $currentHost);
            $listHtml .= '<code style="background:' . ($isCurrent ? '#dcfce7' : '#f1f5f9')
                . ';color:' . ($isCurrent ? '#166534' : '#475569')
                . ';padding:2px 8px;border-radius:4px;font-size:12.5px;margin-right:6px;font-weight:' . ($isCurrent ? '600' : '400') . '">'
                . $h($hn) . ($isCurrent ? ' (this site)' : '') . '</code>';
        }

        if ($inScope) {
            // Quiet confirmation — small, green, doesn't dominate.
            return '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:7px;padding:10px 14px;margin-bottom:14px;font-size:13px;line-height:1.55">'
                . '<span style="color:#166534;font-weight:600">✓ Dashboard URL scope ·</span> '
                . '<span style="color:#15803d;margin-right:8px">' . count($hosts) . ' hostname' . (count($hosts) === 1 ? '' : 's') . ' linked:</span>'
                . $listHtml
                . '</div>';
        }

        // Mismatch — loud warning + explain remedy.
        $currentLabel = $currentHost !== '' ? $currentHost : '(unknown — site_url not set)';
        return '<div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:7px;padding:14px 18px;margin-bottom:14px;font-size:13.5px;line-height:1.6">'
            . '<div style="font-weight:700;color:#92400e;margin-bottom:6px">⚠ Dashboard URL host-scope mismatch</div>'
            . '<p style="margin:0 0 8px;color:#78350f">'
            . 'This token grants access to '
            . '<strong>' . count($hosts) . ' hostname' . (count($hosts) === 1 ? '' : 's') . '</strong>'
            . ' — but this site (<strong>' . $h($currentLabel) . '</strong>) isn\'t one of them.'
            . '</p>'
            . '<p style="margin:0 0 8px;color:#78350f">Linked: ' . $listHtml . '</p>'
            . '<p style="margin:0;color:#78350f;font-size:12.5px">'
            . 'Saves on this site will fire purges that Nivoli rejects with 403 (host not in scope). Stats hero above will stay empty. Two fixes:<br>'
            . '<strong>(a)</strong> Paste this site\'s OWN dashboard URL into the Setup form below (each tenant gets its own URL), OR<br>'
            . '<strong>(b)</strong> Link this site to the existing token via the Nivoli admin → Hosts → Link to token.'
            . '</p>'
            . '</div>';
    }

    /**
     * Build a small "your stats" widget from the parsed /stats payload.
     * Returns empty string if data isn't available or doesn't have the
     * keys we need.
     *
     * v2.4.5: remapped to the real Nivoli /stats response shape. The
     * previous version read `savings.cache_served_bytes` which doesn't
     * exist in the API (bytes-served is computed dashboard-side and not
     * exposed). Now reads only fields the API actually returns:
     *
     *   summary.totalRequests   — int request count for the window
     *   summary.hitRate         — float 0..1
     *   summary.errorRate       — float 0..1
     *   summary.bypassRate      — float 0..1
     *   summary.avgDurationMs   — float, mean perceived response time
     *
     * Four tiles, all derived from `summary` so nothing renders if the
     * API returns the shape we don't recognize. The widget grid is
     * `auto-fit minmax(160px, 1fr)` so it relaxes to 2 / 1 columns on
     * narrower viewports.
     */
    private function renderNivoliStatsWidget(array $stats): string
    {
        $summary = $stats['summary'] ?? [];
        $totalReqs    = (int)   ($summary['totalRequests']  ?? 0);
        $hitRate      = (float) ($summary['hitRate']        ?? 0);
        $errorRate    = (float) ($summary['errorRate']      ?? 0);
        $avgDurMs     = (float) ($summary['avgDurationMs']  ?? 0);
        if ($totalReqs <= 0) return '';

        $fmtNum = function ($n): string {
            $n = (int) $n;
            if ($n >= 1_000_000) return number_format($n / 1_000_000, 1) . 'M';
            if ($n >= 1_000)     return number_format($n / 1_000, 1) . 'k';
            return (string) $n;
        };
        $fmtDuration = function (float $ms): string {
            if ($ms <= 0) return '—';
            if ($ms < 1) return number_format($ms, 2) . ' ms';
            if ($ms < 1000) return (int) round($ms) . ' ms';
            return number_format($ms / 1000, 2) . ' s';
        };

        $hitPct = round($hitRate * 100);
        $errPct = $errorRate > 0 ? number_format($errorRate * 100, 2) . '%' : '0%';
        $errCount = (int) round($totalReqs * $errorRate);

        // Cell builder so all four tiles stay visually consistent.
        $cell = function (string $label, string $value, string $sub = '') {
            $subHtml = $sub !== ''
                ? '<div style="font-size:11px;opacity:0.65;margin-top:2px">' . htmlspecialchars($sub) . '</div>'
                : '';
            return '<div><div style="font-size:11px;opacity:0.78;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:2px">' . htmlspecialchars($label) . '</div>'
                . '<div style="font-size:24px;font-weight:700;line-height:1.1">' . $value . '</div>'
                . $subHtml
                . '</div>';
        };

        return '<div style="background:linear-gradient(135deg,#065f46 0%,#0e7490 100%);color:white;border-radius:8px;padding:16px 20px;margin-bottom:16px;box-shadow:0 3px 12px rgba(6,95,70,0.18)">'
            . '<div style="font-size:11px;font-weight:700;letter-spacing:0.10em;text-transform:uppercase;color:#6ee7b7;margin-bottom:8px">📊 Your cache performance · last 30 days</div>'
            . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px">'
            . $cell('Hit rate', $hitPct . '%')
            . $cell('Requests served', $fmtNum($totalReqs))
            . $cell('Avg response', $fmtDuration($avgDurMs))
            . $cell('Error rate', $errPct, $errCount > 0 ? $fmtNum($errCount) . ' errors' : '')
            . '</div></div>';
    }

    private function renderDocsBlock(): string
    {
        // EE's CP view layer flattens whitespace inside <pre> blocks (or the
        // HTML gets re-templated somewhere up-stack), so each code line goes
        // in its own styled <div> instead of relying on \n inside <pre>.
        // Defensive, but guarantees layout regardless of EE's pipeline.
        $code = function ($lines) {
            $rows = '';
            foreach ((array) $lines as $ln) {
                $rows .= '<div class="ect-docs-codeline">' . $ln . '</div>';
            }
            return '<div class="ect-docs-code">' . $rows . '</div>';
        };

        // v2.4.20 — supplemental CSS for the Documentation tab: section
        // dividers, callouts (info/warn/tip), cleaner code-line spans. Lives
        // here so the doc tab is self-contained and easy to restyle.
        $supplementalCss = '<style>
.ect-docs-toc { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 18px; margin:0 0 24px; max-width:760px; }
.ect-docs-toc-title { display:block; font-size:11.5px; font-weight:600; letter-spacing:0.1em; text-transform:uppercase; color:#64748b; margin-bottom:8px; }
.ect-docs-toc ol { margin:0; padding-left:20px; columns:2; column-gap:28px; }
.ect-docs-toc li { margin:3px 0; break-inside:avoid; }
.ect-docs-toc a { color:#1d4ed8; text-decoration:none; font-size:13px; }
.ect-docs-toc a:hover { text-decoration:underline; }
.ect-docs-section { padding-top:8px; margin-top:36px; border-top:1px solid #e2e8f0; }
.ect-docs-section:first-of-type { margin-top:0; border-top:0; padding-top:0; }
.ect-docs-section h3 { font-size:18px; margin:0 0 14px; padding-bottom:10px; border-bottom:1px solid #e2e8f0; color:#0f172a; line-height:1.3; scroll-margin-top:80px; }
.ect-docs-section h3 .num { color:#1d4ed8; font-variant-numeric:tabular-nums; margin-right:10px; font-weight:700; font-size:0.85em; }
.ect-docs-section h4 { font-size:14px; margin:20px 0 6px; color:#1e293b; font-weight:600; }
.ect-docs-code { background:#0f172a; color:#e2e8f0; padding:14px 16px; border-radius:7px; font-size:12.5px; line-height:1.75; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; overflow-x:auto; white-space:pre-wrap; word-break:break-word; margin:0 0 14px; max-width:760px; }
.ect-docs-codeline { padding:1px 0; }
.ect-docs .ect-callout { border-radius:7px; padding:12px 16px; margin:0 0 16px; font-size:13px; line-height:1.65; border:1px solid; max-width:760px; }
.ect-docs .ect-callout p:last-child { margin-bottom:0; }
.ect-docs .ect-callout p { margin:0 0 8px; color:inherit; }
.ect-docs .ect-callout-tip { background:#ecfdf5; border-color:#a7f3d0; color:#065f46; }
.ect-docs .ect-callout-tip strong { color:#047857; }
.ect-docs .ect-callout-info { background:#eff6ff; border-color:#bfdbfe; color:#1e3a8a; }
.ect-docs .ect-callout-info strong { color:#1d4ed8; }
.ect-docs .ect-callout-warn { background:#fffbeb; border-color:#fde68a; color:#78350f; }
.ect-docs .ect-callout-warn strong { color:#b45309; }
.ect-docs .ect-callout .ect-label { display:inline-block; font-size:10.5px; font-weight:700; letter-spacing:0.1em; text-transform:uppercase; margin-right:8px; padding:1px 7px; border-radius:4px; }
.ect-docs .ect-callout-tip  .ect-label { background:#bbf7d0; color:#065f46; }
.ect-docs .ect-callout-info .ect-label { background:#bfdbfe; color:#1d4ed8; }
.ect-docs .ect-callout-warn .ect-label { background:#fde68a; color:#78350f; }
.ect-docs table.ect-table { border-collapse:collapse; width:100%; max-width:760px; margin:0 0 16px; font-size:13px; background:white; border:1px solid #e2e8f0; border-radius:6px; overflow:hidden; }
.ect-docs table.ect-table thead { background:#f1f5f9; }
.ect-docs table.ect-table th, .ect-docs table.ect-table td { padding:8px 12px; border-bottom:1px solid #e2e8f0; text-align:left; vertical-align:top; color:#0f172a; }
.ect-docs table.ect-table th { font-size:11.5px; text-transform:uppercase; letter-spacing:0.06em; color:#64748b; font-weight:600; }
.ect-docs table.ect-table tr:last-child td { border-bottom:0; }
.ect-docs .ect-companion { display:flex; gap:14px; align-items:flex-start; padding:14px 16px; background:#fef3c7; border:1px solid #fde68a; border-radius:8px; max-width:760px; }
.ect-docs .ect-companion-icon { flex:0 0 36px; height:36px; background:#fbbf24; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:18px; font-weight:700; color:#78350f; }
.ect-docs .ect-companion-body { flex:1; color:#78350f; font-size:13px; line-height:1.6; }
.ect-docs .ect-companion-body strong { color:#78350f; }
.ect-docs .ect-companion-body a { color:#b45309; font-weight:600; }
</style>';

        // Examples use a generic <code>articles</code> channel so the docs read
        // the same whether the site happens to be news, games, recipes,
        // events, products, or anything else. Replace mentally with your
        // own channel short_name.
        $emittedExample = $code([
            '<span style="color:#94a3b8">Surrogate-Key:</span> <span style="color:#7dd3fc">path-articles</span> <span style="color:#fcd34d">entry-123</span> <span style="color:#fcd34d">channel-articles</span> <span style="color:#fcd34d">category-9</span> <span style="color:#86efac">all</span>',
            '<span style="color:#94a3b8">Cache-Tag:</span>     <span style="color:#7dd3fc">path-articles</span>,<span style="color:#fcd34d">entry-123</span>,<span style="color:#fcd34d">channel-articles</span>,<span style="color:#fcd34d">category-9</span>,<span style="color:#86efac">all</span>  <span style="color:#64748b">// only emitted when backend = cloudflare</span>',
        ]);

        $singleEntryExample = $code([
            '<span style="color:#94a3b8">// templates/articles/_view.html — single-entry view</span>',
            '<span style="color:#fcd34d">{exp:channel:entries channel="articles" limit="1"}</span>',
            '  <span style="color:#86efac">{exp:edge_cache_tags:key name="entry-{entry_id} channel-articles"}</span>',
            '',
            '  <span style="color:#94a3b8">{!-- Optional: only if you use EE categories. Safe to omit. --}</span>',
            '  <span style="color:#fcd34d">{categories}</span><span style="color:#86efac">{exp:edge_cache_tags:key name="category-{category_id}"}</span><span style="color:#fcd34d">{/categories}</span>',
            '',
            '  <span style="color:#94a3b8">&lt;article&gt;...&lt;/article&gt;</span>',
            '<span style="color:#fcd34d">{/exp:channel:entries}</span>',
        ]);

        $listingExample = $code([
            '<span style="color:#94a3b8">// templates/articles/index.html — listing page</span>',
            '<span style="color:#fcd34d">{exp:channel:entries channel="articles" limit="20"}</span>',
            '  <span style="color:#86efac">{exp:edge_cache_tags:key name="entry-{entry_id}"}</span>',
            '  <span style="color:#94a3b8">&lt;a href="{url_title_path=\\"articles\\"}"&gt;{title}&lt;/a&gt;</span>',
            '<span style="color:#fcd34d">{/exp:channel:entries}</span>',
            '<span style="color:#86efac">{exp:edge_cache_tags:key name="channel-articles"}</span>',
        ]);

        // Backend-neutral config example. Each block is roughly the same
        // shape so no one backend visually dominates. Order is alphabetical
        // for neutrality (Cloudflare → Fastly → Nivoli → webhook).
        $configExample = $code([
            '<span style="color:#94a3b8">// Pick ONE of the blocks below.</span>',
            '',
            '<span style="color:#94a3b8">// Cloudflare Enterprise (Cache-Tag purge API)</span>',
            '<span style="color:#86efac">$config</span>[<span style="color:#fcd34d">\'edge_cache_tags_backend\'</span>]      = <span style="color:#fcd34d">\'cloudflare\'</span>;',
            '<span style="color:#86efac">$config</span>[<span style="color:#fcd34d">\'edge_cache_tags_cf_zone_id\'</span>]   = <span style="color:#fcd34d">\'abc123...\'</span>;',
            '<span style="color:#86efac">$config</span>[<span style="color:#fcd34d">\'edge_cache_tags_cf_api_token\'</span>] = <span style="color:#fcd34d">\'...\'</span>;',
            '',
            '<span style="color:#94a3b8">// Fastly (Surrogate-Key purge API)</span>',
            '<span style="color:#86efac">$config</span>[<span style="color:#fcd34d">\'edge_cache_tags_backend\'</span>]         = <span style="color:#fcd34d">\'fastly\'</span>;',
            '<span style="color:#86efac">$config</span>[<span style="color:#fcd34d">\'edge_cache_tags_fastly_service\'</span>]  = <span style="color:#fcd34d">\'SU1Z0...\'</span>;',
            '<span style="color:#86efac">$config</span>[<span style="color:#fcd34d">\'edge_cache_tags_fastly_api_key\'</span>]  = <span style="color:#fcd34d">\'...\'</span>;',
            '',
            '<span style="color:#94a3b8">// Nivoli (managed edge with this addon pre-wired)</span>',
            '<span style="color:#86efac">$config</span>[<span style="color:#fcd34d">\'edge_cache_tags_backend\'</span>]         = <span style="color:#fcd34d">\'nivoli\'</span>;',
            '<span style="color:#86efac">$config</span>[<span style="color:#fcd34d">\'edge_cache_tags_nivoli_endpoint\'</span>] = <span style="color:#fcd34d">\'https://console.nivoli.com/cache/&lt;token&gt;\'</span>;',
            '',
            '<span style="color:#94a3b8">// Generic webhook (your own edge / Varnish / custom proxy)</span>',
            '<span style="color:#86efac">$config</span>[<span style="color:#fcd34d">\'edge_cache_tags_backend\'</span>]        = <span style="color:#fcd34d">\'webhook\'</span>;',
            '<span style="color:#86efac">$config</span>[<span style="color:#fcd34d">\'edge_cache_tags_webhook_url\'</span>]    = <span style="color:#fcd34d">\'https://cache.example.com/purge\'</span>;',
            '<span style="color:#86efac">$config</span>[<span style="color:#fcd34d">\'edge_cache_tags_webhook_secret\'</span>] = <span style="color:#fcd34d">\'...\'</span>;',
        ]);

        return $supplementalCss . '<div class="ect-card ect-docs">

<h2 style="margin-bottom:6px">Documentation</h2>
<p class="sub" style="margin:0 0 18px">Full reference at <a href="https://codebit.nl/edge-cache-ee/docs/" target="_blank" rel="noopener">codebit.nl/edge-cache-ee/docs</a> — this tab is the in-CP quick reference.</p>

<nav class="ect-docs-toc" aria-label="On this page">
  <span class="ect-docs-toc-title">On this page</span>
  <ol>
    <li><a href="#d-concepts">How it works</a></li>
    <li><a href="#d-emitted">What auto-emits</a></li>
    <li><a href="#d-templates">Template integration</a></li>
    <li><a href="#d-purges">What purges on save</a></li>
    <li><a href="#d-config">Configuration</a></li>
    <li><a href="#d-backends">Backends</a></li>
    <li><a href="#d-msm">MSM</a></li>
    <li><a href="#d-faq">Common questions</a></li>
    <li><a href="#d-companion">Add-on Manager</a></li>
  </ol>
</nav>

<section class="ect-docs-section" id="d-concepts">
  <h3><span class="num">1.</span>How tag-based cache invalidation works</h3>
  <p>If you\'ve only used URL-based purges before — "when /news/foo updates, purge /news/foo" — tag-based is the upgrade. The page advertises what it <em>contains</em>; the edge purges by content identity.</p>
  <p><strong>The chain in one sentence:</strong> every page emits a list of tags describing what\'s on it (<code>entry-123</code>, <code>category-9</code>, <code>channel-news</code>, <code>home</code>). When an editor saves entry 123, this addon POSTs a purge for those tags. The edge evicts every page carrying any of them — single-entry view, channel index, category archive — all in one call.</p>
  <h4>Why entry IDs and not URLs?</h4>
  <ul>
    <li><strong>Stability.</strong> Entry IDs never change. URL titles change on slug edits; URLs change when you reorganize taxonomies. ID-tagged cache survives all of that.</li>
    <li><strong>Cross-page coverage.</strong> The same entry appears on many URLs — one <code>entry-N</code> tag intersects all of them.</li>
    <li><strong>Save-event compatibility.</strong> EE\'s <code>after_channel_entry_save</code> hook hands the addon the entry id directly — no URL list to maintain.</li>
  </ul>
</section>

<section class="ect-docs-section" id="d-emitted">
  <h3><span class="num">2.</span>What gets emitted on every page</h3>
  <p>The addon auto-tags from the URI on every front-end GET. Example for <code>/news/some-article</code>:</p>
  ' . $emittedExample . '
  <table class="ect-table">
    <thead><tr><th>Tag</th><th>When emitted</th></tr></thead>
    <tbody>
      <tr><td><code>path-&lt;first-segment&gt;</code></td><td>Any URL with a path. <code>/news/foo</code> → <code>path-news</code>.</td></tr>
      <tr><td><code>home</code></td><td>Homepage / front controller root.</td></tr>
      <tr><td><code>all</code></td><td>Every page (lets an admin nuke everything via one manual purge).</td></tr>
      <tr><td><code>site-&lt;id&gt;-*</code></td><td>MSM only — every tag prefixed with site id + an unprefixed <code>all</code>.</td></tr>
      <tr><td><code>entry-N</code>, <code>channel-X</code>, <code>category-N</code></td><td>Only when templates declare them — see next section.</td></tr>
    </tbody>
  </table>
  <div class="ect-callout ect-callout-info">
    <span class="ect-label">Note</span><strong>No auto template tag.</strong> EE\'s <code>template_post_parse</code> fires multiple times per page (URL template → embeds → layout), so any auto-captured value tracked the wrong one. Push template-scoped tags explicitly: <code>{exp:edge_cache_tags:key name="tmpl-news-index"}</code>.
  </div>
</section>

<section class="ect-docs-section" id="d-templates">
  <h3><span class="num">3.</span>Template integration</h3>
  <p>For each page that displays entry data, declare which entries are on it. <code>{exp:edge_cache_tags:key name="…"}</code> outputs nothing — it just registers tags that emit in <code>Surrogate-Key</code>.</p>

  <h4>Pattern 1 — single entry view (e.g. <code>/news/some-article</code>)</h4>
  ' . $singleEntryExample . '
  <p>Now editing this entry, changing categories, or deleting it evicts this page. <strong>If you don\'t use EE categories,</strong> omit the <code>{categories}</code> block.</p>

  <h4>Pattern 2 — listing / index page (e.g. <code>/news/</code>)</h4>
  ' . $listingExample . '
  <p>Listing pages tag each entry they display, plus the channel. Saving any of the displayed entries purges this listing. Adding a new entry also purges it via <code>channel-articles</code>.</p>

  <h4>Pattern 3 — paginated listings</h4>
  <p>Every paginated page runs the same template, so they all emit the same <code>channel-&lt;name&gt;</code>. Each page additionally tags the entries currently visible on it.</p>
  <ul>
    <li>Edit <code>entry-50</code> → fires <code>entry-50 channel-articles</code> → page 3 (had entry-50) evicts via entry-50, rest evict via channel-articles.</li>
    <li>Add a new entry → fires <code>channel-articles</code> → all pagination pages evict (correct — order shifts).</li>
    <li>Delete entry-25 → same: <code>channel-articles</code> fires, all pages refresh.</li>
  </ul>
  <p><strong><code>channel-&lt;name&gt;</code> is the load-bearing tag for paginated listings</strong> — emit it on every listing template.</p>

  <h4>Pattern 4 — your own taxonomy</h4>
  <p>Tags are arbitrary strings. Invent any you can spell:</p>
  <pre style="background:#0f172a;color:#e2e8f0;padding:10px 14px;border-radius:6px;font-size:12px;font-family:ui-monospace,Menlo,monospace;line-height:1.6;overflow-x:auto;white-space:pre-wrap;word-break:break-word;max-width:760px">{exp:channel:entries channel="articles" limit="1"}
  {exp:edge_cache_tags:key name="entry-{entry_id} channel-articles"}
  <span style="color:#94a3b8">{!-- Custom: tag per related author / topic --}</span>
  {your_authors_field}{exp:edge_cache_tags:key name="author-{author_slug}"}{/your_authors_field}
  {your_topics_field}{exp:edge_cache_tags:key name="topic-{topic_slug}"}{/your_topics_field}
{/exp:channel:entries}</pre>
  <p>Now the page is tagged <code>entry-N channel-articles author-jane topic-pricing</code>. Purging <code>author-jane</code> evicts every page bylined to her in one call.</p>
  <p><strong>Triggering custom-tag purges:</strong> auto-purge only fires the standard tags. For custom taxonomies, use Quick actions on Setup, or call <code>Edge_cache_tags_ext::manual_purge_tags([\'author-jane\'])</code> from a hook.</p>
</section>

<section class="ect-docs-section" id="d-purges">
  <h3><span class="num">4.</span>What gets purged on save</h3>
  <p>When an editor hits Save (or deletes an entry), one POST per backend dispatches these tags:</p>
  <table class="ect-table">
    <thead><tr><th>Tag</th><th>Evicts</th></tr></thead>
    <tbody>
      <tr><td><code>entry-&lt;id&gt;</code></td><td>Every page that featured this entry</td></tr>
      <tr><td><code>channel-&lt;name&gt;</code></td><td>Channel listing pages declared in templates</td></tr>
      <tr><td><code>path-&lt;name&gt;</code></td><td>Every URL under <code>/&lt;name&gt;/…</code></td></tr>
      <tr><td><code>category-&lt;cat_id&gt;</code> ×N</td><td>One per category — category archives</td></tr>
      <tr><td><code>home</code></td><td>The homepage</td></tr>
      <tr><td><code>site-&lt;id&gt;-*</code></td><td>MSM only — all the above prefixed for isolation</td></tr>
    </tbody>
  </table>
  <div class="ect-callout ect-callout-warn">
    <span class="ect-label">Notable absence</span><code>all</code> is NOT auto-purged on save. Every page emits it so an admin can nuke the cache via Quick actions — but firing it on every save would evict every cached page on every publish, defeating the surgical-purge premise.
  </div>
  <p>Multiple saves in the same CP request coalesce into <strong>one</strong> POST per backend. Bounded 5-second timeout.</p>
</section>

<section class="ect-docs-section" id="d-config">
  <h3><span class="num">5.</span>Configuration</h3>
  <p>Three ways to configure, highest-precedence first:</p>
  <ol>
    <li><code>$assign_to_config</code> in your front controller (per-MSM-site, front-end only)</li>
    <li><code>config.php</code> — shared across all MSM sites</li>
    <li>CP form — Edge Cache → Setup; per-site row in <code>exp_edge_cache_tags_settings</code></li>
  </ol>
  <h4>config.php example</h4>
  <p>Pin values code-side. The 🔒 lock indicator on the CP form shows which fields are pinned.</p>
  ' . $configExample . '
  <h4>Per-front-controller (MSM)</h4>
  <p>If each MSM site has its own <code>index.php</code>, put per-site overrides there:</p>
  <pre style="background:#0f172a;color:#e2e8f0;padding:10px 14px;border-radius:6px;font-size:12px;font-family:ui-monospace,Menlo,monospace;line-height:1.6;overflow-x:auto;white-space:pre-wrap;word-break:break-word;max-width:760px"><span style="color:#94a3b8">// index.site-a.php</span>
$assign_to_config[\'edge_cache_tags_backend\']      = \'cloudflare\';
$assign_to_config[\'edge_cache_tags_cf_zone_id\']   = \'...\';
$assign_to_config[\'edge_cache_tags_cf_api_token\'] = \'...\';</pre>
  <div class="ect-callout ect-callout-warn">
    <span class="ect-label">Gotcha</span><strong><code>$assign_to_config</code> in <code>index.php</code> is front-end only.</strong> The CP runs through <code>admin.php</code>. Values in <code>index.php</code> ARE seen by front-end emits + auto-purges, but the CP can\'t read them — "unknown backend" shows in Diagnostics. Either put them in <code>config.php</code> (shared) or mirror into <code>admin.php</code> too, gated by hostname.
  </div>
</section>

<section class="ect-docs-section" id="d-backends">
  <h3><span class="num">6.</span>Backends</h3>
  <p>Headers always emit. The backend choice is just where purges go.</p>
  <table class="ect-table">
    <thead><tr><th>Backend</th><th>Reads</th><th>Notes</th></tr></thead>
    <tbody>
      <tr><td><strong>fastly</strong></td><td><code>Surrogate-Key</code></td><td><code>POST /service/&lt;id&gt;/purge</code>. Standard plans OK. Token needs <em>purge</em> permission.</td></tr>
      <tr><td><strong>cloudflare</strong></td><td><code>Cache-Tag</code></td><td><code>POST /zones/&lt;id&gt;/purge_cache</code>. <strong>Requires Enterprise.</strong></td></tr>
      <tr><td><strong>nivoli</strong></td><td><code>Surrogate-Key</code></td><td><code>POST &lt;dashboard&gt;/purge-tag</code>. Managed, pre-wired.</td></tr>
      <tr><td><strong>webhook</strong></td><td>(your edge)</td><td>JSON <code>{tags, site_id, source}</code>. Optional bearer secret.</td></tr>
      <tr><td><strong>none</strong></td><td>headers only</td><td>Headers emit; no dispatch. For VCL-managed Varnish.</td></tr>
    </tbody>
  </table>
</section>

<section class="ect-docs-section" id="d-msm">
  <h3><span class="num">7.</span>Multi-Site Manager (MSM)</h3>
  <p><strong>Default site (<code>site_id = 1</code>):</strong> keys are emitted and purged unprefixed. Works like a single-site install.</p>
  <p><strong>Secondary sites (<code>site_id &gt; 1</code>):</strong> all keys prefixed with <code>site-&lt;id&gt;-</code> for cross-site isolation. Pages still emit an unprefixed <code>all</code> so an admin can do a network-wide nuke.</p>
  <p>One EE install with N sites uses <strong>one</strong> purge backend. The site-id prefix keeps tag namespaces separate.</p>
  <div class="ect-callout ect-callout-tip">
    <span class="ect-label">Nivoli + MSM</span>To share one Nivoli endpoint across MSM sites, ask Nivoli support to link your tokens — the <code>site-&lt;id&gt;-</code> prefix routes purges to the right hostname automatically. The Setup tab\'s host-scope banner confirms the current site is in scope.
  </div>
</section>

<section class="ect-docs-section" id="d-faq">
  <h3><span class="num">8.</span>Common questions</h3>

  <h4>Do I need a backend selected before headers emit?</h4>
  <p><strong>No.</strong> Headers emit on every front-end GET regardless of which backend is selected, including <code>none</code>. The backend choice only controls where purges go on save.</p>

  <h4>How can headers come out before the body if the template tag is inside the HTML?</h4>
  <p>Output buffering. All template tags run first (including <code>{exp:edge_cache_tags:key}</code>, which stashes keys silently). Then <code>template_post_parse</code> fires, the addon reads the stashed keys, and calls <code>ee()->output->set_header(...)</code>. EE sends headers before the body.</p>
  <p>Template tag order doesn\'t matter — place the plugin tag anywhere inside <code>&lt;html&gt;…&lt;/html&gt;</code>.</p>

  <h4>I curled and didn\'t see the headers. What gives?</h4>
  <p>Almost always: your edge cache is still serving the version cached <em>before</em> the addon was installed. Look for <code>cf-cache-status: HIT</code> in the response — that confirms the answer didn\'t come from EE just now.</p>
  <div class="ect-callout ect-callout-info">
    <span class="ect-label">Cache-bust</span>Run this to see fresh-from-origin:
    <div class="ect-docs-code" style="margin:8px 0 0">curl -I "https://yoursite.com/page?nocache=$(date +%s)"</div>
  </div>

  <h4>Why don\'t archive pages evict when I save an entry?</h4>
  <p>Addon versions before v2.4.19 had a bug where channel + category + path tags weren\'t dispatched on save (only <code>home + entry-N</code>). Archive pages had no matching tag, so they didn\'t evict. <strong>Upgrade to v2.4.19+</strong> — saves now dispatch the full set.</p>

  <h4>Can I make up my own tags?</h4>
  <p>Yes — see Pattern 4 above. Auto-purge only fires the standard tags. For custom taxonomies, use Quick actions on the Setup tab or call <code>Edge_cache_tags_ext::manual_purge_tags([\'your-tag\'])</code> from a custom extension.</p>

  <h4>Can I disable the addon without CP access?</h4>
  <p>Set <code>$config[\'edge_cache_tags_disable\'] = true;</code> in <code>config.php</code> or <code>assign_to_config</code>. The hooks short-circuit without touching the CP.</p>
</section>

<section class="ect-docs-section" id="d-companion">
  <h3><span class="num">9.</span>Recommended companion: Add-on Manager</h3>
  <div class="ect-companion">
    <div class="ect-companion-icon">↥</div>
    <div class="ect-companion-body">
      <p style="margin:0 0 6px"><strong>If you don\'t want to FTP zips into <code>system/user/addons/</code></strong>, the free <a href="https://expressionengine.com/add-ons/addon-manager" target="_blank" rel="noopener">Add-on Manager</a> by Javid Fazaeli is a useful tool — drag an add-on zip into the CP, it installs it for you. No FTP / SCP required.</p>
      <p style="margin:0">Note: it doesn\'t poll for new versions. Edge Cache Tags ships its own GitHub-releases poller for that — update notifications appear in this CP either way.</p>
    </div>
  </div>
  <h4 style="margin-top:22px">More reading</h4>
  <ul>
    <li><a href="https://codebit.nl/edge-cache-ee/docs/" target="_blank" rel="noopener">Full documentation on codebit.nl</a> — same content, with more breathing room</li>
    <li><a href="https://github.com/calimonk/ee-edge-cache-tags" target="_blank" rel="noopener">GitHub repository</a> — source, releases, issues, README</li>
    <li><a href="https://github.com/calimonk/wp-edge-cache-tags" target="_blank" rel="noopener">Sister WordPress plugin</a> — same dispatch model</li>
    <li><a href="https://nivoli.com/" target="_blank" rel="noopener">Nivoli</a> — managed full-page caching, pre-wired with this addon</li>
  </ul>
</section>

</div>';
    }
}
