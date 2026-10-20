import { describe, it, expect } from 'vitest';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { execSync } from 'node:child_process';

describe('Real build failures and fixture isolation', () => {
  const starterRoot = path.resolve(__dirname, '..');
  const distDir = path.join(starterRoot, 'dist');

  it('fails build with non-zero exit code when CMS API fails and leaves no fabricated posts', () => {
    // Point CMS_URL to an invalid unreachable host
    let buildError: any = null;
    try {
      execSync('npx astro build', {
        cwd: starterRoot,
        env: {
          ...process.env,
          BS_CMS_URL: 'http://127.0.0.1:59999/unreachable',
          CMS_URL: 'http://127.0.0.1:59999/unreachable',
          BS_USE_FIXTURES: '0',
        },
        stdio: 'pipe',
      });
    } catch (err) {
      buildError = err;
    }

    expect(buildError).not.toBeNull();
    expect(buildError.status).not.toBe(0);
    const stderr = buildError.stderr?.toString() || buildError.stdout?.toString() || '';
    expect(stderr).toMatch(/Fatal error fetching collection 'posts'/);
  }, 30000);

  it('builds zero post pages and no benchmark strings when live API returns empty list', () => {
    // When building against bskickstarter.com with 0 published posts:
    execSync('npx astro build && npx tsx scripts/generate-artifacts.mjs', {
      cwd: starterRoot,
      env: {
        ...process.env,
        BS_CMS_URL: 'https://bskickstarter.com',
        CMS_URL: 'https://bskickstarter.com',
        BS_USE_FIXTURES: '0',
      },
      stdio: 'pipe',
    });

    expect(fs.existsSync(distDir)).toBe(true);
    expect(fs.existsSync(path.join(distDir, 'index.html'))).toBe(true);
    expect(fs.existsSync(path.join(distDir, 'blog/index.html'))).toBe(true);

    // Assert zero post pages were generated
    const blogItems = fs.readdirSync(path.join(distDir, 'blog'));
    const postDirs = blogItems.filter((item) => item.startsWith('post-'));
    expect(postDirs.length).toBe(0);

    // Assert no benchmark strings exist anywhere in dist
    function checkDir(dir: string) {
      for (const file of fs.readdirSync(dir)) {
        const full = path.join(dir, file);
        if (fs.statSync(full).isDirectory()) {
          checkDir(full);
        } else {
          const content = fs.readFileSync(full, 'utf8');
          expect(content).not.toMatch(/Benchmark Post Title/);
          expect(content).not.toMatch(/01BENCH/);
        }
      }
    }
    checkDir(distDir);

    // Blog index should contain the clean empty state message
    const blogIndexHtml = fs.readFileSync(path.join(distDir, 'blog/index.html'), 'utf8');
    expect(blogIndexHtml).toMatch(/No posts have been published yet\./);
  }, 45000);

  it('only generates benchmark fixtures when BS_USE_FIXTURES=1 is explicitly set', () => {
    execSync('npx astro build && npx tsx scripts/generate-artifacts.mjs', {
      cwd: starterRoot,
      env: {
        ...process.env,
        BS_USE_FIXTURES: '1',
        BENCHMARK_POSTS: '2',
      },
      stdio: 'pipe',
    });

    expect(fs.existsSync(path.join(distDir, 'blog/post-1/index.html'))).toBe(true);
    expect(fs.existsSync(path.join(distDir, 'blog/post-2/index.html'))).toBe(true);
    expect(fs.existsSync(path.join(distDir, 'blog/post-3/index.html'))).toBe(false);

    const post1Html = fs.readFileSync(path.join(distDir, 'blog/post-1/index.html'), 'utf8');
    expect(post1Html).toMatch(/Benchmark Post Title 1/);
  }, 45000);
});
