const fs = require('fs');
const cookie = fs.readFileSync('.migration/cj3.txt', 'utf8').split('\n').filter(l => l.trim() && (!l.startsWith('#') || l.startsWith('#HttpOnly_'))).map(l => { const p = l.split('\t').map(s => s.trim()); return p[5] + '=' + p[6]; }).join('; ');

const shared = `<style>
.blt { max-width:1080px; margin:0 auto; padding:24px; color:#cbd5e1; }
.blt .badge { display:inline-block; font-size:.72rem; letter-spacing:.08em; text-transform:uppercase; color:#93c5fd; background:rgba(37,99,235,.12); border:1px solid rgba(37,99,235,.3); border-radius:999px; padding:5px 14px; margin:48px 0 14px; }
.blt h1 { font-size:clamp(2rem,4.5vw,3rem); font-weight:800; margin:0 0 14px; background:linear-gradient(90deg,#f8fafc,#93c5fd); -webkit-background-clip:text; background-clip:text; color:transparent; }
.blt .sub { color:#94a3b8; font-size:1.1rem; margin:0 0 40px; max-width:680px; }
.blt .cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:24px; }
.blt .card { display:flex; flex-direction:column; background:rgba(30,41,59,.55); border:1px solid rgba(148,163,184,.15); border-radius:18px; overflow:hidden; text-decoration:none; transition:transform .2s, border-color .2s; }
.blt .card:hover { transform:translateY(-4px); border-color:rgba(37,99,235,.5); }
.blt .card img { width:100%; height:190px; object-fit:cover; margin:0; border:none; border-radius:0; }
.blt .card .b { padding:22px; display:flex; flex-direction:column; flex:1; }
.blt .card h3 { margin:0 0 8px; font-size:1.08rem; color:#f1f5f9; line-height:1.4; }
.blt .card p { margin:0 0 14px; color:#94a3b8; font-size:.9rem; flex:1; line-height:1.55; }
.blt .card .m { color:#64748b; font-size:.78rem; }
.blt .article img { max-width:100%; border-radius:14px; margin:26px auto; display:block; }
.blt .article h2 { color:#f1f5f9; font-size:1.5rem; margin:40px 0 12px; border-bottom:1px solid rgba(148,163,184,.18); padding-bottom:8px; }
.blt .article h3 { color:#e2e8f0; font-size:1.2rem; margin:28px 0 10px; }
.blt .article p, .blt .article li { color:#cbd5e1; line-height:1.8; font-size:1.02rem; }
.blt .article a { color:#93c5fd; }
.blt .article ul { padding-left:22px; }
.blt .article li { margin-bottom:8px; }
.blt .article li::marker { color:#60a5fa; }
.blt .back { display:inline-block; margin-top:44px; color:#93c5fd; text-decoration:none; font-weight:600; }
</style>`;

const blogArchive = shared + `<div class="blt">
  <span class="badge">Blog</span>
  <h1>{{label}}</h1>
  <p class="sub">Farming guides, cultivation stories, and horticulture insights from across India.</p>
  <div class="cards">
    {{#each items}}
    <a class="card" href="{{url}}">
      {{#if featured_url}}<img src="{{featured_url}}" alt="{{title}}" loading="lazy" />{{/if}}
      <div class="b"><h3>{{title}}</h3><p>{{excerpt}}</p><div class="m">{{date}}</div></div>
    </a>
    {{/each}}
  </div>
</div>`;

const blogSingle = shared + `<div class="blt">
  <span class="badge">Journal</span>
  <h1>{{title}}</h1>
  {{#if featured_url}}<img src="{{featured_url}}" alt="{{title}}" style="width:100%;border-radius:18px;margin:0 0 30px;" />{{/if}}
  <div class="article">{{{html_code}}}</div>
  <a class="back" href="/blog/">← Back to all articles</a>
</div>`;

(async () => {
  const csrf = (await (await fetch('https://localhost:8481/api/v1/auth/session', { headers: { Cookie: cookie } })).json()).csrf_token;
  const headers = { Cookie: cookie, 'Content-Type': 'application/json', 'X-CSRF-Token': csrf };
  for (const [slug, html] of [['blog-archive', blogArchive], ['blog-single', blogSingle]]) {
    const e = await fetch('https://localhost:8481/api/v1/public/collections/templates/entries/by-slug/' + slug).then(r => r.json());
    const id = e.data.id, ver = e.data.version || 1;
    const res = await fetch('https://localhost:8481/api/v1/collections/templates/entries/' + id, {
      method: 'PATCH', headers: { ...headers, 'If-Match': `"${ver}"` }, body: JSON.stringify({ html_code: html }),
    });
    console.log(slug, '->', res.status);
  }
})().catch(e => { console.error(e); process.exit(1); });
