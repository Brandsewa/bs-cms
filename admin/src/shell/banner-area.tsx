import { useState, useEffect } from 'react';
import { Banner, BannerArea, Button } from '@bs/ui';

export interface GlobalBannerItem {
  id: string;
  variant: 'info' | 'warning' | 'error' | 'success';
  message: string;
  action?: {
    label: string;
    onClick?: () => void;
    href?: string;
  };
  dismissible?: boolean;
}

export interface ShellBannerAreaProps {
  banners?: GlobalBannerItem[];
  onDismiss?: (id: string) => void;
}

export function ShellBannerArea({ banners: controlledBanners, onDismiss }: ShellBannerAreaProps) {
  const [internalBanners, setInternalBanners] = useState<GlobalBannerItem[]>([]);
  const [dismissedIds, setDismissedIds] = useState<Set<string>>(() => new Set());

  useEffect(() => {
    // Check for pending AI changes from dashboard endpoint if available
    let mounted = true;
    fetch('/api/v1/dashboard', { headers: { Accept: 'application/json' } })
      .then((res) => {
        if (!res.ok) return null;
        return res.json();
      })
      .then((data) => {
        if (!mounted || !data) return;
        const pendingAi = data.counts?.pending_ai_changes ?? data.pending_ai_changes?.length ?? 0;
        const next: GlobalBannerItem[] = [];

        if (pendingAi > 0) {
          next.push({
            id: 'ai-pending',
            variant: 'info',
            message: `${pendingAi} AI change${pendingAi > 1 ? 's' : ''} pending review and approval.`,
            action: {
              label: 'Review in AI Studio',
              href: '/admin/ai',
            },
            dismissible: true,
          });
        }

        if (data.system_status?.maintenance_mode) {
          next.push({
            id: 'maintenance-mode',
            variant: 'warning',
            message: 'Maintenance mode is active. Public site visitors receive 503 Service Unavailable.',
            dismissible: false,
          });
        }

        if (data.site_health?.last_deploy_status === 'failed') {
          next.push({
            id: 'deploy-failed',
            variant: 'error',
            message: 'The last static site build or deployment failed. Check developer logs.',
            action: {
              label: 'View Developer Logs',
              href: '/admin/developer',
            },
            dismissible: true,
          });
        }

        setInternalBanners(next);
      })
      .catch(() => {
        // Non-blocking background check
      });

    return () => {
      mounted = false;
    };
  }, []);

  const activeBanners = (controlledBanners ?? internalBanners).filter(
    (b) => !dismissedIds.has(b.id)
  );

  if (activeBanners.length === 0) {
    return <BannerArea>{null}</BannerArea>;
  }

  const handleDismiss = (id: string) => {
    setDismissedIds((prev) => new Set([...prev, id]));
    onDismiss?.(id);
  };

  return (
    <BannerArea className="px-4 py-2 sm:px-6">
      <div className="space-y-2">
        {activeBanners.map((banner) => (
          <Banner
            key={banner.id}
            variant={banner.variant}
            onDismiss={banner.dismissible !== false ? () => handleDismiss(banner.id) : undefined}
            action={
              banner.action ? (
                banner.action.href ? (
                  <Button variant="outline" size="sm" className="h-6 text-xs px-2" asChild>
                    <a href={banner.action.href}>{banner.action.label}</a>
                  </Button>
                ) : (
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-6 text-xs px-2"
                    onClick={banner.action.onClick}
                  >
                    {banner.action.label}
                  </Button>
                )
              ) : undefined
            }
          >
            <span>{banner.message}</span>
          </Banner>
        ))}
      </div>
    </BannerArea>
  );
}
