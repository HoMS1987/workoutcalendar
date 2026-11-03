<template>
	<NcNoteCard type="warning" data-test="mfa-box">
		<template v-if="pending">
			<p>{{ pending.origin === 'sync' ? t('workoutcalendar', 'Garmin sent you a code during the last attempt.') : t('workoutcalendar', 'Enter the verification code to finish signing in again.') }}</p>
			<MfaCodeForm
				:method="pending.method ?? status.mfa.method"
				:nextRequestAt="status.mfa.nextRequestAt"
				@status="emit('status', $event)" />
			<NcButton
				v-if="status.paused === null"
				class="mfa-box__cancel"
				type="button"
				variant="tertiary"
				:disabled="busy"
				@click="cancel">
				{{ t('workoutcalendar', 'Cancel') }}
			</NcButton>
		</template>
		<template v-else>
			<p>{{ t('workoutcalendar', 'Garmin asks for a verification code. Syncing is paused until you enter it.') }}</p>
			<NcButton
				type="button"
				variant="primary"
				:disabled="busy || waitSeconds > 0"
				@click="request">
				{{ isApp ? t('workoutcalendar', 'Start sign-in') : t('workoutcalendar', 'Request code') }}
			</NcButton>
			<p v-if="waitSeconds > 0" role="status">
				{{ t('workoutcalendar', 'You can request a new code in {seconds} s.', { seconds: waitSeconds }) }}
			</p>
		</template>
		<p v-if="error" class="mfa-box__error" role="alert">
			{{ error }}
		</p>
	</NcNoteCard>
</template>

<script setup lang="ts">
import type { Status } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { computed, onScopeDispose, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import MfaCodeForm from '../MfaCodeForm.vue'
import { ApiError, cancelMfa, requestMfaCode } from '../../api.ts'
import { apiErrorText, isLoginFailure, loginError, mfaKind } from '../../messages.ts'

const props = defineProps<{ status: Status }>()
const emit = defineEmits<{ status: [status: Status] }>()

const busy = ref(false)
const error = ref('')

const pending = computed(() => props.status.mfa.pending)
const isApp = computed(() => mfaKind(props.status.mfa.method) === 'app')

// WHY: the shared useNow() clock ticks every 30 seconds, too coarse for a countdown in seconds.
const nowSeconds = ref(Math.floor(Date.now() / 1000))
const waitSeconds = computed(() => {
	const at = props.status.mfa.nextRequestAt
	return typeof at !== 'number' ? 0 : Math.max(0, at - nowSeconds.value)
})

let timer: ReturnType<typeof setInterval> | undefined
function stopTimer() {
	if (timer !== undefined) {
		clearInterval(timer)
		timer = undefined
	}
}
watch(() => props.status.mfa.nextRequestAt, () => {
	stopTimer()
	nowSeconds.value = Math.floor(Date.now() / 1000)
	if (waitSeconds.value > 0) {
		timer = setInterval(() => {
			nowSeconds.value = Math.floor(Date.now() / 1000)
			if (waitSeconds.value === 0) {
				stopTimer()
			}
		}, 1000)
	}
}, { immediate: true })
onScopeDispose(stopTimer)

function errorText(e: unknown): string {
	const apiError = e instanceof ApiError ? e : new ApiError(0, 'internal')
	return isLoginFailure(apiError.code) ? loginError(apiError.code) : apiErrorText(apiError)
}

async function request() {
	// A second click while the first request runs must not ask Garmin for another code.
	if (busy.value || waitSeconds.value > 0) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		emit('status', (await requestMfaCode()).status)
	} catch (e) {
		error.value = errorText(e)
	} finally {
		busy.value = false
	}
}

async function cancel() {
	if (busy.value) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		emit('status', await cancelMfa())
	} catch (e) {
		error.value = errorText(e)
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.mfa-box__cancel {
	margin-top: 8px;
}
</style>
