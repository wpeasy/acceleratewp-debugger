# CLAUDE.md

## Project Properties

- **Plugin Name:** AccelerateWP Debugger
- **Version:** 1.0.1
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
