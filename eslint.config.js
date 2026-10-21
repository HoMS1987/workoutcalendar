import { recommended } from '@nextcloud/eslint-config'
import { defineConfig } from 'eslint/config'

export default defineConfig([
	...recommended,
	{
		rules: {
			// WHY: most functions are small, named and typed; a forced docblock would only repeat the
			// signature. Functions that get a docblock still have to document every parameter.
			'jsdoc/require-jsdoc': 'off',
		},
	},
])
