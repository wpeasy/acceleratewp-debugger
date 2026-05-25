# Changelog

All notable changes to AccelerateWP Debugger are documented here.

## 1.0.2 — 2026-05-25

Polling, cron visibility, and corrected Action Scheduler integration after inspecting the real AccelerateWP (CloudLinux `clsop`) source.

### Added
- 5-second auto-poll of the preload table while there is active work (`pending + in-progress > 0`) or a scheduled cron run within 60 s. Stops when both conditions are false; cleans up on component unmount.
- Cron status pill in the header with four states:
  - `Processing · N queued` (animated dot) when any cache rows are `in-progress`.
  - `N queued · next in Xm Ys` with a live 1-second countdown when the Action Scheduler `rocket-preload` group has pending actions.
  - `Idle` when the hook is registered but no actions are pending.
  - `Hook not registered` (red) when AccelerateWP/WP Rocket is not active on the site.
- `cron` block on the `GET /preload` response: `hook_registered`, `scheduled`, `next_run` (unix ts), `server_time` (clock-skew anchor), `is_processing`, `source` (`action_scheduler` / `wp_cron` / `none`), `pending_actions` (count).
- `Assets::asset_version()` — every enqueued JS/CSS uses `filemtime()` of the built file as the `?ver=` query string, so every rebuild auto-busts the browser cache. Falls back to `AWPD_VERSION` if the built file is missing.

### Changed
- `POST /preload/run-cron` now triggers `do_action('action_scheduler_run_queue', 'AWPD Debugger')` — Action Scheduler's documented sync queue-runner entry point. Processes one batch (default 25 actions). Falls back to `spawn_cron()` if AS is not installed.
- `CRON_HOOK` constant corrected from the made-up `rocket_preload_process_pending_jobs` to the actual per-URL worker `rocket_preload_job_preload_url`. Cron status is now sourced from a direct `{wp_prefix}actionscheduler_actions` JOIN `{wp_prefix}actionscheduler_groups` query for fast count + earliest scheduled timestamp.
- Removed the stale-bundle footgun: previously, `?ver=AWPD_VERSION` meant the browser cached `main.js` until the plugin version was bumped, hiding new code on every rebuild.

### Documentation
- New `CLAUDE.md` section "AccelerateWP / WP Rocket Internals" recording table/column names, Action Scheduler hook names and group, and the Run-Cron strategy — knowledge gained by reading the upstream `clsop` plugin so future work doesn't have to re-discover it.

## 1.0.1 — 2026-05-25

Initial scaffold of the plugin.

### Added
- Plugin bootstrap (`acceleratewp-debugger.php`) with PSR-4 autoloader fallback (no Composer required at runtime).
- Tools → AccelerateWP Debugger admin page (`manage_options` capability).
- REST API namespace `awpd/v1`:
  - `GET /preload` — paginated rows from `{wp_prefix}wpr_rocket_cache` with URL search, status filter, sortable columns (`url`, `modified`, `last_accessed`, `status`, `id`), and a global summary (completed / pending / in-progress / failed / total / percent complete).
  - `POST /preload/run-cron` — triggers `do_action('rocket_preload_process_pending_jobs')` with a `spawn_cron()` fallback when the hook is not registered.
  - `POST /preload/rebuild` — same-origin URL check, then `rocket_clean_post()` + non-blocking `wp_remote_get` (desktop + mobile variants when the matching WP Rocket options are on).
- Svelte 5 + Vite + TypeScript build:
  - `shared.js` IIFE that exposes `window.AWPD.api.{get,post}` and boot data.
  - Admin app (`assets/dist/admin/main.js` + `style.css`) loaded as an ES module via `wp_enqueue_script_module()`.
- `PreloadTable.svelte`: debounced URL search, status dropdown, click-to-sort columns, paginated table, per-row "Rebuild Cache" action, header "Run Cron" button, status pills, inline notices.
- Documentation: `CLAUDE.md` (project properties, conventions), `CODE_STANDARDS.md` (carried in from the project template).
