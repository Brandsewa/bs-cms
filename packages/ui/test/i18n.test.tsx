import { describe, expect, it } from 'vitest';
import { render, screen } from "./test-utils";
import { I18nProvider, useI18n, type MessageKey } from '../src/i18n';

function Probe({ path, vars }: { path: MessageKey; vars?: Record<string, string | number> }) {
  const { t } = useI18n();
  return <span>{t(path, vars)}</span>;
}

describe('I18nProvider', () => {
  it('translates a nested key', () => {
    render(
      <I18nProvider>
        <Probe path="common.loading" />
      </I18nProvider>
    );
    expect(screen.getByText('Loading…')).toBeInTheDocument();
  });

  it('interpolates variables', () => {
    render(
      <I18nProvider>
        <Probe path="common.selected" vars={{ count: 3 }} />
      </I18nProvider>
    );
    expect(screen.getByText('3 selected')).toBeInTheDocument();
  });

  it('leaves unknown placeholders intact', () => {
    render(
      <I18nProvider>
        <Probe path="common.selected" />
      </I18nProvider>
    );
    expect(screen.getByText('{count} selected')).toBeInTheDocument();
  });

  it('supports overrides for any subset of the catalog', () => {
    render(
      <I18nProvider overrides={{ common: { loading: 'Wird geladen…' } }}>
        <Probe path="common.loading" />
      </I18nProvider>
    );
    expect(screen.getByText('Wird geladen…')).toBeInTheDocument();
  });

  it('throws loudly when a key is missing from the catalog', () => {
    function Broken() {
      const { t } = useI18n();
      // Simulates catalog/key drift that passes the TypeScript layer.
      return <span>{t('common.doesNotExist' as MessageKey)}</span>;
    }
    // Console output is expected; silence it for the assertion.
    expect(() =>
      render(
        <I18nProvider>
          <Broken />
        </I18nProvider>
      )
    ).toThrow(/Missing message/);
  });
});
