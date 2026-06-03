<?php
/**
 * Standalone test for the Edge_cache_tags_ext key derivation.
 *
 * Mocks just enough of ExpressionEngine so the extension's pure logic
 * methods can run without a real EE install:
 *   - ee()->config->item()       backend + config items
 *   - ee()->uri->uri_string()    request URI
 *   - ee()->TMPL->group_name     template-group context
 *   - ee()->session->cache()     explicit key store
 *   - ee()->output->set_header() header capture
 *   - ee()->extensions->last_call
 *
 * Covers:
 *   - keys_for_request: homepage, deep URI, MSM site prefix, explicit
 *     tags from the template plugin's session store
 *   - keys_for_entry: entry id, channel name, categories, MSM prefix
 *   - clean(): header-unsafe char sanitization
 *   - backend() resolution: known values + invalid fallback to 'none'
 *
 * Note on driver dispatch tests: the per-driver URL/header/body shape
 * (nivoli, fastly, cloudflare, webhook) is verified in the WP test
 * harness (wp-edge-cache-tags/tests/test-keys.php). This EE plugin uses
 * the identical driver semantics — only host-language plumbing (curl vs
 * wp_remote_post) differs. A separate EE dispatch test would need a
 * curl mock for marginal added value.
 *
 * Run:   php tests/test-keys.php
 * Exit:  0 on pass, 1 on any failure.
 */

if (!defined('BASEPATH')) { define('BASEPATH', __DIR__ . '/'); }
error_reporting(E_ALL & ~E_DEPRECATED);

// ---------------------------------------------------------------------------
// Mock ee() and its sub-objects.
// ---------------------------------------------------------------------------

class Mock_EE {
    public $config;
    public $uri;
    public $TMPL;
    public $session;
    public $output;
    public $extensions;
    public $db;
    public $headers_set = array();

    public function __construct() {
        $self = $this;
        $this->config     = new Mock_EE_Config();
        $this->uri        = new Mock_EE_Uri();
        $this->TMPL       = null; // tests opt-in to TMPL context
        $this->session    = new Mock_EE_Session();
        $this->extensions = new Mock_EE_Extensions();
        $this->output     = new Mock_EE_Output($this);
        $this->db         = new Mock_EE_DB();
    }
}
class Mock_EE_Config {
    public $items = array();
    public function item($key) { return $this->items[$key] ?? null; }
}
class Mock_EE_Uri {
    public $uri = '';
    public function uri_string() { return $this->uri; }
}
class Mock_EE_Tmpl {
    public $group_name = '';
    public $template_name = '';
}
class Mock_EE_Session {
    public $cache_store = array();
    public function cache($a, $b) {
        return $this->cache_store[$a][$b] ?? null;
    }
    public function set_cache($a, $b, $v) {
        $this->cache_store[$a][$b] = $v;
    }
}
class Mock_EE_Output {
    private $owner;
    public function __construct($owner) { $this->owner = $owner; }
    public function set_header($h) { $this->owner->headers_set[] = $h; }
}
class Mock_EE_Extensions {
    public $last_call = false;
}

/**
 * Tiny chainable mock of EE's DB layer, just enough to satisfy
 * `select(...)->where(...)->get(...)->row_array()` and `->result_array()`
 * — the only chain shapes used by channel_name_for() / category_ids_for()
 * in v2.4.19. Tables are seeded as array-of-rows via `$db->seed($table, $rows)`.
 */
class Mock_EE_DB {
    public $tables = array();
    private $_select_cols = null;
    private $_where = array();
    public function seed($table, $rows) {
        $this->tables[$table] = $rows;
    }
    public function table_exists($t) { return array_key_exists($t, $this->tables); }
    public function select($cols)    { $this->_select_cols = $cols; return $this; }
    public function where($col, $v)  { $this->_where[$col] = $v; return $this; }
    public function get($table) {
        $rows = $this->tables[$table] ?? array();
        foreach ($this->_where as $c => $v) {
            $rows = array_values(array_filter($rows, function ($r) use ($c, $v) {
                return isset($r[$c]) && (string) $r[$c] === (string) $v;
            }));
        }
        $this->_select_cols = null;
        $this->_where = array();
        return new Mock_EE_DB_Result($rows);
    }
}
class Mock_EE_DB_Result {
    private $rows;
    public function __construct($rows) { $this->rows = $rows; }
    public function row_array()    { return $this->rows[0] ?? array(); }
    public function result_array() { return $this->rows; }
}

$EE = new Mock_EE();
function ee() { global $EE; return $EE; }

// ---------------------------------------------------------------------------
// Load extension.
// ---------------------------------------------------------------------------

require __DIR__ . '/../system/user/addons/edge_cache_tags/ext.edge_cache_tags.php';

// ---------------------------------------------------------------------------
// Assertion helpers.
// ---------------------------------------------------------------------------

$TESTS_RUN = 0; $TESTS_FAIL = 0;

function assertContains_($needle, $haystack, $label) {
    global $TESTS_RUN, $TESTS_FAIL;
    $TESTS_RUN++;
    if (in_array($needle, $haystack, true)) { echo "  \033[32m✓\033[0m $label\n"; }
    else {
        $TESTS_FAIL++;
        echo "  \033[31m✗\033[0m $label\n";
        echo "      missing '$needle' in: " . json_encode($haystack) . "\n";
    }
}
function assertNotContains_($needle, $haystack, $label) {
    global $TESTS_RUN, $TESTS_FAIL;
    $TESTS_RUN++;
    if (!in_array($needle, $haystack, true)) { echo "  \033[32m✓\033[0m $label\n"; }
    else {
        $TESTS_FAIL++;
        echo "  \033[31m✗\033[0m $label\n";
        echo "      unexpected '$needle' in: " . json_encode($haystack) . "\n";
    }
}
function assertEquals_($expected, $actual, $label) {
    global $TESTS_RUN, $TESTS_FAIL;
    $TESTS_RUN++;
    if ($expected === $actual) { echo "  \033[32m✓\033[0m $label\n"; }
    else {
        $TESTS_FAIL++;
        echo "  \033[31m✗\033[0m $label\n";
        echo "      expected: " . json_encode($expected) . "\n";
        echo "      actual:   " . json_encode($actual) . "\n";
    }
}

function reset_ee() {
    global $EE;
    $EE = new Mock_EE();
    $EE->config->items['site_id'] = 1;
    // Clear v2.4.19 channel-name request cache between tests.
    $r = new ReflectionClass('Edge_cache_tags_ext');
    if ($r->hasProperty('channel_name_cache')) {
        $p = $r->getProperty('channel_name_cache');
        $p->setAccessible(true);
        $p->setValue(null, array());
    }
}

// Use reflection to invoke a private method on the extension.
function call_private($obj, $method, $args = array()) {
    $r = new ReflectionMethod($obj, $method);
    $r->setAccessible(true);
    return $r->invokeArgs($obj, $args);
}

// ---------------------------------------------------------------------------
// Section 1 — keys_for_request
// ---------------------------------------------------------------------------

echo "\n\033[1mEdge Cache Tags (EE) — key derivation\033[0m\n\n";

echo "Homepage (URI empty)\n";
reset_ee();
ee()->uri->uri = '';
$ext = new Edge_cache_tags_ext();
$k = call_private($ext, 'keys_for_request');
assertContains_('home', $k, 'emits home for empty URI');
assertContains_('all',  $k, 'emits all');
assertNotContains_('path-', $k, 'no path-* on homepage');

echo "\nDeep URI /news/some-article\n";
reset_ee();
ee()->uri->uri = 'news/some-article';
$ext = new Edge_cache_tags_ext();
$k = call_private($ext, 'keys_for_request');
assertContains_('path-news', $k, 'path-<first-segment>');
assertContains_('all',       $k, 'all');
assertNotContains_('home',   $k, 'no home on deep URI');

// v2.4.9: tmpl-<group>-<template> was dropped from auto-emit. The tag
// captured the LAST template parsed (typically the layout) not the
// URL-resolved template, making it useless as a surgical purge target.
// keys_for_request() no longer emits any tmpl-* tag — operators who
// genuinely want one can push it explicitly via the {exp:edge_cache_tags:key}
// template tag.
echo "\nTMPL group + template name NO LONGER emitted (v2.4.9)\n";
reset_ee();
ee()->uri->uri = 'news';
ee()->TMPL = new Mock_EE_Tmpl();
ee()->TMPL->group_name = 'news';
ee()->TMPL->template_name = 'index';
$ext = new Edge_cache_tags_ext();
$k = call_private($ext, 'keys_for_request');
assertNotContains_('tmpl-news-index', $k, 'tmpl-<group>-<template> NOT auto-emitted');

echo "\nExplicit keys from {exp:edge_cache_tags:key}\n";
reset_ee();
ee()->uri->uri = 'news/article-1';
ee()->session->set_cache('edge_cache_tags', 'keys',
    array('entry-123' => 'entry-123', 'channel-news' => 'channel-news'));
$ext = new Edge_cache_tags_ext();
$k = call_private($ext, 'keys_for_request');
assertContains_('entry-123',    $k, 'entry-123 from template plugin');
assertContains_('channel-news', $k, 'channel-news from template plugin');

echo "\nMSM site_id=3 prefixes keys with site-3-\n";
reset_ee();
ee()->uri->uri = '';
ee()->config->items['site_id'] = 3;
$ext = new Edge_cache_tags_ext();
$k = call_private($ext, 'keys_for_request');
assertContains_('site-3-home', $k, 'prefixed home');
assertContains_('site-3-all',  $k, 'prefixed all');
assertContains_('all',         $k, 'network-wide all also present');

// ---------------------------------------------------------------------------
// Section 2 — keys_for_entry (purge derivation)
// ---------------------------------------------------------------------------

echo "\n\n\033[1mEdge Cache Tags (EE) — purge derivation\033[0m\n\n";

// v2.4.19 — keys_for_entry resolves channel_name from channel_id via
// the channels DB lookup (NOT via the model's lazy-loaded ->Channel
// relationship, which isn't populated at after_channel_entry_save fire
// time on EE7). Categories come from category_posts via entry_id.
//
// The mock entry shape mirrors EE7's ChannelEntry model at hook fire:
// columns from exp_channel_titles are direct properties; relationships
// (->Channel, ->Categories) are NOT pre-loaded.

echo "keys_for_entry(entry_id=42, channel_id=11 -> news, cats=[3,9])\n";
reset_ee();
ee()->db->seed('channels', array(
    array('channel_id' => 11, 'channel_name' => 'news'),
    array('channel_id' => 12, 'channel_name' => 'games'),
));
ee()->db->seed('category_posts', array(
    array('entry_id' => 42, 'cat_id' => 3),
    array('entry_id' => 42, 'cat_id' => 9),
));
$entry = (object) array(
    'entry_id'   => 42,
    'channel_id' => 11,
    // Note: NO ->Channel and NO ->Categories. That's the realistic shape
    // the EE7 model lifecycle hook actually delivers.
);
$ext = new Edge_cache_tags_ext();
$k = call_private($ext, 'keys_for_entry', array($entry, array()));
assertContains_('home',         $k, 'purges home');
assertNotContains_('all',       $k, 'does NOT purge all on save');
assertContains_('entry-42',     $k, 'purges entry-<id>');
assertContains_('channel-news', $k, 'purges channel-<name> via channel_id lookup');
assertContains_('path-news',    $k, 'purges path-<channel> via channel_id lookup');
assertContains_('category-3',   $k, 'purges category-3 via category_posts lookup');
assertContains_('category-9',   $k, 'purges category-9 via category_posts lookup');

echo "\nchannel_id from \$values (not \$entry) — covers the \$entry-is-array case\n";
reset_ee();
ee()->db->seed('channels', array(
    array('channel_id' => 7, 'channel_name' => 'reviews'),
));
$ext = new Edge_cache_tags_ext();
$values = array('entry_id' => 99, 'channel_id' => 7);
$k = call_private($ext, 'keys_for_entry', array(null, $values));
assertContains_('entry-99',        $k, 'reads entry_id from \$values');
assertContains_('channel-reviews', $k, 'reads channel_id from \$values then resolves name');
assertContains_('path-reviews',    $k, 'path-<channel> from \$values path');

echo "\nMSM keys_for_entry prefixes with site-<id>-\n";
reset_ee();
ee()->config->items['site_id'] = 5;
ee()->db->seed('channels', array(
    array('channel_id' => 4, 'channel_name' => 'blog'),
));
$ext = new Edge_cache_tags_ext();
$entry = (object) array('entry_id' => 100, 'channel_id' => 4);
$k = call_private($ext, 'keys_for_entry', array($entry, array()));
assertContains_('site-5-entry-100',     $k, 'prefixed entry-100');
assertContains_('site-5-channel-blog',  $k, 'prefixed channel-blog');
assertContains_('site-5-home',          $k, 'prefixed home');
// Importantly: an MSM save on site 5 must NOT carry the unprefixed
// network-wide 'all' tag — that would cross-purge sites 1, 2, 3, 4 too.
// keys_for_REQUEST does emit unprefixed 'all' so an admin CAN do a
// network-wide nuke; keys_for_ENTRY (the auto-purge on save) intentionally
// does not, to keep MSM sites isolated under normal operation.
assertNotContains_('all',         $k, 'MSM site-5 save does NOT purge unprefixed `all` (no cross-site nuke)');
assertNotContains_('entry-100',   $k, 'MSM site-5 save does NOT purge unprefixed entry-100 (would leak)');
assertNotContains_('channel-blog', $k, 'MSM site-5 save does NOT purge unprefixed channel-blog');

echo "\nDefault site (site_id=1): keys NOT prefixed\n";
reset_ee();
ee()->config->items['site_id'] = 1;  // explicit default
ee()->db->seed('channels', array(array('channel_id' => 11, 'channel_name' => 'news')));
ee()->db->seed('category_posts', array(array('entry_id' => 7, 'cat_id' => 4)));
$ext = new Edge_cache_tags_ext();
$entry = (object) array('entry_id' => 7, 'channel_id' => 11);
$k = call_private($ext, 'keys_for_entry', array($entry, array()));
assertContains_('entry-7',      $k, 'unprefixed on default site');
assertContains_('channel-news', $k, 'unprefixed channel-news on default site');
assertContains_('category-4',   $k, 'unprefixed category-4 on default site');
assertNotContains_('site-1-entry-7', $k, 'no site-1- prefix on default site');

// REGRESSION TEST FOR THE 2026-06-03 BUG.
//
// Before v2.4.19 the code tried $entry->Channel->channel_name (lazy
// relationship — null at hook fire). It silently fell through every
// catch-Throwable and emitted only `home + entry-N`. /news/ and /games/
// archive pages never evicted on save. This test asserts the fix: when
// $entry->Channel is missing but channel_id is present, we still get
// channel-news + path-news in the output.
echo "\nv2.4.19 regression: ->Channel relationship absent, channel_id only\n";
reset_ee();
ee()->db->seed('channels', array(array('channel_id' => 13, 'channel_name' => 'news')));
$ext = new Edge_cache_tags_ext();
$entry = (object) array(
    'entry_id'   => 16639,
    'channel_id' => 13,
    // No ->Channel, no ->Categories. This is what the EE7 model emits.
);
$k = call_private($ext, 'keys_for_entry', array($entry, array()));
assertContains_('channel-news', $k, 'pre-v2.4.19 bug: archive page tag NOW emitted from channel_id alone');
assertContains_('path-news',    $k, 'pre-v2.4.19 bug: URL-segment tag NOW emitted from channel_id alone');

echo "\nDefault site (site_id=1) keys_for_request: unprefixed too\n";
reset_ee();
ee()->config->items['site_id'] = 1;
ee()->uri->uri = 'news/some-article';
$ext = new Edge_cache_tags_ext();
$k = call_private($ext, 'keys_for_request');
assertContains_('path-news', $k, 'path-news (unprefixed)');
assertContains_('all',       $k, 'all (unprefixed)');
assertNotContains_('site-1-path-news', $k, 'no site-1- prefix on default site');

// ---------------------------------------------------------------------------
// Section 3 — clean() sanitization
// ---------------------------------------------------------------------------

echo "\n\n\033[1mEdge Cache Tags (EE) — clean()\033[0m\n\n";

reset_ee();
$ext = new Edge_cache_tags_ext();
$out = call_private($ext, 'clean', array(array(
    'post-1',
    'has space-2',
    'with"quote',
    'control' . "\n" . 'newline',
    '',                                   // empty -> dropped
    str_repeat('x', 200),                 // overlong -> truncated
    'post-1',                             // dupe -> dedup
)));
assertContains_('post-1',       $out, 'plain key passes through');
assertContains_('has-space-2',  $out, 'space -> hyphen');
assertContains_('with-quote',   $out, 'quote -> hyphen');
assertContains_('control-newline', $out, 'newline -> hyphen');
assertEquals_(64, strlen($out[array_search('xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', $out)] ?? ''), 'overlong key truncated to 64');
$dupes = array_filter($out, function ($k) { return $k === 'post-1'; });
assertEquals_(1, count($dupes), 'duplicates removed');

// ---------------------------------------------------------------------------
// Section 4 — backend() resolution
// ---------------------------------------------------------------------------

echo "\n\n\033[1mEdge Cache Tags (EE) — backend()\033[0m\n\n";

reset_ee();
$ext = new Edge_cache_tags_ext();
assertEquals_('none', call_private($ext, 'backend'), 'unset -> none');

reset_ee();
ee()->config->items['edge_cache_tags_backend'] = 'fastly';
$ext = new Edge_cache_tags_ext();
assertEquals_('fastly', call_private($ext, 'backend'), 'fastly resolves');

reset_ee();
ee()->config->items['edge_cache_tags_backend'] = 'FASTLY';
$ext = new Edge_cache_tags_ext();
assertEquals_('fastly', call_private($ext, 'backend'), 'uppercase resolves (lowercased)');

reset_ee();
ee()->config->items['edge_cache_tags_backend'] = 'bogus';
$ext = new Edge_cache_tags_ext();
assertEquals_('none', call_private($ext, 'backend'), 'invalid -> none');

// v2.4.15 regression: simulate the EE installs where ee()->config->item()
// returns boolean false (not null) for missing keys. cfg() used to let
// false fall through `!== null && !== ''` and return (string) false = ''
// BEFORE the DB-row lookup — net effect: backend() always returned 'none'
// even when the DB had the right value, so no purges ever fired.
//
// Override the mock just for this test by subclassing in-place.
echo "\nv2.4.15 — cfg() handles false-vs-null sentinel\n";
reset_ee();
// Mock variant that returns false (not null) for missing keys, mirroring
// what we've seen on real EE installs in the wild.
ee()->config = new class extends Mock_EE_Config {
    public function item($key) {
        return $this->items[$key] ?? false; // <-- false, not null
    }
};
ee()->config->items['site_id'] = 2;
// Seed a DB-row-equivalent: simulate that settings_row returns backend=nivoli.
// (We can't easily mock settings_row directly since it queries ee()->db,
// so set the config item too — cfg() should prefer it; the test is that
// it doesn't bail with '' before getting to the DB fallback.)
ee()->config->items['edge_cache_tags_backend'] = 'nivoli';
$ext = new Edge_cache_tags_ext();
assertEquals_('nivoli', call_private($ext, 'backend'),
    'false-returning config does NOT prematurely return "" before DB lookup');

// Now the truly-missing case under the false-returning mock: should
// return 'none' (no config item, no DB row in this mock context).
reset_ee();
ee()->config = new class extends Mock_EE_Config {
    public function item($key) {
        return $this->items[$key] ?? false;
    }
};
ee()->config->items['site_id'] = 2;
// No edge_cache_tags_backend set.
$ext = new Edge_cache_tags_ext();
assertEquals_('none', call_private($ext, 'backend'),
    'false sentinel + no config + no DB row -> none (not crash, not false)');

// ---------------------------------------------------------------------------

echo "\n";
if ($TESTS_FAIL === 0) {
    echo "\033[32m\033[1mAll $TESTS_RUN tests passed.\033[0m\n";
    exit(0);
} else {
    echo "\033[31m\033[1m$TESTS_FAIL of $TESTS_RUN tests failed.\033[0m\n";
    exit(1);
}
