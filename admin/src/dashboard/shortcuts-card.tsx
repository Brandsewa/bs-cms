import { Card, CardHeader, CardTitle, CardContent, Kbd } from '@bs/ui';
import { Command } from 'lucide-react';

interface ShortcutItem {
  keys: string[];
  description: string;
}

const SHORTCUTS: ShortcutItem[] = [
  { keys: ['Ctrl', 'K'], description: 'Quick search / Jump to route' },
  { keys: ['Ctrl', 'S'], description: 'Save current entry draft' },
  { keys: ['Ctrl', 'Shift', 'P'], description: 'Publish entry' },
  { keys: ['G', 'D'], description: 'Go to Dashboard' },
  { keys: ['G', 'E'], description: 'Go to Entries list' },
  { keys: ['G', 'M'], description: 'Go to Media library' },
];

export function ShortcutsCard({ className }: { className?: string }) {
  return (
    <Card className={className} data-testid="dashboard-shortcuts-card">
      <CardHeader className="pb-3">
        <div className="flex items-center gap-2">
          <Command className="h-4 w-4 text-muted-foreground" />
          <CardTitle className="text-15 font-semibold">Keyboard shortcuts</CardTitle>
        </div>
      </CardHeader>
      <CardContent>
        <ul className="space-y-2.5 text-13" role="list">
          {SHORTCUTS.map((item, index) => (
            <li key={index} className="flex items-center justify-between text-muted-foreground">
              <span>{item.description}</span>
              <div className="flex items-center gap-1">
                {item.keys.map((k, kIdx) => (
                  <Kbd key={kIdx} className="text-11">
                    {k}
                  </Kbd>
                ))}
              </div>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
