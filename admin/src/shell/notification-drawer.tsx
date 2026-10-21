import { useState, useEffect } from 'react';
import {
  Button,
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
  Badge,
} from '@bs/ui';
import { Bell, CheckCheck, CheckCircle2 } from 'lucide-react';
import { apiClient } from '../lib/api';

interface NotificationItem {
  id: string;
  type?: string;
  title?: string;
  message?: string;
  read?: boolean;
  created_at?: string;
}

export function NotificationDrawerTrigger() {
  const [open, setOpen] = useState(false);
  const [unreadCount, setUnreadCount] = useState(0);
  const [notifications, setNotifications] = useState<NotificationItem[]>([]);
  const [loading, setLoading] = useState(false);

  const fetchNotifications = async () => {
    setLoading(true);
    try {
      const res = await apiClient.request<{ items: NotificationItem[]; unread_count: number }>(
        'GET',
        '/notifications'
      );
      if (res.ok && res.data) {
        setNotifications(res.data.items || []);
        setUnreadCount(res.data.unread_count || 0);
      }
    } catch {
      // Endpoint may not have items yet; keep honest state
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void fetchNotifications();
  }, []);

  const handleMarkAllRead = async () => {
    try {
      await apiClient.request('POST', '/notifications/read-all');
      setUnreadCount(0);
      setNotifications((prev) => prev.map((n) => ({ ...n, read: true })));
    } catch {
      // Ignored
    }
  };

  return (
    <>
      <Button
        variant="ghost"
        size="sm"
        className="relative h-7 w-7 min-h-[44px] min-w-[44px] sm:min-h-0 sm:min-w-0 p-0 text-muted-foreground hover:text-foreground hover:bg-surface-200"
        onClick={() => {
          setOpen(true);
          void fetchNotifications();
        }}
        aria-label={`Notifications (${unreadCount} unread)`}
      >
        <Bell className="h-4 w-4" />
        {unreadCount > 0 && (
          <span className="absolute top-1 right-1 flex h-2 w-2">
            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary opacity-75"></span>
            <span className="relative inline-flex h-2 w-2 rounded-full bg-primary"></span>
          </span>
        )}
      </Button>

      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent side="right" className="w-full sm:max-w-md p-6">
          <SheetHeader className="pb-4 border-b border-border">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <SheetTitle>Notifications</SheetTitle>
                {unreadCount > 0 && (
                  <Badge variant="secondary" className="text-11">
                    {unreadCount} unread
                  </Badge>
                )}
              </div>
              {unreadCount > 0 && (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={handleMarkAllRead}
                  className="h-7 px-2 text-xs text-muted-foreground hover:text-foreground"
                >
                  <CheckCheck className="h-3.5 w-3.5 mr-1" /> Mark all read
                </Button>
              )}
            </div>
            <SheetDescription>System alerts, publishing updates, and task notices.</SheetDescription>
          </SheetHeader>

          <div className="py-4">
            {loading ? (
              <div className="space-y-3 py-6">
                <div className="h-12 w-full animate-pulse rounded bg-surface-200" />
                <div className="h-12 w-full animate-pulse rounded bg-surface-200" />
              </div>
            ) : notifications.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-12 text-center">
                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-surface-200 text-muted-foreground mb-3">
                  <CheckCircle2 className="h-5 w-5" />
                </div>
                <p className="text-13 font-medium text-foreground">You are all caught up</p>
                <p className="text-11 text-muted-foreground mt-0.5">No notifications to display.</p>
              </div>
            ) : (
              <div className="divide-y divide-border/60">
                {notifications.map((n) => (
                  <div key={n.id} className="py-3 space-y-1">
                    <div className="flex items-center justify-between">
                      <span className="text-12 font-medium text-foreground">{n.title || 'System Notification'}</span>
                      {n.created_at && (
                        <span className="text-[10px] text-muted-foreground">{n.created_at}</span>
                      )}
                    </div>
                    <p className="text-11 text-muted-foreground">{n.message}</p>
                  </div>
                ))}
              </div>
            )}
          </div>
        </SheetContent>
      </Sheet>
    </>
  );
}
