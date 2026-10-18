import { useRef, type ClipboardEvent, type KeyboardEvent } from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';

export interface InputOTPProps {
  /** Current code; call onChange with the full string. */
  value: string;
  onChange: (value: string) => void;
  /** Number of digits (default 6 for TOTP). */
  length?: number;
  disabled?: boolean;
  /** Only digits are accepted (default true — TOTP codes). */
  numericOnly?: boolean;
  'aria-label'?: string;
}

/**
 * One-time-code input with auto-advance, paste support and full arrow-key
 * navigation. Hand-rolled (the common otp package is not on the allow-list).
 * Used by the 2FA challenge screen.
 */
export function InputOTP({
  value,
  onChange,
  length = 6,
  disabled = false,
  numericOnly = true,
  'aria-label': ariaLabel,
}: InputOTPProps) {
  const { t } = useI18n();
  const inputsRef = useRef<Array<HTMLInputElement | null>>([]);

  const digits = Array.from({ length }, (_, index) => value.charAt(index) ?? '');

  const focusDigit = (index: number) => {
    const target = inputsRef.current[Math.max(0, Math.min(length - 1, index))];
    target?.focus();
    target?.select();
  };

  const setDigit = (index: number, digit: string) => {
    const chars = digits.slice();
    chars[index] = digit;
    onChange(chars.join('').slice(0, length));
  };

  const handleInput = (index: number, raw: string) => {
    const filtered = numericOnly ? raw.replace(/\D/g, '') : raw.trim();
    if (filtered.length === 0) return;
    if (filtered.length > 1) {
      // Autocomplete fill or fast typing: spread across cells.
      const merged = (value.slice(0, index) + filtered).slice(0, length);
      onChange(merged);
      focusDigit(Math.min(index + filtered.length, length - 1));
      return;
    }
    setDigit(index, filtered);
    if (index < length - 1) focusDigit(index + 1);
  };

  const handleKeyDown = (index: number, event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Backspace') {
      event.preventDefault();
      if (digits[index]) {
        setDigit(index, '');
      } else if (index > 0) {
        focusDigit(index - 1);
        setDigit(index - 1, '');
      }
    } else if (event.key === 'ArrowLeft' && index > 0) {
      event.preventDefault();
      focusDigit(index - 1);
    } else if (event.key === 'ArrowRight' && index < length - 1) {
      event.preventDefault();
      focusDigit(index + 1);
    } else if (event.key === 'Home') {
      event.preventDefault();
      focusDigit(0);
    } else if (event.key === 'End') {
      event.preventDefault();
      focusDigit(length - 1);
    }
  };

  const handlePaste = (event: ClipboardEvent<HTMLInputElement>) => {
    event.preventDefault();
    const pasted = numericOnly
      ? event.clipboardData.getData('text').replace(/\D/g, '')
      : event.clipboardData.getData('text').trim();
    if (pasted.length === 0) return;
    onChange(pasted.slice(0, length));
    focusDigit(Math.min(pasted.length, length - 1));
  };

  return (
    <div
      role="group"
      aria-label={ariaLabel ?? t('otp.label')}
      className="flex items-center gap-2"
    >
      <span className="sr-only">{t('otp.description', { length })}</span>
      {digits.map((digit, index) => (
        <input
          key={index}
          ref={(node) => {
            inputsRef.current[index] = node;
          }}
          type="text"
          inputMode={numericOnly ? 'numeric' : 'text'}
          autoComplete={index === 0 ? 'one-time-code' : 'off'}
          value={digit}
          disabled={disabled}
          aria-label={`${ariaLabel ?? t('otp.label')} ${index + 1} ${index === 0 ? 'of' + length : ''}`.trim()}
          onChange={(event) => handleInput(index, event.target.value)}
          onKeyDown={(event) => handleKeyDown(index, event)}
          onPaste={handlePaste}
          onFocus={(event) => event.target.select()}
          className={cn(
            'h-9 w-9 rounded-md border border-input bg-transparent text-center text-base font-medium text-foreground shadow-xs transition-colors',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
            'disabled:cursor-not-allowed disabled:opacity-50',
            digit && 'border-ring'
          )}
        />
      ))}
    </div>
  );
}
