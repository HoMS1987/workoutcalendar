import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CalendarPicker from '../../src/components/CalendarPicker.vue'

vi.mock('@nextcloud/l10n', async (importOriginal) => (await import('./wizard/l10nMock.ts')).l10nFactory(importOriginal))

async function openPicker() {
	const wrapper = mount(CalendarPicker, {
		props: {
			modelValue: { kind: 'new', name: 'Garmin-Aktivitäten' },
			calendars: [{ id: 3, name: 'Sport', color: null }],
			defaultName: 'Garmin-Aktivitäten',
		},
		attachTo: document.body,
	})
	await wrapper.find('input').trigger('mousedown')
	await flushPromises()
	const options = [...document.body.querySelectorAll<HTMLElement>('[role="option"]')]
	return { wrapper, options }
}

describe('CalendarPicker', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
	})

	it('announces the group heading as disabled', async () => {
		const { wrapper, options } = await openPicker()

		const heading = options.find((o) => o.textContent?.includes('Existing calendars'))
		expect(heading).toBeDefined()
		expect(heading?.getAttribute('aria-disabled')).toBe('true')
		wrapper.unmount()
	})

	it('keeps the calendars themselves selectable', async () => {
		const { wrapper, options } = await openPicker()

		const sport = options.find((o) => o.textContent?.includes('Sport'))
		const created = options.find((o) => o.textContent?.includes('New calendar'))
		expect(sport?.getAttribute('aria-disabled')).toBeNull()
		expect(created?.getAttribute('aria-disabled')).toBeNull()
		// The calendars keep the select's own option rendering.
		expect(sport?.querySelector('.name-parts')).not.toBeNull()
		wrapper.unmount()
	})

	it('highlights the search text in the calendar names', async () => {
		const { wrapper } = await openPicker()

		await wrapper.find('input').setValue('Spo')
		await flushPromises()
		const sport = [...document.body.querySelectorAll<HTMLElement>('[role="option"]')].find((o) => o.textContent?.includes('Sport'))
		expect(sport?.querySelector('strong')?.textContent).toBe('Spo')
		wrapper.unmount()
	})
})
