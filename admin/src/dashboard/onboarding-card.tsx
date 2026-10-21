import { useState } from 'react';
import { Card, CardHeader, CardTitle, CardContent, Button, Checkbox, Progress } from '@bs/ui';
import { ChevronRight, X } from 'lucide-react';

export interface OnboardingCardProps {
  steps: {
    create_page: boolean;
    write_post: boolean;
    site_details: boolean;
    configure_email: boolean;
    connect_astro: boolean;
    connect_ai: boolean;
  };
  onDismiss?: () => void;
  className?: string;
}

interface StepItem {
  id: keyof OnboardingCardProps['steps'];
  title: string;
  description: string;
  href: string;
}

const ONBOARDING_STEP_DEFS: StepItem[] = [
  {
    id: 'create_page',
    title: 'Create your first page',
    description: 'Set up an About, Contact, or Landing page.',
    href: '/admin/entries/pages/new',
  },
  {
    id: 'write_post',
    title: 'Write your first blog post',
    description: 'Create and draft your introductory article.',
    href: '/admin/entries/posts/new',
  },
  {
    id: 'site_details',
    title: 'Review site settings',
    description: 'Configure your site title, description, and canonical URL.',
    href: '/admin/settings/general',
  },
  {
    id: 'configure_email',
    title: 'Configure outgoing email',
    description: 'Verify SMTP/mail settings for user invitations and password resets.',
    href: '/admin/settings/email',
  },
  {
    id: 'connect_astro',
    title: 'Connect the Astro frontend',
    description: 'Link your static site build and verify automatic publishing.',
    href: '/admin/settings/publishing',
  },
  {
    id: 'connect_ai',
    title: 'Connect an AI assistant (MCP)',
    description: 'Configure Claude, Cursor, or ChatGPT with zero-trust permissions.',
    href: '/admin/ai/connections',
  },
];

export function OnboardingChecklistCard({ steps, onDismiss, className }: OnboardingCardProps) {
  const [localSteps, setLocalSteps] = useState(steps);

  const completedCount = ONBOARDING_STEP_DEFS.filter((s) => localSteps[s.id]).length;
  const totalCount = ONBOARDING_STEP_DEFS.length;
  const progressPercent = Math.round((completedCount / totalCount) * 100);

  const toggleStep = (id: keyof OnboardingCardProps['steps']) => {
    setLocalSteps((prev) => ({ ...prev, [id]: !prev[id] }));
  };

  return (
    <Card className={className} data-testid="onboarding-checklist-card">
      <CardHeader className="flex flex-row items-start justify-between pb-2">
        <div className="space-y-0.5">
          <div className="flex items-center gap-2">
            <CardTitle className="text-13 font-semibold text-foreground">Welcome to BS CMS — Getting Started</CardTitle>
            <span className="rounded-full bg-surface-200 px-2 py-0.2 text-[11px] font-medium text-foreground-muted tabular-nums">
              {completedCount} of {totalCount} completed
            </span>
          </div>
          <p className="text-12 text-foreground-lighter">
            Recommended steps to launch your content operations.
          </p>
        </div>
        {onDismiss && (
          <Button
            variant="ghost"
            size="sm"
            onClick={onDismiss}
            aria-label="Dismiss onboarding checklist"
            className="h-6 w-6 p-0 text-foreground-muted hover:text-foreground"
          >
            <X className="h-3.5 w-3.5" />
          </Button>
        )}
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="space-y-1">
          <Progress value={progressPercent} className="h-1" />
        </div>

        <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3" role="list">
          {ONBOARDING_STEP_DEFS.map((step) => {
            const isDone = localSteps[step.id];
            return (
              <li
                key={step.id}
                className={`group flex flex-col justify-between rounded-md border p-2.5 transition-colors ${
                  isDone ? 'border-border/50 bg-surface-100/30' : 'border-border bg-card hover:border-border-strong'
                }`}
              >
                <div className="flex items-start gap-2">
                  <div className="pt-0.5">
                    <Checkbox
                      id={`step-${step.id}`}
                      checked={isDone}
                      onCheckedChange={() => toggleStep(step.id)}
                      aria-label={step.title}
                    />
                  </div>
                  <div className="min-w-0 flex-1">
                    <label
                      htmlFor={`step-${step.id}`}
                      className={`block text-12 font-medium cursor-pointer leading-tight ${
                        isDone ? 'line-through text-foreground-muted' : 'text-foreground'
                      }`}
                    >
                      {step.title}
                    </label>
                    <p className="mt-0.5 text-[11px] leading-normal text-foreground-muted line-clamp-1">
                      {step.description}
                    </p>
                  </div>
                </div>

                <div className="mt-2 flex justify-end">
                  <a
                    href={step.href}
                    className="inline-flex items-center gap-0.5 text-[11px] font-medium text-primary hover:underline"
                  >
                    Setup <ChevronRight className="h-2.5 w-2.5" />
                  </a>
                </div>
              </li>
            );
          })}
        </ul>
      </CardContent>
    </Card>
  );
}
