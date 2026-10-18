import { Link, useSearch } from '@tanstack/react-router';
import { cn, useTheme, type ThemePreference } from '@bs/ui';
import { sections } from './registry';
import { ThemeScope } from './ThemeScope';
import type { PropRow } from './registry';

function ThemeToggle() {
  const { theme, setTheme } = useTheme();
  const options: Array<{ value: ThemePreference; label: string }> = [
    { value: 'light', label: 'Light' },
    { value: 'dark', label: 'Dark' },
    { value: 'system', label: 'System' },
  ];
  return (
    <fieldset>
      <legend className="text-xs font-medium text-muted-foreground">Preview theme</legend>
      <div className="mt-1 flex gap-1" role="group">
        {options.map((option) => (
          <button
            key={option.value}
            type="button"
            aria-pressed={theme === option.value}
            onClick={() => setTheme(option.value)}
            className={cn(
              'rounded-md border px-2 py-1 text-xs transition-colors',
              theme === option.value
                ? 'border-primary bg-primary text-primary-foreground'
                : 'border-border bg-surface-100 text-foreground hover:bg-surface-200'
            )}
          >
            {option.label}
          </button>
        ))}
      </div>
    </fieldset>
  );
}

function PropsTable({ rows }: { rows: PropRow[] }) {
  return (
    <table className="mt-4 w-full border-collapse text-left text-13">
      <caption className="sr-only">Component props</caption>
      <thead>
        <tr className="border-b border-border">
          <th scope="col" className="py-1.5 pr-4 font-medium">Prop</th>
          <th scope="col" className="py-1.5 pr-4 font-medium">Type</th>
          <th scope="col" className="py-1.5 pr-4 font-medium">Default</th>
          <th scope="col" className="py-1.5 font-medium">Description</th>
        </tr>
      </thead>
      <tbody>
        {rows.map((row) => (
          <tr key={row.name} className="border-b border-border-muted align-top">
            <td className="py-1.5 pr-4 font-mono text-xs">{row.name}</td>
            <td className="py-1.5 pr-4 font-mono text-xs text-muted-foreground">{row.type}</td>
            <td className="py-1.5 pr-4 font-mono text-xs text-muted-foreground">
              {row.defaultValue ?? '—'}
            </td>
            <td className="py-1.5 text-muted-foreground">{row.description}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

export function GalleryApp() {
  const { section } = useSearch({ from: '/_gallery' });
  const active = sections.find((candidate) => candidate.id === section) ?? sections[0]!;

  return (
    <div className="flex min-h-screen bg-background text-foreground">
      <nav
        aria-label="Gallery sections"
        className="sticky top-0 flex h-screen w-56 shrink-0 flex-col gap-4 overflow-y-auto border-r border-border p-4"
      >
        <div>
          <h1 className="text-sm font-semibold">BS CMS Gallery</h1>
          <p className="text-xs text-muted-foreground">Development build only</p>
        </div>
        <ul className="space-y-1">
          {sections.map((section) => (
            <li key={section.id}>
              <Link
                to="/_gallery"
                search={{ section: section.id }}
                activeProps={{ 'aria-current': 'page' }}
                className={cn(
                  'block rounded-md px-2 py-1.5 text-13 hover:bg-surface-200',
                  section.id === active.id && 'bg-surface-200 font-medium'
                )}
              >
                {section.name}
              </Link>
            </li>
          ))}
        </ul>
        <ThemeToggle />
      </nav>

      <main className="min-w-0 flex-1 p-8">
        <div className="space-y-16">
          {active.entries.map((entry) => (
            <section
              key={entry.id}
              aria-labelledby={`gallery-entry-${entry.id}`}
              className="space-y-4"
            >
              <div>
                <h2
                  id={`gallery-entry-${entry.id}`}
                  className="text-base font-semibold text-foreground"
                >
                  {entry.name}
                </h2>
                {entry.description ? (
                  <p className="mt-1 max-w-2xl text-13 text-muted-foreground">
                    {entry.description}
                  </p>
                ) : null}
              </div>
              <div className="grid gap-4 xl:grid-cols-2">
                <div>
                  <h3 className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    Light
                  </h3>
                  <ThemeScope theme="light">{entry.render()}</ThemeScope>
                </div>
                <div>
                  <h3 className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    Dark
                  </h3>
                  <ThemeScope theme="dark">{entry.render()}</ThemeScope>
                </div>
              </div>
              {entry.props ? <PropsTable rows={entry.props} /> : null}
            </section>
          ))}
        </div>
      </main>
    </div>
  );
}
