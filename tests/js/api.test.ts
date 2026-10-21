import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, chooseHistory, getStatus, login, updateSettings } from '../../src/api.ts'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path: string) => '/index.php' + path,
}))

const mocked = vi.mocked(axios)

beforeEach(() => {
	vi.resetAllMocks()
})

describe('api client', () => {
	it('sends numbers as JSON numbers', async () => {
		mocked.post.mockResolvedValue({ data: { setup: {} } })

		await chooseHistory(90)

		expect(mocked.post).toHaveBeenCalledWith(
			expect.stringMatching(/\/apps\/workoutcalendar\/api\/history$/),
			{ historyDays: 90 },
		)
	})

	it('sends null for the complete history', async () => {
		mocked.post.mockResolvedValue({ data: {} })

		await chooseHistory(null)

		expect(mocked.post.mock.calls[0][1]).toEqual({ historyDays: null })
	})

	it('uses PUT for settings', async () => {
		mocked.put.mockResolvedValue({ data: {} })
		const settings = { intervalMinutes: 60, jitterPercent: 10, deletionMode: 'keep' as const, deletionCheckDays: 30 }

		await updateSettings(settings)

		expect(mocked.put).toHaveBeenCalledWith(expect.stringMatching(/\/api\/settings$/), settings)
	})

	it('maps 422 to ApiError with the category', async () => {
		mocked.post.mockRejectedValue({ response: { status: 422, data: { error: 'INVALID_CREDENTIALS' } } })

		const error = await login('a@b.c', 'secret').catch((e: unknown) => e)

		expect(error).toBeInstanceOf(ApiError)
		expect(error).toMatchObject({ status: 422, code: 'INVALID_CREDENTIALS' })
	})

	it('maps 409 to busy', async () => {
		mocked.get.mockRejectedValue({ response: { status: 409, data: { error: 'busy' } } })

		await expect(getStatus()).rejects.toMatchObject({ status: 409, code: 'busy' })
	})

	it('maps a response without error code to internal', async () => {
		mocked.get.mockRejectedValue({ response: { status: 500, data: '<html>' } })

		await expect(getStatus()).rejects.toMatchObject({ status: 500, code: 'internal' })
	})

	it('maps a network failure to code network', async () => {
		mocked.get.mockRejectedValue(new Error('Network Error'))

		await expect(getStatus()).rejects.toMatchObject({ status: 0, code: 'network' })
	})

	it('uses the app routes', async () => {
		mocked.get.mockResolvedValue({ data: {} })

		await getStatus()

		expect(mocked.get.mock.calls[0][0]).toMatch(/\/apps\/workoutcalendar\/api\/status$/)
	})
})
