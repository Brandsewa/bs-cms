import { FC, useEffect, useState } from 'react';
import {
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Label,
  Switch,
  problemToast,
  successToast,
} from '@bs/ui';
import { Bell, Mail, ShieldAlert, KeyRound, Globe, CheckCircle2 } from 'lucide-react';
import { FormPageSkeleton } from '../components/page-skeletons';
import { apiClient } from '../lib/api';

interface NotificationPreferences {
  in_app_system_alerts: boolean;
  email_on_login: boolean;
  email_on_role_change: boolean;
  email_security_alerts: boolean;
  email_publish_digest: boolean;
}

const DEFAULT_PREFERENCES: NotificationPreferences = {
  in_app_system_alerts: true,
  email_on_login: true,
  email_on_role_change: true,
  email_security_alerts: true,
  email_publish_digest: false,
};

export const NotificationsPreferencesView: FC = () => {
  const [prefs, setPrefs] = useState<NotificationPreferences>(DEFAULT_PREFERENCES);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  const loadPreferences = async () => {
    setLoading(true);
    try {
      const res = await apiClient.request<{ preferences?: Partial<NotificationPreferences> }>(
        'GET',
        '/me/notification-preferences'
      );
      if (res.ok && res.data?.preferences) {
        setPrefs((prev) => ({
          ...prev,
          ...res.data?.preferences,
        }));
      }
    } catch {
      problemToast(null, { fallbackMessage: 'Failed to load notification preferences.' });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadPreferences();
  }, []);

  const handleToggle = (key: keyof NotificationPreferences) => {
    setPrefs((prev) => ({ ...prev, [key]: !prev[key] }));
  };

  const handleSave = async () => {
    setSaving(true);
    setSaved(false);
    try {
      const res = await apiClient.request<{ preferences: NotificationPreferences }>(
        'PATCH',
        '/me/notification-preferences',
        {
          body: { preferences: prefs },
        }
      );
      if (res.ok) {
        if (res.data?.preferences) {
          setPrefs((prev) => ({ ...prev, ...res.data?.preferences }));
        }
        setSaved(true);
        successToast('Notification preferences saved successfully.');
        setTimeout(() => setSaved(false), 3000);
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to save notification preferences.' });
      }
    } catch (err) {
      problemToast(err, { fallbackMessage: 'Error saving notification preferences.' });
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <FormPageSkeleton
        title="Notification Preferences"
        description="Configure dispatch preferences for system events, content life-cycles, and security triggers."
      />
    );
  }

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <div className="flex items-center gap-2">
            <Bell className="h-4 w-4 text-primary" />
            <CardTitle>In-App & Email Notification Channels</CardTitle>
          </div>
          <CardDescription>
            Configure dispatch preferences for system events, content life-cycles, and security triggers.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center justify-between">
            <div className="space-y-0.5">
              <div className="flex items-center gap-2">
                <Bell className="h-4 w-4 text-muted-foreground" />
                <Label htmlFor="pref_in_app" className="cursor-pointer font-medium">
                  In-App System Alerts
                </Label>
              </div>
              <p className="text-12 text-muted-foreground">
                Display notifications in the admin header bell for schema migrations, media jobs, and system status changes.
              </p>
            </div>
            <Switch
              id="pref_in_app"
              checked={prefs.in_app_system_alerts}
              onCheckedChange={() => handleToggle('in_app_system_alerts')}
            />
          </div>

          <div className="flex items-center justify-between">
            <div className="space-y-0.5">
              <div className="flex items-center gap-2">
                <ShieldAlert className="h-4 w-4 text-muted-foreground" />
                <Label htmlFor="pref_sec_alerts" className="cursor-pointer font-medium">
                  Security & Step-Up Alerts
                </Label>
              </div>
              <p className="text-12 text-muted-foreground">
                Immediate email notifications when security-critical events or step-up authentication failures occur.
              </p>
            </div>
            <Switch
              id="pref_sec_alerts"
              checked={prefs.email_security_alerts}
              onCheckedChange={() => handleToggle('email_security_alerts')}
            />
          </div>

          <div className="flex items-center justify-between">
            <div className="space-y-0.5">
              <div className="flex items-center gap-2">
                <Mail className="h-4 w-4 text-muted-foreground" />
                <Label htmlFor="pref_login" className="cursor-pointer font-medium">
                  Sign-In Email Notifications
                </Label>
              </div>
              <p className="text-12 text-muted-foreground">
                Send an email verification alert when a new device or browser session logs into your administrator account.
              </p>
            </div>
            <Switch
              id="pref_login"
              checked={prefs.email_on_login}
              onCheckedChange={() => handleToggle('email_on_login')}
            />
          </div>

          <div className="flex items-center justify-between">
            <div className="space-y-0.5">
              <div className="flex items-center gap-2">
                <KeyRound className="h-4 w-4 text-muted-foreground" />
                <Label htmlFor="pref_role" className="cursor-pointer font-medium">
                  Role & Permission Updates
                </Label>
              </div>
              <p className="text-12 text-muted-foreground">
                Receive an email confirmation whenever your user roles or organizational permissions are modified.
              </p>
            </div>
            <Switch
              id="pref_role"
              checked={prefs.email_on_role_change}
              onCheckedChange={() => handleToggle('email_on_role_change')}
            />
          </div>

          <div className="flex items-center justify-between">
            <div className="space-y-0.5">
              <div className="flex items-center gap-2">
                <Globe className="h-4 w-4 text-muted-foreground" />
                <Label htmlFor="pref_publish_digest" className="cursor-pointer font-medium">
                  Publishing Activity Digest
                </Label>
              </div>
              <p className="text-12 text-muted-foreground">
                Periodic email summary covering published articles, static site builds, and production releases.
              </p>
            </div>
            <Switch
              id="pref_publish_digest"
              checked={prefs.email_publish_digest}
              onCheckedChange={() => handleToggle('email_publish_digest')}
            />
          </div>

          <div className="flex items-center justify-between pt-4">
            {saved && (
              <span className="text-12 text-success-text flex items-center gap-1 font-medium">
                <CheckCircle2 className="h-3.5 w-3.5" /> Preferences saved.
              </span>
            )}
            <Button
              size="sm"
              className="ml-auto"
              onClick={handleSave}
              loading={saving}
              data-testid="save-notifications-btn"
            >
              Save Preferences
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};
