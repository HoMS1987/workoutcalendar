import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import StatusHints from '../../../src/components/overview/StatusHints.vue'
import { makeStatus } from '../fixtures.ts'

vi.mock('@nextcloud/l10n', async (importOriginal) => (await import('../wizard/l10nMock.ts')).l10nFactory(importOriginal))

describe('StatusHints', () => {
	const cardType = (category: string) => {
		const status = makeStatus({ failure: { category, since: null, count: 1 } })
		const wrapper = mount(StatusHints, { props: { status, hints: ['failure'] } })
		return wrapper.findComponent(NcNoteCard).props('type')
	}

	it('shows a transient sync failure as a warning card', () => {
		expect(cardType('NETWORK')).toBe('warning')
	})

	it('shows a failure only the person can fix as an error card', () => {
		expect(cardType('INVALID_CREDENTIALS')).toBe('error')
	})
})
