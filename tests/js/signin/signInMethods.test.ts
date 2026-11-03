import type * as ApiModule from '../../../src/api.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SignInMethods from '../../../src/components/signin/SignInMethods.vue'

vi.mock('@nextcloud/l10n', async (importOriginal) => (await import('../wizard/l10nMock.ts')).l10nFactory(importOriginal))
vi.mock('../../../src/api.ts', async (importOriginal) => ({
	...(await importOriginal<typeof ApiModule>()),
	connectApiKey: vi.fn(),
	login: vi.fn(),
}))

const props = { submitLabel: 'Connect and continue' }

describe('SignInMethods', () => {
	it('apiKeyTabIsPreselected', () => {
		const wrapper = mount(SignInMethods, { props })

		expect(wrapper.find('[data-test="method-apikey"]').attributes('aria-pressed')).toBe('true')
		expect(wrapper.find('[data-test="method-password"]').attributes('aria-pressed')).toBe('false')
		expect(wrapper.text()).toContain('API key (recommended)')
		expect(wrapper.text()).toContain('Email and password')
		expect(wrapper.find('ol').exists()).toBe(true)
		expect(wrapper.find('input[type="email"]').exists()).toBe(false)
	})

	it('switchesToThePasswordForm', async () => {
		const wrapper = mount(SignInMethods, { props })

		await wrapper.find('[data-test="method-password"]').trigger('click')

		expect(wrapper.find('[data-test="method-password"]').attributes('aria-pressed')).toBe('true')
		expect(wrapper.find('input[type="email"]').exists()).toBe(true)
		expect(wrapper.find('ol').exists()).toBe(false)

		await wrapper.find('[data-test="method-apikey"]').trigger('click')
		expect(wrapper.find('ol').exists()).toBe(true)
	})

	it('honoursTheInitialTab', () => {
		const wrapper = mount(SignInMethods, { props: { ...props, initial: 'password' } })

		expect(wrapper.find('input[type="email"]').exists()).toBe(true)
	})

	it('pendingSetupCodeOpensThePasswordTab', async () => {
		const pending = { origin: 'setup' as const, method: 'email', expiresAt: 1_760_001_800 }
		const wrapper = mount(SignInMethods, { props: { ...props, pending, nextRequestAt: null } })

		expect(wrapper.find('[data-test="method-password"]').attributes('aria-pressed')).toBe('true')
		expect(wrapper.find('input[autocomplete="one-time-code"]').exists()).toBe(true)

		await wrapper.setProps({ pending: null })
		expect(wrapper.find('input[type="email"]').exists()).toBe(true)
	})

	it('keepsTheTypedEmailAcrossTabs', async () => {
		const wrapper = mount(SignInMethods, { props: { ...props, initial: 'password' } })

		await wrapper.find('input[type="email"]').setValue('me@example.org')
		await wrapper.find('[data-test="method-apikey"]').trigger('click')
		await wrapper.find('[data-test="method-password"]').trigger('click')

		expect((wrapper.find('input[type="email"]').element as HTMLInputElement).value).toBe('me@example.org')
	})
})
