import type * as ApiModule from '../../src/api.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import App from '../../src/App.vue'
import { makeStatus } from './fixtures.ts'

vi.mock('../../src/api.ts', async (importOriginal) => ({
	...(await importOriginal<typeof ApiModule>()),
	getStatus: vi.fn(async () => makeStatus({ setup: { connected: false, step: 'garmin' } })),
}))

describe('settings app', () => {
	it('mounts the settings app into its container', async () => {
		const container = document.createElement('div')
		container.id = 'workoutcalendar-settings'
		document.body.appendChild(container)

		const wrapper = mount(App, { attachTo: container })
		await flushPromises()

		expect(wrapper.text()).toContain('Workout Calendar')
		expect(wrapper.text()).toContain('Sign in and continue')
		wrapper.unmount()
	})

	it('mounts the real entry into the element the template provides', async () => {
		const template = readFileSync(join(import.meta.dirname, '..', '..', 'templates', 'settings-personal.php'), 'utf8')
		const id = /<div id="([^"]+)"><\/div>/.exec(template)?.[1]
		expect(id).toBe('workoutcalendar-settings')
		document.body.innerHTML = template.slice(template.indexOf('?>') + 2)

		await import('../../src/settings.ts')
		await flushPromises()

		const container = document.getElementById(id!)!
		expect(container.textContent).toContain('Workout Calendar')
		expect(container.textContent).toContain('Sign in and continue')
	})
})
