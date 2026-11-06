<?php
/**
 * Standalone test for the origin lock (OriginLock.php, v2.5.0).
 *
 * OriginLock::decide() is a pure function of $_SERVER, the config and the
 * SAPI, so no EE mock is needed. Covers the decisions the nginx snippet
 * makes (spoiler-migrate ops/origin-secret-nginx.sh), so the two agree, and
 * what only PHP sees: the command line, trailing-dot and port spellings of
 * the host, MSM hosts without a secret, the phase 4 assertion hook.
 *
 * Mutation-checked 2026-11-06 (each fails, passes restored):
 *   - the cli/REQUEST_METHOD exemption removed
 *   - rtrim of the trailing dot removed from normalizeHost
 *   - the local exemption applied even when a header was sent
 *   - hash_equals compared against only the first secret of a host
 *
 * Run:   php tests/test-origin-lock.php
 * Exit:  0 on pass, 1 on any failure.
 */

require __DIR__ . '/../system/user/addons/edge_cache_tags/OriginLock.php';

use EdgeCacheTags\OriginLock;

$pass = 0; $fail = 0;
function check(string $label, $actual, $expected): void {
    global $pass, $fail;
    if ($actual === $expected) { $pass++; echo "✓ $label\n"; }
    else { $fail++; echo "✗ $label\n    expected: " . var_export($expected, true) . "\n    actual:   " . var_export($actual, true) . "\n"; }
}

const S1 = '0123456789abcdef0123456789abcdef';
const S2 = 'fedcba9876543210fedcba9876543210';
const S3 = 'aaaaaaaaaaaaaaaabbbbbbbbbbbbbbbb';

$items = [
    'edge_cache_tags_origin_lock' => 'enforce',
    'edge_cache_tags_origin_secrets' => [
        'RPGgamers.com' => S1,
        'platformgamers.com:443' => [S2, S3],   // its own, plus the demo host that forwards to it
    ],
    'edge_cache_tags_origin_local' => ['185.24.220.74'],
];
$cfg = OriginLock::config(fn($k) => $items[$k] ?? false);
check('config: hosts normalised, lists kept', $cfg['secrets'], ['rpggamers.com' => [S1], 'platformgamers.com' => [S2, S3]]);
check('config: mode and local list', [$cfg['mode'], $cfg['local'], $cfg['invalid']], ['enforce', ['185.24.220.74'], []]);

$monitorCfg = array_merge($cfg, ['mode' => 'monitor']);
$req = fn(array $extra = []) => array_merge(['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'rpggamers.com', 'REMOTE_ADDR' => '172.70.1.2', 'SERVER_ADDR' => '185.24.220.74', 'REQUEST_URI' => '/'], $extra);
$d = fn(array $server, ?array $c = null, string $sapi = 'fpm-fcgi') => (function ($r) { return $r['verdict'] . ' ' . $r['reason'] . ' ' . $r['act']; })(OriginLock::decide($server, $c ?? $cfg, $sapi));

// ---- the edge ----
check('the right secret passes', $d($req(['HTTP_X_NIVOLI_ORIGIN' => S1])), 'pass secret none');
check('a second secret of the host passes', $d($req(['HTTP_HOST' => 'platformgamers.com', 'HTTP_X_NIVOLI_ORIGIN' => S3])), 'pass secret none');
check('another host\'s secret is refused', $d($req(['HTTP_X_NIVOLI_ORIGIN' => S2])), 'miss wrong-secret refuse');
check('no header from the internet is refused', $d($req()), 'miss no-header refuse');
check('an empty header counts as none', $d($req(['HTTP_X_NIVOLI_ORIGIN' => ''])), 'miss no-header refuse');
check('the secret with a suffix is refused', $d($req(['HTTP_X_NIVOLI_ORIGIN' => S1 . 'x'])), 'miss wrong-secret refuse');
check('two header copies (joined by PHP) are refused', $d($req(['HTTP_X_NIVOLI_ORIGIN' => S1 . ', ' . S1])), 'miss wrong-secret refuse');
check('monitor logs instead of refusing', $d($req(), $monitorCfg), 'miss no-header log');

// ---- host spellings ----
check('Host with a port', $d($req(['HTTP_HOST' => 'rpggamers.com:443'])), 'miss no-header refuse');
check('Host in capitals', $d($req(['HTTP_HOST' => 'RPGGAMERS.COM'])), 'miss no-header refuse');
check('Host with a trailing dot is the same host', $d($req(['HTTP_HOST' => 'rpggamers.com.'])), 'miss no-header refuse');
check('no Host: the server name decides, as nginx $host', $d($req(['HTTP_HOST' => '', 'SERVER_NAME' => 'rpggamers.com'])), 'miss no-header refuse');
check('IPv6 literal host is parsed', OriginLock::normalizeHost('[2001:db8::1]:8443'), '2001:db8::1');
check('a bare IPv6 address keeps its colons', OriginLock::normalizeHost('2001:db8::1'), '2001:db8::1');

// ---- not checked ----
check('an MSM site without a secret is not checked', $d($req(['HTTP_HOST' => 'coregamers.com'])), 'pass unlisted-host none');
check('the command line is never checked (cron, eecli.php, imports)', $d($req(), null, 'cli'), 'pass cli none');
check('phpdbg too', $d($req(), null, 'phpdbg'), 'pass cli none');
check('no REQUEST_METHOD is not a request (php-cgi from cron)', $d(['HTTP_HOST' => 'rpggamers.com']), 'pass cli none');
check('mode off checks nothing', $d($req(), array_merge($cfg, ['mode' => 'off'])), 'pass off none');

// ---- the server itself ----
check('127.0.0.1 without the header passes', $d($req(['REMOTE_ADDR' => '127.0.0.1'])), 'pass local none');
check('::1 without the header passes', $d($req(['REMOTE_ADDR' => '::1'])), 'pass local none');
check('the server\'s own address (SERVER_ADDR) passes', $d($req(['REMOTE_ADDR' => '185.24.220.74', 'SERVER_ADDR' => '185.24.220.74'])), 'pass local none');
check('a configured local address passes', $d($req(['REMOTE_ADDR' => '185.24.220.74', 'SERVER_ADDR' => '10.0.0.5'])), 'pass local none');
check('a local request WITH a wrong header is still refused (how enforcement is tested)', $d($req(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_NIVOLI_ORIGIN' => 'nope'])), 'miss wrong-secret refuse');

// ---- config mistakes ----
$c2 = OriginLock::config(fn($k) => ['edge_cache_tags_origin_secrets' => ['a.example' => 'short', 'b.example' => strtoupper(S1)], 'edge_cache_tags_origin_local' => ['not-an-ip']][$k] ?? null);
check('a malformed secret is ignored and reported; upper-case hex is accepted', [array_keys($c2['secrets']), count($c2['invalid'])], [['b.example'], 2]);
check('secrets without a mode start in monitor', $c2['mode'], 'monitor');
$c3 = OriginLock::config(fn($k) => ['edge_cache_tags_origin_secrets' => ['a.example' => S1], 'edge_cache_tags_origin_lock' => 'block'][$k] ?? null);
check('an unknown mode falls back to monitor, reported', [$c3['mode'], count($c3['invalid'])], ['monitor', 1]);
$c4 = OriginLock::config(fn($k) => null);
check('nothing configured is off', [$c4['mode'], $c4['secrets']], ['off', []]);
check('a string instead of a map is reported', count(OriginLock::config(fn($k) => $k === 'edge_cache_tags_origin_secrets' ? S1 : null)['invalid']), 1);

// ---- phase 4 hook: the Gate assertion ----
$calls = 0;
$withAssert = array_merge($cfg, ['assertion' => function ($server, $host) use (&$calls) { $calls++; return ($server['HTTP_X_NIVOLI_ASSERTION'] ?? '') === 'good' ? true : 'expired'; }]);
check('assertion valid after the secret: pass', $d($req(['HTTP_X_NIVOLI_ORIGIN' => S1, 'HTTP_X_NIVOLI_ASSERTION' => 'good']), $withAssert), 'pass secret none');
check('assertion refused after the secret: miss with its reason', $d($req(['HTTP_X_NIVOLI_ORIGIN' => S1, 'HTTP_X_NIVOLI_ASSERTION' => 'old']), $withAssert), 'miss assertion:expired refuse');
$calls = 0;
$d($req(), $withAssert);
check('the assertion is not consulted when the secret is missing', $calls, 0);

// ---- the log line ----
$line = OriginLock::logLine(OriginLock::decide($req(['HTTP_X_NIVOLI_ORIGIN' => S2, 'REQUEST_URI' => "/x\n\ty"]), $cfg, 'fpm-fcgi'), $req(['HTTP_X_NIVOLI_ORIGIN' => S2, 'REQUEST_URI' => "/x\n\ty"]));
check('the log line never carries the header value', strpos($line, S2), false);
check('the log line has no control characters', (bool) preg_match('/[\x00-\x1f]/', $line), false);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
