import type * as ApiModule from '../../src/api.ts'

import { flushPromises, mount } from '@vue/test-utils'
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
})
