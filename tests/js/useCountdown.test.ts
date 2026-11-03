import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, ref } from 'vue'
import { useCountdown } from '../../src/useCountdown.ts'

const START = 1_760_000_000

function host(initial: number | null) {
	const target = ref<number | null>(initial)
	let seconds = { value: -1 }
	const wrapper = mount(defineComponent({
		setup() {
			seconds = useCountdown(() => target.value).waitSeconds
			return () => null
		},
	}))
	return { wrapper, target, seconds: () => seconds.value }
}

describe('useCountdown', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date(START * 1000))
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('countsDownToZero', async () => {
		const { seconds } = host(START + 5)

		expect(seconds()).toBe(5)
		await vi.advanceTimersByTimeAsync(2000)
		expect(seconds()).toBe(3)
		await vi.advanceTimersByTimeAsync(10_000)
		expect(seconds()).toBe(0)
	})

	it('stopsTheTimerAtZero', async () => {
		host(START + 2)
		expect(vi.getTimerCount()).toBe(1)

		await vi.advanceTimersByTimeAsync(3000)

		expect(vi.getTimerCount()).toBe(0)
	})

	it('stopsOnScopeDispose', () => {
		const { wrapper } = host(START + 60)
		expect(vi.getTimerCount()).toBe(1)

		wrapper.unmount()

		expect(vi.getTimerCount()).toBe(0)
	})

	it('restartsWhenTheTargetChanges', async () => {
		const { target, seconds } = host(START + 2)
		await vi.advanceTimersByTimeAsync(3000)
		expect(seconds()).toBe(0)
		expect(vi.getTimerCount()).toBe(0)

		target.value = START + 3 + 30
		await vi.advanceTimersByTimeAsync(0)
		expect(seconds()).toBe(30)
		expect(vi.getTimerCount()).toBe(1)
		await vi.advanceTimersByTimeAsync(10_000)
		expect(seconds()).toBe(20)
	})

	it('isZeroWithoutATarget', () => {
		const { seconds } = host(null)

		expect(seconds()).toBe(0)
		expect(vi.getTimerCount()).toBe(0)
	})
})
