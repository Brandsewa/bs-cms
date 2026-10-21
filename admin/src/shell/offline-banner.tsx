import { useEffect, useState } from 'react';
import { WifiOff, AlertTriangle } from 'lucide-react';

export function OfflineBanner() {
  const [isOffline, setIsOffline] = useState(
    typeof navigator !== 'undefined' ? !navigator.onLine : false
  );

  useEffect(() => {
    const handleOnline = () => setIsOffline(false);
    const handleOffline = () => setIsOffline(true);

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  }, []);

  if (!isOffline) return null;

  return (
    <div
      role="alert"
      className="flex items-center justify-center gap-2 border-b border-destructive/30 bg-destructive/15 px-4 py-2 text-12 font-medium text-destructive animate-in fade-in"
    >
      <WifiOff className="h-4 w-4 shrink-0" />
      <span>You are currently offline. Changes may not be saved until your connection is restored.</span>
    </div>
  );
}

export function MaintenanceModeBanner({ active = false }: { active?: boolean }) {
  if (!active) return null;

  return (
    <div
      role="status"
      className="flex items-center justify-center gap-2 border-b border-amber-500/30 bg-amber-500/10 px-4 py-2 text-12 font-medium text-amber-600 dark:text-amber-400"
    >
      <AlertTriangle className="h-4 w-4 shrink-0" />
      <span>Maintenance mode is active. Public site visitors see the maintenance page.</span>
    </div>
  );
}
