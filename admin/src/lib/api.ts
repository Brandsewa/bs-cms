import { ApiClient } from '@bs/client';
import { sanitizeReturnTo } from './return-to';

export const apiClient = new ApiClient({
  baseUrl: '/api/v1',
  onUnauthorized: () => {
    // Session expired or 401 on an API call: redirect to login preserving current route if inside /admin
    if (typeof window !== 'undefined') {
      const pathname = window.location.pathname;
      // Do not redirect if we are already on an auth page
      if (
        pathname.startsWith('/admin/login') ||
        pathname.startsWith('/admin/forgot-password') ||
        pathname.startsWith('/admin/reset-password') ||
        pathname.startsWith('/admin/invitations')
      ) {
        return;
      }
      const current = window.location.pathname + window.location.search;
      const safe = sanitizeReturnTo(current);
      window.location.href = `/admin/login?return_to=${encodeURIComponent(safe)}`;
    }
  },
});
