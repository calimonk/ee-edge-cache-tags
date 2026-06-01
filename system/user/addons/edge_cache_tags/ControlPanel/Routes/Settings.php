<?php
namespace EdgeCacheTags\ControlPanel\Routes;

use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;

/**
 * Edge Cache Tags — Settings + Diagnostics + Docs (all in one page).
 *
 * URL: ?/cp/addons/settings/edge_cache_tags/settings
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
class Settings extends AbstractRoute
{
    protected $route_path = 'settings';
    protected $cp_page_title = 'Edge Cache Tags · Settings';

    private const BACKENDS = ['none', 'nivoli', 'fastly', 'cloudflare', 'webhook'];

    public function process($id = false)
    {
        $this->addBreadcrumb('settings', 'Settings');

        $siteId = (int) ee()->config->item('site_id');
        $msg = null;
        $action = ee('CP/URL')->make('addons/settings/edge_cache_tags/settings')->compile();

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
            'ControlPanel/Routes/Settings.php',
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
.ect pre code { background:transparent; padding:0; }
.ect-docs h3 { margin:16px 0 6px; font-size:14px; font-weight:600; }
.ect-docs p { margin:6px 0; color:#475569; }
.ect-docs ul { margin:6px 0 10px 18px; color:#475569; }
.ect-docs li { margin:2px 0; }
.ect-docs a { color:#1d4ed8; }
</style>

<div class="ect">

<h2>Edge Cache Tags · site #{$siteId}</h2>
<p class="sub">Emits <code>Surrogate-Key</code> + <code>Cache-Tag</code> headers on every front-end GET, then dispatches tag-based purges to the configured backend when channel entries are saved or deleted.</p>

{$alert}

<form method="POST" action="{$action}">
<div class="ect-card">
  <div class="ect-row">
    <label class="ect-lbl">Backend</label>
    <div class="ect-field">
      {$backendSelect}
      <div class="help">Pick where to dispatch purges. Headers always emit regardless of backend. Field below changes to match.</div>
    </div>
  </div>

  {$configBlocks}

  <div class="ect-save"><button type="submit" class="ect-btn">Save settings</button></div>
</div>
</form>

{$diagBlock}
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
                $body = '<p style="margin:0;color:#475569;font-size:13px">Pages still emit <code>Surrogate-Key</code> + <code>Cache-Tag</code> headers. Your cache infrastructure handles purges externally.</p>'
                      . '<p style="margin:10px 0 0;color:#475569;font-size:13px">'
                      . '<strong>Don\'t have a managed edge cache yet?</strong> '
                      . '<a href="https://console.nivoli.com/signup" target="_blank" rel="noopener">Sign up for Nivoli</a> — free for 1 domain up to 100k requests/month, plug-and-play with this addon.</p>';
                break;
            case 'nivoli':
                $body = $this->field('nivoli_endpoint', 'Dashboard URL', 'url',
                    'https://console.nivoli.com/cache/&lt;token&gt;', $r, $overrides,
                    'The token in the URL is the auth. Don\'t have one? <a href="https://console.nivoli.com/signup" target="_blank" rel="noopener">Sign up free</a>.');
                break;
            case 'fastly':
                $body = $this->field('fastly_service', 'Service ID', 'text', '', $r, $overrides, 'The Fastly service to purge against.')
                      . $this->field('fastly_api_key', 'API token', 'password', '', $r, $overrides,
                            'Needs the <code>purge_select</code> permission.');
                break;
            case 'cloudflare':
                $body = '<p style="margin:0 0 12px;color:#78350f;background:#fef3c7;padding:8px 12px;border-radius:5px;font-size:12.5px">⚠ Cache-Tag purge is a Cloudflare <strong>Enterprise</strong>-plan feature.</p>'
                      . $this->field('cf_zone_id',   'Zone ID',   'text',     '', $r, $overrides, '')
                      . $this->field('cf_api_token', 'API token', 'password', '', $r, $overrides,
                            'Scoped to <code>Zone -> Cache Purge -> Purge</code>.');
                break;
            case 'webhook':
                $body = $this->field('webhook_url',    'Webhook URL',  'url',      'https://your-edge.example.com/purge', $r, $overrides,
                            'Receives <code>POST</code> with <code>{"tags":[…]}</code> JSON.')
                      . $this->field('webhook_secret', 'Bearer secret', 'password', '', $r, $overrides,
                            'Optional. When set, sent as <code>Authorization: Bearer …</code>.');
                break;
        }
        return '<div id="cfg-' . $kind . '" class="' . $cls . '">'
            . '<h3 style="margin:14px 0 6px;font-size:14px">' . htmlspecialchars($title) . '</h3>'
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

    private function renderDocsBlock(): string
    {
        return '<div class="ect-card ect-docs">
<h2>How it works</h2>

<h3>What gets emitted</h3>
<p>Every front-end GET that isn\'t the CP gets both headers:</p>
<pre style="background:#0f172a;color:#e2e8f0;padding:12px;border-radius:6px;font-size:12px;overflow:auto"><code>Surrogate-Key: tmpl-news-index path-news entry-123 channel-news category-9 all
Cache-Tag:     tmpl-news-index,path-news,entry-123,channel-news,category-9,all</code></pre>
<p>Auto-derived from the URI and template context. MSM sites > 1 get <code>site-&lt;id&gt;-</code> prefixed keys plus a network-wide <code>all</code>.</p>

<h3>Declaring entry-level keys in templates</h3>
<p>Inside a <code>channel:entries</code> loop, use the plugin tag so the response carries <code>entry-&lt;id&gt;</code> / <code>category-&lt;id&gt;</code>:</p>
<pre style="background:#0f172a;color:#e2e8f0;padding:12px;border-radius:6px;font-size:12px;overflow:auto"><code>{exp:channel:entries channel="news" limit="1"}
  {exp:edge_cache_tags:key name="entry-{entry_id} channel-news"}
  {categories}{exp:edge_cache_tags:key name="category-{category_id}"}{/categories}
  ...
{/exp:channel:entries}</code></pre>

<h3>What gets purged on entry save</h3>
<ul>
  <li><code>home</code>, <code>all</code> (or their <code>site-&lt;id&gt;-</code> prefixed variants on MSM &gt; 1)</li>
  <li><code>entry-&lt;id&gt;</code></li>
  <li><code>channel-&lt;name&gt;</code>, <code>path-&lt;name&gt;</code></li>
  <li>One <code>category-&lt;cat_id&gt;</code> per attached category</li>
</ul>
<p>Multiple saves in the same request coalesce into <strong>one</strong> POST per backend. Fire-and-forget with a 5-second timeout — a slow edge never blocks an EE CP save.</p>

<h3>config.php overrides</h3>
<p>Settings above are stored in <code>exp_edge_cache_tags_settings</code>. If you prefer config-as-code, set any of these in <code>system/user/config/config.php</code> and they win over the form (the locked-field indicator above shows you which are pinned):</p>
<pre style="background:#0f172a;color:#e2e8f0;padding:12px;border-radius:6px;font-size:12px;overflow:auto"><code>$config[\'edge_cache_tags_backend\']         = \'nivoli\';
$config[\'edge_cache_tags_nivoli_endpoint\'] = \'https://console.nivoli.com/cache/&lt;token&gt;\';
$config[\'edge_cache_tags_fastly_service\']  = \'...\';
$config[\'edge_cache_tags_fastly_api_key\']  = \'...\';
$config[\'edge_cache_tags_cf_zone_id\']      = \'...\';
$config[\'edge_cache_tags_cf_api_token\']    = \'...\';
$config[\'edge_cache_tags_webhook_url\']     = \'https://your-edge/purge\';
$config[\'edge_cache_tags_webhook_secret\']  = \'...\';</code></pre>

<h3>More</h3>
<ul>
  <li><a href="https://github.com/calimonk/ee-edge-cache-tags" target="_blank" rel="noopener">GitHub README</a> — filter hooks, MSM section, backend comparison table</li>
  <li><a href="https://console.nivoli.com/signup" target="_blank" rel="noopener">Sign up for Nivoli</a> — managed edge with this addon pre-wired</li>
</ul>
</div>';
    }
}
