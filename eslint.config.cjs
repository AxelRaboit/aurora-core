const globals = require('globals');
const pluginVue = require('eslint-plugin-vue');
const prettierPlugin = require('eslint-plugin-prettier');

// Stated here rather than left to Prettier's defaults, which are 2-space.
// With no options Prettier resolves indent width from .editorconfig, so the
// whole ruleset silently hinged on that file being present: aurora-editorial
// was extracted without one and every indented line in its 52 .js files —
// 4109 of them — was reported as an error, on sources that had not changed.
// It also put Prettier's implicit 2 against the vue/*-indent rules below,
// which ask for 4; they now agree. .editorconfig stays for editors, but
// nothing here depends on it any more.
const PRETTIER_OPTIONS = {
    tabWidth: 4,
    useTabs: false,
    endOfLine: 'lf',
};

/** @type {import('eslint').Linter.FlatConfig[]} */
module.exports = [
    {
        ignores: ['node_modules/**', 'vendor/**', 'public/**', 'assets/vendor/**', 'tools/**', 'var/**', 'vite.config.js'],
    },

    // JS files — Prettier formatting
    {
        files: ['**/*.js'],
        plugins: { prettier: prettierPlugin },
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: globals.browser,
        },
        rules: {
            semi: 'error',
            'prefer-const': 'error',
            'no-undef': 'error',
            'prettier/prettier': ['error', PRETTIER_OPTIONS],
        },
    },

    // Build config and test runners execute under Node, not in a browser.
    // Given the node environment rather than exempted from no-undef, so a real
    // typo in them is still an error.
    {
        files: [
            '*.config.js',
            'tests/e2e/**/*.js',
            '**/*.test.js',
            '**/*.spec.js',
        ],
        languageOptions: {
            globals: { ...globals.browser, ...globals.node },
        },
    },

    // Vue files — Vue rules
    ...pluginVue.configs['flat/recommended'],
    {
        files: ['**/*.vue'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                ...globals.browser,
                // Compiler macros: the SFC compiler removes them, so they never
                // exist as bindings and no-undef would flag every component.
                defineProps: 'readonly',
                defineEmits: 'readonly',
                defineExpose: 'readonly',
                defineOptions: 'readonly',
                defineSlots: 'readonly',
                defineModel: 'readonly',
                withDefaults: 'readonly',
            },
        },
        rules: {
            semi: 'error',
            'prefer-const': 'error',
            // Caught a composable returning a name it never declared: the
            // component threw on setup and rendered nothing, while build, tests
            // and lint were all green. A ReferenceError is not a style question.
            'no-undef': 'error',
            'vue/multi-word-component-names': 'off',
            'vue/v-on-style': ['error', 'longform'],
            'vue/v-bind-style': ['error', 'shorthand'],
            'vue/html-indent': ['warn', 4],
            'vue/script-indent': ['warn', 4],
            'vue/max-attributes-per-line': ['warn', { singleline: 4, multiline: 1 }],
            'vue/singleline-html-element-content-newline': 'off',
            'vue/component-definition-name-casing': ['error', 'PascalCase'],
            'vue/require-prop-types': 'warn',
            'vue/require-default-prop': 'off',
            'vue/no-v-html': 'off',
            'vue/attributes-order': 'warn',
        },
    },
];
