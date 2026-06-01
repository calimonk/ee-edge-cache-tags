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
    public $headers_set = array();

    public function __construct() {
        $self = $this;
        $this->config     = new Mock_EE_Config();
        $this->uri        = new Mock_EE_Uri();
        $this->TMPL       = null; // tests opt-in to TMPL context
        $this->session    = new Mock_EE_Session();
        $this->extensions = new Mock_EE_Extensions();
        $this->output     = new Mock_EE_Output($this);
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

echo "\nTMPL group + template name -> tmpl-<group>-<template>\n";
reset_ee();
ee()->uri->uri = 'news';
ee()->TMPL = new Mock_EE_Tmpl();
ee()->TMPL->group_name = 'news';
ee()->TMPL->template_name = 'index';
$ext = new Edge_cache_tags_ext();
$k = call_private($ext, 'keys_for_request');
assertContains_('tmpl-news-index', $k, 'tmpl-<group>-<template>');

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

echo "keys_for_entry(entry_id=42, channel=news, categories=[3,9])\n";
reset_ee();
$mockCat3 = (object) array('cat_id' => 3);
$mockCat9 = (object) array('cat_id' => 9);
$mockChannel = (object) array('channel_name' => 'news');
// EE's entry model exposes related models via __get magic; for the test
// the extension touches ->Channel and ->Categories as if they were
// properties. Plain stdClass with those fields works.
$entry = (object) array(
    'entry_id'     => 42,
    'channel_name' => 'news',
    'Channel'      => $mockChannel,
    'Categories'   => array($mockCat3, $mockCat9),
);
$ext = new Edge_cache_tags_ext();
$k = call_private($ext, 'keys_for_entry', array($entry, array()));
assertContains_('home',       $k, 'purges home');
assertContains_('all',        $k, 'purges all');
assertContains_('entry-42',   $k, 'purges entry-<id>');
assertContains_('channel-news', $k, 'purges channel-<name>');
assertContains_('path-news',  $k, 'purges path-<channel>');
assertContains_('category-3', $k, 'purges category-3');
assertContains_('category-9', $k, 'purges category-9');

echo "\nMSM keys_for_entry prefixes with site-<id>-\n";
reset_ee();
ee()->config->items['site_id'] = 5;
$ext = new Edge_cache_tags_ext();
$entry = (object) array(
    'entry_id'   => 100,
    'Channel'    => (object) array('channel_name' => 'blog'),
    'Categories' => array(),
);
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
$ext = new Edge_cache_tags_ext();
$entry = (object) array(
    'entry_id'   => 7,
    'Channel'    => (object) array('channel_name' => 'news'),
    'Categories' => array((object) array('cat_id' => 4)),
);
$k = call_private($ext, 'keys_for_entry', array($entry, array()));
assertContains_('entry-7',      $k, 'unprefixed on default site');
assertContains_('channel-news', $k, 'unprefixed channel-news on default site');
assertContains_('category-4',   $k, 'unprefixed category-4 on default site');
assertNotContains_('site-1-entry-7', $k, 'no site-1- prefix on default site');

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

// ---------------------------------------------------------------------------

echo "\n";
if ($TESTS_FAIL === 0) {
    echo "\033[32m\033[1mAll $TESTS_RUN tests passed.\033[0m\n";
    exit(0);
} else {
    echo "\033[31m\033[1m$TESTS_FAIL of $TESTS_RUN tests failed.\033[0m\n";
    exit(1);
}
