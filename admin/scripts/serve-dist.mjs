#!/usr/bin/env node
/**
 * Tiny static server for the built admin with the same SPA fallback the PHP
 * front controller provides (core/src/Http/Kernel.php): any /admin/* path
 * that is not a real file serves index.html, so deep links boot the app.
 * Used by the Playwright deep-link test until M3 runs the release zip.
 *
 *   node scripts/serve-dist.mjs [port]   # default 4173
 */
import { createServer } from 'node:http';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { extname, join, normalize } from 'node:path';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', 'dist');
const port = Number.parseInt(process.argv[2] ?? '4173', 10);

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.woff2': 'font/woff2',
  '.json': 'application/json',
};

const server = createServer((req, res) => {
  const url = new URL(req.url ?? '/', 'http://localhost');
  let pathname = decodeURIComponent(url.pathname);

  // Only paths under /admin are served (mirrors the production mount point).
  if (pathname.startsWith('/admin/')) pathname = pathname.slice('/admin'.length);
  else if (pathname === '/admin') pathname = '/';

  const resolved = normalize(join(root, pathname));
  if (!resolved.startsWith(root)) {
    res.writeHead(403).end();
    return;
  }

  const file = existsSync(resolved) && statSync(resolved).isFile() ? resolved : join(root, 'index.html');
  if (!existsSync(file)) {
    res.writeHead(500, { 'content-type': 'text/plain' });
    res.end('dist/index.html missing — run `npm run build -w admin` first.');
    return;
  }

  res.writeHead(200, { 'content-type': MIME[extname(file)] ?? 'application/octet-stream' });
  res.end(readFileSync(file));
});

server.listen(port, '127.0.0.1', () => {
  console.log(`Serving admin dist at http://127.0.0.1:${port}/admin/`);
});
