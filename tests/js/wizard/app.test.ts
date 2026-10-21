import type * as ApiModule from '../../../src/api.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import App from '../../../src/App.vue'
import StepGarmin from '../../../src/components/wizard/StepGarmin.vue'
import { makeStatus } from '../fixtures.ts'

vi.mock('@nextcloud/l10n', async (importOriginal) => (await import('./l10nMock.ts')).l10nFactory(importOriginal))
vi.mock('../../../src/api.ts', async (importOriginal) => ({
	...(await importOriginal<typeof ApiModule>()),
	getStatus: vi.fn(),
	login: vi.fn(),
	getCalendars: vi.fn(),
	chooseCalendar: vi.fn(),
	chooseHistory: vi.fn(),
}))

const api = await import('../../../src/api.ts')
const disconnected = makeStatus({ setup: { connected: false, step: 'garmin', maskedEmail: null, calendar: null } })
const atCalendar = makeStatus({ setup: { step: 'calendar', calendar: null } })
const atHistory = makeStatus({ setup: { step: 'history' } })
const importing = makeStatus({ sync: { eventCount: 12, backfill: { running: true, reachedDate: '2024-05-01' } } })

async function mountApp() {
	const wrapper = mount(App, { attachTo: document.body })
	await flushPromises()
	return wrapper
}

async function clickTest(wrapper: ReturnType<typeof mount>, name: string) {
	await wrapper.find(`button[data-test="${name}"]`).trigger('click')
	await flushPromises()
}

async function submitLogin(wrapper: ReturnType<typeof mount>) {
	await wrapper.find('input[type="email"]').setValue('me@example.org')
	await wrapper.find('input[type="password"]').setValue('secret')
	await wrapper.find('form').trigger('submit')
	await flushPromises()
}

describe('App wizard flow', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		vi.mocked(api.getStatus).mockReset().mockResolvedValue(disconnected)
		vi.mocked(api.login).mockReset().mockResolvedValue(atCalendar)
		vi.mocked(api.getCalendars).mockReset().mockResolvedValue({ defaultName: 'Garmin-Aktivitäten', calendars: [] })
		vi.mocked(api.chooseCalendar).mockReset().mockResolvedValue({ needsConfirmation: false, existingEvents: 0, calendar: { id: 1, name: 'x' }, status: atHistory })
		vi.mocked(api.chooseHistory).mockReset().mockResolvedValue(importing)
	})

	it('shows no sponsor link in the wizard', async () => {
		vi.mocked(api.getStatus).mockResolvedValue(disconnected)
		const wrapper = await mountApp()

		expect(wrapper.find('[data-test="support"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('moves from a successful login to the calendar step', async () => {
		const wrapper = await mountApp()
		expect(wrapper.find('form').exists()).toBe(true)

		await submitLogin(wrapper)

		expect(wrapper.text()).toContain('Choose a calendar')
		wrapper.unmount()
	})

	it('goes back to the login form and returns to the calendar after a new login', async () => {
		const wrapper = await mountApp()
		await submitLogin(wrapper)

		await clickTest(wrapper, 'back')
		expect(wrapper.text()).toContain('Sign in to Garmin')
		expect(api.login).toHaveBeenCalledTimes(1)

		await submitLogin(wrapper)
		expect(wrapper.text()).toContain('Choose a calendar')
		wrapper.unmount()
	})

	it('keeps the typed email address, but not the password, when going back to the login form', async () => {
		const wrapper = await mountApp()
		await submitLogin(wrapper)

		await clickTest(wrapper, 'back')

		expect((wrapper.find('input[type="email"]').element as HTMLInputElement).value).toBe('me@example.org')
		expect((wrapper.find('input[type="password"]').element as HTMLInputElement).value).toBe('')
		wrapper.unmount()
	})

	it('keeps the masked address of an earlier account as a hint only', async () => {
		vi.mocked(api.getStatus).mockResolvedValue(makeStatus({ setup: { connected: false, step: 'garmin', maskedEmail: 'a***@example.org', calendar: null } }))
		const wrapper = await mountApp()

		expect(wrapper.text()).toContain('Last connected as a***@example.org.')
		expect(wrapper.findComponent(StepGarmin).props('maskedEmail')).toBe('a***@example.org')
		expect((wrapper.find('input[type="email"]').element as HTMLInputElement).value).toBe('')
		wrapper.unmount()
	})

	it('passes no masked address to the login form while the account is connected', async () => {
		vi.mocked(api.getStatus).mockResolvedValue(atCalendar)
		expect(atCalendar.setup.maskedEmail).toBe('a***@example.org')
		const wrapper = await mountApp()

		await clickTest(wrapper, 'back')

		expect(wrapper.text()).toContain('Sign in to Garmin')
		expect(wrapper.findComponent(StepGarmin).props('maskedEmail')).toBeNull()
		expect(wrapper.text()).not.toContain('Last connected as')
		wrapper.unmount()
	})

	it('keeps the progress visible after the start, then shows done, then the overview', async () => {
		vi.mocked(api.getStatus).mockResolvedValue(atHistory)
		const wrapper = await mountApp()

		await clickTest(wrapper, 'start')
		expect(wrapper.text()).toContain('12 activities, back to May 2024')
		expect(wrapper.find('button[data-test="next"]').exists()).toBe(true)

		await clickTest(wrapper, 'next')
		expect(wrapper.text()).toContain('All set')
		expect(wrapper.text()).toContain('The import is still running in the background.')

		await clickTest(wrapper, 'settings')
		expect(wrapper.text()).not.toContain('All set')
		expect(wrapper.find('.workoutcalendar-settings__overview').exists()).toBe(true)
		wrapper.unmount()
	})
})
