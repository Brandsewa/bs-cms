import { lazy, Suspense, useEffect, useState } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  ErrorState,
  FormItemLayout,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
} from '@bs/ui';
import { apiClient } from '../lib/api';
import { useSession } from '../auth/session-context';

const StepUpDialog = lazy(() =>
  import('../auth/step-up-dialog').then((m) => ({ default: m.StepUpDialog }))
);

export interface EmailSettingsData {
  provider: 'zoho' | 'resend' | 'generic';
  host: string;
  port: number;
  encryption: 'tls' | 'starttls' | 'none';
  username: string;
  password?: string;
  from_email: string;
  from_name: string;
  reply_to?: string | null;
  has_password: boolean;
}

const ZOHO_REGIONS: Record<string, { label: string; host: string }> = {
  us: { label: 'United States (US) — smtp.zeptomail.com', host: 'smtp.zeptomail.com' },
  eu: { label: 'Europe (EU) — smtp.zeptomail.eu', host: 'smtp.zeptomail.eu' },
  in: { label: 'India (IN) — smtp.zeptomail.in', host: 'smtp.zeptomail.in' },
  au: { label: 'Australia (AU) — smtp.zeptomail.com.au', host: 'smtp.zeptomail.com.au' },
  jp: { label: 'Japan (JP) — smtp.zeptomail.jp', host: 'smtp.zeptomail.jp' },
  ca: { label: 'Canada (CA) — smtp.zeptomail.ca', host: 'smtp.zeptomail.ca' },
  sa: { label: 'Saudi Arabia (SA) — smtp.zeptomail.sa', host: 'smtp.zeptomail.sa' },
};

export function EmailSettingsView() {
  const { session } = useSession();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [testingConnection, setTestingConnection] = useState(false);
  const [sendingTest, setSendingTest] = useState(false);

  // Status banners
  const [saveSuccess, setSaveSuccess] = useState<string | null>(null);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [connResult, setConnResult] = useState<{ ok: boolean; message: string } | null>(null);
  const [sendResult, setSendResult] = useState<{ ok: boolean; message: string } | null>(null);

  // Dialogs
  const [stepUpOpen, setStepUpOpen] = useState(false);
  const [testEmailDialogOpen, setTestEmailDialogOpen] = useState(false);
  const [recipientEmail, setRecipientEmail] = useState('');

  // Form state
  const [provider, setProvider] = useState<'zoho' | 'resend' | 'generic'>('generic');
  const [zohoRegion, setZohoRegion] = useState<string>('us');
  const [host, setHost] = useState('');
  const [port, setPort] = useState(587);
  const [encryption, setEncryption] = useState<'tls' | 'starttls' | 'none'>('tls');
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [hasPassword, setHasPassword] = useState(false);
  const [isChangingPassword, setIsChangingPassword] = useState(false);
  const [fromName, setFromName] = useState('BS CMS');
  const [fromEmail, setFromEmail] = useState('');
  const [replyTo, setReplyTo] = useState('');

  const loadSettings = async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await apiClient.request<EmailSettingsData>('GET', '/settings/email');
      if (!res.ok) {
        setError(res.problem?.detail || 'Failed to load email settings.');
        return;
      }
      const data = res.data;
      const prov = (data.provider as 'zoho' | 'resend' | 'generic') || 'generic';
      setProvider(prov);
      setHost(data.host || '');
      setPort(data.port || 587);
      setEncryption(data.encryption || 'tls');
      setUsername(data.username || '');
      setHasPassword(Boolean(data.has_password));
      setFromName(data.from_name || 'BS CMS');
      setFromEmail(data.from_email || '');
      setReplyTo(data.reply_to || '');

      // Detect Zoho region if applicable
      if (prov === 'zoho' && data.host) {
        const found = Object.entries(ZOHO_REGIONS).find(([, r]) => r.host === data.host);
        if (found) {
          setZohoRegion(found[0]);
        }
      }
    } catch {
      setError('A network error occurred while loading email configuration.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadSettings();
  }, []);

  useEffect(() => {
    if (session?.user?.email && !recipientEmail) {
      setRecipientEmail(session.user.email);
    }
  }, [session, recipientEmail]);

  const handleProviderChange = (val: 'zoho' | 'resend' | 'generic') => {
    setProvider(val);
    setConnResult(null);
    setSendResult(null);

    if (val === 'zoho') {
      const reg = ZOHO_REGIONS[zohoRegion] ?? ZOHO_REGIONS.us;
      if (reg) {
        setHost(reg.host);
      }
      setPort(465);
      setEncryption('tls');
      setUsername('emailapikey');
    } else if (val === 'resend') {
      setHost('smtp.resend.com');
      setPort(465);
      setEncryption('tls');
      setUsername('resend');
    } else {
      // Generic preset
      setPort(587);
      setEncryption('starttls');
    }
  };

  const handleZohoRegionChange = (regKey: string) => {
    setZohoRegion(regKey);
    const reg = ZOHO_REGIONS[regKey] ?? ZOHO_REGIONS.us;
    if (reg) {
      setHost(reg.host);
    }
  };

  const handleSave = async () => {
    setSaving(true);
    setSaveSuccess(null);
    setSaveError(null);
    setConnResult(null);

    const payload: Record<string, unknown> = {
      provider,
      host: host.trim(),
      port: Number(port),
      encryption,
      username: username.trim(),
      from_name: fromName.trim(),
      from_email: fromEmail.trim(),
      reply_to: replyTo.trim() || null,
    };

    if (isChangingPassword || !hasPassword) {
      if (password) {
        payload.password = password;
      }
    }

    try {
      const res = await apiClient.request<{ status: string }>('PUT', '/settings/email', {
        body: payload,
      });

      if (res.ok) {
        setSaveSuccess('Email settings saved successfully.');
        if (password) {
          setHasPassword(true);
          setIsChangingPassword(false);
          setPassword('');
        }
        setSaving(false);
        return;
      }

      // If Step-up required
      if (res.status === 403 && (res.problem?.title?.includes('Step-Up') || res.problem?.detail?.includes('Step-up'))) {
        setStepUpOpen(true);
        setSaving(false);
        return;
      }

      setSaveError(res.problem?.detail || 'Failed to save email settings.');
    } catch {
      setSaveError('An unexpected error occurred while saving.');
    } finally {
      setSaving(false);
    }
  };

  const handleTestConnection = async () => {
    setTestingConnection(true);
    setConnResult(null);
    setSendResult(null);
    try {
      const res = await apiClient.request<{ connected: boolean; error?: string }>(
        'POST',
        '/settings/email/test'
      );
      if (res.ok) {
        if (res.data.connected) {
          setConnResult({ ok: true, message: 'SMTP server connected and authenticated successfully.' });
        } else {
          setConnResult({
            ok: false,
            message: res.data.error || 'Failed to connect to SMTP server. Please verify credentials.',
          });
        }
      } else {
        setConnResult({
          ok: false,
          message: res.problem?.detail || 'Connection test failed.',
        });
      }
    } catch {
      setConnResult({ ok: false, message: 'Network error while testing connection.' });
    } finally {
      setTestingConnection(false);
    }
  };

  const handleSendTestEmail = async () => {
    if (!recipientEmail || !recipientEmail.includes('@')) {
      return;
    }
    setSendingTest(true);
    setSendResult(null);
    try {
      const res = await apiClient.request<{ status: string; error?: string }>(
        'POST',
        '/settings/email/send-test',
        {
          body: { to: recipientEmail.trim() },
        }
      );
      if (res.ok) {
        setSendResult({
          ok: true,
          message: `Test email dispatched to ${recipientEmail.trim()}. Check your inbox and spam folder.`,
        });
        setTestEmailDialogOpen(false);
      } else {
        setSendResult({
          ok: false,
          message: res.problem?.detail || 'Failed to send test email.',
        });
      }
    } catch {
      setSendResult({ ok: false, message: 'Network error while sending test email.' });
    } finally {
      setSendingTest(false);
    }
  };

  if (loading) {
    return (
      <div className="flex min-h-[250px] flex-col items-center justify-center gap-3">
        <Spinner size={24} />
        <p className="text-13 text-muted-foreground">Loading email settings...</p>
      </div>
    );
  }

  if (error) {
    return (
      <ErrorState
        title="Unable to load email configuration"
        message={error}
        onRetry={loadSettings}
      />
    );
  }

  return (
    <div className="space-y-6">
      {saveSuccess ? (
        <Alert variant="success" role="status" className="border-border">
          <p className="text-13 text-foreground">{saveSuccess}</p>
        </Alert>
      ) : null}

      {saveError ? (
        <Alert variant="error" role="alert">
          <p className="text-13">{saveError}</p>
        </Alert>
      ) : null}

      {connResult ? (
        <Alert
          variant={connResult.ok ? 'success' : 'error'}
          role={connResult.ok ? 'status' : 'alert'}
          className={connResult.ok ? 'border-border' : ''}
        >
          <p className="text-13 font-medium">
            {connResult.ok ? 'Connection Verified' : 'Connection Failed'}
          </p>
          <p className="text-13 mt-0.5">{connResult.message}</p>
        </Alert>
      ) : null}

      {sendResult ? (
        <Alert
          variant={sendResult.ok ? 'success' : 'error'}
          role={sendResult.ok ? 'status' : 'alert'}
          className={sendResult.ok ? 'border-border' : ''}
        >
          <p className="text-13 font-medium">
            {sendResult.ok ? 'Email Dispatched' : 'Send Failed'}
          </p>
          <p className="text-13 mt-0.5">{sendResult.message}</p>
        </Alert>
      ) : null}

      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <CardTitle>Delivery Provider</CardTitle>
              <CardDescription>
                Select your transactional email service. Zoho CPaaS (ZeptoMail) is first-class and recommended for high deliverability.
              </CardDescription>
            </div>
            <Badge variant="outline">
              {provider === 'zoho'
                ? 'Zoho CPaaS (ZeptoMail)'
                : provider === 'resend'
                ? 'Resend'
                : 'Generic SMTP'}
            </Badge>
          </div>
        </CardHeader>

        <CardContent className="space-y-4">
          <FormItemLayout
            layout="horizontal"
            id="email-provider"
            label="Provider Preset"
            required
            helpText="Prefills optimal port, security encryption, and authentication conventions."
          >
            <Select
              value={provider}
              onValueChange={(val) => handleProviderChange(val as 'zoho' | 'resend' | 'generic')}
            >
              <SelectTrigger id="email-provider">
                <SelectValue placeholder="Select email provider" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="zoho">Zoho CPaaS (ZeptoMail) — First Class</SelectItem>
                <SelectItem value="resend">Resend (SMTP)</SelectItem>
                <SelectItem value="generic">Generic SMTP Server</SelectItem>
              </SelectContent>
            </Select>
          </FormItemLayout>

          {provider === 'zoho' ? (
            <FormItemLayout
              layout="horizontal"
              id="email-zoho-region"
              label="Data Center Region"
              required
              helpText="Select the data center region hosting your ZeptoMail account."
            >
              <Select value={zohoRegion} onValueChange={handleZohoRegionChange}>
                <SelectTrigger id="email-zoho-region">
                  <SelectValue placeholder="Select region" />
                </SelectTrigger>
                <SelectContent>
                  {Object.entries(ZOHO_REGIONS).map(([key, reg]) => (
                    <SelectItem key={key} value={key}>
                      {reg.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </FormItemLayout>
          ) : null}

          <FormItemLayout
            layout="horizontal"
            id="email-host"
            label="SMTP Host"
            required
            helpText="Hostname of the outgoing mail server."
          >
            <Input
              id="email-host"
              value={host}
              onChange={(e) => setHost(e.target.value)}
              placeholder="smtp.zeptomail.com"
              required
            />
          </FormItemLayout>

          <FormItemLayout
            layout="horizontal"
            id="email-port"
            label="Port"
            required
            helpText="465 for implicit SSL/TLS; 587 for STARTTLS."
          >
            <Input
              id="email-port"
              type="number"
              value={port}
              onChange={(e) => setPort(Number(e.target.value))}
              placeholder="465"
              required
            />
          </FormItemLayout>

          <FormItemLayout
            layout="horizontal"
            id="email-encryption"
            label="Encryption"
            required
            helpText="TLS (port 465) or STARTTLS (port 587)."
          >
            <Select
              value={encryption}
              onValueChange={(val) => setEncryption(val as 'tls' | 'starttls' | 'none')}
            >
              <SelectTrigger id="email-encryption">
                <SelectValue placeholder="Select encryption" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="tls">TLS (Implicit SSL / Port 465)</SelectItem>
                <SelectItem value="starttls">STARTTLS (Port 587)</SelectItem>
                <SelectItem value="none">None (Insecure / Local dev only)</SelectItem>
              </SelectContent>
            </Select>
          </FormItemLayout>

          <FormItemLayout
            layout="horizontal"
            id="email-username"
            label="Username"
            required
            helpText={
              provider === 'zoho'
                ? 'ZeptoMail username is fixed as emailapikey.'
                : provider === 'resend'
                ? 'Resend username is fixed as resend.'
                : 'SMTP authentication username.'
            }
          >
            <Input
              id="email-username"
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              placeholder="emailapikey"
              required
            />
          </FormItemLayout>

          <FormItemLayout
            layout="horizontal"
            id="email-password"
            label={
              provider === 'zoho'
                ? 'Send Mail Token'
                : provider === 'resend'
                ? 'Resend API Key'
                : 'Password'
            }
            required={!hasPassword}
            helpText="Stored encrypted with the app key; never exposed in plain text."
          >
            {hasPassword && !isChangingPassword ? (
              <div className="flex items-center gap-3">
                <Input
                  id="email-password"
                  type="password"
                  value="••••••••••••"
                  disabled
                  className="bg-muted text-muted-foreground"
                />
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setIsChangingPassword(true)}
                >
                  Replace
                </Button>
              </div>
            ) : (
              <div className="space-y-2">
                <Input
                  id="email-password"
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder={
                    provider === 'zoho'
                      ? 'Zoho Mail Agent Send Mail Token'
                      : provider === 'resend'
                      ? 're_123...'
                      : 'SMTP Password'
                  }
                  required={!hasPassword}
                />
                {hasPassword ? (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                      setIsChangingPassword(false);
                      setPassword('');
                    }}
                  >
                    Cancel replacement
                  </Button>
                ) : null}
              </div>
            )}
          </FormItemLayout>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Sender Identity</CardTitle>
          <CardDescription>
            Configure how recipients see notification and transactional emails.
          </CardDescription>
        </CardHeader>

        <CardContent className="space-y-4">
          <FormItemLayout
            layout="horizontal"
            id="email-from-name"
            label="From Name"
            required
            helpText="Display name shown in user email clients."
          >
            <Input
              id="email-from-name"
              value={fromName}
              onChange={(e) => setFromName(e.target.value)}
              placeholder="Brand Sewa CMS"
              required
            />
          </FormItemLayout>

          <FormItemLayout
            layout="horizontal"
            id="email-from-email"
            label="From Address"
            required
            helpText="Sender email address. Must be on a verified sending domain with your provider."
          >
            <Input
              id="email-from-email"
              type="email"
              value={fromEmail}
              onChange={(e) => setFromEmail(e.target.value)}
              placeholder="notifications@example.com"
              required
            />
          </FormItemLayout>

          <FormItemLayout
            layout="horizontal"
            id="email-reply-to"
            label="Reply-To Address"
            helpText="Optional return address for recipient direct replies."
          >
            <Input
              id="email-reply-to"
              type="email"
              value={replyTo}
              onChange={(e) => setReplyTo(e.target.value)}
              placeholder="support@example.com"
            />
          </FormItemLayout>
        </CardContent>

        <CardFooter className="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
          <div className="flex flex-wrap items-center gap-2">
            <Button
              type="button"
              variant="outline"
              onClick={handleTestConnection}
              disabled={testingConnection || saving || !host.trim()}
            >
              {testingConnection ? (
                <span className="flex items-center gap-2">
                  <Spinner size={14} />
                  Testing...
                </span>
              ) : (
                'Test Connection'
              )}
            </Button>

            <Button
              type="button"
              variant="outline"
              onClick={() => setTestEmailDialogOpen(true)}
              disabled={sendingTest || saving || !host.trim()}
            >
              Send Test Email
            </Button>
          </div>

          <Button
            type="button"
            onClick={handleSave}
            disabled={saving || !host.trim() || !fromEmail.trim()}
          >
            {saving ? (
              <span className="flex items-center gap-2">
                <Spinner size={14} />
                Saving...
              </span>
            ) : (
              'Save Settings'
            )}
          </Button>
        </CardFooter>
      </Card>

      {/* Deliverability Checklist Card */}
      <Card>
        <CardHeader>
          <CardTitle>Deliverability Checklist</CardTitle>
          <CardDescription>
            Best practices to ensure notifications reach user inboxes without spam classification.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <ul className="space-y-2 text-13 text-muted-foreground list-disc pl-5">
            <li>
              <strong className="text-foreground">SPF Record:</strong> Add your provider's include mechanism to your DNS TXT record (e.g. <code className="text-xs bg-muted px-1 py-0.5 rounded">include:zeptomail.net</code>).
            </li>
            <li>
              <strong className="text-foreground">DKIM Key:</strong> Generate and verify the DKIM DNS record provided in your email provider console.
            </li>
            <li>
              <strong className="text-foreground">DMARC Policy:</strong> Publish a DMARC policy at <code className="text-xs bg-muted px-1 py-0.5 rounded">_dmarc.yourdomain.com</code> to protect brand identity.
            </li>
            <li>
              <strong className="text-foreground">Verified Domain:</strong> Your <em>From Address</em> must strictly match the verified sender domain in your email account.
            </li>
          </ul>
        </CardContent>
      </Card>

      {/* Test Email Dialog */}
      <Dialog open={testEmailDialogOpen} onOpenChange={setTestEmailDialogOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Send Test Email</DialogTitle>
            <DialogDescription>
              Verify end-to-end delivery by sending a sample test notification from BS CMS.
            </DialogDescription>
          </DialogHeader>

          <div className="py-4">
            <FormItemLayout
              id="test-email-recipient"
              label="Recipient Email"
              required
              helpText="Enter the destination address where the test message will be sent."
            >
              <Input
                id="test-email-recipient"
                type="email"
                value={recipientEmail}
                onChange={(e) => setRecipientEmail(e.target.value)}
                placeholder="you@example.com"
                required
              />
            </FormItemLayout>
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => setTestEmailDialogOpen(false)}
            >
              Cancel
            </Button>
            <Button
              type="button"
              onClick={handleSendTestEmail}
              disabled={sendingTest || !recipientEmail.trim() || !recipientEmail.includes('@')}
            >
              {sendingTest ? (
                <span className="flex items-center gap-2">
                  <Spinner size={14} />
                  Sending...
                </span>
              ) : (
                'Dispatch Email'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Step-Up Authentication Dialog */}
      {stepUpOpen ? (
        <Suspense fallback={null}>
          <StepUpDialog
            open={stepUpOpen}
            onOpenChange={setStepUpOpen}
            title="Security Step-Up Required"
            description="Updating mail delivery credentials requires confirming your password or 2FA code."
            onSuccess={handleSave}
          />
        </Suspense>
      ) : null}
    </div>
  );
}
