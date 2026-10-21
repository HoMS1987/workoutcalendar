import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import OverviewTiles from '../../../src/components/overview/OverviewTiles.vue'
import { makeStatus } from '../fixtures.ts'

vi.mock('@nextcloud/l10n', async (importOriginal) => (await import('../wizard/l10nMock.ts')).l10nFactory(importOriginal))

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
