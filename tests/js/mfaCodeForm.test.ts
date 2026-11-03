import type * as ApiModule from '../../src/api.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import MfaCodeForm from '../../src/components/MfaCodeForm.vue'
import { makeStatus } from './fixtures.ts'

vi.mock('@nextcloud/l10n', async (importOriginal) => (await import('./wizard/l10nMock.ts')).l10nFactory(importOriginal))
vi.mock('../../src/api.ts', async (importOriginal) => ({
	...(await importOriginal<typeof ApiModule>()),
	verifyMfaCode: vi.fn(),
	requestMfaCode: vi.fn(),
}))

const api = await import('../../src/api.ts')
const verifyMfaCode = vi.mocked(api.verifyMfaCode)
const requestMfaCode = vi.mocked(api.requestMfaCode)

async function submit(wrapper: ReturnType<typeof mount>, code: string) {
	await wrapper.find('input').setValue(code)
	await wrapper.find('form').trigger('submit')
}

describe('MfaCodeForm', () => {
	beforeEach(() => {
		verifyMfaCode.mockReset()
		requestMfaCode.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('sendsOnlyOneVerifyOnDoubleClick', async () => {
		verifyMfaCode.mockReturnValue(new Promise(() => {}))
		const wrapper = mount(MfaCodeForm, { props: { method: 'email', nextRequestAt: null } })

		await submit(wrapper, '123456')
		await wrapper.find('form').trigger('submit')

		expect(verifyMfaCode).toHaveBeenCalledTimes(1)
	})

	it('stripsSpacesFromAPastedCode', async () => {
		const status = makeStatus()
		verifyMfaCode.mockResolvedValue(status)
		const wrapper = mount(MfaCodeForm, { props: { method: 'email', nextRequestAt: null } })

		await submit(wrapper, '123 456')
		await flushPromises()

		expect(verifyMfaCode).toHaveBeenCalledWith('123456')
		expect(wrapper.emitted('status')).toEqual([[status]])
	})

	it('rejectsSevenDigitsWithoutARequest', async () => {
		const wrapper = mount(MfaCodeForm, { props: { method: 'email', nextRequestAt: null } })

		await submit(wrapper, '1234567')

		expect(verifyMfaCode).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('Please enter the 6-digit code.')
	})

	it('hidesNewCodeForAnAuthenticatorApp', () => {
		const wrapper = mount(MfaCodeForm, { props: { method: 'totp', nextRequestAt: null } })

		expect(wrapper.text()).not.toContain('Request a new code')
		expect(wrapper.text()).toContain('Enter the code from your authenticator app.')
	})

	it('disablesNewCodeUntilNextRequestAt', async () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date(1_760_000_000_000))
		const wrapper = mount(MfaCodeForm, { props: { method: 'email', nextRequestAt: 1_760_000_030 } })
		const button = () => wrapper.findAll('button').find((b) => b.text().includes('Request a new code'))!

		expect(button().attributes('disabled')).toBeDefined()
		expect(wrapper.text()).toContain('You can request a new code in 30 s.')

		await vi.advanceTimersByTimeAsync(31_000)

		expect(button().attributes('disabled')).toBeUndefined()
		expect(wrapper.text()).not.toContain('You can request a new code in')
	})

	it('shows the text for a wrong code', async () => {
		verifyMfaCode.mockRejectedValue(new api.ApiError(422, 'MFA_CODE_INVALID'))
		const wrapper = mount(MfaCodeForm, { props: { method: 'email', nextRequestAt: null } })

		await submit(wrapper, '123456')
		await flushPromises()

		expect(wrapper.text()).toContain('The code is not correct.')
	})

	it('requests a new code and emits the status', async () => {
		const status = makeStatus()
		requestMfaCode.mockResolvedValue({ outcome: 'codeRequired', status })
		const wrapper = mount(MfaCodeForm, { props: { method: 'email', nextRequestAt: null } })

		await wrapper.findAll('button').find((b) => b.text().includes('Request a new code'))!.trigger('click')
		await flushPromises()

		expect(requestMfaCode).toHaveBeenCalledTimes(1)
		expect(wrapper.emitted('status')).toEqual([[status]])
	})
})
