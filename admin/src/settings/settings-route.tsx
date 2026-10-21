import { useRouterState } from '@tanstack/react-router';
import { SettingsLayout } from './settings-layout';
import { GeneralSettingsView } from './general-settings-view';
import { EmailSettingsView } from './email-settings-view';

export function SettingsRoute() {
  const pathname = useRouterState({
    select: (s) => s.location.pathname,
  });

  const activeTab: 'general' | 'email' = pathname.includes('/email') ? 'email' : 'general';

  return (
    <SettingsLayout activeTab={activeTab}>
      {activeTab === 'general' ? <GeneralSettingsView /> : <EmailSettingsView />}
    </SettingsLayout>
  );
}
