import js from '@eslint/js';
import prettier from 'eslint-config-prettier';
import globals from 'globals';
import vue from 'eslint-plugin-vue';

export default [
  {ignores: ['build/**', 'node_modules/**', 'vendor/**']},
  js.configs.recommended,
  ...vue.configs['flat/recommended'],
  prettier,
  {
    languageOptions: {
      ecmaVersion: 2023,
      sourceType: 'module',
      globals: {
        ...globals.browser,
        qaRunner: 'readonly'
      }
    },
    rules: {
      quotes: ['error', 'single', {avoidEscape: true}],
      semi: ['error', 'always'],
      'no-unused-vars': ['error', {argsIgnorePattern: '^_'}],
      'vue/multi-word-component-names': 'off',
      'vue/max-attributes-per-line': 'off',
      'vue/singleline-html-element-content-newline': 'off',
      'vue/html-self-closing': 'off',
      'vue/no-v-html': 'off'
    }
  }
];
