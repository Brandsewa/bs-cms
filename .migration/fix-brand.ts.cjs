const fs = require('fs');
const p = 'starter/src/lib/brand.ts';
let s = fs.readFileSync(p, 'utf8');

const broken = [
  '  const push = (name: string, value: string, transform?: (v: string) => string) => {',
  '    if (!value) return;',
  '    lines.push();',
  '  };',
].join('\n');
const fixed = [
  '  const push = (name: string, value: string, transform?: (v: string) => string) => {',
  '    if (!value) return;',
  '    lines.push(`  ${name}: ${transform ? transform(value) : value};`);',
  '  };',
].join('\n');
if (s.includes(broken)) { s = s.replace(broken, fixed); }

s = s.replace(
  "push('--color-text-secondary', get('body_text'), (v) => );",
  "push('--color-text-secondary', get('body_text'), (v) => `color-mix(in srgb, ${v} 78%, var(--color-bg))`);",
);
s = s.replace(
  "push('--color-muted', get('body_text'), (v) => );",
  "push('--color-muted', get('body_text'), (v) => `color-mix(in srgb, ${v} 55%, var(--color-bg))`);",
);
s = s.replace(
  "push('--color-border', get('body_text'), (v) => );",
  "push('--color-border', get('body_text'), (v) => `color-mix(in srgb, ${v} 16%, var(--color-bg))`);",
);

fs.writeFileSync(p, s);
console.log(
  'push fixed:', s.includes('lines.push(`  ${name}'),
  '| transforms:', (s.match(/color-mix\(in srgb/g) || []).length,
  '| no broken:', !s.includes('(v) => );'),
);
