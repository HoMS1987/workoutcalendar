import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import StepDone from '../../../src/components/wizard/StepDone.vue'
import { makeStatus } from '../fixtures.ts'

vi.mock('@nextcloud/l10n', async (importOriginal) => (await import('./l10nMock.ts')).l10nFactory(importOriginal))

function statusWith(historyDays: number | null, running = false) {
	const status = makeStatus({ sync: { backfill: { running } } })
	status.settings.historyDays = historyDays
	status.settings.intervalMinutes = 30
	return status
}

describe('StepDone', () => {
	it.each([
		[30, 'History: 30 days'],
		[90, 'History: 90 days'],
		[365, 'History: 1 year'],
		[null, 'History: complete'],
	])('names the history choice %s', (days, text) => {
		const wrapper = mount(StepDone, { props: { status: statusWith(days) } })

		expect(wrapper.text()).toContain(text)
	})

	it('shows the account, calendar and the interval sentence', () => {
		const wrapper = mount(StepDone, { props: { status: statusWith(90) } })

		expect(wrapper.text()).toContain('a***@example.org')
		expect(wrapper.text()).toContain('Garmin-Aktivitäten')
		expect(wrapper.text()).toContain('New activities appear in the calendar about every 30 minutes.')
		expect(wrapper.text()).not.toContain('still running')
	})

	it('says the calendar no longer exists when it is linked but deleted', () => {
		const status = statusWith(90)
		status.setup.calendar = { id: 7, name: null, createdByApp: true }
		const wrapper = mount(StepDone, { props: { status } })

		expect(wrapper.text()).toContain('Calendar: The calendar no longer exists.')
	})

	it.each([
		[15, 'about every 15 minutes'],
		[60, 'about every hour'],
		[120, 'about every 2 hours'],
		[1440, 'about once a day'],
	])('words the interval of %s minutes like the settings', (minutes, text) => {
		const status = statusWith(90)
		status.settings.intervalMinutes = minutes
		const wrapper = mount(StepDone, { props: { status } })

		expect(wrapper.text()).toContain(`New activities appear in the calendar ${text}.`)
	})

	it('says when the import is still running', () => {
		const wrapper = mount(StepDone, { props: { status: statusWith(90, true) } })

		expect(wrapper.text()).toContain('The import is still running in the background.')
	})

	it('emits settings from the button', async () => {
		const wrapper = mount(StepDone, { props: { status: statusWith(90) } })

		await wrapper.find('button[data-test="settings"]').trigger('click')

		expect(wrapper.emitted('settings')).toHaveLength(1)
	})
})
