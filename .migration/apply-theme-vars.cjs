const fs = require('fs');
const { execSync } = require('child_process');

// Replace hardcoded light-palette colors in stored branding CSS with
// theme-aware CSS variables (fallback preserves the light look).
const REPLACES = [
  ['color: #3B4C40', 'color: var(--color-text, #3B4C40)'],
  ['color:#3B4C40', 'color:var(--color-text, #3B4C40)'],
  ['color: #2C3E30', 'color: var(--color-heading, #2C3E30)'],
  ['color:#2C3E30', 'color:var(--color-heading, #2C3E30)'],
  ['color: #20301F', 'color: var(--color-heading, #20301F)'],
  ['color:#20301F', 'color:var(--color-heading, #20301F)'],
  ['color: #6B7A6E', 'color: var(--color-muted, #6B7A6E)'],
  ['color:#6B7A6E', 'color:var(--color-muted, #6B7A6E)'],
  ['color: #75816F', 'color: var(--color-muted, #75816F)'],
  ['color:#75816F', 'color:var(--color-muted, #75816F)'],
  ['color: #54655A', 'color: var(--color-muted, #54655A)'],
  ['color:#54655A', 'color:var(--color-muted, #54655A)'],
  ['color: #44543F', 'color: var(--color-text, #44543F)'],
  ['color:#44543F', 'color:var(--color-text, #44543F)'],
  ['color: #1E3D2B', 'color: var(--color-primary, #1E3D2B)'],
  ['color:#1E3D2B', 'color:var(--color-primary, #1E3D2B)'],
  ['#E3DFD2', 'var(--color-border, #E3DFD2)'],
  ['rgba(148,163,184,.18)', 'var(--color-border, rgba(148,163,184,.18))'],
  ['rgba(148,163,184,.15)', 'var(--color-border, rgba(148,163,184,.15))'],
];

const tables = ['bs_c_pages', 'bs_c_posts', 'bs_c_templates'];
let sql = '';
for (const t of tables) {
  for (const [from, to] of REPLACES) {
    sql += `UPDATE \`${t}\` SET data = REPLACE(data, '${from}', '${to}');\n`;
  }
}
fs.writeFileSync('.migration/theme-vars.sql', sql);
execSync('docker cp .migration/theme-vars.sql bs-sim-ga1-db:/tmp/theme-vars.sql', { stdio: 'inherit' });
execSync('docker exec bs-sim-ga1-db sh -c "mariadb -uroot -proot bs_cms < /tmp/theme-vars.sql"', { stdio: 'inherit' });
console.log('theme-aware colors applied to', tables.join(', '));
