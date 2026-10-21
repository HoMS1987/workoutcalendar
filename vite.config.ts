import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'

export default createAppConfig(
	{
		settings: resolve(join('src', 'settings.ts')),
	},
	{
		createEmptyCSSEntryPoints: true,
		// The default only clears js/, so CSS chunks with an old hash would pile up in css/.
		emptyOutputDirectory: { additionalDirectories: ['css'] },
		extractLicenseInformation: {},
	},
)
