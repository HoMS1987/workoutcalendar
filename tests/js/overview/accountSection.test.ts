import type * as ApiModule from '../../../src/api.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AccountSection from '../../../src/components/overview/AccountSection.vue'
import { makeStatus } from '../fixtures.ts'

vi.mock('@nextcloud/l10n', async (importOriginal) => (await import('../wizard/l10nMock.ts')).l10nFactory(importOriginal))
vi.mock('../../../src/api.ts', async (importOriginal) => ({
	...(await importOriginal<typeof ApiModule>()),
	logout: vi.fn(),
	reset: vi.fn(),
}))

const api = await import('../../../src/api.ts')
const loggedOut = makeStatus({ setup: { connected: false, step: 'garmin' } })

function mountSection(status = makeStatus(), locked = false) {
	return mount(AccountSection, { props: { status, locked }, attachTo: document.body })
}

async function open(wrapper: ReturnType<typeof mountSection>, name: 'logout' | 'reset') {
	await wrapper.find('button[data-test="toggle"]').trigger('click')
	await wrapper.find(`button[data-test="${name}"]`).trigger('click')
	await flushPromises()
}

function dialogButton(text: string): HTMLButtonElement | undefined {
	return [...document.body.querySelectorAll<HTMLButtonElement>('[role="dialog"] button')].find((b) => b.textContent?.includes(text))
}

describe('AccountSection', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		vi.mocked(api.logout).mockReset().mockResolvedValue(loggedOut)
		vi.mocked(api.reset).mockReset().mockResolvedValue(loggedOut)
	})

	it('is collapsed by default', async () => {
		const wrapper = mountSection()

		expect(wrapper.find('button[data-test="toggle"]').attributes('aria-expanded')).toBe('false')
		expect(wrapper.find('button[data-test="toggle"]').attributes('aria-controls')).toBeUndefined()
		expect(wrapper.find('button[data-test="logout"]').exists()).toBe(false)
		await wrapper.find('button[data-test="toggle"]').trigger('click')
		expect(wrapper.find('button[data-test="toggle"]').attributes('aria-expanded')).toBe('true')
		expect(wrapper.find('button[data-test="toggle"]').attributes('aria-controls')).toBeDefined()
		expect(wrapper.find('button[data-test="logout"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('shows the number of affected events in the logout dialog', async () => {
		const wrapper = mountSection(makeStatus({ sync: { eventCount: 340 } }))
		await open(wrapper, 'logout')

		expect(document.body.textContent).toContain('Remove the app\'s events (340)')
		wrapper.unmount()
	})

	it('offers calendar deletion only for a calendar created by the app', async () => {
		const own = mountSection()
		await open(own, 'logout')
		expect(document.body.textContent).toContain('Delete calendar “Garmin-Aktivitäten”')
		own.unmount()
		document.body.innerHTML = ''

		const foreign = mountSection(makeStatus({ setup: { calendar: { id: 3, name: 'Sport', createdByApp: false } } }))
		await open(foreign, 'logout')
		expect(document.body.textContent).toContain('Remove the app\'s events')
		expect(document.body.textContent).not.toContain('Delete calendar')
		foreign.unmount()
	})

	it('names the event count in the calendar deletion option', async () => {
		const wrapper = mountSection(makeStatus({ sync: { eventCount: 340 } }))
		await open(wrapper, 'logout')

		expect(document.body.textContent).toContain('Delete calendar “Garmin-Aktivitäten” (340)')
		wrapper.unmount()
	})

	it('hides the calendar deletion when the linked calendar no longer exists', async () => {
		const wrapper = mountSection(makeStatus({ setup: { calendar: { id: 7, name: null, createdByApp: true } } }))
		await open(wrapper, 'logout')

		expect(document.body.textContent).toContain('Remove the app\'s events')
		expect(document.body.textContent).not.toContain('Delete calendar')
		wrapper.unmount()
	})

	it('signs out without cleanup by default and passes the chosen cleanup', async () => {
		const wrapper = mountSection()
		await open(wrapper, 'logout')

		dialogButton('Sign out')?.click()
		await flushPromises()
		expect(api.logout).toHaveBeenLastCalledWith('none')
		expect(wrapper.emitted('status')).toEqual([[loggedOut]])
		wrapper.unmount()
		document.body.innerHTML = ''

		const second = mountSection()
		await open(second, 'logout')
		document.body.querySelector<HTMLInputElement>('input[type="radio"][value="events"]')?.click()
		await flushPromises()
		dialogButton('Sign out')?.click()
		await flushPromises()
		expect(api.logout).toHaveBeenLastCalledWith('events')
		second.unmount()
	})

	it('says that reset cannot be undone', async () => {
		const wrapper = mountSection()
		await open(wrapper, 'reset')

		expect(document.body.textContent).toContain('cannot be undone')
		dialogButton('Reset app')?.click()
		await flushPromises()
		expect(api.reset).toHaveBeenCalledWith('none')
		wrapper.unmount()
	})

	it('locks the cleanup options while maintenance runs', async () => {
		const wrapper = mountSection(makeStatus({ setup: { maintenance: 'move' } }), true)
		await open(wrapper, 'logout')

		const radios = [...document.body.querySelectorAll<HTMLInputElement>('input[type="radio"]')]
		expect(radios.find((r) => r.value === 'none')?.disabled).toBe(false)
		expect(radios.find((r) => r.value === 'events')?.disabled).toBe(true)
		expect(radios.find((r) => r.value === 'calendar')?.disabled).toBe(true)
		wrapper.unmount()
	})

	it('disables the confirm button while the request is pending', async () => {
		vi.mocked(api.logout).mockReturnValue(new Promise(() => {}))
		const wrapper = mountSection()
		await open(wrapper, 'logout')

		dialogButton('Sign out')?.click()
		await flushPromises()
		expect(dialogButton('Sign out')?.disabled).toBe(true)
		wrapper.unmount()
	})

	it('shows an error and stays open when the request fails', async () => {
		vi.mocked(api.logout).mockRejectedValue(new api.ApiError(409, 'busy'))
		const wrapper = mountSection()
		await open(wrapper, 'logout')

		dialogButton('Sign out')?.click()
		await flushPromises()
		expect(document.body.textContent).toContain('A sync is running right now')
		expect(wrapper.emitted('status')).toBeUndefined()
		wrapper.unmount()
	})

	it('keeps the dialog open while the request runs and shows a later error', async () => {
		let fail: (e: unknown) => void = () => {}
		vi.mocked(api.logout).mockReturnValue(new Promise((_resolve, reject) => {
			fail = reject
		}))
		const wrapper = mountSection()
		await open(wrapper, 'logout')

		dialogButton('Sign out')?.click()
		await flushPromises()
		expect(dialogButton('Cancel')?.disabled).toBe(true)
		dialogButton('Cancel')?.click()
		document.body.querySelector('[role="dialog"]')?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
		await flushPromises()
		expect(document.body.querySelector('[role="dialog"]')).not.toBeNull()

		fail(new api.ApiError(409, 'busy'))
		await flushPromises()
		expect(document.body.textContent).toContain('A sync is running right now')
		wrapper.unmount()
	})

	it('can reopen the dialog after it was cancelled', async () => {
		const wrapper = mountSection()
		await open(wrapper, 'logout')

		dialogButton('Cancel')?.click()
		await flushPromises()
		expect(document.body.querySelector('[role="dialog"]')).toBeNull()

		await wrapper.find('button[data-test="logout"]').trigger('click')
		await flushPromises()
		expect(document.body.querySelector('[role="dialog"]')).not.toBeNull()
		wrapper.unmount()
	})
})
