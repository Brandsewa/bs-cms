import { useId, useState } from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';
import { Input } from '../components/input';

export interface TimeInputProps {
  /**
   * 24-hour "HH:MM" string or undefined. onChange receives a valid "HH:MM"
   * or undefined while the text is incomplete/invalid.
   */
  value: string | undefined;
  onChange: (value: string | undefined) => void;
  disabled?: boolean;
  className?: string;
  'aria-label'?: string;
}

const TIME_PATTERN = /^([01]\d|2[0-3]):([0-5]\d)$/;

/**
 * Time input for "HH:MM" (24-hour). Native time inputs are forbidden
 * (docs/02-ui-spec.md 3.6); this validates on change and reports invalid
 * state via aria-invalid without firing onChange with invalid text.
 */
export function TimeInput({ value, onChange, disabled = false, className, 'aria-label': ariaLabel }: TimeInputProps) {
  const { t } = useI18n();
  const [draft, setDraft] = useState(value ?? '');
  const [touched, setTouched] = useState(false);
  const invalidId = useId();

  const isValid = (candidate: string): boolean => TIME_PATTERN.test(candidate);

  return (
    <div>
      <Input
        type="text"
        inputMode="numeric"
        value={draft}
        disabled={disabled}
        aria-label={ariaLabel}
        aria-invalid={touched && !isValid(draft) ? true : undefined}
        aria-describedby={touched && !isValid(draft) ? invalidId : undefined}
        placeholder="HH:MM"
        maxLength={5}
        onChange={(event) => {
          const next = event.target.value.replace(/[^0-9:]/g, '');
          setDraft(next);
          if (isValid(next)) {
            onChange(next);
          } else if (next === '') {
            onChange(undefined);
          }
        }}
        onBlur={() => setTouched(true)}
        className={cn('w-24 font-mono', className)}
      />
      {touched && !isValid(draft) ? (
        <p id={invalidId} role="alert" className="mt-1 text-xs text-destructive-text">
          {t('dateTime.invalidTime')}
        </p>
      ) : null}
    </div>
  );
}

export { TIME_PATTERN as timePattern };
