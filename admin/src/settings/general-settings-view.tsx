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
  ConfirmationModal,
  ErrorState,
  FormItemLayout,
  Input,
  RadioGroup,
  RadioGroupItem,
  Spinner,
  Textarea,
} from '@bs/ui';
import { apiClient } from '../lib/api';
import type { SiteSettingsEntry } from '@bs/client';

const StepUpDialog = lazy(() =>
  import('../auth/step-up-dialog').then((m) => ({ default: m.StepUpDialog }))
);

export function GeneralSettingsView() {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [saveSuccess, setSaveSuccess] = useState<string | null>(null);
  const [saveError, setSaveError] = useState<string | null>(null);

  // Form state
  const [siteName, setSiteName] = useState('');
  const [tagline, setTagline] = useState('');
  const [canonicalUrl, setCanonicalUrl] = useState('');
  const [environment, setEnvironment] = useState<'production' | 'staging'>('production');

  // Step-up & confirmation state
  const [stepUpOpen, setStepUpOpen] = useState(false);
  const [confirmModalOpen, setConfirmModalOpen] = useState(false);
  const [pendingConfirmToken, setPendingConfirmToken] = useState<string | null>(null);
  const [targetEnv, setTargetEnv] = useState<'production' | 'staging'>('production');

  const loadSettings = async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await apiClient.request<SiteSettingsEntry & Record<string, unknown>>(
        'GET',
        '/singletons/site_settings'
      );
      if (!res.ok) {
        setError(res.problem?.detail || 'Failed to load site settings.');
        return;
      }
      const data = res.data;
      setSiteName((data.site_name as string) || (data.title as string) || '');
      setTagline((data.tagline as string) || '');
      setCanonicalUrl((data.canonical_base_url as string) || '');
      const env = data.environment === 'staging' ? 'staging' : 'production';
      setEnvironment(env);
    } catch {
      setError('A network error occurred while loading settings.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadSettings();
  }, []);

  const handleSave = async (overrideConfirmToken?: string) => {
    setSaving(true);
    setSaveSuccess(null);
    setSaveError(null);

    const payload: Record<string, unknown> = {
      site_name: siteName.trim(),
      title: siteName.trim(),
      tagline: tagline.trim(),
      canonical_base_url: canonicalUrl.trim(),
      environment,
    };

    if (overrideConfirmToken) {
      payload.confirm_token = overrideConfirmToken;
    }

    try {
      const res = await apiClient.request<SiteSettingsEntry>(
        'PATCH',
        '/singletons/site_settings',
        {
          body: payload,
        }
      );

      if (res.ok) {
        setSaveSuccess('General settings saved successfully.');
        setSaving(false);
        return;
      }

      // Check if Step-up is required (403 with Step-Up title/detail)
      if (res.status === 403 && (res.problem?.title?.includes('Step-Up') || res.problem?.detail?.includes('Step-up'))) {
        setTargetEnv(environment);
        setStepUpOpen(true);
        setSaving(false);
        return;
      }

      // Check if Two-step confirmation is required (409)
      if (res.status === 409) {
        const rawBody = res.problem as unknown as { confirm_token?: string; detail?: string };
        const token = rawBody?.confirm_token;
        if (token) {
          setPendingConfirmToken(token);
          setTargetEnv(environment);
          setConfirmModalOpen(true);
          setSaving(false);
          return;
        }
      }

      setSaveError(res.problem?.detail || 'Failed to save settings.');
    } catch {
      setSaveError('An unexpected error occurred while saving.');
    } finally {
      setSaving(false);
    }
  };

  const handleStepUpSuccess = () => {
    // Retry save immediately after step-up
    handleSave();
  };

  const handleConfirmEnvChange = () => {
    if (pendingConfirmToken) {
      setConfirmModalOpen(false);
      handleSave(pendingConfirmToken);
      setPendingConfirmToken(null);
    }
  };

  if (loading) {
    return (
      <div className="flex min-h-[250px] flex-col items-center justify-center gap-3">
        <Spinner size={24} />
        <p className="text-13 text-muted-foreground">Loading site settings...</p>
      </div>
    );
  }

  if (error) {
    return (
      <ErrorState
        title="Unable to load settings"
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

      <Card>
        <CardHeader>
          <CardTitle>Site Details</CardTitle>
          <CardDescription>
            Basic identity and configuration for your site. These values are used in headings, metadata, and communication templates.
          </CardDescription>
        </CardHeader>

        <CardContent className="space-y-4">
          <FormItemLayout
            layout="horizontal"
            id="general-site-name"
            label="Site Title"
            required
            helpText="Human-readable site name displayed in the top bar and metadata."
          >
            <Input
              id="general-site-name"
              value={siteName}
              onChange={(e) => setSiteName(e.target.value)}
              placeholder="e.g. Acme Media"
              required
            />
          </FormItemLayout>

          <FormItemLayout
            layout="horizontal"
            id="general-tagline"
            label="Tagline / Description"
            helpText="Short summary describing the website or publication."
          >
            <Textarea
              id="general-tagline"
              value={tagline}
              onChange={(e) => setTagline(e.target.value)}
              placeholder="e.g. High performance headless content engine"
              rows={3}
            />
          </FormItemLayout>

          <FormItemLayout
            layout="horizontal"
            id="general-canonical-url"
            label="Canonical Base URL"
            helpText="Public root URL of your front-end site (e.g. https://example.com)."
          >
            <Input
              id="general-canonical-url"
              type="url"
              value={canonicalUrl}
              onChange={(e) => setCanonicalUrl(e.target.value)}
              placeholder="https://example.com"
            />
          </FormItemLayout>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <CardTitle>Environment</CardTitle>
              <CardDescription>
                Designate whether this instance is serving production traffic or a staging test environment.
              </CardDescription>
            </div>
            <Badge variant={environment === 'production' ? 'default' : 'secondary'}>
              {environment.toUpperCase()}
            </Badge>
          </div>
        </CardHeader>

        <CardContent className="space-y-4">
          <FormItemLayout
            layout="horizontal"
            id="general-environment"
            label="Target Mode"
            helpText="Switching environment requires security re-authentication and two-step confirmation."
          >
            <RadioGroup
              value={environment}
              onValueChange={(val) => setEnvironment(val as 'production' | 'staging')}
              className="flex flex-col gap-3"
            >
              <div className="flex items-center gap-3">
                <RadioGroupItem value="production" id="env-prod" />
                <label htmlFor="env-prod" className="text-13 font-medium text-foreground cursor-pointer">
                  Production
                  <span className="block text-12 font-normal text-muted-foreground">
                    Public live environment. Search crawlers permitted based on discoverability policies.
                  </span>
                </label>
              </div>

              <div className="flex items-center gap-3">
                <RadioGroupItem value="staging" id="env-staging" />
                <label htmlFor="env-staging" className="text-13 font-medium text-foreground cursor-pointer">
                  Staging
                  <span className="block text-12 font-normal text-muted-foreground">
                    Isolated testing environment. Sends X-Robots-Tag noindex to protect search rankings.
                  </span>
                </label>
              </div>
            </RadioGroup>
          </FormItemLayout>
        </CardContent>

        <CardFooter className="flex justify-end border-t border-border pt-4">
          <Button
            type="button"
            onClick={() => handleSave()}
            disabled={saving || !siteName.trim()}
          >
            {saving ? (
              <span className="flex items-center gap-2">
                <Spinner size={14} />
                Saving...
              </span>
            ) : (
              'Save Changes'
            )}
          </Button>
        </CardFooter>
      </Card>

      {/* Step-up Authentication Dialog */}
      {stepUpOpen ? (
        <Suspense fallback={null}>
          <StepUpDialog
            open={stepUpOpen}
            onOpenChange={setStepUpOpen}
            title="Security Step-Up Required"
            description="Changing critical environment configuration requires confirming your credentials."
            onSuccess={handleStepUpSuccess}
          />
        </Suspense>
      ) : null}

      {/* Two-step Confirmation Modal */}
      <ConfirmationModal
        open={confirmModalOpen}
        onOpenChange={setConfirmModalOpen}
        title="Confirm Environment Change"
        body={`Are you sure you want to change the site environment to "${targetEnv}"? This affects crawler indexing headers and deployment targets.`}
        confirmLabel={`Switch to ${targetEnv}`}
        destructive={targetEnv === 'production'}
        onConfirm={handleConfirmEnvChange}
      />
    </div>
  );
}
