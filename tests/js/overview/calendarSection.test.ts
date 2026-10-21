import type * as ApiModule from '../../../src/api.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import CalendarSection from '../../../src/components/overview/CalendarSection.vue'
import { makeStatus } from '../fixtures.ts'

vi.mock('@nextcloud/l10n', async (importOriginal) => (await import('../wizard/l10nMock.ts')).l10nFactory(importOriginal))
vi.mock('../../../src/api.ts', async (importOriginal) => ({
	...(await importOriginal<typeof ApiModule>()),
	getCalendars: vi.fn(),
	chooseCalendar: vi.fn(),
}))

const api = await import('../../../src/api.ts')
const after = makeStatus({ setup: { calendar: { id: 3, name: 'Sport', createdByApp: false } } })

function dialogButton(text: string): HTMLButtonElement | undefined {
	return [...document.body.querySelectorAll<HTMLButtonElement>('[role="dialog"] button')].find((b) => b.textContent?.trim().startsWith(text))
}

async function pickSport(wrapper: ReturnType<typeof mount>) {
	await wrapper.find('button[data-test="change"]').trigger('click')
	await flushPromises()
	const select = wrapper.findComponent(NcSelect)
	const options = (select.props() as unknown as { options: Array<{ label: string }> }).options
	select.vm.$emit('update:modelValue', options.find((o) => o.label === 'Sport'))
	await flushPromises()
	await wrapper.find('button[data-test="continue"]').trigger('click')
	await flushPromises()
}

describe('CalendarSection', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		vi.mocked(api.getCalendars).mockReset().mockResolvedValue({ defaultName: 'Garmin-Aktivitäten', calendars: [{ id: 3, name: 'Sport', color: null }] })
		vi.mocked(api.chooseCalendar).mockReset().mockResolvedValue({ needsConfirmation: false, existingEvents: 0, calendar: { id: 3, name: 'Sport' }, status: after })
	})

	it('shows the current calendar', () => {
		const wrapper = mount(CalendarSection, { props: { status: makeStatus(), locked: false } })

		expect(wrapper.text()).toContain('Garmin-Aktivitäten')
		wrapper.unmount()
	})

	it('asks whether to move events when changing the calendar', async () => {
		const wrapper = mount(CalendarSection, { props: { status: makeStatus(), locked: false }, attachTo: document.body })
		await pickSport(wrapper)

		expect(document.body.textContent).toContain('Should the existing events move to the new calendar?')
		expect(api.chooseCalendar).not.toHaveBeenCalled()

		dialogButton('Yes')?.click()
		await flushPromises()

		expect(api.chooseCalendar).toHaveBeenCalledWith({ calendarId: 3, confirmed: false, moveEvents: true })
		expect(wrapper.emitted('status')).toEqual([[after]])
		wrapper.unmount()
	})

	it('keeps the old events when the answer is no', async () => {
		const wrapper = mount(CalendarSection, { props: { status: makeStatus(), locked: false }, attachTo: document.body })
		await pickSport(wrapper)

		dialogButton('No')?.click()
		await flushPromises()

		expect(api.chooseCalendar).toHaveBeenCalledWith({ calendarId: 3, confirmed: false, moveEvents: false })
		wrapper.unmount()
	})

	it('confirms an existing calendar with the same move answer', async () => {
		vi.mocked(api.chooseCalendar).mockResolvedValueOnce({ needsConfirmation: true, existingEvents: 12, calendar: null, status: makeStatus() })
		const wrapper = mount(CalendarSection, { props: { status: makeStatus(), locked: false }, attachTo: document.body })
		await pickSport(wrapper)

		dialogButton('Yes')?.click()
		await flushPromises()
		expect(document.body.textContent).toContain('already contains 12 events')

		dialogButton('Use “Sport”')?.click()
		await flushPromises()
		expect(api.chooseCalendar).toHaveBeenLastCalledWith({ calendarId: 3, confirmed: true, moveEvents: true })
		expect(wrapper.emitted('status')).toEqual([[after]])
		wrapper.unmount()
	})

	it('locks calendar change and cleanup while maintenance runs', () => {
		const wrapper = mount(CalendarSection, { props: { status: makeStatus({ setup: { maintenance: 'move' } }), locked: true } })

		expect(wrapper.find('button[data-test="change"]').attributes('disabled')).toBeDefined()
		expect(wrapper.text()).toContain('cannot be changed while events are being moved or removed')
		wrapper.unmount()
	})
})
