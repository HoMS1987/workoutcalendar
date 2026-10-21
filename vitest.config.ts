// Dates in the tests are formatted in the local time zone; pin it so they are deterministic.
process.env.TZ = 'UTC'

import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
	plugins: [vue()],
	test: {
		environment: 'happy-dom',
		include: ['tests/js/**/*.test.ts'],
		// @nextcloud/vue imports CSS files from its dist, which Node cannot load unprocessed.
		server: { deps: { inline: [/@nextcloud\/vue/] } },
	},
})
