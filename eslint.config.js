import js from '@eslint/js';

export default [
	{
		ignores: [
			'node_modules/',
			'vendor/',
			'modules/highslide-full.js',
		],
	},
	{
		files: ['modules/**/*.js'],
		languageOptions: {
			ecmaVersion: 2021,
			sourceType: 'script',
			globals: {
				// Browser globals
				window: 'readonly',
				document: 'readonly',
				navigator: 'readonly',
				console: 'readonly',
				setTimeout: 'readonly',
				setInterval: 'readonly',
				clearTimeout: 'readonly',
				clearInterval: 'readonly',
				MutationObserver: 'readonly',
				MouseEvent: 'readonly',
				// MediaWiki/Highslide globals
				mw: 'readonly',
				hs: 'readonly',
				MediaWiki: 'readonly',
			},
		},
		rules: {
			...js.configs.recommended.rules,
			'indent': ['error', 'tab', { SwitchCase: 1 }],
			'linebreak-style': ['error', 'unix'],
			'quotes': ['error', 'single', { avoidEscape: true }],
			'semi': ['error', 'always'],
			'no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
			'no-console': 'warn',
			'curly': ['error', 'all'],
			'eqeqeq': ['error', 'always'],
			'no-var': 'error',
			'prefer-const': 'error',
			'prefer-arrow-callback': 'warn',
			'prefer-template': 'warn',
			'space-before-function-paren': [
				'error',
				{
					anonymous: 'always',
					named: 'never',
					asyncArrow: 'always',
				},
			],
			'object-curly-spacing': ['error', 'always'],
			'array-bracket-spacing': ['error', 'never'],
			'keyword-spacing': ['error'],
			'space-infix-ops': 'error',
			'space-before-blocks': 'error',
		},
	},
	{
		files: ['modules/highslide-full.js'],
		rules: {
			'no-unused-vars': 'off',
			'no-var': 'off',
			'eqeqeq': 'off',
			'curly': 'off',
		},
	},
	{
		files: ['modules/highslide.cfg.js'],
		rules: {
			'no-var': 'warn',
			'no-empty': 'warn',
			'no-unused-vars': 'warn',
		},
	},
];
