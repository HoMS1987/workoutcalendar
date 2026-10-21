import type * as ApiModule from '../../../src/api.ts'
import type { Status } from '../../../src/types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from '../../../src/App.vue'
import { makeStatus } from '../fixtures.ts'

vi.mock('@nextcloud/l10n', async (importOriginal) => (await import('../wizard/l10nMock.ts')).l10nFactory(importOriginal))
vi.mock('../../../src/api.ts', async (importOriginal) => ({
	...(await importOriginal<typeof ApiModule>()),
	getStatus: vi.fn(),
	syncNow: vi.fn(),
	logout: vi.fn(),
}))

const api = await import('../../../src/api.ts')

describe('App overview', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		vi.mocked(api.getStatus).mockReset()
		vi.mocked(api.syncNow).mockReset()
		vi.mocked(api.logout).mockReset()
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('shows busy without changing the view', async () => {
		vi.mocked(api.getStatus).mockResolvedValue(makeStatus())
		vi.mocked(api.syncNow).mockRejectedValue(new api.ApiError(409, 'busy'))
		const wrapper = mount(App, { attachTo: document.body })
		await flushPromises()

		await wrapper.find('button[data-test="sync-now"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('A sync is running right now')
		expect(wrapper.find('.workoutcalendar-settings__overview').exists()).toBe(true)
		expect(wrapper.find('form').exists()).toBe(false)
		wrapper.unmount()
	})

	it('locks calendar change and cleanup while maintenance runs', async () => {
		vi.mocked(api.getStatus).mockResolvedValue(makeStatus({ setup: { maintenance: 'move' } }))
		const wrapper = mount(App, { attachTo: document.body })
		await flushPromises()

		expect(wrapper.find('button[data-test="change"]').attributes('disabled')).toBeDefined()
		await wrapper.find('[data-test="account"] button[data-test="toggle"]').trigger('click')
		await wrapper.find('button[data-test="logout"]').trigger('click')
		await flushPromises()
		const events = document.body.querySelector<HTMLInputElement>('input[type="radio"][value="events"]')
		expect(events?.disabled).toBe(true)
		wrapper.unmount()
	})

	it('goes back to the wizard after signing out', async () => {
		vi.mocked(api.getStatus).mockResolvedValue(makeStatus())
		vi.mocked(api.logout).mockResolvedValue(makeStatus({ setup: { connected: false, step: 'garmin', maskedEmail: 'a***@example.org' } }))
		const wrapper = mount(App, { attachTo: document.body })
		await flushPromises()

		await wrapper.find('[data-test="account"] button[data-test="toggle"]').trigger('click')
		await wrapper.find('button[data-test="logout"]').trigger('click')
		await flushPromises()
		const confirm = [...document.body.querySelectorAll<HTMLButtonElement>('[role="dialog"] button')].find((b) => b.textContent?.includes('Sign out'))
		confirm?.click()
		await flushPromises()

		expect(wrapper.find('form').exists()).toBe(true)
		wrapper.unmount()
	})

	it('ignores a poll response that arrives after a newer status from an action', async () => {
		vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
		const running = makeStatus({ sync: { eventCount: 10, backfill: { running: true, reachedDate: null } } })
		const fresh = makeStatus({ sync: { eventCount: 99 } })
		let answerPoll: (status: Status) => void = () => {}

		vi.mocked(api.getStatus)
			.mockResolvedValueOnce(running)
			.mockImplementationOnce(() => new Promise<Status>((resolve) => {
				answerPoll = resolve
			}))
		vi.mocked(api.syncNow).mockResolvedValue({ outcome: 'done', status: fresh })
		const wrapper = mount(App, { attachTo: document.body })
		await flushPromises()

		await vi.advanceTimersByTimeAsync(10_000)
		expect(api.getStatus).toHaveBeenCalledTimes(2)

		await wrapper.find('button[data-test="sync-now"]').trigger('click')
		await flushPromises()
		expect(wrapper.text()).toContain('99 activities')

		answerPoll(running)
		await flushPromises()
		expect(wrapper.text()).toContain('99 activities')
		expect(wrapper.text()).not.toContain('10 activities')
		wrapper.unmount()
	})

	it('updates the status from the poll while an import runs', async () => {
		vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
		const running = makeStatus({ sync: { eventCount: 10, backfill: { running: true, reachedDate: null } } })
		const later = makeStatus({ sync: { eventCount: 25, backfill: { running: true, reachedDate: null } } })
		vi.mocked(api.getStatus).mockResolvedValueOnce(running).mockResolvedValue(later)
		const wrapper = mount(App, { attachTo: document.body })
		await flushPromises()

		await vi.advanceTimersByTimeAsync(10_000)
		await flushPromises()

		expect(wrapper.text()).toContain('25 activities')
		wrapper.unmount()
	})
})
