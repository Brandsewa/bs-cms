const fs = require('fs');
const { execSync } = require('child_process');

const cookie = fs.readFileSync('.migration/cj3.txt', 'utf8').split('\n')
  .filter(l => l.trim() && (!l.startsWith('#') || l.startsWith('#HttpOnly_')))
  .map(l => { const p = l.split('\t').map(s => s.trim()); return `${p[5]}=${p[6]}`; }).join('; ');
const csrf = execSync('curl -sk -b .migration/cj3.txt https://localhost:8481/api/v1/auth/session')
  .toString().match(/"csrf_token":"([^"]*)"/)[1];

const color = (label) => ({ type: 'color', label, required: false });
const text = (label) => ({ type: 'text', label, required: false });

const radiusOptions = { options: ['none', 'small', 'medium', 'large', 'pill'] };
const radius = (label) => ({ type: 'enum', label, required: false, config: radiusOptions });

// H1-H6 size fields for a given device key
function headingSizes(device, labelPrefix) {
  const fields = {};
  for (let i = 1; i <= 6; i++) fields[`fs_h${i}_${device}`] = text(`${labelPrefix} H${i} Size`);
  return fields;
}

const fields = {
  // existing
  logo: { type: 'media', label: 'Primary Logo', required: false },
  logo_dark: { type: 'media', label: 'Logo Variant (dark backgrounds)', required: false },
  favicon: { type: 'media', label: 'Favicon', required: false },
  logo_alt: text('Logo Alt Text'),

  // General
  site_width: text('Site Width (px or %)'),

  // Light theme colors
  color_primary: color('Primary'),
  color_secondary: color('Secondary'),
  color_accent: color('Accent'),
  color_background: color('Page Background'),
  color_heading: color('Heading Text'),
  color_text: color('Body Text'),
  color_button_bg: color('Button Background'),
  color_button_text: color('Button Text'),
  color_card_bg: color('Card Background'),
  color_card_text: color('Card Text'),

  // Dark theme colors
  dark_primary: color('Primary'),
  dark_secondary: color('Secondary'),
  dark_accent: color('Accent'),
  dark_background: color('Page Background'),
  dark_heading: color('Heading Text'),
  dark_body_text: color('Body Text'),
  dark_button_bg: color('Button Background'),
  dark_button_text: color('Button Text'),
  dark_card_bg: color('Card Background'),
  dark_card_text: color('Card Text'),

  // Border & radius
  button_radius: radius('Button Radius'),
  button_border_width: text('Button Border Width'),
  button_border_color: color('Button Border Color'),
  card_radius: radius('Card Radius'),
  card_border_width: text('Card Border Width'),
  card_border_color: color('Card Border Color'),
  image_radius: radius('Image Radius'),
  image_border_width: text('Image Border Width'),
  image_border_color: color('Image Border Color'),

  // Typography
  font_heading: {
    type: 'enum', label: 'Heading Font', required: false,
    config: { options: ['serif-display', 'sans-modern', 'sans-humanist', 'serif-body', 'mono-technical'] },
  },
  font_body: {
    type: 'enum', label: 'Body Font', required: false,
    config: { options: ['sans-modern', 'sans-humanist', 'serif-body', 'mono-technical'] },
  },
  ...headingSizes('desktop', 'Desktop'),
  ...headingSizes('tablet', 'Tablet'),
  ...headingSizes('mobile', 'Mobile'),
};

const body = JSON.stringify({
  handle: 'branding',
  label_singular: 'Branding',
  label_plural: 'Branding',
  description: 'Reusable brand identity: logo, colors, typography and shape',
  kind: 'single',
  is_system: true,
  title_field: 'title',
  slug_source: 'title',
  fields,
});

let out = execSync(
  `curl -sk -b .migration/cj3.txt -X PUT -H "Content-Type: application/json" -H "X-CSRF-Token: ${csrf}" -d @- "https://localhost:8481/api/v1/collections/branding"`,
  { input: body, maxBuffer: 1024 * 1024 },
).toString();
let res = JSON.parse(out);
if (res.confirm_token) {
  const body2 = JSON.parse(body); body2.confirm_token = res.confirm_token;
  out = execSync(
    `curl -sk -b .migration/cj3.txt -X PUT -H "Content-Type: application/json" -H "X-CSRF-Token: ${csrf}" -d @- "https://localhost:8481/api/v1/collections/branding"`,
    { input: JSON.stringify(body2), maxBuffer: 1024 * 1024 },
  ).toString();
  res = JSON.parse(out);
}
console.log('status fields:', res.status || out.slice(0, 200));
console.log('field count:', res.fields ? Object.keys(res.fields).length : 'n/a');
