import type * as ApiModule from '../../../src/api.ts'
import type { Status } from '../../../src/types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import OverviewPage from '../../../src/components/overview/OverviewPage.vue'
import { deriveView } from '../../../src/viewState.ts'
import { makeStatus } from '../fixtures.ts'

vi.mock('@nextcloud/l10n', async (importOriginal) => (await import('../wizard/l10nMock.ts')).l10nFactory(importOriginal))
vi.mock('../../../src/api.ts', async (importOriginal) => ({
	...(await importOriginal<typeof ApiModule>()),
	updateSettings: vi.fn(),
	chooseHistory: vi.fn(),
	syncNow: vi.fn(),
}))

const api = await import('../../../src/api.ts')

function mountPage(status: Status = makeStatus()) {
	return mount(OverviewPage, {
		props: { status, hints: deriveView(status, false).hints, locks: deriveView(status, false).locks },
		attachTo: document.body,
	})
}

async function setValue(wrapper: ReturnType<typeof mountPage>, selector: string, value: string) {
	await wrapper.find(selector).setValue(value)
}

type Option = { value: string | number, label: string }

function select(wrapper: ReturnType<typeof mountPage>, name: string) {
	const found = wrapper.findAllComponents(NcSelect).find((s) => s.attributes('data-test') === name)
	expect(found, name).toBeDefined()
	return found!
}

function selectProps(target: ReturnType<typeof select>) {
	return target.props() as unknown as { options: Option[], modelValue: Option | null }
}

// Picks an option of an NcSelect the way the select itself reports a choice.
async function choose(wrapper: ReturnType<typeof mountPage>, name: string, value: string | number) {
	const target = select(wrapper, name)
	const option = selectProps(target).options.find((o) => o.value === value)
	expect(option, `${name}=${value}`).toBeDefined()
	target.vm.$emit('update:modelValue', option)
	await flushPromises()
}

function chosen(wrapper: ReturnType<typeof mountPage>, name: string): string | number | undefined {
	return selectProps(select(wrapper, name)).modelValue?.value
}

async function openAdvanced(wrapper: ReturnType<typeof mountPage>) {
	await wrapper.find('[data-test="advanced"] button[data-test="toggle"]').trigger('click')
}

describe('OverviewPage', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		vi.mocked(api.updateSettings).mockReset().mockResolvedValue(makeStatus())
		vi.mocked(api.chooseHistory).mockReset().mockResolvedValue(makeStatus())
		vi.mocked(api.syncNow).mockReset()
	})

	it('shows connection, event count, start month and relative sync times', () => {
		const now = Math.floor(Date.now() / 1000)
		const wrapper = mountPage(makeStatus({ sync: { lastSuccessAt: now - 300, nextRunAt: now + 1200 } }))

		expect(wrapper.text()).toContain('Connected')
		expect(wrapper.text()).toContain('a***@example.org')
		expect(wrapper.text()).toContain('340 activities')
		expect(wrapper.text()).toContain('since March 2023')
		expect(wrapper.text()).toContain('5 minutes ago')
		expect(wrapper.text()).toContain('in 20 minutes')
		wrapper.unmount()
	})

	it('replaces "Connected" with the problem hint when the sync fails', () => {
		const wrapper = mountPage(makeStatus({ failure: { category: 'TOKEN_REJECTED', since: 1_760_000_000, count: 3 } }))

		expect(wrapper.text()).not.toContain('Connected')
		expect(wrapper.text()).toContain('Garmin no longer accepts the saved sign-in.')
		wrapper.unmount()
	})

	it('shows the backfill progress only once', () => {
		const wrapper = mountPage(makeStatus({ sync: { eventCount: 12, backfill: { running: true, reachedDate: '2024-05-01' } } }))

		expect(wrapper.text().match(/Importing your history/g)).toHaveLength(1)
		wrapper.unmount()
	})

	it('sends the clamped settings as numbers', async () => {
		const wrapper = mountPage()
		await openAdvanced(wrapper)

		await choose(wrapper, 'interval', 360)
		await setValue(wrapper, 'input[data-test="jitter"]', '80')
		await choose(wrapper, 'deletion-mode', 'mark')
		await setValue(wrapper, 'input[data-test="check-days"]', '3')
		await wrapper.find('button[data-test="save"]').trigger('click')
		await flushPromises()

		expect(api.updateSettings).toHaveBeenCalledTimes(1)
		const sent = vi.mocked(api.updateSettings).mock.calls[0][0]
		expect(sent).toEqual({ intervalMinutes: 360, jitterPercent: 50, deletionMode: 'mark', deletionCheckDays: 7 })
		expect(typeof sent.intervalMinutes).toBe('number')
		expect(typeof sent.jitterPercent).toBe('number')
		expect(typeof sent.deletionCheckDays).toBe('number')
		expect(api.chooseHistory).not.toHaveBeenCalled()
		wrapper.unmount()
	})

	it('sends zero jitter when the spread is switched off', async () => {
		const wrapper = mountPage()
		await openAdvanced(wrapper)

		await wrapper.find('input[data-test="jitter-enabled"]').setValue(false)
		await wrapper.find('button[data-test="save"]').trigger('click')
		await flushPromises()

		expect(vi.mocked(api.updateSettings).mock.calls[0][0].jitterPercent).toBe(0)
		wrapper.unmount()
	})

	it('changes the history through chooseHistory, not updateSettings', async () => {
		const saved = makeStatus()
		saved.settings.historyDays = 365
		vi.mocked(api.chooseHistory).mockResolvedValue(saved)
		const wrapper = mountPage()

		await choose(wrapper, 'history', '365')
		await wrapper.find('button[data-test="save"]').trigger('click')
		await flushPromises()

		expect(api.chooseHistory).toHaveBeenCalledWith(365)
		expect(api.updateSettings).not.toHaveBeenCalled()
		expect(wrapper.emitted('status')).toEqual([[saved]])
		wrapper.unmount()
	})

	it('keeps Save disabled until something changed', async () => {
		const wrapper = mountPage()

		expect(wrapper.find('button[data-test="save"]').attributes('disabled')).toBeDefined()
		await choose(wrapper, 'interval', 15)
		expect(wrapper.find('button[data-test="save"]').attributes('disabled')).toBeUndefined()
		wrapper.unmount()
	})

	it('shows the last error with time, category and count in the advanced section', async () => {
		const wrapper = mountPage(makeStatus({ failure: { category: 'BLOCKED', since: 1_760_000_000, count: 4 } }))

		expect(wrapper.find('[data-test="advanced"]').text()).not.toContain('Failed attempts')
		await openAdvanced(wrapper)
		const text = wrapper.find('[data-test="advanced"]').text()
		expect(text).toContain('Garmin is blocking the sign-in right now.')
		expect(text).toContain('Failed attempts so far: 4.')
		wrapper.unmount()
	})

	it('shows an error when saving fails', async () => {
		vi.mocked(api.updateSettings).mockRejectedValue(new api.ApiError(409, 'busy'))
		const wrapper = mountPage()

		await choose(wrapper, 'interval', 15)
		await wrapper.find('button[data-test="save"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('A sync is running right now')
		expect(wrapper.emitted('status')).toBeUndefined()
		wrapper.unmount()
	})
})

describe('OverviewPage fields', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
	})

	it('uses the Nextcloud components for the settings fields', async () => {
		const wrapper = mountPage()
		await openAdvanced(wrapper)

		for (const name of ['interval', 'history', 'deletion-mode']) {
			expect(select(wrapper, name).exists(), name).toBe(true)
		}
		expect(wrapper.find('[data-test="advanced"] button[data-test="toggle"]').classes()).toContain('button-vue')
		expect(wrapper.find('input[data-test="jitter-enabled"]').element.closest('.checkbox-radio-switch')).not.toBeNull()
		for (const name of ['jitter', 'check-days']) {
			expect(wrapper.find(`input[data-test="${name}"]`).element.closest('.input-field'), name).not.toBeNull()
		}
		wrapper.unmount()
	})

	it('keeps a label for every settings field', async () => {
		const wrapper = mountPage()
		await openAdvanced(wrapper)

		for (const id of ['workoutcalendar-interval', 'workoutcalendar-history', 'workoutcalendar-deletion-mode']) {
			expect(document.getElementById(id)?.tagName, id).toBe('INPUT')
			expect(document.querySelector(`label[for="${id}"]`)?.textContent?.trim(), id).not.toBe('')
		}
		for (const [name, text] of [['jitter', 'Variation in percent (0 to 50)'], ['check-days', 'Check the last days (7 to 365)']]) {
			const input = wrapper.find(`input[data-test="${name}"]`)
			expect(document.querySelector(`label[for="${input.attributes('id')}"]`)?.textContent, name).toContain(text)
			expect(input.attributes('min'), name).toBeDefined()
			expect(input.attributes('max'), name).toBeDefined()
		}
		const jitterEnabled = wrapper.find('input[data-test="jitter-enabled"]')
		const labelledBy = jitterEnabled.attributes('aria-labelledby') ?? ''
		expect(document.getElementById(labelledBy)?.textContent).toContain('Vary the time between syncs randomly')
		wrapper.unmount()
	})
})

describe('OverviewPage form', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		vi.mocked(api.updateSettings).mockReset()
		vi.mocked(api.chooseHistory).mockReset()
	})

	it('takes over new settings from the status while the form is untouched', async () => {
		const wrapper = mountPage()
		const next = makeStatus()
		next.settings.intervalMinutes = 360
		await wrapper.setProps({ status: next })

		expect(chosen(wrapper, 'interval')).toBe(360)
		wrapper.unmount()
	})

	it('keeps edits while a new status arrives', async () => {
		const wrapper = mountPage()
		await choose(wrapper, 'interval', 15)
		const next = makeStatus()
		next.settings.intervalMinutes = 360
		await wrapper.setProps({ status: next })

		expect(chosen(wrapper, 'interval')).toBe(15)
		wrapper.unmount()
	})

	it('clamps the interval to 15 to 1440 minutes', async () => {
		vi.mocked(api.updateSettings).mockResolvedValue(makeStatus())
		const wrapper = mountPage()
		// A value outside the offered options, as a manipulated form could send it.
		select(wrapper, 'interval').vm.$emit('update:modelValue', { value: 5000, label: '5000' })
		await flushPromises()
		await wrapper.find('button[data-test="save"]').trigger('click')
		await flushPromises()

		expect(vi.mocked(api.updateSettings).mock.calls[0][0].intervalMinutes).toBe(1440)
		wrapper.unmount()
	})

	it('emits the settings result even when the history request fails', async () => {
		const saved = makeStatus()
		saved.settings.intervalMinutes = 15
		vi.mocked(api.updateSettings).mockResolvedValue(saved)
		vi.mocked(api.chooseHistory).mockRejectedValue(new api.ApiError(409, 'busy'))
		const wrapper = mountPage()
		await choose(wrapper, 'interval', 15)
		await choose(wrapper, 'history', '365')
		await wrapper.find('button[data-test="save"]').trigger('click')
		await flushPromises()

		expect(wrapper.emitted('status')).toEqual([[saved]])
		expect(wrapper.text()).toContain('A sync is running right now')
		wrapper.unmount()
	})
})

describe('OverviewPage sync now', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		vi.mocked(api.syncNow).mockReset()
	})

	it.each([
		['done', 'Sync finished.'],
		['skipped', 'A sync is already running'],
		['paused', 'Syncing is paused'],
		['notConnected', 'not connected'],
		['calendarMissing', 'calendar no longer exists'],
		['notReady', 'Setup is not finished'],
	] as const)('shows a result text for the outcome %s', async (outcome, text) => {
		const next = makeStatus({ sync: { eventCount: 341 } })
		vi.mocked(api.syncNow).mockResolvedValue({ outcome, status: next })
		const wrapper = mountPage()

		await wrapper.find('button[data-test="sync-now"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain(text)
		expect(wrapper.emitted('status')).toEqual([[next]])
		wrapper.unmount()
	})

	it('shows busy without emitting a new status', async () => {
		vi.mocked(api.syncNow).mockRejectedValue(new api.ApiError(409, 'busy'))
		const wrapper = mountPage()

		await wrapper.find('button[data-test="sync-now"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('A sync is running right now')
		expect(wrapper.emitted('status')).toBeUndefined()
		wrapper.unmount()
	})
})
