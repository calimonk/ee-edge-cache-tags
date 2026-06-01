<?php
/**
 * Edge Cache Tags — EE Mcp (Module Control Panel) controller.
 *
 * Modern EE 6/7 addon CP pattern: extends Mcp, which auto-routes requests
 * under addons/settings/edge_cache_tags/<route> to the matching class
 * under ControlPanel/Routes/<Route>.php based on each route's
 * $route_path. No method bookkeeping needed here — the route classes
 * carry their own paths and process() methods.
 */

if (!defined('BASEPATH')) { exit('No direct script access allowed'); }

use ExpressionEngine\Service\Addon\Mcp;

class Edge_cache_tags_mcp extends Mcp
{
    protected $addon_name = 'edge_cache_tags';
}
