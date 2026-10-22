import { useState, useEffect } from 'react';
import {
  Alert,
  Badge,
  Breadcrumbs,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
  ConfirmationModal,
  ErrorState,
  FormItemLayout,
  Input,
  PageContainer,
  PageHeader,
  PageLoadingSkeleton,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Switch,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from '@bs/ui';
import {
  Copy,
  KeyRound,
  Laptop,
  Mail,
  RotateCw,
  Shield,
  ShieldAlert,
  ShieldCheck,
  Smartphone,
  Trash2,
  User,
} from 'lucide-react';
import { apiClient } from '../lib/api';
import { useSession } from '../auth/session-context';
import { StepUpDialog } from '../auth/step-up-dialog';
import { QrCodeDisplay } from './qr-code';

interface UserProfile {
  id: string;
  email: string;
  name: string;
  status: string;
  avatar_media_id: string | null;
  locale: string;
  timezone: string;
  two_factor_enabled: boolean;
  roles: Array<{ id: string; handle: string; name: string }>;
  created_at: string;
  updated_at: string;
  last_login_at: string | null;
}

interface ActiveSession {
  id: string;
  user_id: string;
  ip: string;
  user_agent: string;
  mfa_passed_at: string | null;
  created_at: string;
  last_seen_at: string;
  expires_at: string;
  is_current: boolean;
}

interface NotificationPreferences {
  email_on_login: boolean;
  email_on_role_change: boolean;
  in_app_system_alerts: boolean;
}

export function MyAccountView() {
  const { refreshSession } = useSession();

  // Loading & Global Status
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState('profile');

  // Profile Form State
  const [profile, setProfile] = useState<UserProfile | null>(null);
  const [name, setName] = useState('');
  const [locale, setLocale] = useState('en');
  const [timezone, setTimezone] = useState('UTC');
  const [profileSaving, setProfileSaving] = useState(false);
  const [profileSuccess, setProfileSuccess] = useState<string | null>(null);
  const [profileError, setProfileError] = useState<string | null>(null);

  // Password Change State
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [passwordSaving, setPasswordSaving] = useState(false);
  const [passwordSuccess, setPasswordSuccess] = useState<string | null>(null);
  const [passwordError, setPasswordError] = useState<string | null>(null);
  const [passwordPolicyError, setPasswordPolicyError] = useState<string | null>(null);

  // Email Change State
  const [newEmail, setNewEmail] = useState('');
  const [emailSaving, setEmailSaving] = useState(false);
  const [emailSuccess, setEmailSuccess] = useState<string | null>(null);
  const [emailError, setEmailError] = useState<string | null>(null);

  // 2FA State
  const [twoFactorEnabled, setTwoFactorEnabled] = useState(false);
  const [twoFactorSetupData, setTwoFactorSetupData] = useState<{
    secret: string;
    otpauth_uri: string;
  } | null>(null);
  const [totpCode, setTotpCode] = useState('');
  const [totpVerifying, setTotpVerifying] = useState(false);
  const [totpError, setTotpError] = useState<string | null>(null);
  const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
  const [copiedCodes, setCopiedCodes] = useState(false);

  // Sessions State
  const [sessions, setSessions] = useState<ActiveSession[]>([]);
  const [revokingSessionId, setRevokingSessionId] = useState<string | null>(null);

  // Notification Preferences State
  const [notifPrefs, setNotifPrefs] = useState<NotificationPreferences>({
    email_on_login: true,
    email_on_role_change: true,
    in_app_system_alerts: true,
  });
  const [notifSaving, setNotifSaving] = useState(false);
  const [notifSuccess, setNotifSuccess] = useState<string | null>(null);
  const [notifError, setNotifError] = useState<string | null>(null);

  // Step-Up Dialog State
  const [stepUpOpen, setStepUpOpen] = useState(false);
  const [stepUpAction, setStepUpAction] = useState<
    'password_change' | 'email_change' | 'disable_2fa' | 'regen_recovery' | null
  >(null);

  // Confirmation Modal State
  const [confirmModalOpen, setConfirmModalOpen] = useState(false);
  const [confirmModalConfig, setConfirmModalConfig] = useState<{
    title: string;
    body: string;
    onConfirm: () => void;
  }>({
    title: '',
    body: '',
    onConfirm: () => {},
  });

  const loadData = async () => {
    setLoading(true);
    setError(null);

    try {
      // 1. Load Profile
      const meRes = await apiClient.request<UserProfile>('GET', '/me');
      if (!meRes.ok) {
        setError(meRes.problem?.detail || 'Failed to load user profile.');
        setLoading(false);
        return;
      }

      setProfile(meRes.data);
      setName(meRes.data.name || '');
      setLocale(meRes.data.locale || 'en');
      setTimezone(meRes.data.timezone || 'UTC');
      setTwoFactorEnabled(Boolean(meRes.data.two_factor_enabled));

      // 2. Load Sessions
      const sessionsRes = await apiClient.request<{ items: ActiveSession[] }>(
        'GET',
        '/me/sessions'
      );
      if (sessionsRes.ok) {
        setSessions(sessionsRes.data.items || []);
      }

      // 3. Load Notification Preferences
      const notifRes = await apiClient.request<{ preferences: NotificationPreferences }>(
        'GET',
        '/me/notification-preferences'
      );
      if (notifRes.ok && notifRes.data?.preferences) {
        setNotifPrefs(notifRes.data.preferences);
      }
    } catch {
      setError('A network error occurred while loading account details.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadData();
  }, []);

  // Update Profile Details
  const handleUpdateProfile = async (e: React.FormEvent) => {
    e.preventDefault();
    setProfileSaving(true);
    setProfileSuccess(null);
    setProfileError(null);

    try {
      const res = await apiClient.request('PATCH', '/me', {
        body: {
          name: name.trim(),
          locale,
          timezone,
        },
      });

      if (!res.ok) {
        setProfileError(res.problem?.detail || 'Failed to update profile.');
        return;
      }

      setProfileSuccess('Profile updated successfully.');
      await refreshSession();
    } catch {
      setProfileError('A network error occurred while updating profile.');
    } finally {
      setProfileSaving(false);
    }
  };

  // Change Password
  const handleChangePassword = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    setPasswordSaving(true);
    setPasswordSuccess(null);
    setPasswordError(null);
    setPasswordPolicyError(null);

    if (newPassword !== confirmPassword) {
      setPasswordError('New password and confirmation do not match.');
      setPasswordSaving(false);
      return;
    }

    if (newPassword.length < 8) {
      setPasswordPolicyError('New password must be at least 8 characters long.');
      setPasswordSaving(false);
      return;
    }

    try {
      const res = await apiClient.request('POST', '/me/password', {
        body: {
          current_password: currentPassword,
          new_password: newPassword,
        },
      });

      if (!res.ok) {
        if (res.status === 403) {
          // Check if step-up authentication is required
          const detail = (res.problem?.detail || '').toLowerCase();
          const title = (res.problem?.title || '').toLowerCase();
          if (
            detail.includes('step-up') ||
            detail.includes('step_up') ||
            title.includes('step-up') ||
            title.includes('step_up') ||
            res.status === 403
          ) {
            setStepUpAction('password_change');
            setStepUpOpen(true);
            return;
          }
        }

        if (res.status === 422) {
          // Password policy failure or field validation error
          const policyMsg =
            res.problem?.errors?.['/new_password']?.[0] ||
            res.problem?.errors?.['new_password']?.[0] ||
            res.problem?.detail ||
            'Password does not meet complexity requirements.';
          setPasswordPolicyError(policyMsg);
          return;
        }

        setPasswordError(res.problem?.detail || 'Failed to change password.');
        return;
      }

      setPasswordSuccess('Password changed successfully. Other active sessions were revoked.');
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');

      // Refresh sessions list
      const sessionsRes = await apiClient.request<{ items: ActiveSession[] }>(
        'GET',
        '/me/sessions'
      );
      if (sessionsRes.ok) {
        setSessions(sessionsRes.data.items || []);
      }
    } catch {
      setPasswordError('A network error occurred while changing password.');
    } finally {
      setPasswordSaving(false);
    }
  };

  // Request Email Change (Requires Step-Up)
  const handleRequestEmailChange = async () => {
    setEmailSaving(true);
    setEmailSuccess(null);
    setEmailError(null);

    try {
      const res = await apiClient.request('POST', '/me/email', {
        body: {
          new_email: newEmail.trim(),
        },
      });

      if (!res.ok) {
        if (res.status === 403) {
          // Needs step-up verification
          setStepUpAction('email_change');
          setStepUpOpen(true);
          return;
        }
        setEmailError(res.problem?.detail || 'Failed to request email change.');
        return;
      }

      setEmailSuccess(`Verification link sent to ${newEmail.trim()}. Please check your inbox.`);
      setNewEmail('');
    } catch {
      setEmailError('A network error occurred while requesting email change.');
    } finally {
      setEmailSaving(false);
    }
  };

  // Setup 2FA: Initiate
  const handleStart2faSetup = async () => {
    setTotpError(null);
    try {
      const res = await apiClient.request<{ secret: string; otpauth_uri: string }>(
        'POST',
        '/me/2fa/setup'
      );
      if (!res.ok) {
        setTotpError(res.problem?.detail || 'Failed to initiate 2FA setup.');
        return;
      }
      setTwoFactorSetupData(res.data);
    } catch {
      setTotpError('A network error occurred while starting 2FA setup.');
    }
  };

  // Setup 2FA: Confirm with code
  const handleConfirm2fa = async (e: React.FormEvent) => {
    e.preventDefault();
    setTotpVerifying(true);
    setTotpError(null);

    try {
      const res = await apiClient.request<{ status: string; recovery_codes: string[] }>(
        'POST',
        '/me/2fa/confirm',
        {
          body: {
            code: totpCode.trim(),
          },
        }
      );

      if (!res.ok) {
        setTotpError(res.problem?.detail || 'Invalid verification code. Please check and try again.');
        return;
      }

      setTwoFactorEnabled(true);
      setRecoveryCodes(res.data.recovery_codes || []);
      setTwoFactorSetupData(null);
      setTotpCode('');
      await refreshSession();
    } catch {
      setTotpError('A network error occurred while verifying 2FA code.');
    } finally {
      setTotpVerifying(false);
    }
  };

  // Disable 2FA (Requires Step-Up)
  const handleDisable2fa = async () => {
    try {
      const res = await apiClient.request('POST', '/me/2fa/disable');
      if (!res.ok) {
        if (res.status === 403) {
          setStepUpAction('disable_2fa');
          setStepUpOpen(true);
          return;
        }
        setTotpError(res.problem?.detail || 'Failed to disable 2FA.');
        return;
      }

      setTwoFactorEnabled(false);
      setRecoveryCodes(null);
      setTwoFactorSetupData(null);
      await refreshSession();
    } catch {
      setTotpError('A network error occurred while disabling 2FA.');
    }
  };

  // Regenerate Recovery Codes (Requires Step-Up)
  const handleRegenerateRecoveryCodes = async () => {
    try {
      const res = await apiClient.request<{ recovery_codes: string[] }>(
        'POST',
        '/me/2fa/recovery-codes'
      );
      if (!res.ok) {
        if (res.status === 403) {
          setStepUpAction('regen_recovery');
          setStepUpOpen(true);
          return;
        }
        setTotpError(res.problem?.detail || 'Failed to regenerate recovery codes.');
        return;
      }

      setRecoveryCodes(res.data.recovery_codes || []);
    } catch {
      setTotpError('A network error occurred while regenerating recovery codes.');
    }
  };

  // Revoke an Active Session
  const handleRevokeSession = async (sessionId: string) => {
    setRevokingSessionId(sessionId);
    try {
      const res = await apiClient.request('DELETE', `/me/sessions/${sessionId}`);
      if (!res.ok) {
        return;
      }
      setSessions((prev) => prev.filter((s) => s.id !== sessionId));
    } finally {
      setRevokingSessionId(null);
    }
  };

  // Save Notification Preferences
  const handleSaveNotifications = async (e: React.FormEvent) => {
    e.preventDefault();
    setNotifSaving(true);
    setNotifSuccess(null);
    setNotifError(null);

    try {
      const res = await apiClient.request('PATCH', '/me/notification-preferences', {
        body: notifPrefs,
      });

      if (!res.ok) {
        setNotifError(res.problem?.detail || 'Failed to save notification preferences.');
        return;
      }

      setNotifSuccess('Notification preferences updated successfully.');
    } catch {
      setNotifError('A network error occurred while saving notification preferences.');
    } finally {
      setNotifSaving(false);
    }
  };

  // Callback on successful step-up authentication
  const handleStepUpSuccess = () => {
    if (stepUpAction === 'password_change') {
      void handleChangePassword();
    } else if (stepUpAction === 'email_change') {
      void handleRequestEmailChange();
    } else if (stepUpAction === 'disable_2fa') {
      void handleDisable2fa();
    } else if (stepUpAction === 'regen_recovery') {
      void handleRegenerateRecoveryCodes();
    }
    setStepUpAction(null);
  };

  const breadcrumbs = [
    { label: 'Admin', href: '/admin/' },
    { label: 'My Account' },
  ];

  if (loading) {
    return (
      <PageContainer width="full" className="space-y-6">
        <Breadcrumbs items={breadcrumbs} className="text-12" />
        <PageHeader title="My Account" description="Manage your personal profile, security credentials, and active sessions." />
        <PageLoadingSkeleton lines={6} />
      </PageContainer>
    );
  }

  if (error) {
    return (
      <PageContainer width="full" className="space-y-6">
        <Breadcrumbs items={breadcrumbs} className="text-12" />
        <PageHeader title="My Account" description="Manage your personal profile, security credentials, and active sessions." />
        <ErrorState message={error} onRetry={() => void loadData()} />
      </PageContainer>
    );
  }

  return (
    <PageContainer width="full" className="space-y-6">
      <div className="space-y-1.5">
        <Breadcrumbs items={breadcrumbs} className="text-12" />
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <PageHeader
            title="My Account"
            description="Manage your personal profile, security credentials, and active sessions."
          />
          <div className="flex items-center gap-2">
            <span className="text-12 text-muted-foreground">Signed in as:</span>
            <Badge variant="outline" className="font-mono text-11">
              {profile?.email}
            </Badge>
          </div>
        </div>
      </div>

      <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
        <TabsList className="grid grid-cols-2 sm:grid-cols-5 w-full sm:w-auto h-auto p-1 bg-surface-100">
          <TabsTrigger value="profile" className="text-12 py-1.5 flex items-center gap-1.5">
            <User className="h-3.5 w-3.5" /> Profile
          </TabsTrigger>
          <TabsTrigger value="password" className="text-12 py-1.5 flex items-center gap-1.5">
            <KeyRound className="h-3.5 w-3.5" /> Password
          </TabsTrigger>
          <TabsTrigger value="email" className="text-12 py-1.5 flex items-center gap-1.5">
            <Mail className="h-3.5 w-3.5" /> Email
          </TabsTrigger>
          <TabsTrigger value="security" className="text-12 py-1.5 flex items-center gap-1.5">
            <Shield className="h-3.5 w-3.5" /> 2FA Security
          </TabsTrigger>
          <TabsTrigger value="sessions" className="text-12 py-1.5 flex items-center gap-1.5">
            <Laptop className="h-3.5 w-3.5" /> Sessions
          </TabsTrigger>
        </TabsList>

        {/* 1. Profile Tab */}
        <TabsContent value="profile" className="space-y-6">
          <form onSubmit={handleUpdateProfile}>
            <Card>
              <CardHeader>
                <CardTitle>Profile Details</CardTitle>
                <CardDescription>
                  Update your display name, language locale, and preferred timezone.
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                {profileSuccess && (
                  <Alert variant="success">
                    <span>{profileSuccess}</span>
                  </Alert>
                )}
                {profileError && (
                  <Alert variant="error">
                    <ShieldAlert className="h-4 w-4" />
                    <span>{profileError}</span>
                  </Alert>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                  <FormItemLayout label="Full Name" id="profile-name">
                    <Input
                      id="profile-name"
                      value={name}
                      onChange={(e) => setName(e.target.value)}
                      placeholder="Your full name"
                      required
                    />
                  </FormItemLayout>

                  <FormItemLayout label="Account Role" id="profile-role">
                    <Input
                      id="profile-role"
                      value={profile?.roles?.map((r) => r.name).join(', ') || 'User'}
                      disabled
                      className="bg-muted text-muted-foreground"
                    />
                  </FormItemLayout>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                  <FormItemLayout label="Preferred Locale" id="profile-locale">
                    <Select value={locale} onValueChange={setLocale}>
                      <SelectTrigger id="profile-locale" aria-label="Preferred Locale">
                        <SelectValue placeholder="Select locale" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="en">English (en)</SelectItem>
                        <SelectItem value="en-US">English - United States (en-US)</SelectItem>
                        <SelectItem value="en-GB">English - United Kingdom (en-GB)</SelectItem>
                      </SelectContent>
                    </Select>
                  </FormItemLayout>

                  <FormItemLayout label="Timezone" id="profile-timezone">
                    <Select value={timezone} onValueChange={setTimezone}>
                      <SelectTrigger id="profile-timezone" aria-label="Timezone">
                        <SelectValue placeholder="Select timezone" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="UTC">UTC (Universal Time Coordinated)</SelectItem>
                        <SelectItem value="America/New_York">America/New_York (EST/EDT)</SelectItem>
                        <SelectItem value="America/Los_Angeles">America/Los_Angeles (PST/PDT)</SelectItem>
                        <SelectItem value="Europe/London">Europe/London (GMT/BST)</SelectItem>
                        <SelectItem value="Asia/Kathmandu">Asia/Kathmandu (NPT)</SelectItem>
                        <SelectItem value="Asia/Tokyo">Asia/Tokyo (JST)</SelectItem>
                      </SelectContent>
                    </Select>
                  </FormItemLayout>
                </div>
              </CardContent>
              <CardFooter className="flex justify-end border-t border-border pt-4">
                <Button type="submit" disabled={profileSaving}>
                  {profileSaving ? 'Saving...' : 'Save Profile'}
                </Button>
              </CardFooter>
            </Card>
          </form>

          {/* Notification Preferences Sub-Card */}
          <form onSubmit={handleSaveNotifications}>
            <Card>
              <CardHeader>
                <CardTitle>Notification Preferences</CardTitle>
                <CardDescription>
                  Configure how and when BS CMS sends alerts regarding your account.
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                {notifSuccess && (
                  <Alert variant="success">
                    <span>{notifSuccess}</span>
                  </Alert>
                )}
                {notifError && (
                  <Alert variant="error">
                    <ShieldAlert className="h-4 w-4" />
                    <span>{notifError}</span>
                  </Alert>
                )}

                <div className="divide-y divide-border/60">
                  <div className="flex items-center justify-between py-3">
                    <div className="space-y-0.5">
                      <p className="text-13 font-medium text-foreground">Sign-in alert emails</p>
                      <p className="text-11 text-muted-foreground">Receive an email whenever your account logs in from a new device.</p>
                    </div>
                    <Switch
                      checked={notifPrefs.email_on_login}
                      onCheckedChange={(v) => setNotifPrefs((p) => ({ ...p, email_on_login: v }))}
                      aria-label="Toggle sign-in alert emails"
                    />
                  </div>

                  <div className="flex items-center justify-between py-3">
                    <div className="space-y-0.5">
                      <p className="text-13 font-medium text-foreground">Role assignment alerts</p>
                      <p className="text-11 text-muted-foreground">Receive notification when your administrative permissions change.</p>
                    </div>
                    <Switch
                      checked={notifPrefs.email_on_role_change}
                      onCheckedChange={(v) => setNotifPrefs((p) => ({ ...p, email_on_role_change: v }))}
                      aria-label="Toggle role assignment alerts"
                    />
                  </div>

                  <div className="flex items-center justify-between py-3">
                    <div className="space-y-0.5">
                      <p className="text-13 font-medium text-foreground">In-app system banners</p>
                      <p className="text-11 text-muted-foreground">Show broadcast banners for system health and maintenance notices.</p>
                    </div>
                    <Switch
                      checked={notifPrefs.in_app_system_alerts}
                      onCheckedChange={(v) => setNotifPrefs((p) => ({ ...p, in_app_system_alerts: v }))}
                      aria-label="Toggle in-app system banners"
                    />
                  </div>
                </div>
              </CardContent>
              <CardFooter className="flex justify-end border-t border-border pt-4">
                <Button type="submit" variant="outline" disabled={notifSaving}>
                  {notifSaving ? 'Updating...' : 'Update Notification Settings'}
                </Button>
              </CardFooter>
            </Card>
          </form>
        </TabsContent>

        {/* 2. Password Tab */}
        <TabsContent value="password" className="space-y-6">
          <form onSubmit={handleChangePassword}>
            <Card>
              <CardHeader>
                <CardTitle>Change Password</CardTitle>
                <CardDescription>
                  Ensure your account is using a long, random password to stay secure. Changing your password automatically revokes all other active sessions.
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4 max-w-xl">
                {passwordSuccess && (
                  <Alert variant="success">
                    <span>{passwordSuccess}</span>
                  </Alert>
                )}
                {passwordError && (
                  <Alert variant="error">
                    <ShieldAlert className="h-4 w-4" />
                    <span>{passwordError}</span>
                  </Alert>
                )}

                <FormItemLayout label="Current Password" id="current-password">
                  <Input
                    id="current-password"
                    type="password"
                    value={currentPassword}
                    onChange={(e) => setCurrentPassword(e.target.value)}
                    required
                    autoComplete="current-password"
                  />
                </FormItemLayout>

                <FormItemLayout
                  label="New Password"
                  id="new-password"
                  error={passwordPolicyError || undefined}
                >
                  <Input
                    id="new-password"
                    type="password"
                    value={newPassword}
                    onChange={(e) => {
                      setNewPassword(e.target.value);
                      if (passwordPolicyError) setPasswordPolicyError(null);
                    }}
                    required
                    minLength={8}
                    autoComplete="new-password"
                    aria-invalid={Boolean(passwordPolicyError)}
                  />
                  <p className="text-11 text-muted-foreground mt-1">
                    Must be at least 12 characters long with a mix of letters, numbers, and symbols.
                  </p>
                </FormItemLayout>

                <FormItemLayout label="Confirm New Password" id="confirm-password">
                  <Input
                    id="confirm-password"
                    type="password"
                    value={confirmPassword}
                    onChange={(e) => setConfirmPassword(e.target.value)}
                    required
                    autoComplete="new-password"
                  />
                </FormItemLayout>
              </CardContent>
              <CardFooter className="flex justify-end border-t border-border pt-4">
                <Button type="submit" disabled={passwordSaving}>
                  {passwordSaving ? 'Updating Password...' : 'Update Password'}
                </Button>
              </CardFooter>
            </Card>
          </form>
        </TabsContent>

        {/* 3. Email Tab */}
        <TabsContent value="email" className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Email Address</CardTitle>
              <CardDescription>
                Your email address is used for authentication, password resets, and critical system notifications.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4 max-w-xl">
              {emailSuccess && (
                <Alert variant="success">
                  <span>{emailSuccess}</span>
                </Alert>
              )}
              {emailError && (
                <Alert variant="error">
                  <ShieldAlert className="h-4 w-4" />
                  <span>{emailError}</span>
                </Alert>
              )}

              <FormItemLayout label="Current Email Address" id="current-email">
                <Input
                  id="current-email"
                  value={profile?.email || ''}
                  disabled
                  className="bg-muted text-muted-foreground"
                />
              </FormItemLayout>

              <FormItemLayout label="New Email Address" id="new-email">
                <Input
                  id="new-email"
                  type="email"
                  value={newEmail}
                  onChange={(e) => setNewEmail(e.target.value)}
                  placeholder="name@example.com"
                />
                <p className="text-11 text-muted-foreground mt-1">
                  Changing your email address requires step-up security verification. A verification link will be sent to the new address.
                </p>
              </FormItemLayout>
            </CardContent>
            <CardFooter className="flex justify-end border-t border-border pt-4">
              <Button
                type="button"
                onClick={() => void handleRequestEmailChange()}
                disabled={emailSaving || !newEmail || newEmail === profile?.email}
              >
                {emailSaving ? 'Requesting...' : 'Request Email Change'}
              </Button>
            </CardFooter>
          </Card>
        </TabsContent>

        {/* 4. 2FA Security Tab */}
        <TabsContent value="security" className="space-y-6">
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle>Two-Factor Authentication (2FA)</CardTitle>
                  <CardDescription>
                    Protect your account with Time-based One-Time Passwords (TOTP) using Google Authenticator, 1Password, or Authy.
                  </CardDescription>
                </div>
                {twoFactorEnabled ? (
                  <Badge variant="outline" className="border-emerald-500/40 text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 gap-1">
                    <ShieldCheck className="h-3.5 w-3.5" /> Enabled
                  </Badge>
                ) : (
                  <Badge variant="outline" className="border-amber-500/40 text-amber-600 dark:text-amber-400 bg-amber-500/10 gap-1">
                    <ShieldAlert className="h-3.5 w-3.5" /> Disabled
                  </Badge>
                )}
              </div>
            </CardHeader>
            <CardContent className="space-y-4">
              {totpError && (
                <Alert variant="error">
                  <ShieldAlert className="h-4 w-4" />
                  <span>{totpError}</span>
                </Alert>
              )}

              {!twoFactorEnabled && !twoFactorSetupData && (
                <div className="rounded-md border border-border p-4 space-y-3 bg-surface-100">
                  <p className="text-13 text-foreground font-medium">
                    Two-factor authentication adds an extra layer of security to your BS CMS account.
                  </p>
                  <p className="text-12 text-muted-foreground">
                    When enabled, you will be prompted for a 6-digit TOTP code in addition to your password upon logging in.
                  </p>
                  <Button type="button" onClick={() => void handleStart2faSetup()} className="gap-2">
                    <Smartphone className="h-4 w-4" /> Set Up Two-Factor Authentication
                  </Button>
                </div>
              )}

              {/* 2FA Setup Flow (QR Code + Manual Secret + Verification Code) */}
              {!twoFactorEnabled && twoFactorSetupData && (
                <div className="rounded-md border border-border p-4 space-y-6 bg-surface-100">
                  <div className="flex flex-col md:flex-row gap-6 items-start">
                    <div className="shrink-0 flex flex-col items-center">
                      <QrCodeDisplay value={twoFactorSetupData.otpauth_uri} size={180} />
                      <span className="text-[11px] text-muted-foreground mt-2">Scan with Authenticator</span>
                    </div>

                    <div className="space-y-4 flex-1">
                      <div>
                        <h4 className="text-13 font-semibold text-foreground">Step 1: Scan QR or enter secret</h4>
                        <p className="text-12 text-muted-foreground mt-0.5">
                          Scan the QR code with your authenticator app, or manually copy the secret key below:
                        </p>
                        <div className="mt-2 flex items-center gap-2">
                          <code className="rounded bg-surface-200 px-2 py-1 text-12 font-mono text-foreground font-semibold">
                            {twoFactorSetupData.secret}
                          </code>
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 px-2 text-xs"
                            onClick={() => {
                              void navigator.clipboard.writeText(twoFactorSetupData.secret);
                            }}
                          >
                            <Copy className="h-3 w-3 mr-1" /> Copy
                          </Button>
                        </div>
                      </div>

                      <form onSubmit={handleConfirm2fa} className="space-y-3 pt-2">
                        <h4 className="text-13 font-semibold text-foreground">Step 2: Enter 6-digit verification code</h4>
                        <div className="flex items-center gap-2 max-w-xs">
                          <Input
                            value={totpCode}
                            onChange={(e) => setTotpCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                            placeholder="123456"
                            maxLength={6}
                            className="font-mono text-center tracking-widest text-16 h-10"
                            required
                            aria-label="6-digit TOTP verification code"
                          />
                          <Button type="submit" disabled={totpVerifying || totpCode.length !== 6}>
                            {totpVerifying ? 'Verifying...' : 'Verify & Enable'}
                          </Button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              )}

              {/* 2FA Enabled State & Recovery Codes */}
              {twoFactorEnabled && (
                <div className="space-y-4">
                  <div className="rounded-md border border-emerald-500/30 bg-emerald-500/5 p-4 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                      <ShieldCheck className="h-5 w-5 text-emerald-500" />
                      <div>
                        <p className="text-13 font-medium text-foreground">2FA is actively protecting your account</p>
                        <p className="text-11 text-muted-foreground">TOTP authentication is required at login.</p>
                      </div>
                    </div>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        setConfirmModalConfig({
                          title: 'Disable Two-Factor Authentication',
                          body:
                            'Are you sure you want to disable two-factor authentication? Your account will be less secure.',
                          onConfirm: () => void handleDisable2fa(),
                        });
                        setConfirmModalOpen(true);
                      }}
                      className="text-destructive hover:text-destructive"
                    >
                      Disable 2FA
                    </Button>
                  </div>

                  {/* Recovery Codes Display or Action */}
                  <div className="rounded-md border border-border p-4 space-y-3">
                    <div className="flex items-center justify-between">
                      <div>
                        <h4 className="text-13 font-semibold text-foreground">Backup Recovery Codes</h4>
                        <p className="text-11 text-muted-foreground">
                          Store these codes in a safe password manager. If you lose access to your authenticator, each code can be used once.
                        </p>
                      </div>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => void handleRegenerateRecoveryCodes()}
                        className="gap-1.5"
                      >
                        <RotateCw className="h-3.5 w-3.5" /> Regenerate Codes
                      </Button>
                    </div>

                    {recoveryCodes && recoveryCodes.length > 0 && (
                      <div className="mt-3 space-y-2">
                        <div className="grid grid-cols-2 sm:grid-cols-5 gap-2 rounded bg-surface-200 p-3 font-mono text-12 text-center">
                          {recoveryCodes.map((c, i) => (
                            <span key={i} className="rounded bg-surface-100 p-1 border border-border/40 text-foreground font-semibold">
                              {c}
                            </span>
                          ))}
                        </div>
                        <div className="flex justify-end">
                          <Button
                            variant="ghost"
                            size="sm"
                            className="text-xs"
                            onClick={() => {
                              void navigator.clipboard.writeText(recoveryCodes.join('\n'));
                              setCopiedCodes(true);
                              setTimeout(() => setCopiedCodes(false), 2000);
                            }}
                          >
                            <Copy className="h-3.5 w-3.5 mr-1" />
                            {copiedCodes ? 'Copied to Clipboard!' : 'Copy All Codes'}
                          </Button>
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        {/* 5. Sessions Tab */}
        <TabsContent value="sessions" className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Active Sessions</CardTitle>
              <CardDescription>
                Devices and browsers that currently have active session cookies for your account. You can revoke any unrecognized session.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
              {sessions.length === 0 ? (
                <p className="text-12 text-muted-foreground py-4 text-center">No active sessions found.</p>
              ) : (
                <div className="divide-y divide-border/60">
                  {sessions.map((sess) => (
                    <div key={sess.id} className="flex items-center justify-between py-3">
                      <div className="flex items-center gap-3">
                        <div className="flex h-8 w-8 items-center justify-center rounded bg-surface-200 text-foreground">
                          <Laptop className="h-4 w-4" />
                        </div>
                        <div className="space-y-0.5">
                          <div className="flex items-center gap-2">
                            <span className="text-13 font-medium text-foreground">
                              {sess.ip} {sess.is_current && <Badge variant="secondary" className="text-[10px] py-0 px-1.5">Current Session</Badge>}
                            </span>
                            {sess.mfa_passed_at && (
                              <Badge variant="outline" className="text-[10px] text-emerald-600 border-emerald-500/40 py-0">
                                Step-Up Verified
                              </Badge>
                            )}
                          </div>
                          <p className="text-11 text-muted-foreground truncate max-w-md">
                            {sess.user_agent || 'Client Application'}
                          </p>
                          <p className="text-[10px] text-muted-foreground">
                            Created: {sess.created_at} &bull; Last seen: {sess.last_seen_at}
                          </p>
                        </div>
                      </div>

                      {!sess.is_current && (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => void handleRevokeSession(sess.id)}
                          disabled={revokingSessionId === sess.id}
                          className="text-destructive hover:text-destructive h-8 px-2 text-xs"
                          aria-label={`Revoke session ${sess.id}`}
                        >
                          <Trash2 className="h-3.5 w-3.5 mr-1" />
                          {revokingSessionId === sess.id ? 'Revoking...' : 'Revoke'}
                        </Button>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {/* Security Step-Up Authentication Dialog */}
      <StepUpDialog
        open={stepUpOpen}
        onOpenChange={setStepUpOpen}
        onSuccess={handleStepUpSuccess}
        title="Security Step-Up Verification"
        description="This action requires recent authentication. Please enter your password or 2FA code to continue."
      />

      {/* Confirmation Modal */}
      <ConfirmationModal
        open={confirmModalOpen}
        onOpenChange={setConfirmModalOpen}
        title={confirmModalConfig.title}
        body={confirmModalConfig.body}
        confirmLabel="Confirm"
        destructive={true}
        onConfirm={() => {
          setConfirmModalOpen(false);
          confirmModalConfig.onConfirm();
        }}
      />
    </PageContainer>
  );
}
