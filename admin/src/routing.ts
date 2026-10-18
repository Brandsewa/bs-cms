import { createRootRoute, Outlet } from '@tanstack/react-router';

/** Shared code-based router root: main.tsx builds the route tree from this. */
export const rootRoute = createRootRoute({ component: Outlet });
