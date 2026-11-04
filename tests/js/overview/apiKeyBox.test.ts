import type * as ApiModule from '../../../src/api.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ApiKeyBox from '../../../src/components/overview/ApiKeyBox.vue'
import { makeStatus } from '../fixtures.ts'

vi.mock('@nextcloud/l10n', async (importOriginal) => (await import('../wizard/l10nMock.ts')).l10nFactory(importOriginal))
vi.mock('../../../src/api.ts', async (importOriginal) => ({
	...(await importOriginal<typeof ApiModule>()),
	connectApiKey: vi.fn(),
}))

const api = await import('../../../src/api.ts')
const connectApiKey = vi.mocked(api.connectApiKey)

describe('ApiKeyBox', () => {
	beforeEach(() => {
		connectApiKey.mockReset()
	})

	it('showsTheTextAndTheForm', () => {
		const wrapper = mount(ApiKeyBox)

		expect(wrapper.text()).toContain('Your Garmin API key has expired or was deleted.')
		expect(wrapper.find('a').attributes('href')).toBe('https://connect.garmin.com/app/settings/personalApiToken')
		expect(wrapper.find('input').exists()).toBe(true)
		expect(wrapper.find('button[type="submit"]').text()).toBe('Save')
	})

	it('savingEmitsTheStatus', async () => {
		const status = makeStatus({ setup: { authMethod: 'apiKey', maskedEmail: null } })
		connectApiKey.mockResolvedValue(status)
		const wrapper = mount(ApiKeyBox)

		await wrapper.find('input').setValue(' k-2 ')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(connectApiKey).toHaveBeenCalledWith('k-2')
		expect(wrapper.emitted('status')).toEqual([[status]])
	})

	it('saveSendsOnlyOnce', async () => {
		connectApiKey.mockReturnValue(new Promise(() => {}))
		const wrapper = mount(ApiKeyBox)

		await wrapper.find('input').setValue('k-2')
		await wrapper.find('form').trigger('submit')
		await wrapper.find('form').trigger('submit')

		expect(connectApiKey).toHaveBeenCalledTimes(1)
	})
})
