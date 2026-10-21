import { FC, useState, useEffect } from 'react';
import {
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Input,
  Label,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Switch,
} from '@bs/ui';
import { ShieldCheck, Save, KeyRound, Lock, Clock, AlertTriangle } from 'lucide-react';
import { apiClient } from '../lib/api';
import { problemToast } from '../lib/problem-toast';

interface SecuritySettings {
  password_min_length: number;
  password_require_uppercase: boolean;
  password_require_lowercase: boolean;
  password_require_numbers: boolean;
  password_require_symbols: boolean;
  two_factor_policy: 'optional' | 'mandatory_all' | 'mandatory_roles';
  two_factor_enforced_roles: string[];
  session_idle_timeout_minutes: number;
  session_absolute_lifetime_hours: number;
  login_max_attempts: number;
  login_decay_minutes: number;
  allowed_email_domains: string[];
}

export const SecuritySettingsView: FC = () => {
  const [settings, setSettings] = useState<SecuritySettings | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [domainsInput, setDomainsInput] = useState('');
  const [savedSuccess, setSavedSuccess] = useState(false);

  const loadSettings = async () => {
    setLoading(true);
    try {
      const res = await apiClient.request<SecuritySettings>('GET', '/settings/security');
      if (res.ok && res.data) {
        setSettings(res.data);
        setDomainsInput((res.data.allowed_email_domains || []).join(', '));
      } else {
        problemToast(res.status, res.data as any, 'Failed to load security settings');
      }
    } catch {
      problemToast(500, null, 'Error connecting to security service');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadSettings();
  }, []);

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!settings) return;
    setSaving(true);
    setSavedSuccess(false);

    try {
      const payload: SecuritySettings = {
        ...settings,
        allowed_email_domains: domainsInput
          .split(',')
          .map((d) => d.trim().toLowerCase())
          .filter(Boolean),
      };

      const res = await apiClient.request<SecuritySettings>('PUT', '/settings/security', payload);
      if (res.ok && res.data) {
        setSettings(res.data);
        setSavedSuccess(true);
        setTimeout(() => setSavedSuccess(false), 3000);
      } else {
        problemToast(res.status, res.data as any, 'Failed to save security settings');
      }
    } catch {
      problemToast(500, null, 'Error saving security settings');
    } finally {
      setSaving(false);
    }
  };

  if (loading || !settings) {
    return (
      <div className="flex min-h-[300px] flex-col items-center justify-center gap-3">
        <Spinner size={28} />
        <p className="text-13 text-muted-foreground">Loading security configuration...</p>
      </div>
    );
  }

  return (
    <form onSubmit={handleSave} className="space-y-6">
      {/* Password Policy */}
      <Card>
        <CardHeader>
          <div className="flex items-center gap-2">
            <KeyRound className="h-4 w-4 text-primary" />
            <CardTitle>Password Policy</CardTitle>
          </div>
          <CardDescription>
            Enforce complexity and minimum entropy for administrator and user passwords.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="password_min_length">Minimum Password Length</Label>
              <Input
                id="password_min_length"
                type="number"
                min={8}
                max={64}
                value={settings.password_min_length}
                onChange={(e) =>
                  setSettings({ ...settings, password_min_length: Number(e.target.value) })
                }
              />
              <span className="text-11 text-muted-foreground">Recommended: 12 or more characters.</span>
            </div>
          </div>

          <div className="space-y-3 pt-2">
            <div className="flex items-center justify-between">
              <div>
                <Label htmlFor="pwd_upper" className="cursor-pointer font-medium">Require Uppercase Letter</Label>
                <p className="text-12 text-muted-foreground">At least one uppercase ASCII character (A-Z)</p>
              </div>
              <Switch
                id="pwd_upper"
                checked={settings.password_require_uppercase}
                onCheckedChange={(val) =>
                  setSettings({ ...settings, password_require_uppercase: val })
                }
              />
            </div>

            <div className="flex items-center justify-between">
              <div>
                <Label htmlFor="pwd_lower" className="cursor-pointer font-medium">Require Lowercase Letter</Label>
                <p className="text-12 text-muted-foreground">At least one lowercase ASCII character (a-z)</p>
              </div>
              <Switch
                id="pwd_lower"
                checked={settings.password_require_lowercase}
                onCheckedChange={(val) =>
                  setSettings({ ...settings, password_require_lowercase: val })
                }
              />
            </div>

            <div className="flex items-center justify-between">
              <div>
                <Label htmlFor="pwd_nums" className="cursor-pointer font-medium">Require Numbers</Label>
                <p className="text-12 text-muted-foreground">At least one numerical digit (0-9)</p>
              </div>
              <Switch
                id="pwd_nums"
                checked={settings.password_require_numbers}
                onCheckedChange={(val) =>
                  setSettings({ ...settings, password_require_numbers: val })
                }
              />
            </div>

            <div className="flex items-center justify-between">
              <div>
                <Label htmlFor="pwd_symbols" className="cursor-pointer font-medium">Require Special Symbols</Label>
                <p className="text-12 text-muted-foreground">Punctuation and non-alphanumeric symbols</p>
              </div>
              <Switch
                id="pwd_symbols"
                checked={settings.password_require_symbols}
                onCheckedChange={(val) =>
                  setSettings({ ...settings, password_require_symbols: val })
                }
              />
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Two-Factor Authentication Policy */}
      <Card>
        <CardHeader>
          <div className="flex items-center gap-2">
            <ShieldCheck className="h-4 w-4 text-primary" />
            <CardTitle>Two-Factor Authentication (2FA)</CardTitle>
          </div>
          <CardDescription>
            Specify TOTP 2FA enforcement requirements across organizational roles.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="two_factor_policy">Enforcement Scope</Label>
            <Select
              value={settings.two_factor_policy}
              onValueChange={(val: any) => setSettings({ ...settings, two_factor_policy: val })}
            >
              <SelectTrigger id="two_factor_policy">
                <SelectValue placeholder="Select enforcement policy" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="optional">Optional for all users</SelectItem>
                <SelectItem value="mandatory_roles">Mandatory for privileged roles (Owner, Admin)</SelectItem>
                <SelectItem value="mandatory_all">Mandatory for all users</SelectItem>
              </SelectContent>
            </Select>
          </div>

          {settings.two_factor_policy === 'mandatory_all' && (
            <div className="flex items-center gap-2 rounded-md border border-warning/30 bg-warning/10 p-3 text-12 text-warning">
              <AlertTriangle className="h-4 w-4 shrink-0" />
              <span>Users without 2FA enabled will be forced into step-up setup on their next login.</span>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Session Management */}
      <Card>
        <CardHeader>
          <div className="flex items-center gap-2">
            <Clock className="h-4 w-4 text-primary" />
            <CardTitle>Session Lifetimes</CardTitle>
          </div>
          <CardDescription>
            Control idle session timeout and maximum absolute lifetime before re-authentication.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="session_idle">Idle Timeout (Minutes)</Label>
              <Input
                id="session_idle"
                type="number"
                min={5}
                max={1440}
                value={settings.session_idle_timeout_minutes}
                onChange={(e) =>
                  setSettings({ ...settings, session_idle_timeout_minutes: Number(e.target.value) })
                }
              />
              <span className="text-11 text-muted-foreground">Default: 60 minutes.</span>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="session_absolute">Absolute Session Lifetime (Hours)</Label>
              <Input
                id="session_absolute"
                type="number"
                min={1}
                max={720}
                value={settings.session_absolute_lifetime_hours}
                onChange={(e) =>
                  setSettings({ ...settings, session_absolute_lifetime_hours: Number(e.target.value) })
                }
              />
              <span className="text-11 text-muted-foreground">Maximum session duration regardless of activity.</span>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Rate Limiting & Allowed Domains */}
      <Card>
        <CardHeader>
          <div className="flex items-center gap-2">
            <Lock className="h-4 w-4 text-primary" />
            <CardTitle>Brute-Force Rate Limits & Domain Restriction</CardTitle>
          </div>
          <CardDescription>
            Defend against credential attacks and restrict registration to corporate domains.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="login_max_attempts">Max Failed Login Attempts</Label>
              <Input
                id="login_max_attempts"
                type="number"
                min={3}
                max={20}
                value={settings.login_max_attempts}
                onChange={(e) =>
                  setSettings({ ...settings, login_max_attempts: Number(e.target.value) })
                }
              />
              <span className="text-11 text-muted-foreground">Lockout triggered after consecutive failures.</span>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="login_decay">Lockout Window (Minutes)</Label>
              <Input
                id="login_decay"
                type="number"
                min={1}
                max={120}
                value={settings.login_decay_minutes}
                onChange={(e) =>
                  setSettings({ ...settings, login_decay_minutes: Number(e.target.value) })
                }
              />
              <span className="text-11 text-muted-foreground">Duration before failed login bucket decays.</span>
            </div>
          </div>

          <div className="space-y-1.5 pt-2">
            <Label htmlFor="allowed_domains">Allowed Email Domains</Label>
            <Input
              id="allowed_domains"
              placeholder="e.g. company.com, agency.net (leave empty for any domain)"
              value={domainsInput}
              onChange={(e) => setDomainsInput(e.target.value)}
            />
            <span className="text-11 text-muted-foreground">
              Comma-separated list. If set, only users with matching email domains may be invited.
            </span>
          </div>
        </CardContent>
      </Card>

      {/* Save Button */}
      <div className="flex items-center justify-between pt-2">
        {savedSuccess && (
          <span className="text-13 text-success-text font-medium">
            Security settings saved successfully.
          </span>
        )}
        <div className="ml-auto">
          <Button type="submit" loading={saving} data-testid="save-security-settings-btn">
            <Save className="mr-1.5 h-3.5 w-3.5" /> Save Security Policies
          </Button>
        </div>
      </div>
    </form>
  );
};
