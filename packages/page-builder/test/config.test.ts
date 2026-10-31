import { describe, it, expect } from 'vitest';
import { createPuckConfig, puckConfig, componentTypes } from '../src/index.js';

/** Minimal structural view of a compiled config for assertions. */
interface SimpleField {
  type: string;
  label?: string;
}

interface SimpleComponent {
  fields?: Record<string, SimpleField>;
  defaultProps?: Record<string, unknown>;
}

interface SimpleConfig {
  components: Record<string, SimpleComponent>;
}

describe('puckConfig', () => {
  it('contains exactly the eight contract component keys', () => {
    const config = puckConfig as unknown as SimpleConfig;
    expect(Object.keys(config.components).sort()).toEqual([...componentTypes].sort());
  });

  it('gives every component demo default props', () => {
    const config = puckConfig as unknown as SimpleConfig;
    for (const type of componentTypes) {
      const component = config.components[type];
      expect(component?.defaultProps, `${type} lacks defaultProps`).toBeTruthy();
      expect(Object.keys(component?.defaultProps ?? {}).length, `${type} has empty defaultProps`).toBeGreaterThan(0);
    }
  });

  it('uses a plain text field for ImageBlock.src by default', () => {
    const config = puckConfig as unknown as SimpleConfig;
    const src = config.components['ImageBlock']?.fields?.['src'];
    expect(src).toMatchObject({ type: 'text', label: 'Image URL' });
  });

  it('imageField injection replaces only the ImageBlock.src field', () => {
    const customImageField = { type: 'text' as const, label: 'Pick image' };
    const config = createPuckConfig({ imageField: customImageField }) as unknown as SimpleConfig;

    expect(config.components['ImageBlock']?.fields?.['src']).toEqual(customImageField);
    // The remaining ImageBlock fields are untouched.
    expect(config.components['ImageBlock']?.fields?.['alt']?.type).toBe('text');
    expect(config.components['ImageBlock']?.fields?.['caption']).toBeTruthy();
    // Other components keep their identity.
    expect(config.components['Hero']?.fields?.['heading']?.type).toBe('text');
  });

  it('createPuckConfig() with no options matches the default config', () => {
    const config = createPuckConfig() as unknown as SimpleConfig;
    expect(Object.keys(config.components).sort()).toEqual([...componentTypes].sort());
  });
});
