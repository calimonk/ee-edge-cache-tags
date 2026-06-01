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

        if (ee('Request')->method() === 'POST') {
            $this->save($siteId);
            $msg = 'Settings saved.';
        }

        $row = $this->loadSettings($siteId);
        $configOverrides = $this->configOverrides();
        $diag = $this->runDiagnostics($siteId, $row, $configOverrides);

        $this->setBody($this->render($siteId, $row, $configOverrides, $action, $msg, $diag));
        return $this;
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

        // 2. Extension hooks registered + enabled. Should be 3:
        //    template_post_parse, after_channel_entry_save, after_channel_entry_delete
        $hookRows = (int) ee()->db->where([
            'class'   => 'Edge_cache_tags_ext',
            'enabled' => 'y',
        ])->count_all_results('extensions');
        $checks[] = [
            'label'  => 'Extension hooks',
            'ok'     => $hookRows >= 3,
            'detail' => $hookRows . ' enabled row(s) — expected 3 (template_post_parse, after_channel_entry_save, after_channel_entry_delete)',
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
        $docsBlock = $this->renderDocsBlock();

        return <<<HTML
<style>
.ect { font-size:14px; line-height:1.55; color:#0f172a; }
.ect h2 { margin:0 0 4px; font-size:16px; font-weight:600; color:#0f172a; }
.ect .sub { color:#64748b; font-size:13px; margin:0 0 14px; }
.ect-alert { background:#d1fae5; color:#065f46; padding:9px 12px; border-radius:6px; margin-bottom:14px; font-weight:500; }
.ect-card { background:white; border:1px solid #e2e8f0; border-radius:8px; padding:18px 22px 22px; margin-bottom:14px; }
.ect-row { display:grid; grid-template-columns:200px 1fr; gap:14px 22px; align-items:start; margin-bottom:14px; padding-bottom:14px; border-bottom:1px solid #f1f5f9; }
.ect-row:last-of-type { margin-bottom:0; padding-bottom:0; border-bottom:0; }
.ect-row > label.ect-lbl { font-weight:600; color:#1e293b; font-size:14px; padding-top:7px; }
.ect-row > label.ect-lbl small { display:block; font-weight:400; color:#94a3b8; font-size:12px; margin-top:2px; }
.ect-field input[type=text], .ect-field input[type=url], .ect-field input[type=password], .ect-field select { width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13.5px; box-sizing:border-box; background:white; color:#0f172a; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
.ect-field input:focus, .ect-field select:focus { outline:2px solid #1d4ed8; outline-offset:-1px; border-color:#1d4ed8; }
.ect-field .help { color:#64748b; font-size:12.5px; margin-top:6px; line-height:1.5; }
.ect-field .locked { background:#f1f5f9; color:#64748b; cursor:not-allowed; }
.ect-field .lock-note { display:inline-block; margin-top:6px; padding:3px 8px; background:#fef3c7; color:#78350f; font-size:11.5px; border-radius:4px; font-weight:500; }
.ect-btn { display:inline-block; padding:9px 18px; border-radius:6px; text-decoration:none; font-weight:600; font-size:13px; background:#1d4ed8; color:white !important; border:1px solid transparent; cursor:pointer; }
.ect-btn:hover { background:#1e40af; }
.ect-save { margin-top:18px; }
.ect-backend-cfg { display:none; }
.ect-backend-cfg.active { display:block; }
.ect-diag-summary { display:inline-block; padding:3px 10px; border-radius:4px; font-size:12px; font-weight:600; margin-left:8px; }
.ect-diag-summary.ok { background:#d1fae5; color:#065f46; }
.ect-diag-summary.bad { background:#fee2e2; color:#991b1b; }
.ect-diag-row { display:grid; grid-template-columns:28px 240px 1fr; align-items:center; padding:8px 10px; border-bottom:1px solid #f1f5f9; gap:12px; font-size:13px; }
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
.ect code { background:#f1f5f9; padding:1px 5px; border-radius:3px; font-size:12.5px; color:#1e293b; }
/* Inside a dark <pre> block the inline-code styling above made the text
   invisible — same color as background. Reset to inherit so the pre's
   light-on-dark color applies. */
.ect pre code { background:transparent; padding:0; color:inherit; }
.ect pre { color:#e2e8f0; }
.ect-docs h3 { margin:16px 0 6px; font-size:14px; font-weight:600; }
.ect-docs p { margin:6px 0; color:#475569; }
.ect-docs ul { margin:6px 0 10px 18px; color:#475569; }
.ect-docs li { margin:2px 0; }
.ect-docs a { color:#1d4ed8; }
</style>

<div class="ect">

<h2>Edge Cache Tags · site #{$siteId}</h2>
<p class="sub" style="font-size:14px;line-height:1.6;color:#334155;max-width:760px">
  <strong style="color:#0f172a">Surgical cache invalidation for ExpressionEngine.</strong>
  Every page gets tagged with what it actually contains — the entry ID, channel name, category IDs, template group. When an editor publishes an entry, only the pages featuring it (the entry page, the homepage, its channel index, its category archives) get cleared from your edge cache. Not the whole site.
  <span style="color:#64748b">Works with Fastly, Cloudflare Enterprise, Nivoli, or your own edge via webhook.</span>
</p>

{$alert}

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

{$diagBlock}
{$activityBlock}
{$docsBlock}

</div>

<script>
(function () {
  var sel = document.getElementById('ect-backend');
  if (!sel) return;
  function syncCfgVisibility() {
    var v = sel.value;
    document.querySelectorAll('.ect-backend-cfg').forEach(function (el) {
      el.classList.toggle('active', el.id === 'cfg-' + v);
    });
  }
  sel.addEventListener('change', syncCfgVisibility);
  syncCfgVisibility();
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

                      // Hero — lead with the key insight: Cloudflare DOESN\'T
                      // cache HTML out of the box. That\'s the differentiator,
                      // not the cache itself.
                      . '<div style="padding:26px 28px 22px;border-bottom:1px solid rgba(255,255,255,0.14)">'
                      . '<div style="font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:#fbbf24;margin-bottom:10px">⚡ Full-page HTML caching on Cloudflare</div>'
                      . '<h3 style="margin:0 0 12px;color:white;font-size:24px;font-weight:700;line-height:1.2;letter-spacing:-0.015em">Cloudflare doesn\'t cache HTML.<br><span style="color:#fbbf24">Nivoli does.</span></h3>'
                      . '<p style="margin:0 0 10px;font-size:14.5px;line-height:1.62;opacity:0.95;max-width:640px">'
                      . 'Out of the box, Cloudflare caches your CSS, JS, and images at the edge — but every HTML pageview still round-trips to your origin server. '
                      . '<strong>Nivoli adds the missing layer:</strong> full-page HTML caching on Cloudflare\'s global network. '
                      . 'Every visitor hits Cloudflare; only <strong style="color:#fbbf24">cache MISSes touch origin</strong>.</p>'
                      . '<p style="margin:0;font-size:14px;line-height:1.6;opacity:0.92;max-width:640px">'
                      . '<strong>And it stays correct.</strong> When an editor saves an entry in EE, this addon fires tag purge — only the pages that show that entry refresh, never the whole cache. '
                      . 'You get speed <em>and</em> consistency. No "wait 30 minutes for the homepage to update" UX.</p>'
                      . '</div>'

                      // Featured: 404 / 5xx management — these are what
                      // operators don\'t realize they want until they have
                      // them. Foreground them as the lead differentiator pair.
                      . '<div style="padding:22px 28px 18px;background:rgba(0,0,0,0.16)">'
                      . '<div style="font-size:11px;font-weight:700;letter-spacing:0.10em;text-transform:uppercase;opacity:0.78;margin-bottom:14px">Operational dashboards that ship with the cache</div>'
                      . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">'

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

                      // Secondary differentiators
                      . '<div style="padding:14px 28px 18px;background:rgba(0,0,0,0.20)">'
                      . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">'
                      . '<div style="background:rgba(52,211,153,0.14);border:1px solid rgba(52,211,153,0.38);padding:10px 13px;border-radius:6px;font-size:12.5px;line-height:1.45">'
                      .   '<strong style="color:#6ee7b7;display:block;margin-bottom:2px;font-size:13px">Attack blackholing</strong>'
                      .   '<span style="opacity:0.88">.env / .git / wp-admin / xmlrpc probes return 404 at the edge. Origin never sees them.</span>'
                      . '</div>'
                      . '<div style="background:rgba(96,165,250,0.16);border:1px solid rgba(96,165,250,0.42);padding:10px 13px;border-radius:6px;font-size:12.5px;line-height:1.45">'
                      .   '<strong style="color:#93c5fd;display:block;margin-bottom:2px;font-size:13px">Deploy alerts</strong>'
                      .   '<span style="opacity:0.88">Webhook on 5xx storms (broken release) or mass-404 spikes (deleted route).</span>'
                      . '</div>'
                      . '<div style="background:rgba(244,114,182,0.14);border:1px solid rgba(244,114,182,0.38);padding:10px 13px;border-radius:6px;font-size:12.5px;line-height:1.45">'
                      .   '<strong style="color:#f9a8d4;display:block;margin-bottom:2px;font-size:13px">URL rules</strong>'
                      .   '<span style="opacity:0.88">Redirect old URLs / block specific patterns at the edge — never round-trip to origin.</span>'
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
        $input = '<input type="' . $h($type) . '" name="' . $h($name) . '" value="' . $h($val) . '" placeholder="' . $placeholder . '" class="' . $cls . '" ' . $attr . '>';
        $lockNote = $locked ? '<span class="lock-note">🔒 set via config.php</span>' : '';
        $helpHtml = $help ? '<div class="help">' . $help . ' ' . $lockNote . '</div>' : ($locked ? '<div class="help">' . $lockNote . '</div>' : '');
        return '<div class="ect-row" style="border:0;padding:6px 0;margin:0">'
            . '<label class="ect-lbl">' . $h($label) . '</label>'
            . '<div class="ect-field">' . $input . $helpHtml . '</div>'
            . '</div>';
    }

    private function renderDiagBlock(array $diag): string
    {
        $h = fn($v) => htmlspecialchars((string) $v);
        $allOk = true;
        $rowsHtml = '';
        foreach ($diag['checks'] as $c) {
            if (!$c['ok']) $allOk = false;
            $icon = $c['ok']
                ? '<span class="ect-diag-tick ok">✓</span>'
                : '<span class="ect-diag-tick bad">!</span>';
            $rowsHtml .= '<div class="ect-diag-row ' . ($c['ok'] ? 'ok' : 'bad') . '">'
                . $icon
                . '<div class="ect-diag-label">' . $c['label'] . '</div>'
                . '<div class="ect-diag-detail">' . $h($c['detail']) . '</div>'
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

        $emittedExample = $code([
            '<span style="color:#94a3b8">Surrogate-Key:</span> <span style="color:#7dd3fc">tmpl-news-index</span> <span style="color:#7dd3fc">path-news</span> <span style="color:#fcd34d">entry-123</span> <span style="color:#fcd34d">channel-news</span> <span style="color:#fcd34d">category-9</span> <span style="color:#86efac">all</span>',
            '<span style="color:#94a3b8">Cache-Tag:</span>     <span style="color:#7dd3fc">tmpl-news-index</span>,<span style="color:#7dd3fc">path-news</span>,<span style="color:#fcd34d">entry-123</span>,<span style="color:#fcd34d">channel-news</span>,<span style="color:#fcd34d">category-9</span>,<span style="color:#86efac">all</span>',
        ]);

        $singleEntryExample = $code([
            '<span style="color:#94a3b8">// templates/news/_view.html  — single-entry view</span>',
            '<span style="color:#fcd34d">{exp:channel:entries channel="news" limit="1"}</span>',
            '  <span style="color:#86efac">{exp:edge_cache_tags:key name="entry-{entry_id} channel-news"}</span>',
            '  <span style="color:#fcd34d">{categories}</span><span style="color:#86efac">{exp:edge_cache_tags:key name="category-{category_id}"}</span><span style="color:#fcd34d">{/categories}</span>',
            '',
            '  <span style="color:#94a3b8">&lt;article&gt;...&lt;/article&gt;</span>',
            '<span style="color:#fcd34d">{/exp:channel:entries}</span>',
        ]);

        $listingExample = $code([
            '<span style="color:#94a3b8">// templates/news/index.html  — listing page</span>',
            '<span style="color:#fcd34d">{exp:channel:entries channel="news" limit="20"}</span>',
            '  <span style="color:#86efac">{exp:edge_cache_tags:key name="entry-{entry_id}"}</span>',
            '  <span style="color:#94a3b8">&lt;a href="{url_title_path=\\"news\\"}"&gt;{title}&lt;/a&gt;</span>',
            '<span style="color:#fcd34d">{/exp:channel:entries}</span>',
            '<span style="color:#86efac">{exp:edge_cache_tags:key name="channel-news"}</span>',
        ]);

        $configExample = $code([
            '<span style="color:#86efac">$config</span>[<span style="color:#fcd34d">\'edge_cache_tags_backend\'</span>]         = <span style="color:#fcd34d">\'nivoli\'</span>;',
            '<span style="color:#86efac">$config</span>[<span style="color:#fcd34d">\'edge_cache_tags_nivoli_endpoint\'</span>] = <span style="color:#fcd34d">\'https://console.nivoli.com/cache/&lt;token&gt;\'</span>;',
            '',
            '<span style="color:#94a3b8">// or for Fastly:</span>',
            '<span style="color:#86efac">$config</span>[<span style="color:#fcd34d">\'edge_cache_tags_fastly_service\'</span>]  = <span style="color:#fcd34d">\'SU1Z0...\'</span>;',
            '<span style="color:#86efac">$config</span>[<span style="color:#fcd34d">\'edge_cache_tags_fastly_api_key\'</span>]  = <span style="color:#fcd34d">\'...\'</span>;',
        ]);

        return '<div class="ect-card ect-docs">

<h2 style="font-size:18px;margin-bottom:6px">How tag-based cache invalidation works</h2>
<p style="font-size:14px;color:#475569;margin:0 0 18px">If you\'ve only used URL-based purges before ("when /blog/foo updates, purge /blog/foo"), tag-based is the upgrade. The page advertises what it CONTAINS; the edge purges by content identity. Read this once and the template patterns below click immediately.</p>

<h3>The chain in one sentence</h3>
<p>Every page emits a list of tags describing what\'s on it (<code>entry-123</code>, <code>category-9</code>, <code>channel-news</code>, <code>home</code>). When an editor saves entry 123 in the CP, this addon POSTs a purge for the tag <code>entry-123</code>. The edge cache evicts <strong>every page</strong> that carried that tag — the single-entry view, the homepage list that featured it, the category archive that included it — all in one call. No URL enumeration. No "forgot to purge the homepage" bugs.</p>

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
<p style="margin-top:8px;font-size:13px;color:#475569">Now if someone edits this entry, OR changes its categories, OR deletes it — this page evicts.</p>

<p style="margin-top:18px"><strong>Pattern 2 — listing / index page</strong> (e.g. <code>/news/</code> with 20 latest entries)</p>
' . $listingExample . '
<p style="margin-top:8px;font-size:13px;color:#475569">Listing pages tag EACH entry they display, plus the channel. Saving any one of those 20 entries purges this listing. Adding a 21st entry also purges it (the <code>channel-news</code> tag fires on every save in that channel).</p>

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
<p style="margin-bottom:10px">All form settings can be pinned via <code>system/user/config/config.php</code>. Pinned values win over the CP form (the 🔒 lock indicator on the field shows you which):</p>
' . $configExample . '

<h3>More</h3>
<ul>
  <li><a href="https://github.com/calimonk/ee-edge-cache-tags" target="_blank" rel="noopener">GitHub README</a> — filter hooks, full MSM behavior, backend comparison table</li>
  <li><a href="https://github.com/calimonk/ee-edge-cache-tags/blob/main/README.md#multi-site-manager-msm" target="_blank" rel="noopener">MSM section</a> — site-prefix isolation rules</li>
  <li><a href="https://console.nivoli.com/signup" target="_blank" rel="noopener">Sign up for Nivoli</a> — managed edge with this addon pre-wired</li>
</ul>
</div>';
    }
}
