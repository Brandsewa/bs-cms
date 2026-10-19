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

import { controlsSection } from './sections/controls';
import { dateTimeSection } from './sections/date-time';
import { feedbackSection } from './sections/feedback';
import { formSection } from './sections/form';
import { foundationsSection } from './sections/foundations';
import { overlaysSection } from './sections/overlays';
import { pickersSection } from './sections/pickers';
import { primitivesSection } from './sections/primitives';

/** Every component must register an entry here: the gallery page and the axe
 * acceptance suite both render from this registry, so a component without a
 * gallery entry is a missing acceptance test. */
export const sections: GallerySection[] = [
  foundationsSection,
  primitivesSection,
  controlsSection,
  dateTimeSection,
  formSection,
  overlaysSection,
  feedbackSection,
  pickersSection,
];
