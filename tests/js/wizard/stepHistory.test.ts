import type * as ApiModule from '../../../src/api.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import StepHistory from '../../../src/components/wizard/StepHistory.vue'
import { makeStatus } from '../fixtures.ts'

vi.mock('@nextcloud/l10n', async (importOriginal) => (await import('./l10nMock.ts')).l10nFactory(importOriginal))
vi.mock('../../../src/api.ts', async (importOriginal) => ({
	...(await importOriginal<typeof ApiModule>()),
	chooseHistory: vi.fn(),
}))

const api = await import('../../../src/api.ts')
const chooseHistory = vi.mocked(api.chooseHistory)

const running = makeStatus({ sync: { eventCount: 340, backfill: { running: true, reachedDate: '2023-03-02' } } })
const fresh = makeStatus({ setup: { step: 'history' } })

describe('StepHistory', () => {
	beforeEach(() => {
		chooseHistory.mockReset()
	})

	it('preselects 90 days and sends null for the complete history', async () => {
		chooseHistory.mockResolvedValue(running)
		const wrapper = mount(StepHistory, { props: { status: fresh, started: false } })

		expect((wrapper.find('input[type="radio"][value="90"]').element as HTMLInputElement).checked).toBe(true)
		expect(wrapper.text()).toContain('Recommended')

		await wrapper.find('input[type="radio"][value="all"]').setValue(true)
		await wrapper.find('button[data-test="start"]').trigger('click')
		await flushPromises()

		expect(chooseHistory).toHaveBeenCalledWith(null)
		expect(wrapper.emitted('status')).toEqual([[running]])
		expect(wrapper.emitted('started')).toHaveLength(1)
	})

	it('sends 90 by default', async () => {
		chooseHistory.mockResolvedValue(running)
		const wrapper = mount(StepHistory, { props: { status: fresh, started: false } })

		await wrapper.find('button[data-test="start"]').trigger('click')
		await flushPromises()

		expect(chooseHistory).toHaveBeenCalledWith(90)
	})

	it('can continue while the import runs', async () => {
		const wrapper = mount(StepHistory, { props: { status: running, started: true } })

		expect(wrapper.text()).toContain('340')
		expect(wrapper.text()).toContain('about 5 minutes')
		const next = wrapper.find('button[data-test="next"]')
		expect(next.attributes('disabled')).toBeUndefined()
		await next.trigger('click')

		expect(wrapper.emitted('next')).toHaveLength(1)
	})
})

describe('StepHistory before the start', () => {
	it('offers only the start button, no Next', () => {
		const wrapper = mount(StepHistory, { props: { status: fresh, started: false } })

		expect(wrapper.find('button[data-test="start"]').exists()).toBe(true)
		expect(wrapper.find('button[data-test="next"]').exists()).toBe(false)
	})

	it('formats the reached month in UTC', () => {
		const wrapper = mount(StepHistory, {
			props: { status: makeStatus({ sync: { eventCount: 5, backfill: { running: true, reachedDate: '2023-03-01' } } }), started: true },
		})

		expect(wrapper.text()).toContain('March 2023')
	})
})
