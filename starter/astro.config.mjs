import { defineConfig } from 'astro/config';

const siteUrl = (process.env.SITE_URL || 'https://bskickstarter.com').replace(/\/+$/, '');

export default defineConfig({
  site: siteUrl,
  trailingSlash: 'always',
  output: 'static',
  build: {
    format: 'directory'
  }
});
