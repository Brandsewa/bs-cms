import { FormPageSkeleton } from '../components/page-skeletons';
import { FC, useCallback, useEffect, useState } from 'react';
import {
  Breadcrumbs,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Input,
  Label,
  PageContainer,
  PageHeader,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
  problemToast,
  successToast,
} from '@bs/ui';
import { apiClient } from '../lib/api';
import { MediaPickerModal, normalizeMedia, unwrapMediaList, type MediaItem } from '../media/media-picker-modal';

/* ------------------------------------------------------------------ */
/* Field metadata                                                      */
/* ------------------------------------------------------------------ */

const FONT_OPTIONS = [
  { value: 'serif-display', label: 'Editorial Serif (Georgia-like)' },
  { value: 'sans-modern', label: 'Modern Sans (system)' },
  { value: 'sans-humanist', label: 'Humanist Sans (Segoe-like)' },
  { value: 'serif-body', label: 'Classic Serif (Palatino-like)' },
  { value: 'mono-technical', label: 'Monospace' },
];

const RADIUS_OPTIONS = [
  { value: 'none', label: 'None' },
  { value: 'small', label: 'Small' },
  { value: 'medium', label: 'Medium' },
  { value: 'large', label: 'Large' },
  { value: 'pill', label: 'Pill' },
];

const THEME_ROWS: { rows: Array<{ fields: string[]; labels: string[]; cols?: number }>; title: string } = {
  title: '',
  rows: [
    { fields: ['color_primary', 'color_secondary', 'color_accent'], labels: ['Primary', 'Secondary', 'Accent'] },
    { fields: ['color_background'], labels: ['Page Background'], cols: 3 },
    { fields: ['color_heading', 'color_body_text'], labels: ['Heading Text', 'Body Text'] },
    { fields: ['color_button_bg', 'color_button_text'], labels: ['Button Background', 'Button Text'] },
    { fields: ['color_card_bg', 'color_card_text'], labels: ['Card Background', 'Card Text'] },
  ],
};

const DARK_PREFIX = 'dark_';
const DARK_ROWS = {
  rows: THEME_ROWS.rows.map((r) => ({
    fields: r.fields.map((f) => (f === 'color_body_text' ? 'dark_body_text' : DARK_PREFIX + f.replace('color_', ''))),
    labels: r.labels,
    cols: r.cols,
  })),
};

const BORDER_BLOCKS = [
  { key: 'button', label: 'Buttons', radius: 'button_radius', bw: 'button_border_width', bc: 'button_border_color' },
  { key: 'card', label: 'Cards', radius: 'card_radius', bw: 'card_border_width', bc: 'card_border_color' },
  { key: 'image', label: 'Images', radius: 'image_radius', bw: 'image_border_width', bc: 'image_border_color' },
];

const DEVICES = [
  { key: 'desktop', label: 'Desktop' },
  { key: 'tablet', label: 'Tablet' },
  { key: 'mobile', label: 'Mobile' },
];

/* ------------------------------------------------------------------ */
/* State                                                               */
/* ------------------------------------------------------------------ */

interface BrandingState {
  [key: string]: string;
}

const EMPTY: BrandingState = {};

const LOGO_FIELDS: Array<{ key: string; label: string }> = [
  { key: 'logo', label: 'Primary Logo' },
  { key: 'logo_dark', label: 'Dark Background Variant' },
  { key: 'favicon', label: 'Favicon' },
];

type LogoKey = 'logo' | 'logo_dark' | 'favicon';

export const BrandingView: FC = () => {
  const [state, setState] = useState<BrandingState>(EMPTY);
  const [mediaNames, setMediaNames] = useState<Record<string, string>>({});
  const [mediaUrls, setMediaUrls] = useState<Record<string, string>>({});
  const [version, setVersion] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [pickerOpen, setPickerOpen] = useState(false);
  const [pickerKey, setPickerKey] = useState<LogoKey>('logo');
  const [themeTab, setThemeTab] = useState<'light' | 'dark'>('light');
  const [typeDevice, setTypeDevice] = useState<'desktop' | 'tablet' | 'mobile'>('desktop');

  const set = useCallback((key: string, value: string) => {
    setState((prev) => ({ ...prev, [key]: value }));
  }, []);

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const res = await apiClient.request<Record<string, unknown>>('GET', '/singletons/branding');
        if (!mounted) return;
        if (res.ok && res.data) {
          const d = res.data as Record<string, unknown>;
          const data = (d.data && typeof d.data === 'object' ? d.data : d) as Record<string, unknown>;
          const next: BrandingState = {};
          for (const [k, v] of Object.entries(data)) {
            if (typeof v === 'string') next[k] = v;
            else if (typeof v === 'number') next[k] = String(v);
          }
          setState(next);
          setVersion(typeof d.version === 'number' ? d.version : null);
          const ids = LOGO_FIELDS.map((f) => next[f.key]).filter((v): v is string => Boolean(v));
          const names: Record<string, string> = {};
          const urls: Record<string, string> = {};
          const list = await apiClient.request<unknown>('GET', '/media', { query: { per_page: 100 } });
          if (list.ok && list.data) {
            const items = unwrapMediaList(list.data) as unknown as Array<Record<string, unknown>>;
            for (const id of ids) {
              const found = items.find((m) => m.id === id);
              if (found) {
                names[id] = String(found.name || found.original_name || id);
                urls[id] = String(found.url || '');
              }
            }
          }
          if (mounted) { setMediaNames(names); setMediaUrls(urls); }
        } else if (!res.ok && res.status !== 404) {
          problemToast(res.problem, { fallbackMessage: 'Failed to load branding.' });
        }
      } catch {
        problemToast(null, { fallbackMessage: 'Failed to load branding.' });
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => { mounted = false; };
  }, []);

  const pickMedia = (key: LogoKey) => {
    setPickerKey(key);
    setPickerOpen(true);
  };

  const handleSave = async () => {
    setSaving(true);
    try {
      const res = await apiClient.request<unknown>('PUT', '/singletons/branding', {
        body: { ...state },
        headers: version ? { 'If-Match': `"${version}"` } : undefined,
      });
      if (res.ok) {
        successToast('Branding saved. Rebuild the static site to apply it to your website.');
        const fresh = await apiClient.request<Record<string, unknown>>('GET', '/singletons/branding');
        if (fresh.ok && fresh.data && typeof (fresh.data as Record<string, unknown>).version === 'number') {
          setVersion((fresh.data as Record<string, unknown>).version as number);
        }
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to save branding.' });
      }
    } catch {
      problemToast(null, { fallbackMessage: 'Network error while saving branding.' });
    } finally {
      setSaving(false);
    }
  };

  /* ------------------------------ small renderers ------------------------------ */

  const mediaUrl = (id: string) => mediaUrls[id] || '';

  const ColorField = ({ field, label }: { field: string; label: string }) => (
    <div className="space-y-1.5">
      <Label htmlFor={`br_${field}`}>{label}</Label>
      <div className="flex items-center gap-2">
        <input
          type="color"
          aria-label={`${label} swatch`}
          value={/^#[0-9a-fA-F]{6}$/.test(state[field] || '') ? state[field] : '#000000'}
          onChange={(e) => set(field, e.target.value)}
          className="h-9 w-10 cursor-pointer rounded border border-border bg-transparent p-0.5"
        />
        <Input
          id={`br_${field}`}
          value={state[field] || ''}
          onChange={(e) => set(field, e.target.value)}
          placeholder="auto"
          className="font-mono text-12"
        />
      </div>
    </div>
  );

  const ColorRowBlock = ({ fields, labels }: { fields: string[]; labels: string[] }) => (
    <div className={`grid gap-4 ${fields.length >= 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-2'}`}>
      {fields.map((f, idx) => <ColorField key={f} field={f} label={labels[idx] as string} />)}
    </div>
  );

  const [brandingTab, setBrandingTab] = useState('general');
  const themeRows = themeTab === 'light' ? THEME_ROWS.rows : DARK_ROWS.rows;

  const p = state.color_primary || '#1E3D2B';
  const bg = state.color_background || '#F7F5EE';
  const surface = state.color_card_bg || '#FDFCF8';
  const text = state.color_heading || '#20301F';
  const btnBg = state.color_button_bg || p;
  const btnFg = state.color_button_text || '#ffffff';
  const btnRadius = { none: '0px', small: '4px', medium: '10px', large: '16px', pill: '999px' }[state.button_radius || 'medium'] || '10px';
  const cardRadius = { none: '0px', small: '4px', medium: '10px', large: '16px', pill: '999px' }[state.card_radius || 'medium'] || '10px';
  const headingFont = state.font_heading === 'sans-modern' || state.font_heading === 'sans-humanist'
    ? 'system-ui, sans-serif'
    : 'Georgia, serif';

  return (
    <PageContainer width="full" className="space-y-6">
      <Breadcrumbs
        items={[
          { label: 'Admin', href: '/admin/' },
          { label: 'Branding' },
        ]}
      />
      <PageHeader
        title="Branding"
        description="Your logo, site layout, color themes, typography and shape language — applied across your public website as design tokens."
        actions={
          <Button size="sm" loading={saving} onClick={handleSave}>
            Save Branding
          </Button>
        }
      />

      {loading ? (
        <FormPageSkeleton cardsCount={2} />
      ) : (
        <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
          <div className="space-y-6 xl:col-span-2">
            <Tabs value={brandingTab} onValueChange={setBrandingTab}>
              <TabsList className="h-9 w-full flex-wrap">
                <TabsTrigger value="general" className="text-xs px-4">General</TabsTrigger>
                <TabsTrigger value="logo" className="text-xs px-4">Logo</TabsTrigger>
                <TabsTrigger value="typography" className="text-xs px-4">Typography</TabsTrigger>
                <TabsTrigger value="colours" className="text-xs px-4">Colours</TabsTrigger>
                <TabsTrigger value="border" className="text-xs px-4">Border &amp; Radius</TabsTrigger>
              </TabsList>

              <TabsContent value="general">

            {/* General */}
            <Card>
              <CardHeader>
                <CardTitle>General</CardTitle>
                <CardDescription>Global layout settings for the public site.</CardDescription>
              </CardHeader>
              <CardContent>
                <div className="space-y-1.5 max-w-sm">
                  <Label htmlFor="br_site_width">Site Width</Label>
                  <div className="flex items-center gap-2">
                    <Input
                      id="br_site_width"
                      value={state.site_width || ''}
                      onChange={(e) => set('site_width', e.target.value)}
                      placeholder="e.g. 1380px or 90%"
                      className="font-mono text-13"
                    />
                  </div>
                  <p className="text-11 text-muted-foreground">
                    Max content width. Accepts pixels (1380px) or a percentage (90%).
                  </p>
                </div>
              </CardContent>
            </Card>
            </TabsContent>

            <TabsContent value="logo">
            <Card>
              <CardHeader>
                <CardTitle>Logo</CardTitle>
                <CardDescription>Primary logo for the header, a variant for dark backgrounds (footer), and a favicon.</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                {LOGO_FIELDS.map(({ key, label }) => (
                  <div key={key} className="flex items-center justify-between gap-4 rounded-md border border-border p-3">
                    <div className="flex items-center gap-3 min-w-0">
                      {state[key] ? (
                        <img src={mediaUrl(state[key])} alt={label} className="h-10 w-16 rounded object-contain bg-muted" />
                      ) : (
                        <div className="flex h-10 w-16 items-center justify-center rounded bg-muted text-11 text-muted-foreground">None</div>
                      )}
                      <div className="min-w-0">
                        <p className="text-13 font-medium text-foreground">{label}</p>
                        <p className="text-11 text-muted-foreground truncate">{state[key] ? mediaNames[state[key]] || state[key] : 'Not set'}</p>
                      </div>
                    </div>
                    <div className="flex gap-2">
                      <Button size="sm" variant="outline" onClick={() => pickMedia(key as LogoKey)}>Choose…</Button>
                      {state[key] && (
                        <Button size="sm" variant="ghost" onClick={() => set(key, '')}>Clear</Button>
                      )}
                    </div>
                  </div>
                ))}
                <div className="space-y-1.5">
                  <Label htmlFor="brand_alt">Logo Alt Text</Label>
                  <Input id="brand_alt" value={state.logo_alt || ''} onChange={(e) => set('logo_alt', e.target.value)} placeholder="e.g. Himalayan Florica logo" />
                </div>
              </CardContent>
            </Card>
            </TabsContent>

            <TabsContent value="colours">
            <Card>
              <CardHeader>
                <CardTitle>Brand Colors</CardTitle>
                <CardDescription>
                  Two complete palettes. The dark theme applies automatically when a visitor's system requests dark mode.
                  Text contrast is derived automatically.
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-5">
                <div className="flex gap-2">
                  {(['light', 'dark'] as const).map((t) => (
                    <button
                      key={t}
                      onClick={() => setThemeTab(t)}
                      className={`px-3.5 py-1.5 text-13 rounded-md border transition-colors ${
                        themeTab === t
                          ? 'bg-primary text-primary-foreground border-primary font-medium'
                          : 'border-border text-muted-foreground hover:text-foreground hover:bg-muted'
                      }`}
                    >
                      {t === 'light' ? 'Light Theme' : 'Dark Theme'}
                      {t === 'light' && <span className="ml-1.5 text-10 opacity-70">(default)</span>}
                    </button>
                  ))}
                </div>

                {themeRows.map((row) => (
                  <ColorRowBlock key={row.fields.join('|')} fields={row.fields} labels={row.labels} />
                ))}
              </CardContent>
            </Card>
            </TabsContent>

            <TabsContent value="border">
            <Card>
              <CardHeader>
                <CardTitle>Border &amp; Radius</CardTitle>
                <CardDescription>Corner rounding, border width and border color per element group.</CardDescription>
              </CardHeader>
              <CardContent className="space-y-5">
                {BORDER_BLOCKS.map((block) => (
                  <div key={block.key} className="rounded-md border border-border p-4 space-y-4">
                    <p className="text-13 font-semibold text-foreground">{block.label}</p>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                      <div className="space-y-1.5">
                        <Label htmlFor={`br_${block.radius}`}>Radius</Label>
                        <Select value={state[block.radius] || ''} onValueChange={(v) => set(block.radius, v)}>
                          <SelectTrigger id={`br_${block.radius}`}><SelectValue placeholder="Theme default" /></SelectTrigger>
                          <SelectContent>
                            {RADIUS_OPTIONS.map((r) => <SelectItem key={r.value} value={r.value}>{r.label}</SelectItem>)}
                          </SelectContent>
                        </Select>
                      </div>
                      <div className="space-y-1.5">
                        <Label htmlFor={`br_${block.bw}`}>Border</Label>
                        <Input
                          id={`br_${block.bw}`}
                          value={state[block.bw] || ''}
                          onChange={(e) => set(block.bw, e.target.value)}
                          placeholder="e.g. 1px or none"
                          className="font-mono text-12"
                        />
                      </div>
                      <ColorField field={block.bc} label="Border Color" />
                    </div>
                  </div>
                ))}
              </CardContent>
            </Card>
            </TabsContent>

            <TabsContent value="typography">
            <Card>
              <CardHeader>
                <CardTitle>Typography</CardTitle>
                <CardDescription>Font pairing and responsive heading scale (H1–H6) per device class.</CardDescription>
              </CardHeader>
              <CardContent className="space-y-5">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <div className="space-y-1.5">
                    <Label htmlFor="brand_fh">Heading Font</Label>
                    <Select value={state.font_heading || ''} onValueChange={(v) => set('font_heading', v)}>
                      <SelectTrigger id="brand_fh"><SelectValue placeholder="Theme default" /></SelectTrigger>
                      <SelectContent>
                        {FONT_OPTIONS.map((f) => <SelectItem key={f.value} value={f.value}>{f.label}</SelectItem>)}
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="brand_fb">Body Font</Label>
                    <Select value={state.font_body || ''} onValueChange={(v) => set('font_body', v)}>
                      <SelectTrigger id="brand_fb"><SelectValue placeholder="Theme default" /></SelectTrigger>
                      <SelectContent>
                        {FONT_OPTIONS.slice(1).map((f) => <SelectItem key={f.value} value={f.value}>{f.label}</SelectItem>)}
                      </SelectContent>
                    </Select>
                  </div>
                </div>

                <div className="flex gap-2">
                  {DEVICES.map((d) => (
                    <button
                      key={d.key}
                      onClick={() => setTypeDevice(d.key as typeof typeDevice)}
                      className={`px-3.5 py-1.5 text-13 rounded-md border transition-colors ${
                        typeDevice === d.key
                          ? 'bg-primary text-primary-foreground border-primary font-medium'
                          : 'border-border text-muted-foreground hover:text-foreground hover:bg-muted'
                      }`}
                    >
                      {d.label}
                    </button>
                  ))}
                </div>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                  {[1, 2, 3, 4, 5, 6].map((level) => {
                    const field = `fs_h${level}_${typeDevice}`;
                    return (
                      <div key={field} className="space-y-1.5">
                        <Label htmlFor={`br_${field}`}>H{level} size</Label>
                        <Input
                          id={`br_${field}`}
                          value={state[field] || ''}
                          onChange={(e) => set(field, e.target.value)}
                          placeholder={typeDevice === 'desktop' ? 'e.g. 3rem' : 'auto'}
                          className="font-mono text-12"
                        />
                      </div>
                    );
                  })}
                </div>
              </CardContent>
            </Card>
            </TabsContent>
          </Tabs>
          </div>

          {/* Live preview */}
          <div className="space-y-4">
            <Card className="sticky top-6">
              <CardHeader>
                <CardTitle>Live Preview</CardTitle>
                <CardDescription>Approximates how tokens apply on your public site ({themeTab} theme).</CardDescription>
              </CardHeader>
              <CardContent>
                <div className="overflow-hidden border" style={{
                  background: themeTab === 'dark' ? (state.dark_background || '#0f1720') : bg,
                  borderRadius: cardRadius,
                  borderColor: themeTab === 'dark' ? (state.dark_card_border || 'transparent') : 'transparent',
                }}>
                  <div className="flex items-center justify-between px-4 py-3" style={{
                    background: themeTab === 'dark' ? (state.dark_card_bg || '#151e28') : surface,
                    borderBottom: `1px solid ${themeTab === 'dark' ? '#ffffff22' : '#00000018'}`,
                  }}>
                    {state.logo ? (
                      <img src={mediaUrl(state.logo)} alt="logo preview" className="h-7 w-auto object-contain" />
                    ) : (
                      <span className="text-13 font-bold" style={{ color: themeTab === 'dark' ? (state.dark_heading || '#fff') : text, fontFamily: headingFont }}>YOUR BRAND</span>
                    )}
                    <span className="px-3 py-1.5 text-11 font-semibold" style={{
                      background: themeTab === 'dark' ? (state.dark_button_bg || p) : btnBg,
                      color: themeTab === 'dark' ? (state.dark_button_text || '#fff') : btnFg,
                      borderRadius: btnRadius,
                      border: state.button_border_width && state.button_border_width !== 'none' ? `${state.button_border_width} solid ${state.button_border_color || 'transparent'}` : 'none',
                    }}>
                      Enquire →
                    </span>
                  </div>
                  <div className="px-4 py-6">
                    <p className="text-[10px] font-semibold uppercase tracking-widest" style={{ color: themeTab === 'dark' ? `${state.dark_body_text || '#94a3b8'}` : `${text}99` }}>
                      Horticulture · Seeds
                    </p>
                    <p className="mt-2 text-2xl font-semibold leading-tight" style={{
                      color: themeTab === 'dark' ? (state.dark_heading || '#fff') : text,
                      fontFamily: headingFont,
                    }}>
                      Better varieties.<br />Stronger growing.
                    </p>
                    <p className="mt-3 text-12 leading-relaxed" style={{ color: themeTab === 'dark' ? `${state.dark_body_text || '#94a3b8'}cc` : `${text}b3` }}>
                      Quality seeds and planting material styled with your brand tokens.
                    </p>
                    <div className="mt-4 grid grid-cols-2 gap-2">
                      <div className="border p-2.5" style={{
                        background: themeTab === 'dark' ? (state.dark_card_bg || '#151e28') : surface,
                        borderRadius: cardRadius,
                        borderColor: themeTab === 'dark' ? '#ffffff22' : (state.card_border_color || '#00000018'),
                        borderWidth: state.card_border_width && state.card_border_width !== 'none' ? state.card_border_width : 1,
                        borderStyle: 'solid',
                      }}>
                        <p className="text-11 font-semibold" style={{ color: themeTab === 'dark' ? (state.dark_heading || '#fff') : (state.color_card_text || text), fontFamily: headingFont }}>Card title</p>
                        <p className="text-10 mt-1" style={{ color: themeTab === 'dark' ? `${state.dark_card_text || '#94a3b8'}` : `${state.color_card_text || text}99` }}>Card body sample text.</p>
                      </div>
                      <img
                        src={state.logo ? mediaUrl(state.logo) : 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="80" height="40"><rect width="80" height="40" fill="%23e5e7eb"/></svg>'}
                        alt="image token preview"
                        className="h-full w-full object-cover"
                        style={{
                          borderRadius: { none: '0px', small: '4px', medium: '10px', large: '16px', pill: '999px' }[state.image_radius || 'medium'] || '10px',
                          border: state.image_border_width && state.image_border_width !== 'none' ? `${state.image_border_width} solid ${state.image_border_color || 'transparent'}` : 'none',
                        }}
                      />
                    </div>
                  </div>
                </div>
                <p className="mt-3 text-11 text-muted-foreground">
                  Applies after saving and rebuilding the static site. Dark theme shows when the visitor's system requests dark mode.
                </p>
              </CardContent>
            </Card>
          </div>
        </div>
      )}

      <MediaPickerModal
        open={pickerOpen}
        onClose={() => setPickerOpen(false)}
        onSelect={(item: MediaItem) => {
          const normalized = normalizeMedia(item as unknown as Record<string, unknown>);
          set(pickerKey, normalized.id);
          setMediaNames((prev) => ({ ...prev, [normalized.id]: normalized.name || normalized.original_name || normalized.id }));
          setMediaUrls((prev) => ({ ...prev, [normalized.id]: (item as unknown as { url?: string }).url || '' }));
          setPickerOpen(false);
        }}
      />
    </PageContainer>
  );
};
