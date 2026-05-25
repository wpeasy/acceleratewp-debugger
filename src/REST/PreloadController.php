<?php
/**
 * Preload REST endpoints.
 *
 * @package AB\AccelerateWPDebugger
 * @since   1.0
 */

declare(strict_types=1);

namespace AB\AccelerateWPDebugger\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined('ABSPATH') || exit;

/**
 * Exposes the WP Rocket / AccelerateWP preload cache table over REST,
 * plus actions to trigger the preload cron and rebuild a single URL.
 */
final class PreloadController {

    private const REST_NAMESPACE   = 'awpd/v1';
    private const TABLE_SUFFIX     = 'wpr_rocket_cache';
    private const CRON_HOOK        = 'rocket_preload_job_preload_url';
    private const AS_GROUP         = 'rocket-preload';
    private const ALLOWED_STATUSES = ['completed', 'in-progress', 'failed', 'pending'];
    private const ALLOWED_SORT     = ['url', 'modified', 'last_accessed', 'status', 'id'];
    private const MAX_PER_PAGE     = 500;
    private const DEFAULT_PER_PAGE = 50;

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route(self::REST_NAMESPACE, '/preload', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'get_preload'],
            'permission_callback' => [self::class, 'check_permission'],
            'args'                => [
                's'        => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'status'   => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'sort'     => ['type' => 'string', 'sanitize_callback' => 'sanitize_key'],
                'order'    => ['type' => 'string', 'sanitize_callback' => 'sanitize_key'],
                'page'     => ['type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint'],
                'per_page' => ['type' => 'integer', 'default' => self::DEFAULT_PER_PAGE, 'sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/preload/run-cron', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'run_cron'],
            'permission_callback' => [self::class, 'check_permission'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/preload/rebuild', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'rebuild_url'],
            'permission_callback' => [self::class, 'check_permission'],
            'args'                => [
                'url' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw'],
            ],
        ]);
    }

    public static function check_permission(): bool {
        return current_user_can('manage_options');
    }

    public static function get_preload(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        if (!self::table_exists($table)) {
            return rest_ensure_response([
                'success'     => false,
                'error'       => __('The WP Rocket / AccelerateWP cache table was not found.', 'acceleratewp-debugger'),
                'items'       => [],
                'total'       => 0,
                'page'        => 1,
                'per_page'    => 0,
                'total_pages' => 0,
                'summary'     => self::empty_summary(),
                'cron'        => self::get_cron_status(self::empty_summary()),
            ]);
        }

        $search   = trim((string) $request->get_param('s'));
        $status   = (string) $request->get_param('status');
        $sort     = (string) $request->get_param('sort');
        $order    = strtolower((string) $request->get_param('order'));
        $page     = max(1, (int) $request->get_param('page'));
        $per_page = (int) $request->get_param('per_page');
        $per_page = $per_page > 0 ? min(self::MAX_PER_PAGE, $per_page) : self::DEFAULT_PER_PAGE;

        $sort  = in_array($sort, self::ALLOWED_SORT, true) ? $sort : 'url';
        $order = $order === 'desc' ? 'DESC' : 'ASC';

        $where        = [];
        $where_params = [];

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[]        = '(url LIKE %s OR status LIKE %s)';
            $where_params[] = $like;
            $where_params[] = $like;
        }

        if (in_array($status, self::ALLOWED_STATUSES, true)) {
            $where[]        = 'status = %s';
            $where_params[] = $status;
        }

        $where_sql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset    = ($page - 1) * $per_page;

        if ($where_params !== []) {
            $total = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$table} {$where_sql}", ...$where_params)
            );
        } else {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        }

        $sql_rows   = "SELECT id, url, status, modified, last_accessed FROM {$table} {$where_sql} ORDER BY {$sort} {$order} LIMIT %d OFFSET %d";
        $row_params = array_merge($where_params, [$per_page, $offset]);
        $rows       = $wpdb->get_results($wpdb->prepare($sql_rows, ...$row_params), ARRAY_A);

        $summary = self::compute_summary($table);

        return rest_ensure_response([
            'success'     => true,
            'items'       => array_map([self::class, 'format_row'], is_array($rows) ? $rows : []),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => $total > 0 ? (int) ceil($total / $per_page) : 0,
            'summary'     => $summary,
            'cron'        => self::get_cron_status($summary),
        ]);
    }

    public static function run_cron(WP_REST_Request $request): WP_REST_Response {
        unset($request);

        // Preferred path: trigger Action Scheduler's queue runner, which is what
        // AccelerateWP's preloader actually uses. Processes one batch (default 25).
        if (
            class_exists('\\ActionScheduler_QueueRunner')
            || function_exists('as_get_scheduled_actions')
        ) {
            if (function_exists('set_time_limit')) {
                @set_time_limit(60);
            }
            $start = microtime(true);
            do_action('action_scheduler_run_queue', 'AWPD Debugger');
            $elapsed = round(microtime(true) - $start, 2);

            return rest_ensure_response([
                'success' => true,
                'message' => sprintf(
                    /* translators: %s: elapsed seconds */
                    __('Action Scheduler queue run dispatched (%ss). Process is up to one batch of actions.', 'acceleratewp-debugger'),
                    $elapsed
                ),
                'hook'    => 'action_scheduler_run_queue',
                'source'  => 'action_scheduler',
            ]);
        }

        // Fallback: plain wp-cron sweep.
        if (function_exists('spawn_cron')) {
            spawn_cron();
            return rest_ensure_response([
                'success' => true,
                'message' => __('Action Scheduler not detected; spawned wp-cron sweep.', 'acceleratewp-debugger'),
                'hook'    => 'wp-cron',
                'source'  => 'wp_cron',
            ]);
        }

        return new WP_REST_Response([
            'success' => false,
            'error'   => __('Could not trigger cron.', 'acceleratewp-debugger'),
        ], 500);
    }

    public static function rebuild_url(WP_REST_Request $request): WP_REST_Response {
        $url = esc_url_raw((string) $request->get_param('url'));
        if ($url === '') {
            return new WP_REST_Response([
                'success' => false,
                'error'   => __('Missing URL parameter.', 'acceleratewp-debugger'),
            ], 400);
        }

        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $url_host  = wp_parse_url($url, PHP_URL_HOST);
        if (!$home_host || !$url_host || strcasecmp((string) $home_host, (string) $url_host) !== 0) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => __('URL must belong to this site.', 'acceleratewp-debugger'),
            ], 400);
        }

        $post_id = url_to_postid($url);
        if ($post_id > 0 && function_exists('rocket_clean_post')) {
            rocket_clean_post($post_id);
        }

        $args = [
            'timeout'     => 15,
            'redirection' => 0,
            'sslverify'   => true,
            'blocking'    => false,
        ];

        if (function_exists('get_rocket_option') && (int) get_rocket_option('cache_webp') === 1) {
            $args['headers'] = [
                'Accept'      => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
                'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
            ];
        }

        wp_remote_get($url, $args);

        if (function_exists('get_rocket_option') && (int) get_rocket_option('do_caching_mobile_files') === 1) {
            $args['headers']['user-agent'] = 'Mozilla/5.0 (Linux; Android 8.0.0;) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/80.0.3987.132 Mobile Safari/537.36';
            wp_remote_get($url, $args);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => __('Cache rebuild triggered.', 'acceleratewp-debugger'),
            'url'     => $url,
        ]);
    }

    private static function table_exists(string $table): bool {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $found === $table;
    }

    /**
     * @param array{completed:int,pending:int,in-progress:int,failed:int,total:int,percent_complete:int} $summary
     * @return array{hook:string,hook_registered:bool,scheduled:bool,next_run:int|null,server_time:int,is_processing:bool,source:string,pending_actions:int}
     */
    private static function get_cron_status(array $summary): array {
        global $wpdb;

        $pending_actions = 0;
        $next_run        = null;
        $source          = 'none';
        $hook_registered = (bool) has_action(self::CRON_HOOK);

        $as_actions = $wpdb->prefix . 'actionscheduler_actions';
        $as_groups  = $wpdb->prefix . 'actionscheduler_groups';

        if (self::table_exists($as_actions) && self::table_exists($as_groups)) {
            $source = 'action_scheduler';

            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COUNT(*) AS cnt, MIN(a.scheduled_date_gmt) AS earliest
                     FROM {$as_actions} a
                     INNER JOIN {$as_groups} g ON a.group_id = g.group_id
                     WHERE g.slug = %s AND a.status = %s",
                    self::AS_GROUP,
                    'pending'
                ),
                ARRAY_A
            );

            if (is_array($row)) {
                $pending_actions = (int) ($row['cnt'] ?? 0);
                if (!empty($row['earliest']) && $row['earliest'] !== '0000-00-00 00:00:00') {
                    $ts = strtotime($row['earliest'] . ' GMT');
                    if ($ts !== false) {
                        $next_run = $ts;
                    }
                }
            }
        }

        return [
            'hook'            => self::CRON_HOOK,
            'hook_registered' => $hook_registered,
            'scheduled'       => $pending_actions > 0,
            'next_run'        => $next_run,
            'server_time'     => time(),
            'is_processing'   => $summary['in-progress'] > 0,
            'source'          => $source,
            'pending_actions' => $pending_actions,
        ];
    }

    /**
     * @return array{completed:int,pending:int,in-progress:int,failed:int,total:int,percent_complete:int}
     */
    private static function compute_summary(string $table): array {
        global $wpdb;
        $rows    = $wpdb->get_results("SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A);
        $summary = self::empty_summary();
        $total   = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $key   = (string) ($row['status'] ?? '');
            $count = (int) ($row['c'] ?? 0);
            $total += $count;
            if (array_key_exists($key, $summary)) {
                $summary[$key] = $count;
            }
        }
        $summary['total']            = $total;
        $summary['percent_complete'] = $total > 0 ? (int) ceil($summary['completed'] / $total * 100) : 0;
        return $summary;
    }

    /**
     * @return array{completed:int,pending:int,in-progress:int,failed:int,total:int,percent_complete:int}
     */
    private static function empty_summary(): array {
        return [
            'completed'        => 0,
            'pending'          => 0,
            'in-progress'      => 0,
            'failed'           => 0,
            'total'            => 0,
            'percent_complete' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,url:string,status:string,modified:string,last_accessed:string}
     */
    private static function format_row(array $row): array {
        return [
            'id'            => (int) ($row['id'] ?? 0),
            'url'           => (string) ($row['url'] ?? ''),
            'status'        => (string) ($row['status'] ?? ''),
            'modified'      => (string) ($row['modified'] ?? ''),
            'last_accessed' => (string) ($row['last_accessed'] ?? ''),
        ];
    }
}
