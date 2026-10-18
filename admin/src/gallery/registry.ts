import type { ReactNode } from 'react';

export interface PropRow {
  name: string;
  type: string;
  defaultValue?: string;
  description: string;
}

export interface GalleryEntry {
  id: string;
  name: string;
  description?: string;
  render: () => ReactNode;
  props?: PropRow[];
}

export interface GallerySection {
  id: string;
  name: string;
  entries: GalleryEntry[];
}

import { foundationsSection } from './sections/foundations';

/** Every component must register an entry here: it is the source for the
 * gallery page and for the axe acceptance suite (CI fails on new components
 * missing from the gallery because their tests reference this registry). */
export const sections: GallerySection[] = [foundationsSection];
