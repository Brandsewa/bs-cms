import type { GallerySection } from '../registry';

const colorTokens: Array<[name: string, value: string]> = [
  ['--background', 'var(--background)'],
  ['--foreground', 'var(--foreground)'],
  ['--surface-100', 'var(--surface-100)'],
  ['--surface-200', 'var(--surface-200)'],
  ['--surface-300', 'var(--surface-300)'],
  ['--card', 'var(--card)'],
  ['--card-foreground', 'var(--card-foreground)'],
  ['--popover', 'var(--popover)'],
  ['--popover-foreground', 'var(--popover-foreground)'],
  ['--primary', 'var(--primary)'],
  ['--primary-foreground', 'var(--primary-foreground)'],
  ['--secondary', 'var(--secondary)'],
  ['--secondary-foreground', 'var(--secondary-foreground)'],
  ['--muted', 'var(--muted)'],
  ['--muted-foreground', 'var(--muted-foreground)'],
  ['--accent', 'var(--accent)'],
  ['--accent-foreground', 'var(--accent-foreground)'],
  ['--destructive', 'var(--destructive)'],
  ['--destructive-foreground', 'var(--destructive-foreground)'],
  ['--warning', 'var(--warning)'],
  ['--warning-foreground', 'var(--warning-foreground)'],
  ['--success', 'var(--success)'],
  ['--success-foreground', 'var(--success-foreground)'],
  ['--info', 'var(--info)'],
  ['--info-foreground', 'var(--info-foreground)'],
  ['--ai', 'var(--ai)'],
  ['--ai-foreground', 'var(--ai-foreground)'],
  ['--border', 'var(--border)'],
  ['--border-muted', 'var(--border-muted)'],
  ['--border-strong', 'var(--border-strong)'],
  ['--input', 'var(--input)'],
  ['--ring', 'var(--ring)'],
  ['--overlay', 'var(--overlay)'],
];

function Swatch({ label, value }: { label: string; value: string }) {
  return (
    <figure className="w-36">
      <div
        className="h-12 w-full rounded-md border border-border"
        style={label === '--overlay' ? { backgroundImage: `linear-gradient(#fff, #fff)`, backgroundColor: value } : { backgroundColor: value }}
      />
      <figcaption className="mt-1 font-mono text-xs text-muted-foreground">{label}</figcaption>
    </figure>
  );
}

function ColorsEntry() {
  return (
    <div className="flex flex-wrap gap-3">
      {colorTokens.map(([name, value]) => (
        <Swatch key={name} label={name} value={value} />
      ))}
    </div>
  );
}

function SpacingEntry() {
  return (
    <div className="space-y-2">
      {[1, 2, 3, 4, 5, 6, 8, 10, 12, 16].map((step) => (
        <div key={step} className="flex items-center gap-3">
          <span className="w-20 font-mono text-xs text-muted-foreground">--space-{step}</span>
          <span className="h-3 rounded-sm bg-primary" style={{ width: `var(--space-${step})` }} />
        </div>
      ))}
    </div>
  );
}

function RadiusEntry() {
  return (
    <div className="flex gap-4">
      {(['sm', 'md', 'lg', 'xl'] as const).map((size) => (
        <figure key={size} className="text-center">
          <div
            className="h-16 w-16 border border-border bg-surface-200"
            style={{ borderRadius: `var(--radius-${size})` }}
          />
          <figcaption className="mt-1 font-mono text-xs text-muted-foreground">
            --radius-{size}
          </figcaption>
        </figure>
      ))}
    </div>
  );
}

function ShadowsEntry() {
  return (
    <div className="flex gap-4">
      {(['xs', 'sm', 'md', 'lg', 'xl'] as const).map((size) => (
        <figure key={size} className="text-center">
          <div
            className="h-16 w-16 rounded-md bg-card"
            style={{ boxShadow: `var(--shadow-${size})` }}
          />
          <figcaption className="mt-1 font-mono text-xs text-muted-foreground">
            --shadow-{size}
          </figcaption>
        </figure>
      ))}
    </div>
  );
}

function TypographyEntry() {
  return (
    <div className="space-y-3">
      <p className="text-2xl font-semibold">24 — Page title</p>
      <p className="text-xl font-semibold">20 — Section title</p>
      <p className="text-base">16 — Body</p>
      <p className="text-13">13 — Default UI text</p>
      <p className="text-xs">12 — Captions, meta</p>
      <p className="font-mono text-xs">Monospace — IDs, code: entry_01HXXXXXXX</p>
      <p className="text-sm tabular-nums">Tabular numbers — 1,234,567 rows · 98.6%</p>
    </div>
  );
}

export const foundationsSection: GallerySection = {
  id: 'foundations',
  name: 'Foundations',
  entries: [
    {
      id: 'colors',
      name: 'Colour tokens',
      description:
        'Semantic colour tokens from packages/ui/src/tokens.css. Blue primary hue 221°. Every pair verified WCAG AA in light and dark (NOTES.md, Spike E + Phase 5 corrections).',
      render: () => <ColorsEntry />,
    },
    {
      id: 'spacing',
      name: 'Spacing scale (4px base)',
      render: () => <SpacingEntry />,
    },
    {
      id: 'radius',
      name: 'Radius scale',
      render: () => <RadiusEntry />,
    },
    {
      id: 'shadows',
      name: 'Shadow scale',
      render: () => <ShadowsEntry />,
    },
    {
      id: 'typography',
      name: 'Typography',
      description:
        'System UI font stack, monospace for code/IDs, tabular numbers in tables.',
      render: () => <TypographyEntry />,
    },
  ],
};
