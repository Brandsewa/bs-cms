import { Sun, Moon, Laptop } from 'lucide-react';
import { useTheme, type ThemePreference, Button } from '@bs/ui';

export function ShellThemeToggle() {
  const { theme, setTheme } = useTheme();

  const nextTheme: Record<ThemePreference, ThemePreference> = {
    light: 'dark',
    dark: 'system',
    system: 'light',
  };

  const handleToggle = () => {
    setTheme(nextTheme[theme] || 'light');
  };

  return (
    <Button
      variant="ghost"
      size="sm"
      className="h-8 w-8 sm:h-8 sm:w-8 min-h-[44px] min-w-[44px] sm:min-h-0 sm:min-w-0 p-0"
      onClick={handleToggle}
      aria-label={`Current theme: ${theme}. Click to switch theme.`}
      title={`Theme: ${theme}`}
    >
      {theme === 'light' && <Sun className="h-4 w-4" />}
      {theme === 'dark' && <Moon className="h-4 w-4" />}
      {theme === 'system' && <Laptop className="h-4 w-4" />}
    </Button>
  );
}
