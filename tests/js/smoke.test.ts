import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import App from '../../src/App.vue'

describe('settings app', () => {
	it('mounts the settings app into its container', () => {
		const container = document.createElement('div')
		container.id = 'workoutcalendar-settings'
		document.body.appendChild(container)

		const wrapper = mount(App, { attachTo: container })

		expect(wrapper.text()).toContain('Workout Calendar')
		wrapper.unmount()
	})
})
