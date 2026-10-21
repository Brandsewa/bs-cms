import { FC, useState } from 'react';
import {
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Label,
  Switch,
} from '@bs/ui';
import { Bell, Mail, ShieldAlert, FileText, CheckCircle2 } from 'lucide-react';

export const NotificationsPreferencesView: FC = () => {
  const [prefs, setPrefs] = useState({
    notify_on_publish: true,
    notify_on_ai_proposal: true,
    notify_on_failed_job: true,
    notify_on_security_stepup: true,
    email_digest: false,
  });
  const [saved, setSaved] = useState(false);

  const handleToggle = (key: keyof typeof prefs) => {
    setPrefs((prev) => ({ ...prev, [key]: !prev[key] }));
  };

  const handleSave = () => {
    setSaved(true);
    setTimeout(() => setSaved(false), 2500);
  };

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
                <FileText className="h-4 w-4 text-muted-foreground" />
                <Label htmlFor="pref_pub" className="cursor-pointer font-medium">Content Published</Label>
              </div>
              <p className="text-12 text-muted-foreground">Receive in-app alerts when another collaborator publishes entries.</p>
            </div>
            <Switch id="pref_pub" checked={prefs.notify_on_publish} onCheckedChange={() => handleToggle('notify_on_publish')} />
          </div>

          <div className="flex items-center justify-between">
            <div className="space-y-0.5">
              <div className="flex items-center gap-2">
                <ShieldAlert className="h-4 w-4 text-muted-foreground" />
                <Label htmlFor="pref_ai" className="cursor-pointer font-medium">Pending AI Proposals</Label>
              </div>
              <p className="text-12 text-muted-foreground">Alert when an AI agent drafts changes requiring human review.</p>
            </div>
            <Switch id="pref_ai" checked={prefs.notify_on_ai_proposal} onCheckedChange={() => handleToggle('notify_on_ai_proposal')} />
          </div>

          <div className="flex items-center justify-between">
            <div className="space-y-0.5">
              <div className="flex items-center gap-2">
                <ShieldAlert className="h-4 w-4 text-muted-foreground" />
                <Label htmlFor="pref_fail" className="cursor-pointer font-medium">Background Job Failures</Label>
              </div>
              <p className="text-12 text-muted-foreground">Immediate alerts if async image processing or webhooks exceed retries.</p>
            </div>
            <Switch id="pref_fail" checked={prefs.notify_on_failed_job} onCheckedChange={() => handleToggle('notify_on_failed_job')} />
          </div>

          <div className="flex items-center justify-between">
            <div className="space-y-0.5">
              <div className="flex items-center gap-2">
                <Mail className="h-4 w-4 text-muted-foreground" />
                <Label htmlFor="pref_digest" className="cursor-pointer font-medium">Weekly Email Summary</Label>
              </div>
              <p className="text-12 text-muted-foreground">Send a weekly digest covering publishing frequency and site health scores.</p>
            </div>
            <Switch id="pref_digest" checked={prefs.email_digest} onCheckedChange={() => handleToggle('email_digest')} />
          </div>

          <div className="flex items-center justify-between pt-4">
            {saved && (
              <span className="text-12 text-success-text flex items-center gap-1 font-medium">
                <CheckCircle2 className="h-3.5 w-3.5" /> Preferences saved.
              </span>
            )}
            <Button size="sm" className="ml-auto" onClick={handleSave}>
              Save Preferences
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};
