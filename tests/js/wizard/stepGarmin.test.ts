import type * as ApiModule from '../../../src/api.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PasswordSignIn from '../../../src/components/signin/PasswordSignIn.vue'
import StepGarmin from '../../../src/components/wizard/StepGarmin.vue'
import { ApiError } from '../../../src/api.ts'
import { makeStatus } from '../fixtures.ts'

vi.mock('@nextcloud/l10n', async (importOriginal) => (await import('./l10nMock.ts')).l10nFactory(importOriginal))
vi.mock('../../../src/api.ts', async (importOriginal) => ({
	...(await importOriginal<typeof ApiModule>()),
	login: vi.fn(),
	cancelMfa: vi.fn(),
	verifyMfaCode: vi.fn(),
	getStatus: vi.fn(),
}))

const api = await import('../../../src/api.ts')
const login = vi.mocked(api.login)
const cancelMfa = vi.mocked(api.cancelMfa)

async function fillAndSubmit(wrapper: ReturnType<typeof mount>) {
	await wrapper.find('input[type="email"]').setValue('me@example.org')
	await wrapper.find('input[type="password"]').setValue('secret')
	await wrapper.find('form').trigger('submit')
}

describe('PasswordSignIn', () => {
	beforeEach(() => {
		login.mockReset()
	})

	it('disables the login button while the request runs and sends one request', async () => {
		login.mockReturnValue(new Promise(() => {}))
		const wrapper = mount(PasswordSignIn)

		await fillAndSubmit(wrapper)
		await wrapper.find('form').trigger('submit')

		expect(login).toHaveBeenCalledTimes(1)
		expect(login).toHaveBeenCalledWith('me@example.org', 'secret')
		expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
	})

	it('shows the waiting text during a slow login', async () => {
		login.mockReturnValue(new Promise(() => {}))
		const wrapper = mount(PasswordSignIn)

		await fillAndSubmit(wrapper)

		expect(wrapper.text()).toContain('Signing in to Garmin …')
	})

	it('shows the Garmin error text for INVALID_CREDENTIALS', async () => {
		login.mockRejectedValue(new ApiError(401, 'INVALID_CREDENTIALS'))
		const wrapper = mount(PasswordSignIn)

		await fillAndSubmit(wrapper)
		await flushPromises()

		expect(wrapper.text()).toContain('Garmin rejected your email address or password.')
		expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined()
	})

	it('does not retry the login after 409', async () => {
		login.mockRejectedValue(new ApiError(409, 'busy'))
		const wrapper = mount(PasswordSignIn)

		await fillAndSubmit(wrapper)
		await flushPromises()
		await new Promise((resolve) => setTimeout(resolve, 50))

		expect(login).toHaveBeenCalledTimes(1)
		expect(wrapper.text()).toContain('A sync is running right now.')
	})

	it('emits the returned status after a successful login', async () => {
		const status = makeStatus({ setup: { step: 'calendar' } })
		login.mockResolvedValue(status)
		const wrapper = mount(PasswordSignIn)

		await fillAndSubmit(wrapper)
		await flushPromises()

		expect(wrapper.emitted('status')).toEqual([[status]])
	})
})

describe('PasswordSignIn last account hint', () => {
	it('names the last connected account without filling the input', () => {
		const wrapper = mount(PasswordSignIn, { props: { maskedEmail: 'a***@example.org' } })

		expect(wrapper.text()).toContain('Last connected as a***@example.org.')
		expect((wrapper.find('input[type="email"]').element as HTMLInputElement).value).toBe('')
	})

	it('shows no hint without a known account', () => {
		const wrapper = mount(PasswordSignIn, { props: { maskedEmail: null } })

		expect(wrapper.text()).not.toContain('Last connected')
	})
})

describe('PasswordSignIn two-step verification', () => {
	const pending = { origin: 'setup' as const, method: 'email', expiresAt: 1_760_001_800 }

	it('showsTheCodeFormAndTheTwoStepHintForAPendingSetupRequest', () => {
		const wrapper = mount(PasswordSignIn, { props: { pending, nextRequestAt: null } })

		expect(wrapper.find('input[autocomplete="one-time-code"]').exists()).toBe(true)
		expect(wrapper.find('input[type="password"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('Garmin has sent you a code by email.')
		expect(wrapper.text()).toContain('Your Garmin account uses two-step verification.')
		expect(wrapper.text()).toContain('The app only requests a new code when you ask for it.')
	})

	it('backCancelsTheRequestAndShowsTheForm', async () => {
		const status = makeStatus({ setup: { step: 'garmin', connected: false } })
		cancelMfa.mockResolvedValue(status)
		const wrapper = mount(PasswordSignIn, { props: { pending, nextRequestAt: null } })

		await wrapper.findAll('button').find((b) => b.text() === 'Back')!.trigger('click')
		await flushPromises()

		expect(cancelMfa).toHaveBeenCalledTimes(1)
		// Giving up the request is not a sign-in, so it has its own event.
		expect(wrapper.emitted('back')).toEqual([[status]])
		expect(wrapper.emitted('status')).toBeUndefined()
	})

	it('backInTheWizardStillPassesTheStatusOn', async () => {
		const status = makeStatus({ setup: { step: 'garmin', connected: false } })
		cancelMfa.mockResolvedValue(status)
		const wrapper = mount(StepGarmin, { props: { pending, nextRequestAt: null } })

		await wrapper.findAll('button').find((b) => b.text() === 'Back')!.trigger('click')
		await flushPromises()

		expect(wrapper.emitted('status')).toEqual([[status]])
	})

	it('showsWhyTheCodeFieldWasReplacedByTheLoginForm', async () => {
		const status = makeStatus({ setup: { connected: false, step: 'garmin' } })
		vi.mocked(api.verifyMfaCode).mockRejectedValue(new ApiError(422, 'MFA_EXPIRED'))
		vi.mocked(api.getStatus).mockResolvedValue(status)
		const wrapper = mount(PasswordSignIn, { props: { pending: { origin: 'setup', method: 'email', expiresAt: 1_760_001_800 } } })

		await wrapper.find('input').setValue('123456')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(wrapper.emitted('status')).toEqual([[status]])
		await wrapper.setProps({ pending: null })

		expect(wrapper.find('input[type="password"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('The code request has expired.')
	})
})

describe('StepGarmin', () => {
	it('showsTheHeadingAndTheApiKeyTabFirst', () => {
		const wrapper = mount(StepGarmin)

		expect(wrapper.find('h3').text()).toBe('Sign in to Garmin')
		expect(wrapper.find('[data-test="method-apikey"]').attributes('aria-pressed')).toBe('true')
		expect(wrapper.find('button[type="submit"]').text()).toBe('Connect and continue')
	})

	it('opensThePasswordFormForAPendingSetupRequest', () => {
		const pending = { origin: 'setup' as const, method: 'email', expiresAt: 1_760_001_800 }
		const wrapper = mount(StepGarmin, { props: { pending } })

		expect(wrapper.find('input[autocomplete="one-time-code"]').exists()).toBe(true)
	})
})
