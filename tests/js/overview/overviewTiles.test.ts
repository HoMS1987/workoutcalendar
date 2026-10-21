import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import OverviewTiles from '../../../src/components/overview/OverviewTiles.vue'
import { makeStatus } from '../fixtures.ts'

vi.mock('@nextcloud/l10n', async (importOriginal) => (await import('../wizard/l10nMock.ts')).l10nFactory(importOriginal))

// A mounted tile keeps the shared clock running; each test starts with a fresh one.
enableAutoUnmount(afterEach)

describe('OverviewTiles next sync', () => {
	const now = () => Math.floor(Date.now() / 1000)

	it('shows "Due" instead of a past relative time', () => {
		const wrapper = mount(OverviewTiles, { props: { status: makeStatus({ sync: { nextRunAt: now() - 600 } }) } })

		expect(wrapper.find('[data-test="tile-next"]').text()).toContain('Due')
		expect(wrapper.find('[data-test="tile-next"]').text()).not.toContain('ago')
	})

	it('keeps the relative time for a future run', () => {
		const wrapper = mount(OverviewTiles, { props: { status: makeStatus({ sync: { nextRunAt: now() + 1200 } }) } })

		expect(wrapper.find('[data-test="tile-next"]').text()).toContain('in 20 minutes')
	})
})

describe('OverviewTiles connection marker', () => {
	const marker = (wrapper: ReturnType<typeof mount>) => wrapper.find('[data-test="tile-connection"] [data-test="connection-state"]')

	it('marks a working connection as success next to "Connected"', () => {
		const wrapper = mount(OverviewTiles, { props: { status: makeStatus() } })

		expect(marker(wrapper).classes()).toContain('tiles__state--success')
		expect(marker(wrapper).text()).toBe('Connected')
	})

	it('marks a failing sync with the error colour next to the text', () => {
		const wrapper = mount(OverviewTiles, { props: { status: makeStatus({ failure: { category: 'NETWORK', since: null, count: 1 } }) } })

		expect(marker(wrapper).classes()).toContain('tiles__state--error')
		expect(marker(wrapper).text()).toBe('Needs attention')
	})

	it('marks a paused sync with the error colour next to the text', () => {
		const wrapper = mount(OverviewTiles, { props: { status: makeStatus({ paused: 'INVALID_CREDENTIALS' }) } })

		expect(marker(wrapper).classes()).toContain('tiles__state--error')
		expect(marker(wrapper).text()).toBe('Needs attention')
	})
})

describe('OverviewTiles while the page stays open', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-10-21T12:00:00Z'))
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	const now = () => Math.floor(Date.now() / 1000)

	it('refreshes the time of the last sync', async () => {
		const wrapper = mount(OverviewTiles, { props: { status: makeStatus({ sync: { lastSuccessAt: now() - 300 } }) } })
		expect(wrapper.find('[data-test="tile-last"]').text()).toContain('5 minutes ago')

		await vi.advanceTimersByTimeAsync(60_000)
		expect(wrapper.find('[data-test="tile-last"]').text()).toContain('6 minutes ago')
		wrapper.unmount()
	})

	it('switches the next sync to "Due" once its time has passed', async () => {
		const wrapper = mount(OverviewTiles, { props: { status: makeStatus({ sync: { nextRunAt: now() + 50 } }) } })
		expect(wrapper.find('[data-test="tile-next"]').text()).not.toContain('Due')

		await vi.advanceTimersByTimeAsync(60_000)
		expect(wrapper.find('[data-test="tile-next"]').text()).toContain('Due')
		wrapper.unmount()
	})
})
