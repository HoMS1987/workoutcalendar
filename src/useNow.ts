import type { Ref } from 'vue'

import { onScopeDispose, readonly, ref } from 'vue'

const TICK_MS = 30_000

// One clock for all relative times on the page; it only runs while someone uses it.
const now = ref(Date.now())
let users = 0
let timer: ReturnType<typeof setInterval> | undefined

/**
 * The current time in milliseconds, refreshed every 30 seconds so relative times such as
 * "3 minutes ago" stay current while the page is open. The timer stops with the last user.
 */
export function useNow(): Readonly<Ref<number>> {
	if (users === 0) {
		now.value = Date.now()
		timer = setInterval(() => {
			now.value = Date.now()
		}, TICK_MS)
	}
	users++

	onScopeDispose(() => {
		users--
		if (users === 0 && timer !== undefined) {
			clearInterval(timer)
			timer = undefined
		}
	})

	return readonly(now)
}
