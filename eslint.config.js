import js from '@eslint/js';
import globals from 'globals';

export default [
  { ignores: ['dist/**', 'backend/**', 'node_modules/**'] },
  {
    files: ['src/**/*.{js,jsx}', 'vite.config.js', 'eslint.config.js', 'scripts/*.mjs'],
    ...js.configs.recommended,
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      parserOptions: { ecmaFeatures: { jsx: true } },
      globals: { ...globals.browser, ...globals.node },
    },
    rules: {
      // JSX component references are checked by the existing React-aware Oxlint pass.
      'no-unused-vars': ['error', { varsIgnorePattern: '^[A-Z_]', argsIgnorePattern: '^_' }],
    },
  },
];
