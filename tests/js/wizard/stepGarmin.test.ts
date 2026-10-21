import type * as ApiModule from '../../../src/api.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import StepGarmin from '../../../src/components/wizard/StepGarmin.vue'
import { ApiError } from '../../../src/api.ts'
import { makeStatus } from '../fixtures.ts'

vi.mock('@nextcloud/l10n', async (importOriginal) => (await import('./l10nMock.ts')).l10nFactory(importOriginal))
vi.mock('../../../src/api.ts', async (importOriginal) => ({
	...(await importOriginal<typeof ApiModule>()),
	login: vi.fn(),
}))

const api = await import('../../../src/api.ts')
const login = vi.mocked(api.login)

async function fillAndSubmit(wrapper: ReturnType<typeof mount>) {
	await wrapper.find('input[type="email"]').setValue('me@example.org')
	await wrapper.find('input[type="password"]').setValue('secret')
	await wrapper.find('form').trigger('submit')
}

describe('StepGarmin', () => {
	beforeEach(() => {
		login.mockReset()
	})

	it('disables the login button while the request runs and sends one request', async () => {
		login.mockReturnValue(new Promise(() => {}))
		const wrapper = mount(StepGarmin)

		await fillAndSubmit(wrapper)
		await wrapper.find('form').trigger('submit')

		expect(login).toHaveBeenCalledTimes(1)
		expect(login).toHaveBeenCalledWith('me@example.org', 'secret')
		expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
	})

	it('shows the waiting text during a slow login', async () => {
		login.mockReturnValue(new Promise(() => {}))
		const wrapper = mount(StepGarmin)

		await fillAndSubmit(wrapper)

		expect(wrapper.text()).toContain('Signing in to Garmin …')
	})

	it('shows the Garmin error text for INVALID_CREDENTIALS', async () => {
		login.mockRejectedValue(new ApiError(401, 'INVALID_CREDENTIALS'))
		const wrapper = mount(StepGarmin)

		await fillAndSubmit(wrapper)
		await flushPromises()

		expect(wrapper.text()).toContain('Garmin rejected your email address or password.')
		expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined()
	})

	it('does not retry the login after 409', async () => {
		login.mockRejectedValue(new ApiError(409, 'busy'))
		const wrapper = mount(StepGarmin)

		await fillAndSubmit(wrapper)
		await flushPromises()
		await new Promise((resolve) => setTimeout(resolve, 50))

		expect(login).toHaveBeenCalledTimes(1)
		expect(wrapper.text()).toContain('A sync is running right now.')
	})

	it('emits the returned status after a successful login', async () => {
		const status = makeStatus({ setup: { step: 'calendar' } })
		login.mockResolvedValue(status)
		const wrapper = mount(StepGarmin)

		await fillAndSubmit(wrapper)
		await flushPromises()

		expect(wrapper.emitted('status')).toEqual([[status]])
	})
})

describe('StepGarmin last account hint', () => {
	it('names the last connected account without filling the input', () => {
		const wrapper = mount(StepGarmin, { props: { maskedEmail: 'a***@example.org' } })

		expect(wrapper.text()).toContain('Last connected as a***@example.org.')
		expect((wrapper.find('input[type="email"]').element as HTMLInputElement).value).toBe('')
	})

	it('shows no hint without a known account', () => {
		const wrapper = mount(StepGarmin, { props: { maskedEmail: null } })

		expect(wrapper.text()).not.toContain('Last connected')
	})
})
