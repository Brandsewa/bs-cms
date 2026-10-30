const fs = require('fs');
const p = 'starter/src/layouts/BaseLayout.astro';
let s = fs.readFileSync(p, 'utf8');

const header = `  <body>
    <header>
      <nav class="site-nav" aria-label="Main Navigation">
        <a href="/" class="site-brand">
          <span class="brand-leaf" aria-hidden="true">&#10087;</span>
          <span class="brand-text">
            <span class="brand-name">${siteMeta.name.toUpperCase()}</span>
            <span class="brand-tag">SEEDS &bull; PLANTS &bull; A GREENER TOMORROW</span>
          </span>
        </a>
        <ul class="nav-links">
          ${siteMeta.navigation.map((item) => (
            <li>
              <a href={item.url} target={item.target || undefined} rel={item.target === '_blank' ? 'noopener noreferrer' : undefined}>
                {item.label}
              </a>
            </li>
          ))}
        </ul>
        <a class="enquire-btn" href="/contact-us/">Enquire <span aria-hidden="true">&rarr;</span></a>
      </nav>
    </header>

    <main>
      <slot />
    </main>

    <footer>
      <div class="footer-grid">
        <div class="footer-brand">
          <p class="footer-logo">&#10087; ${siteMeta.name.toUpperCase()}</p>
          <p class="footer-blurb">Supplying quality flower and vegetable seeds, bulbs and planting material to growers in India and around the world.</p>
        </div>
        <div class="footer-col">
          <p class="footer-h">Quick Links</p>
          <ul>
            {siteMeta.navigation.slice(0, 5).map((item) => (
              <li><a href={item.url}>{item.label}</a></li>
            ))}
          </ul>
        </div>
        <div class="footer-col">
          <p class="footer-h">Our Range</p>
          <ul>
            <li><a href="/our-stories/">Flower Seeds</a></li>
            <li><a href="/our-stories/">Vegetable Seeds</a></li>
            <li><a href="/blueberry/">Fruit &amp; Horticulture</a></li>
          </ul>
        </div>
        <div class="footer-col footer-news">
          <p class="footer-h">Newsletter</p>
          <p>Get the latest updates, growing tips and new varieties.</p>
          <a class="footer-btn" href="/contact-us/">Get in touch &rarr;</a>
        </div>
      </div>
      <div class="footer-bottom">
        <p>&copy; {new Date().getFullYear()} ${siteMeta.name}. All rights reserved.</p>
        <div class="footer-links">
          <a href="/terms-and-condition/">Privacy Policy</a>
          <a href="/terms-and-condition/">Terms of Use</a>
          <a href="/sitemap.xml">Sitemap</a>
        </div>
      </div>
    </footer>`;

const bodyStart = s.indexOf('  <body>');
const footerEnd = s.indexOf('    <!-- Embed activation loader');
if (bodyStart === -1 || footerEnd === -1) { console.log('MARKERS MISSING'); process.exit(1); }
s = s.slice(0, bodyStart) + header + '\n\n' + s.slice(footerEnd);
fs.writeFileSync(p, s);
console.log('layout rewritten:', s.includes('enquire-btn'));
