import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs));
}

/**
 * True when the keyboard event's modifier means "platform Ctrl/Cmd"
 * (Ctrl on Windows/Linux, Cmd on macOS).
 */
export function isCtrlOrMeta(event: { metaKey: boolean; ctrlKey: boolean }): boolean {
  return event.metaKey || event.ctrlKey;
}

/** Escapes a string for safe use in RegExp construction. */
export function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
