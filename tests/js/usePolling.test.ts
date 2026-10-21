import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, ref } from 'vue'
import { usePolling } from '../../src/usePolling.ts'

function host(fn: () => Promise<void> | void, active = ref(true), intervalMs?: number) {
	return mount(defineComponent({
		setup() {
			usePolling(fn, active, intervalMs)
			return () => null
		},
	}))
}

describe('usePolling', () => {
	beforeEach(() => {
		vi.useFakeTimers()
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('polls every 10 seconds only while active and stops on unmount', async () => {
		const fn = vi.fn().mockResolvedValue(undefined)
		const active = ref(false)
		const wrapper = host(fn, active)

		await vi.advanceTimersByTimeAsync(30_000)
		expect(fn).not.toHaveBeenCalled()

		active.value = true
		await vi.advanceTimersByTimeAsync(9_999)
		expect(fn).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(1)
		expect(fn).toHaveBeenCalledTimes(1)
		await vi.advanceTimersByTimeAsync(10_000)
		expect(fn).toHaveBeenCalledTimes(2)

		active.value = false
		await vi.advanceTimersByTimeAsync(30_000)
		expect(fn).toHaveBeenCalledTimes(2)

		active.value = true
		await vi.advanceTimersByTimeAsync(10_000)
		expect(fn).toHaveBeenCalledTimes(3)

		wrapper.unmount()
		await vi.advanceTimersByTimeAsync(60_000)
		expect(fn).toHaveBeenCalledTimes(3)
	})

	it('never runs two polls at once', async () => {
		let release: () => void = () => {}
		const fn = vi.fn(() => new Promise<void>((resolve) => {
			release = resolve
		}))
		const wrapper = host(fn)

		await vi.advanceTimersByTimeAsync(35_000)
		expect(fn).toHaveBeenCalledTimes(1)

		release()
		await vi.advanceTimersByTimeAsync(10_000)
		expect(fn).toHaveBeenCalledTimes(2)
		wrapper.unmount()
	})

	it('keeps polling after a failed poll', async () => {
		const fn = vi.fn().mockRejectedValueOnce(new Error('offline')).mockResolvedValue(undefined)
		const wrapper = host(fn)

		await vi.advanceTimersByTimeAsync(20_000)
		expect(fn).toHaveBeenCalledTimes(2)
		wrapper.unmount()
	})
})
