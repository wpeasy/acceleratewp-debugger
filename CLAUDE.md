# CLAUDE.md

## Project Properties

- **Plugin Name:** AccelerateWP Debugger
- **Version:** 1.0.2
- **Description:** Svelte-based admin interface for managing AccelerateWP Preloader.
- **Minimum WordPress:** 6.5
- **Minimum PHP:** 8.0
- **PHP Namespace:** `AB\AccelerateWPDebugger`
- **Constants Prefix:** `AWPD_`
- **Textdomain:** `acceleratewp-debugger`
- **REST API Namespace:** `awpd/v1`
- **JS Global:** `AWPD` (window.AWPD)
- **CSS Prefix:** `.awpd-`

## Required Reading

| File | Purpose |
|------|---------|
| **CODE_STANDARDS.md** | Naming conventions, security, PHP/JS/CSS standards |

## Tech Stack

- **Backend:** WordPress plugin (PHP 8.0+)
- **Admin UI:** Svelte 5 (runes), TypeScript, Vite
- **Target:** AccelerateWP Preloader management

## Conventions

- Svelte 5 runes syntax only — no Svelte 4 `export let`, `$:`, or store autosubscriptions in component setup.
- All admin enqueues gated on `is_admin()` — this is an admin-only plugin. Nothing loads on the frontend unless explicitly required for AccelerateWP Preloader integration.
- TypeScript strict mode for all Svelte/TS code.
- REST endpoints under `awpd/v1`, permission-gated on `manage_options` unless an endpoint documents otherwise.
- Asset cache-busting uses `filemtime()` of the built file via `Assets::asset_version()` — bumping `AWPD_VERSION` is NOT what forces a browser reload after a rebuild; the file mtime is. PHP opcache can hide changes to `Assets.php` itself on dev machines — restart Local if a fresh build doesn't reach the browser.

## AccelerateWP / WP Rocket Internals

The plugin source (`clsop` / WP Rocket) is **not bundled** — the debugger only reads from tables/hooks it knows about. Discovered constants:

| Thing | Value | Source |
|---|---|---|
| Cache table | `{wp_prefix}wpr_rocket_cache` | `inc/Engine/Preload/Database/Tables/Cache.php` |
| Cache columns | `id, url, status, modified, last_accessed, is_locked` | same |
| Action Scheduler group | `rocket-preload` | `inc/Engine/Preload/Controller/Queue.php` |
| Per-URL preload AS hook | `rocket_preload_job_preload_url` | same |
| Sitemap parser AS hook | `rocket_preload_job_parse_sitemap` | same |
| Initial sitemap AS hook | `rocket_preload_job_load_initial_sitemap` | same |
| Check-finished AS hook | `rocket_preload_job_check_finished` | same |

**Run Cron** fires `do_action('action_scheduler_run_queue', 'AWPD Debugger')` — this is AS's documented sync queue-runner entry point. Processes one batch (default 25). Avoid synchronously processing the whole queue from the REST handler — each URL is an HTTP fetch and the REST request will time out on large queues.

**Cron status** is sourced directly from `{wp_prefix}actionscheduler_actions` + `{wp_prefix}actionscheduler_groups` (status=pending, group=rocket-preload). The Action Scheduler PHP API (`as_get_scheduled_actions`) returns hydrated objects which is wasteful for a count + earliest-date lookup.

**Zombie in-progress rows:** AccelerateWP can leave rows stuck in `status=in-progress` after a crashed worker. `Cache::revert_in_progress()` clears them on next sitemap load. Hook `rocket_preload_revert_old_in_progress_rows` was removed in WP Rocket 3.12.5.
