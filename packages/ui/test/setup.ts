import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

// jsdom does not implement these browser APIs; the theme provider and several
// Radix primitives (popper positioning, scroll area) depend on them.
if (typeof window !== 'undefined' && !window.matchMedia) {
  Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: (query: string): MediaQueryList => ({
      matches: false,
      media: query,
      onchange: null,
      addListener: () => {},
      removeListener: () => {},
      addEventListener: () => {},
      removeEventListener: () => {},
      dispatchEvent: () => false,
    }),
  });
}

if (typeof window !== 'undefined' && typeof window.ResizeObserver === 'undefined') {
  class ResizeObserverStub implements ResizeObserver {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  }
  window.ResizeObserver = ResizeObserverStub;
}

// jsdom does not implement the Pointer Capture or scrollIntoView APIs that
// Radix primitives (select, slider, dropdown) call during interactions.
if (typeof Element !== 'undefined' && !Element.prototype.hasPointerCapture) {
  Element.prototype.hasPointerCapture = () => false;
  Element.prototype.setPointerCapture = () => {};
  Element.prototype.releasePointerCapture = () => {};
}
if (typeof Element !== 'undefined' && !Element.prototype.scrollIntoView) {
  Element.prototype.scrollIntoView = () => {};
}

// jsdom implements window.scrollTo as a "not implemented" throw; TanStack
// Router calls it on every navigation for scroll restoration.
if (typeof window !== 'undefined') {
  window.scrollTo = () => {};
}

// axe html-has-lang / html-lang-valid run at the page level, not the component
// level; the admin index.html sets the real value.
document.documentElement.lang = 'en';

afterEach(() => {
  cleanup();
});
