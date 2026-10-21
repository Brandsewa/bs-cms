import { renderHook, act } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { useHotkeys, type HotkeyItem } from '../src/hooks/use-hotkeys';

describe('useHotkeys hook', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('triggers on Ctrl+K shortcut', () => {
    const onCtrlK = vi.fn();
    const items: HotkeyItem[] = [{ combo: 'ctrl+k', onTrigger: onCtrlK }];

    renderHook(() => useHotkeys(items));

    act(() => {
      window.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, bubbles: true })
      );
    });

    expect(onCtrlK).toHaveBeenCalledTimes(1);
  });

  it('ignores hotkey when typing in an input element', () => {
    const onCtrlK = vi.fn();
    const items: HotkeyItem[] = [{ combo: 'ctrl+k', onTrigger: onCtrlK }];

    renderHook(() => useHotkeys(items));

    const input = document.createElement('input');
    document.body.appendChild(input);

    act(() => {
      input.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, bubbles: true })
      );
    });

    expect(onCtrlK).not.toHaveBeenCalled();
    document.body.removeChild(input);
  });

  it('supports sequence hotkeys like G then D', () => {
    const onGD = vi.fn();
    const items: HotkeyItem[] = [{ combo: 'g d', onTrigger: onGD }];

    renderHook(() => useHotkeys(items, { sequenceTimeoutMs: 1000 }));

    // Press 'g'
    act(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'g', bubbles: true }));
    });
    expect(onGD).not.toHaveBeenCalled();

    // Press 'd' within timeout
    act(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'd', bubbles: true }));
    });
    expect(onGD).toHaveBeenCalledTimes(1);
  });

  it('cancels sequence if timeout expires', () => {
    const onGD = vi.fn();
    const items: HotkeyItem[] = [{ combo: 'g d', onTrigger: onGD }];

    renderHook(() => useHotkeys(items, { sequenceTimeoutMs: 500 }));

    // Press 'g'
    act(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'g', bubbles: true }));
    });
    expect(onGD).not.toHaveBeenCalled();

    // Advance time beyond timeout
    act(() => {
      vi.advanceTimersByTime(600);
    });

    // Press 'd'
    act(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'd', bubbles: true }));
    });
    expect(onGD).not.toHaveBeenCalled();
  });
});
