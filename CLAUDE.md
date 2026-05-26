# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

WordPress plugin (`skaut-google-drive-gallery`) that renders galleries on a WP site from images/videos stored in Google Drive. Distributed via the WordPress.org plugin directory. Supports PHP 5.6+ and WordPress 4.9.6+ — language features and APIs must respect those floors.

## Build / lint / test

`npm run build` is the single entry point — it composes Gulp (CSS, vendored npm/Composer deps, PHP file copy) with four Vite bundles in parallel and writes everything to `dist/`. The plugin always runs out of `dist/`; `src/` is never loaded directly (see [tests/bootstrap.php](tests/bootstrap.php#L33) — even PHPUnit requires the dist).

Common commands:

- `npm run build` — full build into `dist/`. Required before `lint` (Phan reads `dist/vendor`) and before `test`.
- `npm run lint` — runs PHPCS, PHPMD, PHPStan, Phan, ESLint, `tsc --noEmit`, Stylelint in parallel. Individual linters via `npm run lint:php:phpcs`, `lint:ts:typecheck`, etc.
- `npm run test` — runs PHPUnit. Requires the WordPress test suite at `$WP_TESTS_DIR` (defaults to `/tmp/wordpress-tests-lib`); install via `./bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]`.
- Single PHPUnit test: `vendor/bin/phpunit --filter <TestName>` (the `pretest`/`posttest` hooks around `npm run test:php:phpunit` rewrite the Composer autoloader — see "PHP-Scoper" below — so prefer running the binary directly when iterating on one test).

## Architecture

### PHP entry & wiring

[src/php/skaut-google-drive-gallery.php](src/php/skaut-google-drive-gallery.php) is the WP plugin header and the only file with `require_once` chains — it loads everything explicitly (no Composer autoloading of plugin code) and instantiates [Main](src/php/class-main.php). `Main` wires the seven subsystems: `Shortcode`, `Block` (Gutenberg), `Page` (AJAX endpoint that paginates gallery contents), `Gallery` (AJAX endpoint for the initial gallery payload), `Video_Proxy`, `Settings_Pages`, `TinyMCE_Plugin`.

The Google API surface is wrapped by two layers: [API_Client](src/php/class-api-client.php) handles raw Google client setup, batching, and Guzzle promises; [API_Facade](src/php/class-api-facade.php) exposes the domain-specific calls (`get_directory_id`, list images/videos, etc.) returning `PromiseInterface`. Frontend code calls the facade, never the raw client.

[Options](src/php/class-options.php) is a static container of typed `*_Option` objects (one per WP option); `Options_Proxy` adapts these for shortcode/block attribute overrides per gallery instance.

### PHP-Scoper vendor isolation (critical)

All Composer dependencies are prefixed into the `Avpvh\Vendor\` namespace via [scoper.inc.php](scoper.inc.php) during `npm run build`, written to `dist/vendor/`. This avoids conflicts when other WP plugins ship different versions of Google's API client / Guzzle / Monolog.

Implications:
- Imports of vendor code use `Avpvh\Vendor\Google\Client`, `Avpvh\Vendor\GuzzleHttp\Promise\PromiseInterface`, etc. — never the upstream namespace.
- The autoloader is patched (see [gulpfile.js](gulpfile.js#L44) `build:deps:composer:autoloader`) so the classmap entries are also prefixed, and `$GLOBALS['__composer_autoload_files']` is renamed to `$GLOBALS['__composer_autoload_files_Avpvh_Vendor']`.
- `npm run test:php:phpunit` has a `pretest` hook that removes `vendor/google` and re-dumps the autoloader; `posttest` runs `composer install` to restore it. If a test run is interrupted, run `composer install` manually before further work.
- Static analysis (Phan, PHPStan) scans `dist/vendor`, not `vendor` — that's why `npm run build` must precede lint.

### Frontend bundles

Four independent Vite configs build IIFE bundles (no shared chunks) via the shared [vite-builder.config.ts](vite-builder.config.ts) helper:
- `block.vite.config.ts` → Gutenberg block editor UI (`src/ts/frontend/block.ts`)
- `shortcode.vite.config.ts` → runtime gallery rendering (`src/ts/frontend/shortcode.ts`)
- `root_selection.vite.config.ts` → admin Drive folder picker (`src/ts/admin/root_selection.ts`)
- `tinymce.vite.config.ts` → classic editor shortcode dialog (`src/ts/admin/tinymce.ts`)

WordPress globals (`@wordpress/*`, `jquery`, `tinymce`, `imagelightbox`, `justified-layout`) are declared as Rollup `external` so each bundle expects them on `window` rather than inlining them.

### Tests

Only one PHPUnit test currently lives in [tests/unit/](tests/unit/). Bootstrap loads the WordPress test framework, then loads the plugin from `dist/` — so tests exercise the scoped/built code, not `src/` directly.

## Conventions

- WordPress Coding Standards via PHPCS (`phpcs.xml`) — class files are `class-foo-bar.php`, classes are `Foo_Bar`. The `WordPress.NamingConventions.PrefixAllGlobals` prefix is `Avpvh`.
- `SlevomatCodingStandard.Complexity.Cognitive` caps cognitive complexity at 10.
- TypeScript runs `strict` plus `exactOptionalPropertyTypes`, `noPropertyAccessFromIndexSignature`, `noUnusedLocals/Parameters`. ESLint adds `@typescript-eslint/strict-type-checked` + `stylistic-type-checked`.
- Browser support follows `@wordpress/browserslist-config` (enforced by `eslint-plugin-compat` and `stylelint-no-unsupported-browser-features`).
