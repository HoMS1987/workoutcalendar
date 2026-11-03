import type * as ApiModule from '../../../src/api.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MfaBox from '../../../src/components/overview/MfaBox.vue'
import { makeStatus } from '../fixtures.ts'

vi.mock('@nextcloud/l10n', async (importOriginal) => (await import('../wizard/l10nMock.ts')).l10nFactory(importOriginal))
vi.mock('../../../src/api.ts', async (importOriginal) => ({
	...(await importOriginal<typeof ApiModule>()),
	requestMfaCode: vi.fn(),
	cancelMfa: vi.fn(),
	verifyMfaCode: vi.fn(),
}))

const api = await import('../../../src/api.ts')
const requestMfaCode = vi.mocked(api.requestMfaCode)
const cancelMfa = vi.mocked(api.cancelMfa)

const paused = () => makeStatus({ paused: 'MFA_REQUIRED', mfa: { account: true, method: 'email' } })
function pending(origin: 'sync' | 'relogin', paused: 'MFA_REQUIRED' | null) {
	return makeStatus({
		paused,
		mfa: { account: true, method: 'email', pending: { origin, method: 'email', expiresAt: 1_760_001_800 } },
	})
}

function button(wrapper: ReturnType<typeof mount>, text: string) {
	return wrapper.findAll('button').find((b) => b.text().includes(text))
}

describe('MfaBox', () => {
	beforeEach(() => {
		requestMfaCode.mockReset()
		cancelMfa.mockReset()
	})

	it('pausedWithoutRequestShowsRequestCode', () => {
		const wrapper = mount(MfaBox, { props: { status: paused() } })

		expect(wrapper.text()).toContain('Garmin asks for a verification code. Syncing is paused until you enter it.')
		expect(button(wrapper, 'Request code')).toBeDefined()
		expect(wrapper.find('input').exists()).toBe(false)
	})

	it('appMethodShowsStartSignIn', () => {
		const status = makeStatus({ paused: 'MFA_REQUIRED', mfa: { account: true, method: 'totp' } })
		const wrapper = mount(MfaBox, { props: { status } })

		expect(button(wrapper, 'Start sign-in')).toBeDefined()
		expect(button(wrapper, 'Request code')).toBeUndefined()
	})

	it('requestSendsOnlyOnce', async () => {
		requestMfaCode.mockReturnValue(new Promise(() => {}))
		const wrapper = mount(MfaBox, { props: { status: paused() } })

		await button(wrapper, 'Request code')!.trigger('click')
		await button(wrapper, 'Request code')!.trigger('click')

		expect(requestMfaCode).toHaveBeenCalledTimes(1)
	})

	it('requestThatSignsInDirectlyEmitsStatus', async () => {
		const status = makeStatus()
		requestMfaCode.mockResolvedValue({ outcome: 'signedIn', status })
		const wrapper = mount(MfaBox, { props: { status: paused() } })

		await button(wrapper, 'Request code')!.trigger('click')
		await flushPromises()

		expect(wrapper.emitted('status')).toEqual([[status]])
	})

	it('pendingFromSyncShowsTheCodeFormAtOnce', () => {
		const wrapper = mount(MfaBox, { props: { status: pending('sync', 'MFA_REQUIRED') } })

		expect(wrapper.text()).toContain('Garmin sent you a code during the last attempt.')
		expect(wrapper.find('input').exists()).toBe(true)
	})

	it('pendingFromReloginAsksToFinishSigningIn', () => {
		const wrapper = mount(MfaBox, { props: { status: pending('relogin', null) } })

		expect(wrapper.text()).toContain('Enter the verification code to finish signing in again.')
		expect(wrapper.find('input').exists()).toBe(true)
	})

	it('cancelIsOfferedOnlyWithoutPause', async () => {
		const withPause = mount(MfaBox, { props: { status: pending('sync', 'MFA_REQUIRED') } })
		expect(button(withPause, 'Cancel')).toBeUndefined()

		const status = makeStatus()
		cancelMfa.mockResolvedValue(status)
		const without = mount(MfaBox, { props: { status: pending('relogin', null) } })
		await button(without, 'Cancel')!.trigger('click')
		await flushPromises()

		expect(cancelMfa).toHaveBeenCalledTimes(1)
		expect(without.emitted('status')).toEqual([[status]])
	})

	it('rateLimitShowsTheWaitTime', async () => {
		requestMfaCode.mockRejectedValue(new api.ApiError(429, 'rateLimited'))
		const wrapper = mount(MfaBox, { props: { status: paused() } })

		await button(wrapper, 'Request code')!.trigger('click')
		await flushPromises()
		expect(wrapper.text()).toContain('Too many attempts. Please try again later.')

		const nextRequestAt = Math.floor(Date.now() / 1000) + 45
		await wrapper.setProps({ status: makeStatus({ paused: 'MFA_REQUIRED', mfa: { account: true, method: 'email', nextRequestAt } }) })
		expect(button(wrapper, 'Request code')!.attributes('disabled')).toBeDefined()
		expect(wrapper.text()).toMatch(/You can request a new code in \d+ s\./)
	})
})
