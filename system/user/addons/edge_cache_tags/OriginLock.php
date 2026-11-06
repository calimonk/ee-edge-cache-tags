<?php

/**
 * Edge Cache: origin lock (Nivoli Gate plan, phase 6).
 *
 * The Nivoli edge stamps every request it forwards with the site's secret
 * in `x-nivoli-origin`. When the origin refuses requests without it, only
 * Nivoli's edge gets through: nobody can skip the edge (and its shields and
 * Gate doors) by sending requests straight to the server, or through another
 * Cloudflare account pointed at it. This is the same check the plugin does
 * for WordPress and the nginx snippet does per vhost, inside EE, so it holds
 * on hosting where nobody can edit the web server's config.
 *
 * It runs in `sessions_start` (Edge_cache_tags_ext::sessions_start), before
 * EE resolves a member, a CP route or an ACT action. Configuration lives in
 * system/user/config/config.php, because one EE install can serve several
 * MSM sites and the secret belongs to the HOST a request names, not to the
 * MSM site the CP happens to have selected:
 *
 *   $config['edge_cache_tags_origin_secrets'] = [
 *       'www.example.com' => '<32 hex, from the Nivoli console>',
 *       // a host may list several (a rotation, or a second edge host that
 *       // forwards to this vhost with its own secret):
 *       'shop.example.com' => ['<current>', '<previous>'],
 *   ];
 *   $config['edge_cache_tags_origin_lock'] = 'enforce';  // or 'monitor', 'off'
 *   // optional: more addresses of this server whose own requests (without
 *   // the header) are let through, like 127.0.0.1, ::1 and SERVER_ADDR are
 *   $config['edge_cache_tags_origin_local'] = ['203.0.113.10'];
 *
 * Decisions, mirroring the nginx snippet (spoiler-migrate
 * ops/origin-secret-nginx.sh), so the two can run together without either
 * refusing what the other lets through:
 *   - the command line is never checked: cron, eecli.php, import and sitemap
 *     scripts are not requests (PHP_SAPI cli/phpdbg, or no REQUEST_METHOD);
 *   - a host with no secret configured is not checked (an MSM site that is
 *     not on Nivoli, or a host the edge does not serve);
 *   - the right secret passes (hash_equals, any of the host's secrets);
 *   - no header at all from this server itself (127.0.0.1, ::1, SERVER_ADDR,
 *     edge_cache_tags_origin_local) passes: the server's own loopback checks;
 *   - anything else is a miss: 421 Misdirected Request in enforce (not 403,
 *     which some vhosts turn into a redirect), a log line in monitor.
 * REMOTE_ADDR must be the TCP peer, or the result of nginx/Apache real-IP
 * handling that trusts only Cloudflare's ranges; a real-IP setup that trusts
 * any X-Forwarded-For would let a caller claim to be 127.0.0.1.
 *
 * Gate assertion (spec 7.6, phase 4, not built yet): the edge will add a
 * signed, short-lived per-request token for requests that passed a Gate
 * door. Its verifier plugs in as `$cfg['assertion']`: a callable taking
 * ($server, $host) and returning true (valid) or a short reason string. It
 * runs only after the secret passed, so the secret stays the first wall.
 * Null (today) means the secret alone decides.
 *
 * No EE calls in here: decide() is a pure function of $_SERVER, the config
 * and the SAPI, so tests/test-origin-lock.php runs it without EE.
 */

namespace EdgeCacheTags;

final class OriginLock
{
    const HEADER = 'HTTP_X_NIVOLI_ORIGIN';
    const MODES = ['off', 'monitor', 'enforce'];
    const SECRET_RE = '/^[a-f0-9]{32}$/';

    /** The last decision this request made, for the CP's status check. */
    public static $last = null;

    /**
     * The settings, from a config reader (EE: fn($k) => ee()->config->item($k)).
     * Returns ['mode', 'secrets' => [host => [secret, ...]], 'local' => [ip, ...],
     * 'invalid' => [what was ignored and why], 'assertion' => null].
     */
    public static function config(callable $item): array
    {
        $invalid = [];
        $secrets = [];
        $raw = $item('edge_cache_tags_origin_secrets');
        if (is_array($raw)) {
            foreach ($raw as $host => $value) {
                $h = self::normalizeHost((string) $host);
                $list = is_array($value) ? $value : [$value];
                foreach ($list as $s) {
                    $s = strtolower(trim((string) $s));
                    if ($h === '' || !preg_match(self::SECRET_RE, $s)) {
                        $invalid[] = ($h === '' ? '(empty host)' : $h) . ': not a Nivoli origin secret (32 hex characters)';
                        continue;
                    }
                    $secrets[$h][] = $s;
                }
            }
        } elseif ($raw !== null && $raw !== false && $raw !== '') {
            $invalid[] = 'edge_cache_tags_origin_secrets must be an array of host => secret';
        }
        $mode = strtolower(trim((string) ($item('edge_cache_tags_origin_lock') ?? '')));
        if ($mode === '') {
            // Secrets without a mode: watch first, as every new lock should.
            $mode = $secrets ? 'monitor' : 'off';
        } elseif (!in_array($mode, self::MODES, true)) {
            $invalid[] = 'edge_cache_tags_origin_lock "' . $mode . '" is not off, monitor or enforce; using monitor';
            $mode = 'monitor';
        }
        $local = [];
        $extra = $item('edge_cache_tags_origin_local');
        foreach ((is_array($extra) ? $extra : []) as $ip) {
            $ip = trim((string) $ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) $local[] = $ip;
            else $invalid[] = 'edge_cache_tags_origin_local: "' . $ip . '" is not an address';
        }
        return ['mode' => $mode, 'secrets' => $secrets, 'local' => $local, 'invalid' => $invalid, 'assertion' => null];
    }

    /** Lower case, no port, no trailing dot, no IPv6 brackets. */
    public static function normalizeHost(string $host): string
    {
        $h = strtolower(trim($host));
        if ($h === '') return '';
        if ($h[0] === '[') {
            $end = strpos($h, ']');
            $h = $end === false ? '' : substr($h, 1, $end - 1);
        } else {
            $colon = strrpos($h, ':');
            if ($colon !== false && substr_count($h, ':') === 1) $h = substr($h, 0, $colon);
        }
        return rtrim($h, '.');
    }

    /** The host a request names: Host, else the server name (as nginx's $host). */
    public static function hostOf(array $server): string
    {
        $h = self::normalizeHost((string) ($server['HTTP_HOST'] ?? ''));
        if ($h === '') $h = self::normalizeHost((string) ($server['SERVER_NAME'] ?? ''));
        return $h;
    }

    /**
     * pass or miss, with the reason:
     *   pass: cli, off, unlisted-host, secret, local
     *   miss: no-header, wrong-secret, assertion:<reason>
     * `act` is what the caller does: none, log (monitor) or refuse (enforce).
     */
    public static function decide(array $server, array $cfg, string $sapi = PHP_SAPI): array
    {
        $host = self::hostOf($server);
        $out = function (string $verdict, string $reason) use ($host, $cfg) {
            $act = 'none';
            if ($verdict === 'miss') $act = ($cfg['mode'] ?? 'off') === 'enforce' ? 'refuse' : 'log';
            return ['verdict' => $verdict, 'reason' => $reason, 'host' => $host, 'act' => $act];
        };
        if ($sapi === 'cli' || $sapi === 'phpdbg' || !isset($server['REQUEST_METHOD']) || $server['REQUEST_METHOD'] === '') {
            return $out('pass', 'cli');
        }
        if (($cfg['mode'] ?? 'off') === 'off') return $out('pass', 'off');
        $wanted = $cfg['secrets'][$host] ?? [];
        if (!$wanted) return $out('pass', 'unlisted-host');

        $sent = $server[self::HEADER] ?? null;
        if ($sent === null || $sent === '') {
            $peer = (string) ($server['REMOTE_ADDR'] ?? '');
            $local = array_merge(['127.0.0.1', '::1'], (array) ($cfg['local'] ?? []));
            if (!empty($server['SERVER_ADDR'])) $local[] = (string) $server['SERVER_ADDR'];
            if ($peer !== '' && in_array($peer, $local, true)) return $out('pass', 'local');
            return $out('miss', 'no-header');
        }
        $ok = false;
        foreach ($wanted as $secret) {
            if (hash_equals($secret, (string) $sent)) { $ok = true; }
        }
        if (!$ok) return $out('miss', 'wrong-secret');

        // Phase 4 hook: the Gate assertion, after the secret.
        if (isset($cfg['assertion']) && is_callable($cfg['assertion'])) {
            $a = ($cfg['assertion'])($server, $host);
            if ($a !== true) return $out('miss', 'assertion:' . (is_string($a) && $a !== '' ? $a : 'invalid'));
        }
        return $out('pass', 'secret');
    }

    /** One log line per miss: never the header's value. */
    public static function logLine(array $d, array $server): string
    {
        $uri = substr(preg_replace('/[\x00-\x1f\x7f]/', '?', (string) ($server['REQUEST_URI'] ?? '')), 0, 200);
        return '[edge_cache_tags] origin lock ' . ($d['act'] === 'refuse' ? 'refused' : 'would refuse (monitor)')
            . ': ' . $d['reason'] . ' host=' . $d['host'] . ' peer=' . ($server['REMOTE_ADDR'] ?? '-')
            . ' ' . ($server['REQUEST_METHOD'] ?? '-') . ' ' . $uri;
    }

    /** The refusal: 421, never cached, no EE page (EE has not started yet). */
    public static function refuse(): void
    {
        if (!headers_sent()) {
            http_response_code(421);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo "Misdirected request: this server answers only through its edge.\n";
        exit;
    }
}
