# Changelog

All notable changes to AccelerateWP Debugger are documented here.

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
