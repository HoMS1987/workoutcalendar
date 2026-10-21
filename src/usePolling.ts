import type { Ref } from 'vue'

import { onScopeDispose, watch } from 'vue'

/**
 * Calls `fn` every `intervalMs` while `active` is true. Stops when `active` turns false or the
 * owning scope (component) is disposed. A new call never starts while the previous one is pending,
 * and a failing call does not stop the polling.
 *
 * @param fn The poll; may be async
 * @param active Polling runs only while this is true
 * @param intervalMs Time between two polls
 */
export function usePolling(fn: () => Promise<void> | void, active: Ref<boolean>, intervalMs = 10_000): void {
	let timer: ReturnType<typeof setInterval> | undefined
	let running = false

	function stop() {
		if (timer !== undefined) {
			clearInterval(timer)
			timer = undefined
		}
	}

	async function tick() {
		if (running) {
			return
		}
		running = true
		try {
			await fn()
		} catch {
			// A failed poll is retried on the next tick.
		} finally {
			running = false
		}
	}

	watch(active, (isActive) => {
		stop()
		if (isActive) {
			timer = setInterval(tick, intervalMs)
		}
	}, { immediate: true })

	onScopeDispose(stop)
}
