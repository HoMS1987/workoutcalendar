import type * as ApiModule from '../../../src/api.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ApiKeyForm from '../../../src/components/signin/ApiKeyForm.vue'
import { ApiError } from '../../../src/api.ts'
import { makeStatus } from '../fixtures.ts'

vi.mock('@nextcloud/l10n', async (importOriginal) => (await import('../wizard/l10nMock.ts')).l10nFactory(importOriginal))
vi.mock('../../../src/api.ts', async (importOriginal) => ({
	...(await importOriginal<typeof ApiModule>()),
	connectApiKey: vi.fn(),
}))

const api = await import('../../../src/api.ts')
const connectApiKey = vi.mocked(api.connectApiKey)

function mountForm() {
	return mount(ApiKeyForm, { props: { submitLabel: 'Connect and continue' } })
}

describe('ApiKeyForm', () => {
	beforeEach(() => {
		connectApiKey.mockReset()
	})

	it('showsTheGuideWithTheGarminLink', () => {
		const wrapper = mountForm()
		const link = wrapper.find('a')

		expect(link.attributes('href')).toBe('https://connect.garmin.com/app/settings/personalApiToken')
		expect(link.attributes('target')).toBe('_blank')
		expect(link.attributes('rel')).toContain('noopener')
		expect(link.text()).toBe('Personal API Key')
		expect(wrapper.findAll('ol li')).toHaveLength(3)
		expect(wrapper.text()).toContain('Open Personal API Key in the Garmin Connect settings.')
		expect(wrapper.text()).toContain('Select Create Key, read the terms and accept them.')
		expect(wrapper.text()).toContain('Garmin shows it only once.')
		expect(wrapper.text()).toContain('A key is valid for one year at most.')
		expect(wrapper.find('input').attributes('autocomplete')).toBe('off')
		expect(wrapper.find('button[type="submit"]').text()).toBe('Connect and continue')
	})

	it('trimsAPastedKey', async () => {
		const status = makeStatus()
		connectApiKey.mockResolvedValue(status)
		const wrapper = mountForm()

		await wrapper.find('input').setValue('  k-1\n')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(connectApiKey).toHaveBeenCalledWith('k-1')
		expect(wrapper.emitted('status')).toEqual([[status]])
	})

	it('sendsNothingForAnEmptyKey', async () => {
		const wrapper = mountForm()

		await wrapper.find('input').setValue('   ')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(connectApiKey).not.toHaveBeenCalled()
	})

	it('sendsOnlyOneRequestOnDoubleClick', async () => {
		connectApiKey.mockReturnValue(new Promise(() => {}))
		const wrapper = mountForm()

		await wrapper.find('input').setValue('k-1')
		await wrapper.find('form').trigger('submit')
		await wrapper.find('form').trigger('submit')

		expect(connectApiKey).toHaveBeenCalledTimes(1)
		expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
	})

	it('showsTheRejectedText', async () => {
		connectApiKey.mockRejectedValue(new ApiError(422, 'API_KEY_REJECTED'))
		const wrapper = mountForm()

		await wrapper.find('input').setValue('k-1')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.text()).toContain('Garmin did not accept this API key. Please check it or create a new one.')
		expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined()
	})

	it('showsTheBusyText', async () => {
		connectApiKey.mockRejectedValue(new ApiError(409, 'busy'))
		const wrapper = mountForm()

		await wrapper.find('input').setValue('k-1')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.text()).toContain('A sync is running right now.')
	})
})
