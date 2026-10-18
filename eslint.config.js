import reactHooks from 'eslint-plugin-react-hooks';
import tseslint from 'typescript-eslint';

export default tseslint.config(
  ...tseslint.configs.recommended,
  {
    files: ['packages/renderer/**/*.ts'],
    rules: {
      '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
    },
  },
  {
    files: ['packages/ui/**/*.{ts,tsx}', 'packages/client/**/*.{ts,tsx}', 'admin/**/*.{ts,tsx}'],
    plugins: {
      'react-hooks': reactHooks,
    },
    rules: {
      '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
      '@typescript-eslint/no-explicit-any': 'error',
      'react-hooks/rules-of-hooks': 'error',
      'react-hooks/exhaustive-deps': 'warn',
      // BS CMS forbids native selects and native date/time inputs (docs/02-ui-spec.md 3.2, 3.6).
      'no-restricted-syntax': [
        'error',
        {
          selector: "JSXOpeningElement[name.name='select']",
          message: 'Native <select> is forbidden; use the @bs/ui Select or Multi Select component.',
        },
        {
          selector: "JSXOpeningElement[name.name='optgroup']",
          message: 'Native <optgroup> is forbidden; use the @bs/ui Select or Multi Select component.',
        },
        {
          selector: "JSXOpeningElement[name.name='option']",
          message: 'Native <option> is forbidden; use the @bs/ui Select, Combobox or Multi Select component.',
        },
        {
          selector:
            "JSXAttribute[name.name='type'][value.value=/^(date|time|datetime-local|month|week)$/]",
          message:
            'Native date/time inputs are forbidden; use the @bs/ui date pickers (docs/02-ui-spec.md 3.6).',
        },
      ],
    },
  },
  {
    ignores: ['**/dist/**', '**/node_modules/**', 'admin/dev-dist/**'],
  }
);
