import { describe, expect, it } from 'vitest';
import { GALLERY_SECTIONS } from './section-list';
import { sections } from './registry';

/**
 * Guards the plain section list used by the Playwright axe sweep against
 * drifting from the component registry (which the sweep cannot import).
 */
describe('section list', () => {
  it('matches the registry exactly (ids in order)', () => {
    expect(GALLERY_SECTIONS.map((section) => section.id)).toEqual(
      sections.map((section) => section.id)
    );
    expect(GALLERY_SECTIONS.map((section) => section.name)).toEqual(
      sections.map((section) => section.name)
    );
  });
});
