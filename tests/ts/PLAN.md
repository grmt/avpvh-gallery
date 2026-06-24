# TypeScript Test Plan

## Overview

Three-layer strategy covering pure logic, browser integration, and full-stack smoke tests.

---

## Layer 1 — Vitest unit tests (`tests/ts/unit/`)

**What:** Pure TypeScript functions with no DOM or AJAX dependencies.

**Target functions** — extracted from `Shortcode.ts` to `src/ts/frontend/shortcode/shortcode-utils.ts`:
- `getNodeForIndex(nodes, index)` — linked-list traversal, returns `{ node, localIndex }`.
- `formatExifString(exif)` — EXIF → display string.
- `updateNodeCounter(el, localIndex, node)` — writes to `.pswp__counter` DOM element.

**Why extract:** all three are currently `private`/`private static` with no `this` use. Extracting them to a utility module makes them importable by tests without class instantiation or type-casting hacks.

**Test cases:**
- `getNodeForIndex`: single node, two-node chain, boundary index, index past end
- `formatExifString`: undefined, partial EXIF, full EXIF, zero values (ISO 0, orientation 0)
- `updateNodeCounter`: counter text format `"{n} / {total}"`, unknown total (`-1`)

**Setup:**
- Add `vitest` + `@vitest/coverage-v8` + `jsdom` to devDependencies.
- Add `vitest.config.ts` at repo root.
- Add `test:ts:unit` script to `package.json`.

---

## Layer 2 — Puppeteer E2E tests (`tests/ts/e2e/`)

**What:** Headless browser tests that exercise PhotoSwipe + the linked-list navigation against a real WordPress + Google Drive stack.

**Two modes via `BASE_URL` env var:**
- `BASE_URL=https://avpvh.nl` → live site (always available, no setup)
- `BASE_URL=http://localhost:8888` → local `wp-env` instance (requires credential sync, see below)

**Test scenarios (`navigation.mjs`):**
1. **Forward boundary crossing** — open lightbox on the last photo of `01-Opgravingen/2002 Tienen`, navigate forward, assert:
   - PhotoSwipe stays open (no close+reopen flicker detected via `pswp--open` class)
   - Counter shows `1 / N` where N is the known total of `2003 Raversijde`
   - Page URL updates to the new folder path
2. **Backward boundary at index 0** — open lightbox on the first photo of a folder, navigate back, assert:
   - Lightbox closes and reopens (close+reopen detected via DOM events)
   - Lands on the last photo of the previous folder
3. **Counter accuracy** — at a known position within a folder, assert counter text matches `localIndex+1 / total`
4. **Slideshow stall recovery** — start slideshow at last preloaded item, assert it auto-advances after preload completes

**Scripts kept as plain `.mjs` files** (no test runner needed) so they can be run directly:
```
node tests/ts/e2e/navigation.mjs [BASE_URL]
```

**Add `test:ts:e2e` script to `package.json`** (skipped unless `AVPVH_RUN_E2E=1`).

---

## Layer 3 — Local `wp-env` full-stack (future)

**What:** Run Layer 2 scenarios against a local WordPress instance with real Google Drive API calls.

**Credential sync** (one-time manual step, not automated):
```bash
# On live server — export plugin options
wp option list --search='avpvh_*' --format=json > avpvh-options.json

# Locally — import into wp-env instance
wp @local option import avpvh-options.json
```

**Fixture recording** (alternative to real credentials):
- Run Puppeteer with request interception recording mode: intercept `admin-ajax.php` calls and save responses as JSON fixtures under `tests/ts/fixtures/`.
- Replay mode replaces live AJAX calls with fixture responses, making tests fully offline and deterministic.
- Recording command: `RECORD_FIXTURES=1 node tests/ts/e2e/navigation.mjs`

**wp-env additions needed:**
- A test page with the gallery shortcode pointed at the test Google Drive root.
- The shortcode hash (embedded by PHP in the page) is read from the live DOM by Puppeteer — no hardcoding.

---

## File layout (target state)

```
tests/ts/
  PLAN.md                          ← this file
  unit/
    shortcode-utils.test.ts        ← Vitest unit tests
  e2e/
    navigation.mjs                 ← Puppeteer boundary-crossing tests (plain Node, no runner)
    fixtures/                      ← recorded AJAX responses (future)
      gallery-2002-tienen.json
      page-2003-raversijde-1.json
src/ts/frontend/shortcode/
  shortcode-utils.ts               ← extracted pure functions (Layer 1 prerequisite)
  Shortcode.ts                     ← imports from shortcode-utils.ts
vitest.config.ts                   ← (future, Layer 1)
```

---

## Execution order

1. **(Now)** Run `tests/ts/e2e/navigation.mjs` against live site to validate linked-list implementation.
2. **(Soon)** Extract pure functions → `shortcode-utils.ts`, write Vitest unit tests.
3. **(Later)** Add fixture recording, wire up `wp-env` full-stack mode.
