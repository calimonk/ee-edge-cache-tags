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
    private function configOverrides(): array
    {
        $keys = [
            'backend'         => 'edge_cache_tags_backend',
            'nivoli_endpoint' => 'edge_cache_tags_nivoli_endpoint',
            'fastly_service'  => 'edge_cache_tags_fastly_service',
            'fastly_api_key'  => 'edge_cache_tags_fastly_api_key',
            'cf_zone_id'      => 'edge_cache_tags_cf_zone_id',
            'cf_api_token'    => 'edge_cache_tags_cf_api_token',
            'webhook_url'     => 'edge_cache_tags_webhook_url',
            'webhook_secret'  => 'edge_cache_tags_webhook_secret',
        ];
        $out = [];
        foreach ($keys as $local => $configKey) {
            $val = ee()->config->item($configKey);
            if ($val !== null && $val !== '') $out[$local] = (string) $val;
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
        $backendSelect = $this->renderBackendSelect($r['backend'], isset($overrides['backend']));
        $configBlocks = $this->renderBackendConfigBlocks($r, $overrides, $effBackend);
        $diagBlock = $this->renderDiagBlock($diag);
        $activityBlock = $this->renderActivityBlock($siteId);
        $toolsBlock = $this->renderToolsBlock($diag['effective'], $action);
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
  <button type="button" class="ect-tab" data-tab="docs" role="tab" aria-selected="false">Documentation</button>
</nav>

<section class="ect-tab-panel active" data-panel="setup">
  <form method="POST" action="{$action}">
  <div class="ect-card">
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

  {$toolsBlock}
</section>

<section class="ect-tab-panel" data-panel="status">
  {$diagBlock}
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
            $select .= ' <span class="lock-note">🔒 set via config.php (edge_cache_tags_backend)</span>';
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

    private function cfgPanel(string $kind, string $title, array $r, array $overrides, bool $active): string
    {
        $cls = 'ect-backend-cfg' . ($active ? ' active' : '');
        $body = '';
        switch ($kind) {
            case 'none':
                // The "headers-only" mode is also the upsell surface — most
                // people see this panel first (it's the default). The Nivoli
                // pitch leads with FULL-PAGE EDGE CACHING (the real product),
                // not tag purge (which is what the addon already does). Uses
                // multiple accent colors to break up the all-blue feel of
                // earlier versions.
                $body = '<p style="margin:0 0 18px;color:#334155;font-size:14px;line-height:1.6">'
                      . 'Pages keep emitting <code>Surrogate-Key</code> + <code>Cache-Tag</code> headers — '
                      . 'your edge cache reads them. The addon just doesn\'t fire purges; whatever wires up '
                      . 'your cache handles invalidation. Good fit for Varnish/VCL setups, evaluators kicking '
                      . 'the tires, or "headers first, purges later" rollouts.</p>'

                      . '<div style="background:linear-gradient(135deg,#1e1b4b 0%,#7c3aed 55%,#db2777 100%);color:white;border-radius:10px;margin-top:18px;overflow:hidden;box-shadow:0 6px 24px rgba(124,58,237,0.22)">'

                      // ---- Top row: hero (left) + secondary differentiators (right) ----
                      // Side-by-side on wide screens; stacks on narrow. Uses the
                      // horizontal space that the hero copy alone leaves blank.
                      . '<div style="display:grid;grid-template-columns:minmax(0,1.55fr) minmax(0,1fr);gap:0;border-bottom:1px solid rgba(255,255,255,0.14)">'

                      // Hero (left) — lead with the contradiction.
                      . '<div style="padding:26px 28px 22px">'
                      . '<div style="font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:#fbbf24;margin-bottom:10px">⚡ Full-page HTML caching on Cloudflare</div>'
                      . '<h3 style="margin:0 0 12px;color:white;font-size:24px;font-weight:700;line-height:1.2;letter-spacing:-0.015em">Cloudflare doesn\'t cache HTML.<br><span style="color:#fbbf24">Nivoli does.</span></h3>'
                      . '<p style="margin:0 0 10px;font-size:14.5px;line-height:1.62;opacity:0.95">'
                      . 'Out of the box, Cloudflare caches your CSS, JS, and images at the edge — but every HTML pageview still round-trips to your origin server. '
                      . '<strong>Nivoli adds the missing layer:</strong> full-page HTML caching on Cloudflare\'s global network. '
                      . 'Every visitor hits Cloudflare; only <strong style="color:#fbbf24">cache MISSes touch origin</strong>.</p>'
                      . '<p style="margin:0;font-size:14px;line-height:1.6;opacity:0.92">'
                      . '<strong>And it stays correct.</strong> When an editor saves an entry in EE, this addon fires tag purge — only the pages that show that entry refresh, never the whole cache. '
                      . 'Speed <em>and</em> consistency.</p>'
                      . '</div>'

                      // Right column — secondary differentiators. Vertical
                      // stack, compact. Stale-on-error added as the
                      // reliability angle the all-blue version was missing.
                      . '<div style="padding:22px 24px 18px;background:rgba(0,0,0,0.20);border-left:1px solid rgba(255,255,255,0.10);display:flex;flex-direction:column;gap:10px">'

                      . '<div style="background:rgba(52,211,153,0.14);border:1px solid rgba(52,211,153,0.38);padding:11px 14px;border-radius:7px;font-size:12.5px;line-height:1.5">'
                      .   '<strong style="color:#6ee7b7;display:block;margin-bottom:3px;font-size:13px">🛡 Attack blackholing</strong>'
                      .   '<span style="opacity:0.88">.env / .git / wp-admin / xmlrpc probes return 404 at the edge. Origin never sees them.</span>'
                      . '</div>'

                      . '<div style="background:rgba(192,132,252,0.16);border:1px solid rgba(192,132,252,0.42);padding:11px 14px;border-radius:7px;font-size:12.5px;line-height:1.5">'
                      .   '<strong style="color:#d8b4fe;display:block;margin-bottom:3px;font-size:13px">⏱ Stale-on-error fallback</strong>'
                      .   '<span style="opacity:0.88">Origin down? Cached copies keep serving visitors for up to 7 days. Your site stays up while you fix the backend.</span>'
                      . '</div>'

                      . '<div style="background:rgba(96,165,250,0.16);border:1px solid rgba(96,165,250,0.42);padding:11px 14px;border-radius:7px;font-size:12.5px;line-height:1.5">'
                      .   '<strong style="color:#93c5fd;display:block;margin-bottom:3px;font-size:13px">📢 Deploy alerts</strong>'
                      .   '<span style="opacity:0.88">Webhook on 5xx storms (broken release) or mass-404 spikes (deleted route).</span>'
                      . '</div>'

                      . '<div style="background:rgba(244,114,182,0.14);border:1px solid rgba(244,114,182,0.38);padding:11px 14px;border-radius:7px;font-size:12.5px;line-height:1.5">'
                      .   '<strong style="color:#f9a8d4;display:block;margin-bottom:3px;font-size:13px">↻ URL rules</strong>'
                      .   '<span style="opacity:0.88">Redirect old URLs / block specific patterns at the edge — never round-trip to origin.</span>'
                      . '</div>'

                      . '</div>'
                      . '</div>'

                      // Featured: 404 / 5xx dashboards — these are the
                      // killer ops features. Full-width pair below the hero.
                      . '<div style="padding:22px 28px 18px;background:rgba(0,0,0,0.16)">'
                      . '<div style="font-size:11px;font-weight:700;letter-spacing:0.10em;text-transform:uppercase;opacity:0.78;margin-bottom:14px">Operational dashboards that ship with the cache</div>'
                      . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px">'

                      // 404 management
                      . '<div style="background:rgba(251,191,36,0.14);border:1px solid rgba(251,191,36,0.45);padding:14px 16px;border-radius:8px;line-height:1.5">'
                      .   '<div style="font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#fcd34d;margin-bottom:6px">⚠ 404 management</div>'
                      .   '<strong style="color:white;display:block;font-size:14.5px;margin-bottom:4px">Every broken URL on your site, on one screen</strong>'
                      .   '<span style="font-size:13px;opacity:0.88">Live 404 traffic clustered by pattern. Smart redirect suggestions matched against your top pages. One-click blackhole for bot probes. Test sandbox for new rules before they ship.</span>'
                      . '</div>'

                      // 5xx triage
                      . '<div style="background:rgba(248,113,113,0.14);border:1px solid rgba(248,113,113,0.45);padding:14px 16px;border-radius:8px;line-height:1.5">'
                      .   '<div style="font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#fca5a5;margin-bottom:6px">🔥 5xx triage</div>'
                      .   '<strong style="color:white;display:block;font-size:14.5px;margin-bottom:4px">Origin failures, captured and clustered</strong>'
                      .   '<span style="font-size:13px;opacity:0.88">Status-code breakdown per host. Captured origin response bodies. Top error URLs sorted by hit rate. Block patterns that should never reach origin — like emoji-laden bot URIs that crash your PHP.</span>'
                      . '</div>'

                      . '</div></div>'

                      // CTA
                      . '<div style="padding:22px 28px;background:rgba(0,0,0,0.32);display:flex;align-items:center;gap:18px;flex-wrap:wrap">'
                      . '<a href="https://console.nivoli.com/signup" target="_blank" rel="noopener" '
                      . 'style="background:#fbbf24;color:#1e1b4b !important;padding:13px 30px;border-radius:7px;text-decoration:none;font-weight:700;font-size:15px;letter-spacing:-0.01em;box-shadow:0 2px 12px rgba(251,191,36,0.40)">Start free →</a>'
                      . '<div style="font-size:13px;opacity:0.95;line-height:1.55">'
                      .   '<strong style="color:#34d399">Free tier:</strong> 1 domain, ~100k req/mo. '
                      .   '<strong style="color:#93c5fd">No credit card.</strong> '
                      .   '<strong style="color:#fcd34d">~90 seconds</strong> from signup to first cached page.<br>'
                      .   '<span style="opacity:0.72;font-size:12px">Nivoli\'s edge runs on top of Cloudflare\'s free tier — same global POPs you already trust.</span>'
                      . '</div>'
                      . '</div>'

                      . '</div>';
                break;
            case 'nivoli':
                // Real stats widget — pulled live from the Nivoli endpoint
                // when it's set + reachable. One curl per CP page load,
                // memoized within the request. Fails silently if the
                // endpoint isn't accessible (network blip, wrong URL, etc.)
                $statsWidget = '';
                $effEndpoint = $overrides['nivoli_endpoint'] ?? ($r['nivoli_endpoint'] ?? '');
                if ($effEndpoint) {
                    $stats = $this->fetchNivoliStats($effEndpoint);
                    if ($stats) {
                        $statsWidget = $this->renderNivoliStatsWidget($stats);
                    }
                }
                $body = $statsWidget
                      . '<p style="margin:0 0 14px;color:#334155;font-size:13.5px;line-height:1.55">'
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
            . '<p class="sub" style="margin:0 0 6px">Keys are space-separated in the <code>Surrogate-Key</code> header and comma-separated in <code>Cache-Tag</code>.</p>'
            . '<div class="ect-sample">' . $sampleRows . '</div>'
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
    private function renderToolsBlock(array $eff, string $action): string
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
        $hint = $disabled
            ? '<span style="color:#b45309;font-weight:500">Pick a backend above before this form can dispatch.</span>'
            : 'Space- or comma-separated. Common tags: <code>home</code>, <code>all</code>, <code>channel-news</code>, <code>entry-123</code>.';
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

        return '<div class="ect-card">'
            . '<h2 style="margin:0 0 4px">Quick actions</h2>'
            . '<p class="sub" style="margin:0 0 16px;color:#64748b">Manual purges when you change something outside an entry save (template edit, asset update, fix to a hand-rolled URL).</p>'
            . $dashboardBlock
            . '<h3 style="margin:0 0 8px;font-size:14px;color:#1e293b">Purge tags manually</h3>'
            . $purgeForm
            . '</div>';
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
        if (self::$stats_cache_key === $endpoint && self::$stats_cache !== null) {
            return self::$stats_cache ?: null;
        }
        $url = rtrim($endpoint, '/') . '/stats?hours=720'; // 30d window
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
        self::$stats_cache_key = $endpoint;
        return $data;
    }

    /**
     * Build a small "your stats" widget from the parsed /stats payload.
     * Returns empty string if data isn't available or doesn't have the
     * keys we need.
     */
    private function renderNivoliStatsWidget(array $stats): string
    {
        $summary = $stats['summary'] ?? [];
        $savings = $stats['savings'] ?? [];
        $totalReqs   = (int)   ($summary['totalRequests'] ?? 0);
        $hitRate     = (float) ($summary['hitRate'] ?? 0);   // 0..1
        $bytesSaved  = (int)   ($savings['cache_served_bytes'] ?? 0);
        if ($totalReqs <= 0) return '';

        $fmtNum = function (int $n): string {
            if ($n >= 1_000_000) return number_format($n / 1_000_000, 1) . 'M';
            if ($n >= 1_000)     return number_format($n / 1_000, 1) . 'k';
            return (string) $n;
        };
        $fmtBytes = function (int $b): string {
            if ($b >= 1_073_741_824) return number_format($b / 1_073_741_824, 1) . ' GB';
            if ($b >= 1_048_576)     return number_format($b / 1_048_576, 1) . ' MB';
            if ($b >= 1024)          return number_format($b / 1024, 1) . ' KB';
            return $b . ' B';
        };
        $hitPct = round($hitRate * 100);

        return '<div style="background:linear-gradient(135deg,#065f46 0%,#0e7490 100%);color:white;border-radius:8px;padding:16px 20px;margin-bottom:16px;box-shadow:0 3px 12px rgba(6,95,70,0.18)">'
            . '<div style="font-size:11px;font-weight:700;letter-spacing:0.10em;text-transform:uppercase;color:#6ee7b7;margin-bottom:8px">📊 Your cache performance · last 30 days</div>'
            . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px">'
            . '<div><div style="font-size:11px;opacity:0.78;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:2px">Hit rate</div>'
            . '<div style="font-size:24px;font-weight:700;line-height:1.1">' . $hitPct . '%</div></div>'
            . '<div><div style="font-size:11px;opacity:0.78;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:2px">Requests served</div>'
            . '<div style="font-size:24px;font-weight:700;line-height:1.1">' . $fmtNum($totalReqs) . '</div></div>'
            . '<div><div style="font-size:11px;opacity:0.78;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:2px">Origin bandwidth saved</div>'
            . '<div style="font-size:24px;font-weight:700;line-height:1.1">' . $fmtBytes($bytesSaved) . '</div></div>'
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
                // Render each line as its own div with monospace + dark bg.
                $rows .= '<div style="padding:2px 0">' . $ln . '</div>';
            }
            return '<div style="background:#0f172a;color:#e2e8f0;padding:14px 16px;border-radius:7px;font-size:12.5px;line-height:1.7;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;overflow-x:auto;white-space:pre-wrap;word-break:break-word">' . $rows . '</div>';
        };

        // Examples use a generic <code>articles</code> channel so the docs read
        // the same whether the site happens to be news, games, recipes,
        // events, products, or anything else. Replace mentally with your
        // own channel short_name.
        $emittedExample = $code([
            '<span style="color:#94a3b8">Surrogate-Key:</span> <span style="color:#7dd3fc">tmpl-articles-index</span> <span style="color:#7dd3fc">path-articles</span> <span style="color:#fcd34d">entry-123</span> <span style="color:#fcd34d">channel-articles</span> <span style="color:#fcd34d">category-9</span> <span style="color:#86efac">all</span>',
            '<span style="color:#94a3b8">Cache-Tag:</span>     <span style="color:#7dd3fc">tmpl-articles-index</span>,<span style="color:#7dd3fc">path-articles</span>,<span style="color:#fcd34d">entry-123</span>,<span style="color:#fcd34d">channel-articles</span>,<span style="color:#fcd34d">category-9</span>,<span style="color:#86efac">all</span>',
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

        return '<div class="ect-card ect-docs">

<h2>How tag-based cache invalidation works</h2>
<p>If you\'ve only used URL-based purges before ("when /blog/foo updates, purge /blog/foo"), tag-based is the upgrade. The page advertises what it CONTAINS; the edge purges by content identity. Read this once and the template patterns below click immediately.</p>

<h3>The chain in one sentence</h3>
<p>Every page emits a list of tags describing what\'s on it (<code>entry-123</code>, <code>category-9</code>, <code>channel-articles</code>, <code>home</code>). When an editor saves entry 123 in the CP, this addon POSTs a purge for the tag <code>entry-123</code>. The edge cache evicts <strong>every page</strong> that carried that tag — the single-entry view, the homepage list that featured it, the category archive that included it — all in one call. No URL enumeration. No "forgot to purge the homepage" bugs.</p>

<h3>What gets emitted on every page (automatic)</h3>
<p>The addon already auto-tags from the URI and template context:</p>
' . $emittedExample . '
<ul style="margin-top:12px">
  <li><code>tmpl-&lt;group&gt;-&lt;template&gt;</code> — which template rendered (e.g. <code>tmpl-news-index</code>)</li>
  <li><code>path-&lt;first-segment&gt;</code> — first URL segment (e.g. <code>path-news</code> for /news/anything)</li>
  <li><code>all</code> — every page carries this; lets an admin nuke everything with one tag</li>
  <li><code>home</code> — only on the homepage / front controller</li>
  <li>MSM site_id &gt; 1: all keys above prefixed with <code>site-&lt;id&gt;-</code>, plus an unprefixed <code>all</code></li>
</ul>
<p style="margin-top:10px"><strong>What\'s missing:</strong> the addon can\'t know which <em>entries</em> are on a page from outside the template — that\'s why you add the next step.</p>

<h3>What YOU add to your templates</h3>
<p>For each page that shows entry data, declare which entries are on it. Then editing that entry purges this page.</p>

<p style="margin-top:14px"><strong>Pattern 1 — single entry view</strong> (e.g. <code>/news/some-article</code>)</p>
' . $singleEntryExample . '
<p style="margin-top:8px;font-size:13px;color:#475569">Now if someone edits this entry, OR changes its categories, OR deletes it — this page evicts. <strong>If your site doesn\'t use EE categories</strong>, just omit the <code>{categories}</code> block — everything else still works.</p>

<p style="margin-top:18px"><strong>Pattern 2 — listing / index page</strong> (e.g. <code>/news/</code> with 20 latest entries)</p>
' . $listingExample . '
<p style="margin-top:8px;font-size:13px;color:#475569">Listing pages tag EACH entry they display, plus the channel. Saving any one of those 20 entries purges this listing. Adding a 21st entry also purges it (the <code>channel-articles</code> tag fires on every save in that channel).</p>

<h4 style="margin:14px 0 4px;font-size:13px;font-weight:600;color:#1e293b">What about paginated pages? (<code>/articles/</code>, <code>/articles/P20</code>, <code>/articles/P40</code> …)</h4>
<p style="margin:0 0 8px;font-size:13px;color:#475569;line-height:1.6">
  Every paginated page runs the <strong>same template</strong>, so they all emit the same <code>channel-&lt;name&gt;</code> tag. Each page additionally tags only the 20 entries currently visible on it (page 1 → <code>entry-1</code> … <code>entry-20</code>, page 3 → <code>entry-41</code> … <code>entry-60</code>, etc).
</p>
<p style="margin:0 0 8px;font-size:13px;color:#475569;line-height:1.6">
  <strong>The result:</strong>
</p>
<ul style="margin:0 0 8px;font-size:13px;color:#475569;line-height:1.55">
  <li>Edit entry-50 → fires <code>entry-50</code> + <code>channel-articles</code> → page 3 (which had entry-50) evicts via entry-50, the rest of the pagination evicts via channel-articles. All pagination pages refresh together.</li>
  <li>Add a NEW entry → fires <code>channel-articles</code> → all pagination pages evict (correct — a new entry shifts the order).</li>
  <li>Delete entry-25 → same: <code>channel-articles</code> fires, all pages refresh.</li>
</ul>
<p style="margin:0 0 0;font-size:13px;color:#475569;line-height:1.55">
  So <strong><code>channel-&lt;name&gt;</code> is the load-bearing tag for paginated listings</strong> — make sure your listing template emits it. The per-entry tags are belt-and-suspenders: they make individual edits land surgically (only the page that featured the edited entry would <em>need</em> to refresh), and they\'re useful when one template hosts a list AND another template embeds the same entries (think: a featured-3 widget on the homepage that uses <code>{exp:channel:entries limit="3"}</code>).
</p>

<h3>Common questions</h3>

<h4 style="margin:14px 0 4px;font-size:13.5px;font-weight:600;color:#1e293b">Do I need to pick a backend before headers start emitting?</h4>
<p style="margin:0 0 12px;font-size:13px;color:#475569;line-height:1.6">
  <strong>No.</strong> Headers emit on every front-end GET that goes through the EE template engine, regardless of which backend is selected — including <code>none</code>. The backend choice only controls where purges get dispatched on entry saves; the response headers themselves are independent. So you can install the addon, leave the backend on <code>none</code>, and start seeing <code>Surrogate-Key</code> / <code>Cache-Tag</code> in fresh responses immediately. Pick a backend later when you want auto-purges too.
</p>

<h4 style="margin:14px 0 4px;font-size:13.5px;font-weight:600;color:#1e293b">How can headers come out BEFORE the body if the template tag is inside the HTML?</h4>
<p style="margin:0 0 8px;font-size:13px;color:#475569;line-height:1.6">
  Output buffering. The EE template engine works in stages:
</p>
<ol style="margin:0 0 12px;font-size:13px;color:#475569;line-height:1.6">
  <li>All template tags run — including <code>{exp:edge_cache_tags:key}</code>, which stashes keys in a session cache and outputs nothing</li>
  <li>After the body is fully parsed, the <code>template_post_parse</code> hook fires</li>
  <li>The addon reads the stashed keys + URI context + template-group context, calls <code>ee()->output->set_header(...)</code> to <strong>register</strong> the headers</li>
  <li>EE finalizes output: HTTP headers are sent FIRST (yours included), THEN the body</li>
</ol>
<p style="margin:0 0 12px;font-size:13px;color:#475569;line-height:1.6">
  So template tag order doesn\'t matter — the entire body is buffered, then headers go out in front of it. You can put the plugin tag anywhere inside <code>&lt;html&gt;...&lt;/html&gt;</code>.
</p>

<h4 style="margin:14px 0 4px;font-size:13.5px;font-weight:600;color:#1e293b">Can I make up my own tags?</h4>
<p style="margin:0 0 8px;font-size:13px;color:#475569;line-height:1.6">
  <strong>Yes — tags are arbitrary strings.</strong> The <code>channel-&lt;name&gt;</code> / <code>category-&lt;id&gt;</code> convention exists because EE hands the addon channel and category data through its save hooks, so those are auto-purged. Outside that, there\'s no preset list — invent any taxonomy that makes sense for your content (tags like <code>author-jane</code>, <code>topic-pricing</code>, <code>region-eu</code>, <code>year-2026</code>, <code>featured</code>, anything you can spell).
</p>
<p style="margin:0 0 8px;font-size:13px;color:#475569;line-height:1.6">Example — an entry view with two custom relationship fields, used to register one tag per related item:</p>
<pre style="background:#0f172a;color:#e2e8f0;padding:10px 14px;border-radius:6px;font-size:12px;font-family:ui-monospace,Menlo,monospace;line-height:1.6;overflow-x:auto;white-space:pre-wrap;word-break:break-word">{exp:channel:entries channel="articles" limit="1"}
  {exp:edge_cache_tags:key name="entry-{entry_id} channel-articles"}
  <span style="color:#94a3b8">{!-- Custom: tag with each related author and topic --}</span>
  {your_authors_field}{exp:edge_cache_tags:key name="author-{author_slug}"}{/your_authors_field}
  {your_topics_field}{exp:edge_cache_tags:key name="topic-{topic_slug}"}{/your_topics_field}
{/exp:channel:entries}</pre>
<p style="margin:0 0 8px;font-size:13px;color:#475569;line-height:1.6">
  Now the page is tagged <code>entry-N channel-articles author-jane topic-pricing topic-eu</code>. Purging <code>author-jane</code> evicts every article page bylined to her AND any author listing that featured her — in one call.
</p>
<p style="margin:0 0 12px;font-size:13px;color:#475569;line-height:1.6">
  <strong>Triggering the purge:</strong> the addon\'s built-in auto-purge only fires the standard tags on entry save (<code>entry-N</code>, <code>channel-X</code>, <code>category-Y</code>, <code>home</code>, <code>all</code>). For custom taxonomies, dispatch via the <strong>Quick actions</strong> panel on the Setup tab when you change them, or call <code>Edge_cache_tags_ext::manual_purge_tags([\'author-jane\'])</code> from a custom EE extension hooked into <code>after_channel_entry_save</code>.
</p>

<h4 style="margin:14px 0 4px;font-size:13.5px;font-weight:600;color:#1e293b">How do I configure different settings per MSM site in config.php?</h4>
<p style="margin:0 0 8px;font-size:13px;color:#475569;line-height:1.6">
  <strong>Easiest answer: skip config.php for MSM and use the CP form.</strong> The settings table (<code>exp_edge_cache_tags_settings</code>) is keyed by <code>site_id</code>, so each MSM site has its own row. Switch the EE site picker at the top of the CP, open Edge Cache Tags, configure backend + credentials independently for that site. Repeat for each site. No code edits.
</p>
<p style="margin:0 0 8px;font-size:13px;color:#475569;line-height:1.6">
  If you really want config-as-code, <code>config.php</code> is shared across all MSM sites — so you have to gate the items on the requesting hostname (the <code>site_id</code> isn\'t resolved this early in EE\'s bootstrap). Pattern:
</p>
<pre style="background:#0f172a;color:#e2e8f0;padding:10px 14px;border-radius:6px;font-size:12px;font-family:ui-monospace,Menlo,monospace;line-height:1.6;overflow-x:auto;white-space:pre-wrap;word-break:break-word">$host = isset($_SERVER[\'HTTP_HOST\']) ? strtolower($_SERVER[\'HTTP_HOST\']) : \'\';

if (strpos($host, \'site-a.example.com\') !== false) {
    $config[\'edge_cache_tags_backend\']      = \'cloudflare\';
    $config[\'edge_cache_tags_cf_zone_id\']   = \'...\';
    $config[\'edge_cache_tags_cf_api_token\'] = \'...\';
} elseif (strpos($host, \'site-b.example.com\') !== false) {
    $config[\'edge_cache_tags_backend\']        = \'fastly\';
    $config[\'edge_cache_tags_fastly_service\'] = \'...\';
    $config[\'edge_cache_tags_fastly_api_key\'] = \'...\';
} elseif (strpos($host, \'site-c.example.com\') !== false) {
    $config[\'edge_cache_tags_backend\']         = \'nivoli\';
    $config[\'edge_cache_tags_nivoli_endpoint\'] = \'https://console.nivoli.com/cache/&lt;token&gt;\';
}</pre>
<p style="margin:0 0 12px;font-size:13px;color:#475569;line-height:1.6">
  The addon\'s config resolution still applies inside each branch: config.php items win over the CP form. The 🔒 lock indicator shows you which fields are pinned <em>for the current MSM site you\'re viewing</em>. So you can pin the backend via config.php for some sites and let others use the CP form — mix and match.
</p>
<p style="margin:0 0 8px;font-size:13px;color:#475569;line-height:1.6">
  <strong>Alternative: per-front-controller via <code>$assign_to_config</code>.</strong> If your MSM setup uses a separate <code>index.php</code> per site (a common pattern for keeping completely independent EE installs that share infrastructure), the top-of-file <code>$assign_to_config</code> array is the cleanest place. EE merges it into the config registry on bootstrap, so the addon reads the right values automatically:
</p>
<pre style="background:#0f172a;color:#e2e8f0;padding:10px 14px;border-radius:6px;font-size:12px;font-family:ui-monospace,Menlo,monospace;line-height:1.6;overflow-x:auto;white-space:pre-wrap;word-break:break-word"><span style="color:#94a3b8">// index.site-a.php (separate front controller per MSM site)</span>
$assign_to_config[\'template_group\'] = \'home\';
$assign_to_config[\'template\']       = \'index\';

$assign_to_config[\'edge_cache_tags_backend\']      = \'cloudflare\';
$assign_to_config[\'edge_cache_tags_cf_zone_id\']   = \'...\';
$assign_to_config[\'edge_cache_tags_cf_api_token\'] = \'...\';</pre>
<p style="margin:0 0 12px;font-size:13px;color:#475569;line-height:1.6">
  Put each site\'s overrides in its own front controller. Main site\'s defaults still come from <code>config.php</code> if anything\'s set there.
</p>
<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:6px;padding:12px 14px;margin:8px 0 14px;max-width:760px">
  <p style="margin:0 0 6px;font-size:13px;color:#78350f;line-height:1.55"><strong>⚠️ Gotcha: <code>$assign_to_config</code> in <code>index.php</code> is front-end only.</strong></p>
  <p style="margin:0 0 6px;font-size:12.5px;color:#78350f;line-height:1.6">
    The Control Panel runs through <code>admin.php</code>, not <code>index.php</code>. So any <code>$assign_to_config</code> values you set in a site\'s <code>index.php</code> are read on front-end page hits (and the addon\'s header emit + auto-purge see them correctly), but the <strong>CP settings page can\'t see them</strong> — you\'ll get a "Backend credentials — unknown backend" warning in Diagnostics, and the form fields show as editable rather than 🔒 locked.
  </p>
  <p style="margin:0 0 6px;font-size:12.5px;color:#78350f;line-height:1.6">
    Two ways to make the CP see the same values:
  </p>
  <ul style="margin:0 0 0 18px;font-size:12.5px;color:#78350f;line-height:1.6">
    <li>Put them in <code>system/user/config/config.php</code> — shared across both front-controllers (recommended for single-site installs)</li>
    <li>OR mirror them into <code>admin.php</code> as well — gate by hostname if you\'re running MSM (the CP doesn\'t know which MSM site you\'re viewing until after bootstrap, so per-host gating is the only way)</li>
  </ul>
  <p style="margin:8px 0 0;font-size:12.5px;color:#78350f;line-height:1.6">
    None of this affects whether the addon actually <em>works</em> — front-end emits + purges still see the index.php values. It only affects what the CP visualizes.
  </p>
</div>
<p style="margin:0 0 12px;font-size:13px;color:#475569;line-height:1.6">
  <strong>Tip for Nivoli users:</strong> if you want one Nivoli endpoint serving multiple MSM hostnames (single dashboard URL covering all sites), ask Nivoli support to link your tokens — the per-site <code>site-&lt;id&gt;-</code> tag prefix routes purges to the right hostname automatically. Otherwise, each MSM site gets its own dashboard URL.
</p>

<h4 style="margin:14px 0 4px;font-size:13.5px;font-weight:600;color:#1e293b">I curled my site and didn\'t see the headers — what gives?</h4>
<p style="margin:0 0 8px;font-size:13px;color:#475569;line-height:1.6">
  Almost always: <strong>your edge cache is still serving the version it cached BEFORE the addon was installed</strong>. The addon only sets headers on responses EE renders fresh — pre-cached HITs carry whatever headers were there at the time of original caching. Look for <code>cf-cache-status: HIT</code> in the response — that confirms the answer didn\'t come from EE just now.
</p>
<p style="margin:0 0 8px;font-size:13px;color:#475569;line-height:1.6">
  <strong>Note on <code>curl -I</code>:</strong> <code>-I</code> sends a HEAD request, not GET. Both should carry the same headers (RFC 7231); the addon supports HEAD as of v2.3.5. If you\'re on an older version, switch to <code>curl -i</code> (lowercase, GET with headers shown) instead.
</p>
<p style="margin:0 0 8px;font-size:13px;color:#475569;line-height:1.6">Bypass cache to see fresh-from-origin:</p>
<pre style="background:#0f172a;color:#e2e8f0;padding:10px 14px;border-radius:6px;font-size:12px;font-family:ui-monospace,Menlo,monospace;overflow-x:auto;white-space:pre-wrap;word-break:break-word">curl -I "https://yoursite.com/some/page?nocache=$(date +%s)"</pre>
<p style="margin:0 0 12px;font-size:13px;color:#475569;line-height:1.6">
  The random query param defeats Cloudflare\'s cache key (and most reverse-proxies). Look for <code>Surrogate-Key:</code> and <code>Cache-Tag:</code> in the cache-busted response. If they\'re there, the addon is working — existing CF cache will start carrying them as pages naturally expire or get purged. If they\'re STILL missing on a cache-busted request, check the <strong>Diagnostics</strong> card above (extension hooks should be 4 enabled rows) and the <strong>Recent activity</strong> log; uninstall + reinstall is the recovery path.
</p>

<h3>Why <code>entry-&lt;id&gt;</code> and not <code>url_title</code> or the full URL?</h3>
<ul>
  <li><strong>Stability.</strong> Entry IDs never change. URL titles change when an editor edits a slug; URLs change when you reorganize taxonomies. Tags tied to IDs survive those edits.</li>
  <li><strong>Cross-page coverage.</strong> The same entry appears on many URLs (single view, homepage, channel index, category archive, search). One <code>entry-N</code> tag intersects all of them — you don\'t maintain a separate "purge list" per page.</li>
  <li><strong>Save-event compatibility.</strong> EE\'s <code>after_channel_entry_save</code> hook hands the addon the entry id. The addon can\'t look up "every URL this entry appears on" — but it can fire a single <code>entry-N</code> tag and trust the emit side did the binding.</li>
</ul>

<h3>What gets purged when content changes</h3>
<p>When an editor hits <strong>Save</strong> on an entry (or deletes it), this addon dispatches purge for:</p>
<ul>
  <li><code>entry-&lt;id&gt;</code> — every page that featured this entry</li>
  <li><code>channel-&lt;name&gt;</code> &amp; <code>path-&lt;name&gt;</code> — channel listing pages</li>
  <li>One <code>category-&lt;cat_id&gt;</code> per category the entry belongs to — category archives</li>
  <li><code>home</code> — the homepage (entries often appear there)</li>
  <li>MSM site_id &gt; 1: all the above prefixed with <code>site-&lt;id&gt;-</code> for isolation</li>
</ul>
<p>Multiple saves in the same CP request coalesce into <strong>one</strong> POST per backend. Fire-and-forget with a 5-second timeout — a slow edge never blocks a CP save.</p>

<h3>config.php overrides (developers / config-as-code)</h3>
<p style="margin-bottom:10px">All form settings can be pinned via <code>system/user/config/config.php</code>. Pinned values win over the CP form (the 🔒 lock indicator on the field shows you which). Pick whichever backend you\'re actually using:</p>
' . $configExample . '

<h3>Backend cheat-sheet</h3>
<ul>
  <li><strong>none</strong> — emit headers, dispatch nothing. Useful while you wire templates up or run a non-purging CDN.</li>
  <li><strong>cloudflare</strong> — POSTs to the CF zone purge API with the <code>tags</code> array. Requires the Enterprise plan (Cache-Tag purging is gated). Tokens scoped to <em>Zone.Cache Purge</em> are enough.</li>
  <li><strong>fastly</strong> — POSTs to <code>api.fastly.com/service/&lt;id&gt;/purge/&lt;key&gt;</code> per key (Surrogate-Key API). Works on Standard plans. Token needs <em>purge</em> permission.</li>
  <li><strong>nivoli</strong> — one POST to your dashboard URL; Nivoli reads the tag list out of the body. Managed service with this addon pre-wired.</li>
  <li><strong>webhook</strong> — your own endpoint. Body is JSON <code>{tags, site_id, source}</code>, optional <code>X-Edge-Cache-Tags-Signature</code> HMAC if you set a secret. Great for Varnish (vcl-driven) or a custom proxy.</li>
</ul>

<h3>More</h3>
<ul>
  <li><a href="https://github.com/calimonk/ee-edge-cache-tags" target="_blank" rel="noopener">GitHub README</a> — filter hooks, full MSM behavior, backend comparison table</li>
  <li><a href="https://github.com/calimonk/ee-edge-cache-tags/blob/main/README.md#multi-site-manager-msm" target="_blank" rel="noopener">MSM section</a> — site-prefix isolation rules</li>
  <li><a href="https://github.com/calimonk/ee-edge-cache-tags/blob/main/README.md#webhook-backend" target="_blank" rel="noopener">Webhook payload spec</a> — for hand-rolled edges</li>
</ul>
</div>';
    }
}
