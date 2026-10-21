#!/usr/bin/env node
/**
 * Admin bundle size budget (implementation/phase-05 5.5, README section 7):
 * - INITIAL JS of the admin shell (shell + entry preloads + login + dashboard)
 *   must stay strictly under 200 KB gzipped;
 * - all subsequent feature screens (settings, users, roles, editor, media)
 *   must be lazily loaded route chunks;
 * - a committed baseline guards against regressions > 5%;
 * - gallery and mock code must never appear in the production bundle.
 *
 * Usage:
 *   node scripts/check-size.mjs            # measure + enforce
 *   node scripts/check-size.mjs --update   # write a new baseline after an
 *                                          # intentional improvement
 */
import { readFileSync, readdirSync, writeFileSync, existsSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const dist = join(root, 'dist');
const distAssets = join(dist, 'assets');
const distHtml = join(dist, 'index.html');
const baselinePath = join(root, 'scripts', 'size-budget.json');
const INITIAL_BUDGET_BYTES = 200 * 1024;
const REGRESSION_TOLERANCE = 1.05;

if (!existsSync(distAssets) || !existsSync(distHtml)) {
  console.error('dist/assets or dist/index.html not found — run `npm run build -w admin` first.');
  process.exit(1);
}

const allJsFiles = readdirSync(distAssets).filter((name) => name.endsWith('.js'));
const htmlContent = readFileSync(distHtml, 'utf8');

// 1. Identify Initial Load Assets from index.html (entry script + modulepreloads)
const initialAssetNames = new Set(
  [...htmlContent.matchAll(/\/assets\/([^"'\s>]+\.js)/g)].map((m) => m[1])
);

// Include the default entry routes in the initial app-shell load (shell + login + dashboard)
for (const file of allJsFiles) {
  if (file.startsWith('login-') || file.startsWith('dashboard-')) {
    initialAssetNames.add(file);
  }
}

let initialGzipped = 0;
let totalGzipped = 0;
const initialRows = [];
const routeRows = [];

for (const name of allJsFiles) {
  const raw = readFileSync(join(distAssets, name));
  const gzipped = gzipSync(raw).length;
  totalGzipped += gzipped;

  if (initialAssetNames.has(name)) {
    initialGzipped += gzipped;
    initialRows.push({ name, raw: raw.length, gzipped });
  } else {
    routeRows.push({ name, raw: raw.length, gzipped });
  }
}

// Gallery/mocks must not ship: these strings only exist in gallery code.
const bundle = allJsFiles.map((name) => readFileSync(join(distAssets, name), 'utf8')).join('\n');
const forbidden = ['GalleryApp', 'data-theme-scope', 'mocks/fixtures'];
const leaked = forbidden.filter((marker) => bundle.includes(marker));

let baseline = { initialJsGzipBytes: 0 };
if (existsSync(baselinePath)) {
  baseline = JSON.parse(readFileSync(baselinePath, 'utf8'));
}

console.log('=== INITIAL LOAD (Shell + Preloads + Login + Dashboard) ===');
initialRows.sort((a, b) => b.gzipped - a.gzipped);
for (const row of initialRows) {
  console.log(`  ${row.name.padEnd(35)} raw ${(row.raw / 1024).toFixed(1).padStart(5)} KB  gzip ${(row.gzipped / 1024).toFixed(1).padStart(5)} KB`);
}
console.log(`INITIAL GZIP LOAD: ${(initialGzipped / 1024).toFixed(1)} KB (Budget: <= 200.0 KB)\n`);

console.log('=== LAZY ROUTE CHUNKS ===');
routeRows.sort((a, b) => b.gzipped - a.gzipped);
for (const row of routeRows) {
  console.log(`  ${row.name.padEnd(35)} raw ${(row.raw / 1024).toFixed(1).padStart(5)} KB  gzip ${(row.gzipped / 1024).toFixed(1).padStart(5)} KB`);
}
console.log(`TOTAL GZIP (All Chunks Combined): ${(totalGzipped / 1024).toFixed(1)} KB\n`);

const failures = [];

if (initialGzipped > INITIAL_BUDGET_BYTES) {
  failures.push(
    `Initial JS ${(initialGzipped / 1024).toFixed(1)} KB exceeds the 200 KB app-shell budget.`
  );
}

if (leaked.length > 0) {
  failures.push(`Gallery/mock code leaked into the production bundle: ${leaked.join(', ')}`);
}

if (
  baseline.initialJsGzipBytes > 0 &&
  initialGzipped > baseline.initialJsGzipBytes * REGRESSION_TOLERANCE
) {
  failures.push(
    `Initial JS regressed beyond 5%: ${(initialGzipped / 1024).toFixed(1)} KB vs baseline ` +
      `${(baseline.initialJsGzipBytes / 1024).toFixed(1)} KB.`
  );
}

if (process.argv.includes('--update')) {
  writeFileSync(
    baselinePath,
    `${JSON.stringify({ initialJsGzipBytes: initialGzipped }, null, 2)}\n`
  );
  console.log(`Baseline updated to ${initialGzipped} bytes.`);
  process.exit(0);
}

if (failures.length > 0) {
  console.error('SIZE BUDGET FAILED:');
  for (const failure of failures) console.error(` - ${failure}`);
  process.exit(1);
}
console.log('Size budget OK.');
