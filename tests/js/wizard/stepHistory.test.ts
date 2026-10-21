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

	it('shows a progress line while the import starts', async () => {
		let resolve: (status: typeof running) => void = () => {}
		chooseHistory.mockReturnValue(new Promise((r) => {
			resolve = r
		}))
		const wrapper = mount(StepHistory, { props: { status: fresh, started: false } })

		await wrapper.find('button[data-test="start"]').trigger('click')

		const line = wrapper.find('[data-test="importing"]')
		expect(line.exists()).toBe(true)
		expect(line.attributes('role')).toBe('status')
		expect(line.text()).toContain('Importing the first activities …')
		expect(line.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)

		resolve(running)
		await flushPromises()
		expect(wrapper.find('[data-test="importing"]').exists()).toBe(false)
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

describe('StepHistory completion text', () => {
	const idle = (lastSuccessAt: number | null) => makeStatus({ sync: { eventCount: 12, lastSuccessAt, backfill: { running: false } } })

	it('says the import is complete only after a successful sync', () => {
		const wrapper = mount(StepHistory, { props: { status: idle(1_760_000_000), started: true } })

		expect(wrapper.text()).toContain('The import is complete.')
	})

	it('announces the first sync instead when none has succeeded yet', () => {
		const wrapper = mount(StepHistory, { props: { status: idle(null), started: true } })

		expect(wrapper.text()).toContain('The import starts with the next sync.')
		expect(wrapper.text()).not.toContain('The import is complete.')
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
