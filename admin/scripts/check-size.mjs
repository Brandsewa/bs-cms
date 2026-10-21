#!/usr/bin/env node
/**
 * Admin bundle size budget (implementation/phase-05 5.5, README section 7):
 * - initial JS of the admin shell must stay under 200 KB gzipped;
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
const baselinePath = join(root, 'scripts', 'size-budget.json');
const INITIAL_BUDGET_BYTES = 200 * 1024;
const REGRESSION_TOLERANCE = 1.05;

if (!existsSync(distAssets)) {
  console.error('dist/assets not found — run `npm run build -w admin` first.');
  process.exit(1);
}

// Initial bundle JS (app shell): scripts and modulepreloads loaded by index.html
const indexHtmlPath = join(dist, 'index.html');
const indexHtml = existsSync(indexHtmlPath) ? readFileSync(indexHtmlPath, 'utf8') : '';
const initialMatches = indexHtml.match(/\/admin\/assets\/[a-zA-Z0-9_\-\.]+\.js/g) ?? [];
const initialFiles = new Set(initialMatches.map((p) => p.replace('/admin/assets/', '')));

const files = readdirSync(distAssets).filter((name) => name.endsWith('.js'));
let totalGzipped = 0;
let initialGzipped = 0;
const rows = [];
for (const name of files) {
  const raw = readFileSync(join(distAssets, name));
  const gzipped = gzipSync(raw).length;
  totalGzipped += gzipped;
  const isInitial = initialFiles.has(name);
  if (isInitial) {
    initialGzipped += gzipped;
  }
  rows.push({ name, raw: raw.length, gzipped, isInitial });
}

// Fallback to totalGzipped if index.html is missing
const measuredInitialGzipped = initialFiles.size > 0 ? initialGzipped : totalGzipped;

// Gallery/mocks must not ship: these strings only exist in gallery code.
const bundle = files.map((name) => readFileSync(join(distAssets, name), 'utf8')).join('\n');
const forbidden = ['GalleryApp', 'data-theme-scope', 'mocks/fixtures'];
const leaked = forbidden.filter((marker) => bundle.includes(marker));

let baseline = { initialJsGzipBytes: 0 };
if (existsSync(baselinePath)) {
  baseline = JSON.parse(readFileSync(baselinePath, 'utf8'));
}

rows.sort((a, b) => b.gzipped - a.gzipped);
for (const row of rows) {
  const tag = row.isInitial ? ' (initial shell)' : ' (lazy route)';
  console.log(`${row.name}  raw ${(row.raw / 1024).toFixed(1)} KB  gzip ${(row.gzipped / 1024).toFixed(1)} KB${tag}`);
}
console.log(`INITIAL SHELL gzip JS: ${(measuredInitialGzipped / 1024).toFixed(1)} KB`);
console.log(`TOTAL (all routes) gzip JS: ${(totalGzipped / 1024).toFixed(1)} KB`);

const failures = [];

if (measuredInitialGzipped > INITIAL_BUDGET_BYTES) {
  failures.push(
    `Initial JS ${(measuredInitialGzipped / 1024).toFixed(1)} KB exceeds the 200 KB app-shell budget.`
  );
}

if (leaked.length > 0) {
  failures.push(`Gallery/mock code leaked into the production bundle: ${leaked.join(', ')}`);
}

if (
  baseline.initialJsGzipBytes > 0 &&
  measuredInitialGzipped > baseline.initialJsGzipBytes * REGRESSION_TOLERANCE
) {
  failures.push(
    `Initial JS regressed beyond 5%: ${(measuredInitialGzipped / 1024).toFixed(1)} KB vs baseline ` +
      `${(baseline.initialJsGzipBytes / 1024).toFixed(1)} KB.`
  );
}

if (process.argv.includes('--update')) {
  writeFileSync(
    baselinePath,
    `${JSON.stringify({ initialJsGzipBytes: measuredInitialGzipped }, null, 2)}\n`
  );
  console.log(`Baseline updated to ${measuredInitialGzipped} bytes.`);
  process.exit(0);
}

if (failures.length > 0) {
  console.error('\nSIZE BUDGET FAILED:');
  for (const failure of failures) console.error(` - ${failure}`);
  process.exit(1);
}
console.log('Size budget OK.');
