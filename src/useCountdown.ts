import type { ComputedRef } from 'vue'

import { computed, onScopeDispose, ref, watch } from 'vue'

/**
 * Seconds left until a Unix timestamp, counted down once per second. The timer only runs while
 * the target lies in the future and stops with the surrounding scope.
 *
 * @param target Unix seconds to count down to, or null/undefined for none
 */
export function useCountdown(target: () => number | null | undefined): { waitSeconds: ComputedRef<number> } {
	// WHY: the shared useNow() clock ticks every 30 seconds, too coarse for a countdown in seconds.
	const nowSeconds = ref(Math.floor(Date.now() / 1000))
	const waitSeconds = computed(() => {
		const at = target()
		return typeof at !== 'number' ? 0 : Math.max(0, at - nowSeconds.value)
	})

	let timer: ReturnType<typeof setInterval> | undefined
	function stop() {
		if (timer !== undefined) {
			clearInterval(timer)
			timer = undefined
		}
	}

	watch(target, () => {
		stop()
		nowSeconds.value = Math.floor(Date.now() / 1000)
		if (waitSeconds.value > 0) {
			timer = setInterval(() => {
				nowSeconds.value = Math.floor(Date.now() / 1000)
				if (waitSeconds.value === 0) {
					stop()
				}
			}, 1000)
		}
	}, { immediate: true })
	onScopeDispose(stop)

	return { waitSeconds }
}
