const { JSDOM } = require('jsdom');
const fs = require('fs');
const { execSync } = require('child_process');

const CMS = 'https://localhost:8481';
const WP = 'https://himalayanflorica.com';
const UA = 'Mozilla/5.0 (migration)';

const jar = fs.readFileSync(process.argv[2], 'utf8');
const cookie = jar.split('\n').filter(l => l.trim() && (!l.startsWith('#') || l.startsWith('#HttpOnly_')))
  .map(l => { const p = l.split('\t').map(s => s.trim()); return `${p[5]}=${p[6]}`; }).join('; ');
let CSRF = '';

async function api(method, path, body, isForm, version) {
  const headers = { Cookie: cookie };
  if (!isForm) headers['Content-Type'] = 'application/json';
  if (CSRF) headers['X-CSRF-Token'] = CSRF;
  if (method === 'PATCH' || method === 'PUT') headers['If-Match'] = `"${version || ''}"`;
  const res = await fetch(`${CMS}/api/v1${path}`, { method, headers, body: isForm ? body : body ? JSON.stringify(body) : undefined });
  const text = await res.text();
  let json; try { json = JSON.parse(text); } catch { json = { raw: text.slice(0, 200) }; }
  return { status: res.status, ok: res.ok, json };
}
async function refreshCsrf() {
  const res = await fetch(`${CMS}/api/v1/auth/session`, { headers: { Cookie: cookie } });
  CSRF = (await res.json()).csrf_token;
}
async function uploadImage(srcUrl, name) {
  const tmp = `.migration/${name.replace(/[^\w.-]/g, '_')}`;
  try {
    execSync(`curl -s -L -A "${UA}" --max-time 60 -o "${tmp}" "${srcUrl}"`);
    if (fs.statSync(tmp).size < 1000) return null;
    const form = new FormData();
    form.append('file', new Blob([fs.readFileSync(tmp)]), name);
    const r = await api('POST', '/media', form, true);
    if (r.ok && r.json.url) return { id: r.json.id, url: r.json.url };
    console.log('  upload fail', srcUrl, JSON.stringify(r.json).slice(0, 100));
    return null;
  } catch (e) { console.log('  dl fail', srcUrl, String(e).slice(0, 60)); return null; }
}
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
function escapeHtml(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

const PAGE_CSS = '<style>\n' +
  '.hf { max-width: 980px; margin: 0 auto; padding: 24px; color: #cbd5e1; font-size: 1.02rem; line-height: 1.75; }\n' +
  '.hf .hf-hero { text-align: center; padding: 72px 16px 40px; }\n' +
  '.hf .hf-hero h1 { font-size: clamp(2rem, 4.5vw, 3rem); font-weight: 800; margin: 0 0 14px; background: linear-gradient(90deg,#f8fafc,#93c5fd); -webkit-background-clip: text; background-clip: text; color: transparent; }\n' +
  '.hf .hf-hero p { color: #94a3b8; font-size: 1.15rem; max-width: 640px; margin: 0 auto; }\n' +
  '.hf h2 { color: #f1f5f9; font-size: 1.6rem; margin: 48px 0 14px; padding-bottom: 8px; border-bottom: 1px solid rgba(148,163,184,.18); }\n' +
  '.hf h3 { color: #e2e8f0; font-size: 1.25rem; margin: 32px 0 10px; }\n' +
  '.hf p { margin: 0 0 18px; }\n' +
  '.hf ul { margin: 0 0 20px; padding-left: 22px; }\n' +
  '.hf li { margin-bottom: 8px; }\n' +
  '.hf li::marker { color: #60a5fa; }\n' +
  '.hf blockquote { margin: 24px 0; padding: 16px 22px; border-left: 3px solid #2563eb; background: rgba(37,99,235,.08); border-radius: 0 12px 12px 0; color: #e2e8f0; }\n' +
  '.hf img { max-width: 100%; border-radius: 16px; margin: 24px auto; display: block; border: 1px solid rgba(148,163,184,.15); }\n' +
  '.hf a { color: #93c5fd; }\n' +
  '.hf .hf-section { padding: 32px 0; }\n' +
  '</style>\n';

async function patchEntry(collection, id, fields) {
  const cur = (await api('GET', `/collections/${collection}/entries/${id}`)).json;
  let r = await api('PATCH', `/collections/${collection}/entries/${id}`, { ...fields }, false, cur.version);
  return r;
}

async function fixPosts() {
  const posts = JSON.parse(execSync(`curl -s "${WP}/wp-json/wp/v2/posts?per_page=50&_fields=id,slug,content,featured_media"`).toString());
  for (const p of posts) {
    await refreshCsrf();
    const bySlug = await fetch(`${CMS}/api/v1/public/collections/posts/entries/by-slug/${p.slug}`).then(r => r.json());
    const id = bySlug && bySlug.data && bySlug.data.id;
    if (!id) { console.log(' post missing:', p.slug); continue; }
    let html = p.content.rendered.replace(/<script[\s\S]*?<\/script>/g, '');
    const urls = [...new Set([...html.matchAll(/src="(https:\/\/himalayanflorica\.com\/wp-content\/uploads\/[^"]+)"/g)].map(m => m[1]))];
    let idx = 0;
    for (const u of urls) {
      const ext = (u.split('.').pop().split('?')[0] || 'jpg');
      const m = await uploadImage(u, `p2-${p.slug}-${idx++}.${ext}`);
      if (m) html = html.split(u).join(CMS + m.url);
    }
    let featuredUrl = null;
    if (p.featured_media) {
      try {
        const meta = JSON.parse(execSync(`curl -s "${WP}/wp-json/wp/v2/media/${p.featured_media}?_fields=source_url"`).toString());
        const ext = (meta.source_url.split('.').pop().split('?')[0] || 'jpg');
        const m = await uploadImage(meta.source_url, `f2-${p.slug}.${ext}`);
        if (m) featuredUrl = CMS + m.url;
      } catch {}
    }
    const fields = { html_code: PAGE_CSS + `<div class="hf"><div class="hf-section">${html}</div></div>` };
    if (featuredUrl) fields.featured_url = featuredUrl;
    const r = await patchEntry('posts', id, fields);
    console.log(' post:', p.slug, '->', r.status, urls.length + ' imgs');
    await sleep(120);
  }
}

function scrapePage(slug) {
  const out = execSync(`curl -s -L -A "${UA}" --max-time 60 "${WP}/${slug}/"`).toString();
  const dom = new JSDOM(out);
  const main = dom.window.document.querySelector('#brx-content') || dom.window.document.body;
  const blocks = [];
  for (const el of main.querySelectorAll('h2,h3,h4,p,ul,ol,img,blockquote')) {
    if (el.closest('nav,footer,form')) continue;
    const tag = el.tagName.toLowerCase();
    if (tag === 'img') {
      const src = el.getAttribute('src') || el.getAttribute('data-lazy-src');
      if (src && !src.includes('logo') && !src.startsWith('data:')) blocks.push({ type: 'img', src });
      continue;
    }
    const text = el.textContent.replace(/\s+/g, ' ').trim();
    if (!text) continue;
    if (tag === 'ul' || tag === 'ol') {
      const items = [...el.querySelectorAll('li')].filter(li => li.parentElement === el)
        .map(li => li.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean);
      if (items.length) blocks.push({ type: 'ul', items });
      continue;
    }
    if (['p', 'h2', 'h3', 'h4', 'blockquote'].includes(tag)) blocks.push({ type: tag, text });
  }
  return blocks.filter(b => b.type !== 'h1');
}
function renderBlocks(blocks) {
  let html = '';
  for (const b of blocks) {
    if (b.type === 'h2') html += '<h2>' + escapeHtml(b.text) + '</h2>\n';
    else if (b.type === 'h3' || b.type === 'h4') html += '<h3>' + escapeHtml(b.text) + '</h3>\n';
    else if (b.type === 'p') html += '<p>' + escapeHtml(b.text) + '</p>\n';
    else if (b.type === 'blockquote') html += '<blockquote>' + escapeHtml(b.text) + '</blockquote>\n';
    else if (b.type === 'ul') html += '<ul>\n' + b.items.map(i => '  <li>' + escapeHtml(i) + '</li>').join('\n') + '\n</ul>\n';
  }
  return html;
}

async function fixPages() {
  const wpPages = JSON.parse(execSync(`curl -s "${WP}/wp-json/wp/v2/pages?per_page=50&_fields=id,slug,title"`).toString());
  for (const p of wpPages) {
    if (['home', 'sample-page'].includes(p.slug)) continue;
    await refreshCsrf();
    const title = p.slug === 'blueberry' ? 'Blueberry' : p.slug === 'contact-us' ? 'Contact Us' : p.title.rendered;
    const bySlug = await fetch(`${CMS}/api/v1/public/collections/pages/entries/by-slug/${p.slug}`).then(r => r.json());
    const id = bySlug && bySlug.data && bySlug.data.id;
    if (!id) { console.log(' page missing:', p.slug); continue; }
    const rawBlocks = scrapePage(p.slug);
    let bodyImgs = '';
    for (const b of rawBlocks) {
      if (b.type === 'img') {
        const m = await uploadImage(b.src, `g2-${p.slug}-${bodyImgs.length}.webp`);
        if (m) bodyImgs += `<img src="${CMS}${m.url}" alt="" loading="lazy" />\n`;
      }
    }
    const contentHtml = renderBlocks(rawBlocks.filter(b => b.type !== 'img'));
    const lead = rawBlocks.find(b => b.type === 'p');
    const html = PAGE_CSS + '<div class="hf">' +
      `<div class="hf-hero"><h1>${escapeHtml(title)}</h1>` +
      (lead ? `<p>${escapeHtml(lead.text.slice(0, 220))}</p>` : '') +
      '</div><div class="hf-section">' + bodyImgs + contentHtml + '</div></div>';
    const r = await patchEntry('pages', id, { html_code: html });
    console.log(' page:', p.slug, '->', r.status);
    await sleep(120);
  }
}

(async () => {
  await refreshCsrf();
  await fixPosts();
  await fixPages();
  console.log('DONE2');
})().catch(e => { console.error('FATAL', e); process.exit(1); });
