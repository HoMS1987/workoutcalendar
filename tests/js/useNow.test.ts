import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import { useNow } from '../../src/useNow.ts'

function host() {
	let now = { value: 0 }
	const wrapper = mount(defineComponent({
		setup() {
			now = useNow()
			return () => null
		},
	}))
	return { wrapper, now: () => now.value }
}

describe('useNow', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-10-21T12:00:00Z'))
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('starts at the current time and ticks every 30 seconds', async () => {
		const { wrapper, now } = host()
		const start = Date.now()
		expect(now()).toBe(start)

		await vi.advanceTimersByTimeAsync(29_999)
		expect(now()).toBe(start)
		await vi.advanceTimersByTimeAsync(1)
		expect(now()).toBe(start + 30_000)
		wrapper.unmount()
	})

	it('shares one timer and stops it when the last user is gone', async () => {
		const first = host()
		const second = host()
		expect(vi.getTimerCount()).toBe(1)

		first.wrapper.unmount()
		expect(vi.getTimerCount()).toBe(1)
		second.wrapper.unmount()
		expect(vi.getTimerCount()).toBe(0)
	})
})
