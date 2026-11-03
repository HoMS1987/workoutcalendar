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
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import MfaCodeForm from '../MfaCodeForm.vue'
import { ApiError, cancelMfa, requestMfaCode } from '../../api.ts'
import { apiErrorText, isLoginFailure, loginError, mfaKind } from '../../messages.ts'
import { useCountdown } from '../../useCountdown.ts'

const props = defineProps<{ status: Status }>()
const emit = defineEmits<{ status: [status: Status] }>()

const busy = ref(false)
const error = ref('')

const pending = computed(() => props.status.mfa.pending)
const isApp = computed(() => mfaKind(props.status.mfa.method) === 'app')

const { waitSeconds } = useCountdown(() => props.status.mfa.nextRequestAt)

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
