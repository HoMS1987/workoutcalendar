import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { draftFromSettings } from '../../../src/components/overview/settingsDraft.ts'
import { makeStatus } from '../fixtures.ts'

const root = join(import.meta.dirname, '..', '..', '..')

// The form duplicates the PHP default; read it from the source so the two cannot drift apart.
function phpDefaultJitter(): number {
	const source = readFileSync(join(root, 'lib', 'Sync', 'SyncSettings.php'), 'utf8')
	const match = source.match(/int \$jitterPercent = (\d+)/)
	expect(match).not.toBeNull()
	return Number(match![1])
}

describe('draftFromSettings', () => {
	it('offers the default spread of SyncSettings when the spread is off', () => {
		const settings = { ...makeStatus().settings, jitterPercent: 0 }

		const draft = draftFromSettings(settings)

		expect(draft.jitterEnabled).toBe(false)
		expect(draft.jitterPercent).toBe(String(phpDefaultJitter()))
	})

	it('keeps a spread that is switched on', () => {
		const settings = { ...makeStatus().settings, jitterPercent: 35 }

		expect(draftFromSettings(settings)).toMatchObject({ jitterEnabled: true, jitterPercent: '35' })
	})
})
