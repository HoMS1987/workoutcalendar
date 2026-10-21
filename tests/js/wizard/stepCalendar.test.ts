import type * as ApiModule from '../../../src/api.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import StepCalendar from '../../../src/components/wizard/StepCalendar.vue'
import { makeStatus } from '../fixtures.ts'

vi.mock('@nextcloud/l10n', async (importOriginal) => (await import('./l10nMock.ts')).l10nFactory(importOriginal))
vi.mock('../../../src/api.ts', async (importOriginal) => ({
	...(await importOriginal<typeof ApiModule>()),
	getCalendars: vi.fn(),
	chooseCalendar: vi.fn(),
}))

const api = await import('../../../src/api.ts')
const getCalendars = vi.mocked(api.getCalendars)
const chooseCalendar = vi.mocked(api.chooseCalendar)

const status = makeStatus({ setup: { step: 'history' } })

async function mountStep() {
	const wrapper = mount(StepCalendar, { attachTo: document.body })
	await flushPromises()
	return wrapper
}

describe('StepCalendar', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		getCalendars.mockReset()
		chooseCalendar.mockReset()
		getCalendars.mockResolvedValue({ defaultName: 'Garmin-Aktivitäten', calendars: [{ id: 3, name: 'Sport', color: null }] })
	})

	it('asks before using an existing calendar and shows its event count', async () => {
		chooseCalendar.mockResolvedValueOnce({ needsConfirmation: true, existingEvents: 37, calendar: null, status })
		const wrapper = await mountStep()

		// Pick the existing calendar "Sport" in the select.
		const select = wrapper.findComponent(NcSelect)
		const options = (select.props() as unknown as { options: Array<{ key: string, label: string }> }).options as Array<{ key: string, label: string }>
		const sport = options.find((o) => o.label === 'Sport')
		select.vm.$emit('update:modelValue', sport)
		await flushPromises()
		await wrapper.find('button[data-test="next"]').trigger('click')
		await flushPromises()

		expect(chooseCalendar).toHaveBeenCalledWith({ calendarId: 3, confirmed: false, moveEvents: false })
		expect(document.body.textContent).toContain('37')
		expect(document.body.textContent).toContain('Sport')

		chooseCalendar.mockResolvedValueOnce({ needsConfirmation: false, existingEvents: 37, calendar: { id: 3, name: 'Sport' }, status })
		const confirm = [...document.body.querySelectorAll('button')].find((b) => b.textContent?.includes('Use “Sport”'))
		confirm?.click()
		await flushPromises()

		expect(chooseCalendar).toHaveBeenLastCalledWith({ calendarId: 3, confirmed: true, moveEvents: false })
		expect(wrapper.emitted('status')).toEqual([[status]])
		wrapper.unmount()
	})

	it('creates a calendar with the typed name', async () => {
		chooseCalendar.mockResolvedValueOnce({ needsConfirmation: false, existingEvents: 0, calendar: { id: 9, name: 'Laufen' }, status })
		const wrapper = await mountStep()

		const select = wrapper.findComponent(NcSelect)
		expect((select.props() as unknown as { taggable: boolean }).taggable).toBe(true)
		const created = ((select.props() as unknown as { createOption: (label: string) => unknown }).createOption as (label: string) => unknown)('Laufen')
		select.vm.$emit('update:modelValue', created)
		await flushPromises()
		await wrapper.find('button[data-test="next"]').trigger('click')
		await flushPromises()

		expect(chooseCalendar).toHaveBeenCalledWith({ name: 'Laufen', confirmed: false, moveEvents: false })
		expect(wrapper.emitted('status')).toEqual([[status]])
		wrapper.unmount()
	})

	it('ignores a whitespace-only typed name', async () => {
		chooseCalendar.mockResolvedValueOnce({ needsConfirmation: false, existingEvents: 0, calendar: { id: 9, name: 'Garmin-Aktivitäten' }, status })
		const wrapper = await mountStep()

		const select = wrapper.findComponent(NcSelect)
		const blank = (select.props() as unknown as { createOption: (label: string) => unknown }).createOption('   ')
		select.vm.$emit('update:modelValue', blank)
		await flushPromises()
		await wrapper.find('button[data-test="next"]').trigger('click')
		await flushPromises()

		expect(chooseCalendar).toHaveBeenCalledWith({ name: 'Garmin-Aktivitäten', confirmed: false, moveEvents: false })
		wrapper.unmount()
	})

	it('creates the preselected default calendar without asking', async () => {
		chooseCalendar.mockResolvedValueOnce({ needsConfirmation: false, existingEvents: 0, calendar: { id: 9, name: 'Garmin-Aktivitäten' }, status })
		const wrapper = await mountStep()

		await wrapper.find('button[data-test="next"]').trigger('click')
		await flushPromises()

		expect(chooseCalendar).toHaveBeenCalledWith({ name: 'Garmin-Aktivitäten', confirmed: false, moveEvents: false })
		wrapper.unmount()
	})

	it('goes back without calling the API', async () => {
		const wrapper = await mountStep()

		await wrapper.find('button[data-test="back"]').trigger('click')

		expect(wrapper.emitted('back')).toHaveLength(1)
		expect(chooseCalendar).not.toHaveBeenCalled()
		wrapper.unmount()
	})
})
