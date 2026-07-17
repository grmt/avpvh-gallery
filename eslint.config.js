import wordpress from '@wordpress/eslint-plugin';
import tseslint from 'typescript-eslint';
import eslintComments from '@eslint-community/eslint-plugin-eslint-comments';
import compat from 'eslint-plugin-compat';
import preferArrowFunctions from 'eslint-plugin-prefer-arrow-functions';
import simpleImportSort from 'eslint-plugin-simple-import-sort';
import globals from 'globals';

export default tseslint.config(
	{
		ignores: ['**/*.d.ts'],
	},
	...wordpress.configs.recommended,
	compat.configs['flat/recommended'],
	...tseslint.configs.strictTypeChecked,
	...tseslint.configs.stylisticTypeChecked,
	{
		languageOptions: {
			globals: {
				...globals.browser,
			},
		},
		plugins: {
			'@eslint-community/eslint-comments': eslintComments,
			'prefer-arrow-functions': preferArrowFunctions,
			'simple-import-sort': simpleImportSort,
		},
		rules: {
			'arrow-body-style': ['error', 'as-needed'],
			camelcase: 'error',
			'no-warning-comments': 'warn',
			strict: ['error', 'never'],
			'compat/compat': 'warn',
			'@eslint-community/eslint-comments/disable-enable-pair': 'error',
			'@eslint-community/eslint-comments/no-aggregating-enable': 'error',
			'@eslint-community/eslint-comments/no-duplicate-disable': 'error',
			'@eslint-community/eslint-comments/no-unlimited-disable': 'error',
			'@eslint-community/eslint-comments/no-unused-enable': 'error',
			'@eslint-community/eslint-comments/no-unused-disable': 'error',
			'@eslint-community/eslint-comments/require-description': [
				'error',
				{
					ignore: ['eslint-env'],
				},
			],
			'prefer-arrow-functions/prefer-arrow-functions': [
				'error',
				{
					allowNamedFunctions: true,
				},
			],
			'simple-import-sort/imports': 'error',
			'simple-import-sort/exports': 'error',
			'react/button-has-type': 'error',
			'react/default-props-match-prop-types': 'error',
			'react/destructuring-assignment': 'error',
			'react/no-array-index-key': 'error',
			'react/no-danger': 'error',
			'react/no-invalid-html-attribute': 'error',
			'react/no-namespace': 'error',
			'react/no-object-type-as-default-prop': 'error',
			'react/no-this-in-sfc': 'error',
			'react/no-typos': 'error',
			'react/no-unknown-property': 'error',
			'react/no-unstable-nested-components': 'error',
			'react/prefer-es6-class': 'error',
			'react/prefer-stateless-function': 'error',
			'react/prefer-read-only-props': 'error',
			'react/require-default-props': ['error', { classes: 'defaultProps' }],
			'react/sort-default-props': 'error',
			'react/sort-prop-types': 'error',
			'react/state-in-constructor': 'error',
			'react/style-prop-object': 'error',
			'react/void-dom-elements-no-children': 'error',
		},
	},
	{
		files: ['**/*.ts', '**/*.tsx'],
		languageOptions: {
			parserOptions: {
				project: './tsconfig.json',
			},
		},
		rules: {
			'import/no-extraneous-dependencies': [
				'error',
				{ devDependencies: true, peerDependencies: true },
			],
			'@typescript-eslint/array-type': ['error', { default: 'generic' }],
			'@typescript-eslint/class-methods-use-this': [
				'error',
				{
					ignoreOverrideMethods: true,
				},
			],
			'@typescript-eslint/consistent-type-exports': 'error',
			'@typescript-eslint/consistent-type-imports': 'error',
			'@typescript-eslint/default-param-last': 'error',
			'@typescript-eslint/explicit-function-return-type': 'error',
			'@typescript-eslint/explicit-member-accessibility': 'error',
			'@typescript-eslint/explicit-module-boundary-types': 'error',
			'@typescript-eslint/init-declarations': 'error',
			'@typescript-eslint/member-ordering': 'error',
			'@typescript-eslint/method-signature-style': ['error', 'method'],
			'@typescript-eslint/no-base-to-string': 'error',
			'@typescript-eslint/no-import-type-side-effects': 'error',
			'@typescript-eslint/no-loop-func': 'error',
			'@typescript-eslint/no-require-imports': 'error',
			'@typescript-eslint/no-shadow': 'error',
			'@typescript-eslint/no-unnecessary-qualifier': 'error',
			'@typescript-eslint/no-unsafe-unary-minus': 'error',
			'@typescript-eslint/no-unused-expressions': 'error',
			'@typescript-eslint/no-unused-vars': 'error',
			'@typescript-eslint/no-use-before-define': 'error',
			'@typescript-eslint/no-useless-empty-export': 'error',
			'@typescript-eslint/parameter-properties': 'error',
			'@typescript-eslint/prefer-enum-initializers': 'error',
			'@typescript-eslint/prefer-find': 'error',
			'@typescript-eslint/prefer-readonly': 'error',
			'@typescript-eslint/prefer-regexp-exec': 'error',
			'@typescript-eslint/promise-function-async': 'error',
			'@typescript-eslint/require-array-sort-compare': 'error',
			'@typescript-eslint/return-await': 'error',
			'@typescript-eslint/sort-type-constituents': 'error',
			'@typescript-eslint/strict-boolean-expressions': 'error',
			'@typescript-eslint/switch-exhaustiveness-check': 'error',
			'@typescript-eslint/typedef': 'error',
		},
	},
	{
		files: ['**/*.js'],
		...tseslint.configs.disableTypeChecked,
	},
	{
		files: ['gulpfile.js', 'webpack.config.js'],
		languageOptions: {
			globals: {
				...globals.node,
			},
		},
	},
	{
		languageOptions: {
			globals: {
				tb_remove: 'readonly',
				tb_show: 'readonly',
			},
		},
	}
);
