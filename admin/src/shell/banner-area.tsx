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

interface AiStatusResponse {
  ai_disabled?: boolean;
  active_grants_count?: number;
  active_ai_tokens_count?: number;
  pending_changes_count?: number;
}

interface FrontendStatusResponse {
  connected?: boolean;
  target_type?: string;
  last_deployment?: {
    status?: string;
    error?: string;
    [key: string]: unknown;
  } | null;
  pending_changes_count?: number;
}

export function ShellBannerArea({ banners: controlledBanners, onDismiss }: ShellBannerAreaProps) {
  const [internalBanners, setInternalBanners] = useState<GlobalBannerItem[]>([]);
  const [dismissedIds, setDismissedIds] = useState<Set<string>>(() => new Set());

  useEffect(() => {
    let mounted = true;

    async function loadStatus() {
      const items: GlobalBannerItem[] = [];

      // 1. Fetch AI status from real endpoint GET /api/v1/ai/status
      try {
        const aiRes = await fetch('/api/v1/ai/status', {
          headers: { Accept: 'application/json' },
        });
        if (aiRes.ok) {
          const aiData: AiStatusResponse = await aiRes.json();
          if (aiData.ai_disabled) {
            items.push({
              id: 'ai-disabled',
              variant: 'warning',
              message: 'AI integration is disabled (kill-switch activated).',
              action: {
                label: 'Manage in AI Studio',
                href: '/admin/ai',
              },
              dismissible: true,
            });
          } else if (aiData.pending_changes_count && aiData.pending_changes_count > 0) {
            const count = aiData.pending_changes_count;
            items.push({
              id: 'ai-pending',
              variant: 'info',
              message: `${count} AI change${count > 1 ? 's' : ''} pending review and approval.`,
              action: {
                label: 'Review in AI Studio',
                href: '/admin/ai',
              },
              dismissible: true,
            });
          }
        }
      } catch {
        // Non-blocking
      }

      // 2. Fetch frontend deployment status from GET /api/v1/frontend/status
      try {
        const feRes = await fetch('/api/v1/frontend/status', {
          headers: { Accept: 'application/json' },
        });
        if (feRes.ok) {
          const feData: FrontendStatusResponse = await feRes.json();
          if (feData.last_deployment?.status === 'failed') {
            items.push({
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
        }
      } catch {
        // Non-blocking
      }

      // 3. Fallback check for maintenance mode via dashboard or health check if present
      try {
        const dashRes = await fetch('/api/v1/dashboard', {
          headers: { Accept: 'application/json' },
        });
        if (dashRes.ok) {
          const dashData = await dashRes.json();
          if (dashData.system_status?.maintenance_mode) {
            items.push({
              id: 'maintenance-mode',
              variant: 'warning',
              message: 'Maintenance mode is active. Public site visitors receive 503 Service Unavailable.',
              dismissible: false,
            });
          }
        }
      } catch {
        // Non-blocking
      }

      if (mounted) {
        setInternalBanners(items);
      }
    }

    void loadStatus();

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
