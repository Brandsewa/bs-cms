import { useEffect, useRef } from 'react';
import { isCtrlOrMeta } from '../lib/utils';

export interface HotkeyItem {
  /**
   * Sequence or shortcut definition.
   * Single shortcut: 'ctrl+k', 'ctrl+s', 'ctrl+shift+p'
   * Sequence: 'g d', 'g e', 'g m' (press first key then second key within timeout)
   */
  combo: string;
  onTrigger: (event: KeyboardEvent) => void;
  description?: string;
  allowInInputs?: boolean;
}

export interface UseHotkeysOptions {
  sequenceTimeoutMs?: number;
  enabled?: boolean;
}

function isInputElement(target: EventTarget | null): boolean {
  if (!target || !(target instanceof HTMLElement)) return false;
  const tagName = target.tagName.toLowerCase();
  if (tagName === 'input' || tagName === 'textarea' || tagName === 'select') return true;
  if (target.isContentEditable) return true;
  return false;
}

/**
 * Global hotkeys layer.
 * Supports:
 * - Cmd/Ctrl combinations (e.g. 'ctrl+k', 'ctrl+s', 'ctrl+shift+p')
 * - Key sequences (e.g. 'g d', 'g e', 'g m')
 * - Automatic suppression when typing inside input/textarea/select/isContentEditable unless allowInInputs is true.
 * - Single event listener at window level.
 */
export function useHotkeys(
  hotkeys: HotkeyItem[],
  options: UseHotkeysOptions = {}
): void {
  const { sequenceTimeoutMs = 1000, enabled = true } = options;
  const pendingKeyRef = useRef<string | null>(null);
  const sequenceTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const hotkeysRef = useRef(hotkeys);
  hotkeysRef.current = hotkeys;

  useEffect(() => {
    if (!enabled) return;

    function handleKeyDown(event: KeyboardEvent) {
      const isInput = isInputElement(event.target);
      const key = event.key.toLowerCase();
      const ctrlOrMeta = isCtrlOrMeta(event);
      const shift = event.shiftKey;

      for (const item of hotkeysRef.current) {
        if (isInput && !item.allowInInputs) {
          continue;
        }

        const combo = item.combo.toLowerCase().trim();

        // Check if combo is a 2-key sequence like 'g d'
        const parts = combo.split(/\s+/);
        if (parts.length === 2) {
          const [firstKey, secondKey] = parts;
          if (!ctrlOrMeta && !event.altKey) {
            if (pendingKeyRef.current === firstKey && key === secondKey) {
              event.preventDefault();
              pendingKeyRef.current = null;
              if (sequenceTimerRef.current) clearTimeout(sequenceTimerRef.current);
              item.onTrigger(event);
              return;
            } else if (!pendingKeyRef.current && key === firstKey) {
              pendingKeyRef.current = firstKey;
              if (sequenceTimerRef.current) clearTimeout(sequenceTimerRef.current);
              sequenceTimerRef.current = setTimeout(() => {
                pendingKeyRef.current = null;
              }, sequenceTimeoutMs);
              return;
            }
          }
          continue;
        }

        // Single key combination (e.g. 'ctrl+k', 'ctrl+shift+p', 'escape')
        const tokens = combo.split('+');
        const needsCtrl = tokens.includes('ctrl') || tokens.includes('cmd') || tokens.includes('meta');
        const needsShift = tokens.includes('shift');
        const needsAlt = tokens.includes('alt');
        const mainKey = tokens[tokens.length - 1];

        if (
          ctrlOrMeta === needsCtrl &&
          shift === needsShift &&
          event.altKey === needsAlt &&
          key === mainKey
        ) {
          event.preventDefault();
          item.onTrigger(event);
          return;
        }
      }

      // If another key was pressed while waiting for sequence second key, cancel sequence
      if (pendingKeyRef.current && !['shift', 'control', 'alt', 'meta'].includes(key)) {
        pendingKeyRef.current = null;
        if (sequenceTimerRef.current) clearTimeout(sequenceTimerRef.current);
      }
    }

    window.addEventListener('keydown', handleKeyDown);
    return () => {
      window.removeEventListener('keydown', handleKeyDown);
      if (sequenceTimerRef.current) clearTimeout(sequenceTimerRef.current);
    };
  }, [enabled, sequenceTimeoutMs]);
}
