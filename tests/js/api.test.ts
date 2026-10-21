import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, chooseCalendar, chooseHistory, getCalendars, getStatus, login, logout, reset, syncNow, updateSettings } from '../../src/api.ts'

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

	it('posts the credentials to login', async () => {
		mocked.post.mockResolvedValue({ data: { setup: {} } })

		await login('a@b.c', 'secret')

		expect(mocked.post).toHaveBeenCalledWith(
			expect.stringMatching(/\/apps\/workoutcalendar\/api\/login$/),
			{ email: 'a@b.c', password: 'secret' },
		)
	})

	it('loads the calendars with GET and returns the response data', async () => {
		const data = { defaultName: 'Workouts', calendars: [{ id: 3, name: 'Sport', color: '#00ff00' }] }
		mocked.get.mockResolvedValue({ data })

		const result = await getCalendars()

		expect(mocked.get).toHaveBeenCalledWith(expect.stringMatching(/\/apps\/workoutcalendar\/api\/calendars$/))
		expect(result).toEqual(data)
	})

	it('posts the calendar choice unchanged', async () => {
		const data = { needsConfirmation: true, existingEvents: 4, calendar: null, status: {} }
		mocked.post.mockResolvedValue({ data })
		const input = { calendarId: 7, confirmed: false, moveEvents: true }

		const result = await chooseCalendar(input)

		expect(mocked.post).toHaveBeenCalledWith(
			expect.stringMatching(/\/apps\/workoutcalendar\/api\/calendar$/),
			input,
		)
		expect(result).toEqual(data)
	})

	it('posts a new calendar name', async () => {
		mocked.post.mockResolvedValue({ data: {} })

		await chooseCalendar({ name: 'Workouts', confirmed: true, moveEvents: false })

		expect(mocked.post.mock.calls[0][1]).toEqual({ name: 'Workouts', confirmed: true, moveEvents: false })
	})

	it('posts an empty object to sync now', async () => {
		const data = { outcome: 'done', status: {} }
		mocked.post.mockResolvedValue({ data })

		const result = await syncNow()

		expect(mocked.post).toHaveBeenCalledWith(
			expect.stringMatching(/\/apps\/workoutcalendar\/api\/sync$/),
			{},
		)
		expect(result).toEqual(data)
	})

	it('maps a busy sync to ApiError busy', async () => {
		mocked.post.mockRejectedValue({ response: { status: 409, data: { error: 'busy' } } })

		await expect(syncNow()).rejects.toMatchObject({ status: 409, code: 'busy' })
	})

	it('posts the cleanup choice to logout', async () => {
		mocked.post.mockResolvedValue({ data: {} })

		await logout('events')

		expect(mocked.post).toHaveBeenCalledWith(
			expect.stringMatching(/\/apps\/workoutcalendar\/api\/logout$/),
			{ cleanup: 'events' },
		)
	})

	it('posts the cleanup choice to reset', async () => {
		mocked.post.mockResolvedValue({ data: {} })

		await reset('calendar')

		expect(mocked.post).toHaveBeenCalledWith(
			expect.stringMatching(/\/apps\/workoutcalendar\/api\/reset$/),
			{ cleanup: 'calendar' },
		)
	})

	it('maps a failed reset to ApiError with the category', async () => {
		mocked.post.mockRejectedValue({ response: { status: 500, data: { error: 'internal' } } })

		await expect(reset('none')).rejects.toBeInstanceOf(ApiError)
	})

	it('maps a failed settings update to ApiError with the category', async () => {
		mocked.put.mockRejectedValue({ response: { status: 422, data: { error: 'invalid' } } })

		await expect(updateSettings({ intervalMinutes: 1, jitterPercent: 0, deletionMode: 'keep', deletionCheckDays: 30 }))
			.rejects.toMatchObject({ status: 422, code: 'invalid' })
	})
})
