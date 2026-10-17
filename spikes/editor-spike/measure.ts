import { chromium } from 'playwright';
import { createServer } from 'vite';

async function measure() {
  const server = await createServer({
    root: process.cwd(),
    server: { port: 5188 }
  });
  await server.listen();

  const browser = await chromium.launch({
    headless: true,
    executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
  });
  const page = await browser.newPage();

  console.log('Navigating to editor benchmark page...');
  await page.goto('http://localhost:5188');

  // Wait for benchmark results
  await page.waitForFunction(() => (window as any).__BENCHMARK_RESULTS__ !== undefined, { timeout: 30000 });

  const results = await page.evaluate(() => (window as any).__BENCHMARK_RESULTS__);
  console.log('BENCHMARK RESULTS:', JSON.stringify(results, null, 2));

  await browser.close();
  await server.close();
}

measure().catch(err => {
  console.error(err);
  process.exit(1);
});
