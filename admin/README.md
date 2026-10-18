# BS CMS Admin

Prebuilt static React admin application.

## Stack
- Vite + React + TypeScript
- Tailwind CSS + shadcn/ui (Radix primitives)
- TanStack Query, TanStack Router, TanStack Table
- Tiptap rich-text editor

## Building for release (shared hosting)

The admin is built to static files that PHP serves from `/admin` (see
`core/src/Http/Kernel.php` — the SPA fallback serves `core/public/admin/index.html`).
`tools/build-release` does not build the admin; the local equivalent of the
CI release build is:

```bash
npm ci
npm run build            # builds @bs/ui (verification) and admin/dist
# copy into the PHP document root (git-ignored, never committed):
mkdir -p core/public/admin
cp -r admin/dist/. core/public/admin/
bash tools/build-release <version>
```

Build constraints:
- `base: './'` — relative asset paths so the app works under `/admin/`.
- Hashed filenames (`dist/assets/index-<hash>.js`).
- No runtime server; CSP is `script-src 'self'`, so the no-flash theme
  bootstrap is an external file (`admin/public/theme-init.js`), not an
  inline script.
- The gallery route (`/admin/_gallery`) and `gallery/mocks/` exist only in
  development builds; `admin/scripts/check-size.mjs` fails if any gallery or
  mock code leaks into `dist/`.

## Development

```bash
npm run dev -w admin     # Vite dev server, http://localhost:5173 (redirects to /_gallery)
npm test -w admin        # vitest
npm run test:axe -w admin
```

## Bundle budget

App shell < 200 KB gzipped JS (measured baseline in
`scripts/size-budget.json`, enforced in CI via `npm run size-budget`).
