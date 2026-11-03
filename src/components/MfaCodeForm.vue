<template>
	<form class="mfa-form" @submit.prevent="submit">
		<p>{{ mfaPromptText(method) }}</p>

		<NcTextField
			v-model="code"
			inputmode="numeric"
			autocomplete="one-time-code"
			:label="t('workoutcalendar', 'Verification code')"
			:disabled="busy" />

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div class="mfa-form__actions">
			<NcButton variant="primary" type="submit" :disabled="busy">
				{{ t('workoutcalendar', 'Confirm') }}
			</NcButton>
			<NcButton
				v-if="canRequestNew"
				type="button"
				variant="tertiary"
				:disabled="busy || waitSeconds > 0"
				@click="requestNew">
				{{ t('workoutcalendar', 'Request a new code') }}
			</NcButton>
		</div>
		<p v-if="canRequestNew && waitSeconds > 0" class="mfa-form__wait" role="status">
			{{ t('workoutcalendar', 'You can request a new code in {seconds} s.', { seconds: waitSeconds }) }}
		</p>
	</form>
</template>

<script setup lang="ts">
import type { Status } from '../types.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ApiError, getStatus, requestMfaCode, verifyMfaCode } from '../api.ts'
import { apiErrorText, isLoginFailure, loginError, MFA_REQUEST_GONE, mfaKind, mfaPromptText, mfaRequestGoneText } from '../messages.ts'
import { useCountdown } from '../useCountdown.ts'

const props = defineProps<{
	/** Verification method Garmin reported, or null if unknown. */
	method: string | null
	/** Unix seconds before which no new code can be requested, or null. */
	nextRequestAt?: number | null
}>()

const emit = defineEmits<{
	status: [status: Status]
	/** The server no longer has the code request; the parent shows the text. Comes before the reloaded status. */
	ended: [message: string]
}>()

const code = ref('')
const busy = ref(false)
const error = ref('')

// An authenticator app generates codes itself, so there is nothing to request.
const canRequestNew = computed(() => mfaKind(props.method) !== 'app')

const { waitSeconds } = useCountdown(() => props.nextRequestAt)

async function fail(e: unknown) {
	const apiError = e instanceof ApiError ? e : new ApiError(0, 'internal')
	if (!MFA_REQUEST_GONE.includes(apiError.code)) {
		error.value = isLoginFailure(apiError.code) ? loginError(apiError.code) : apiErrorText(apiError)
		return
	}
	// The server deleted the request, so this form is out of date: the reloaded status replaces it.
	// WHY the parent shows the text: it outlives this form.
	emit('ended', mfaRequestGoneText(apiError.code, props.method))
	try {
		emit('status', await getStatus())
	} catch {
		// The next status refresh of the page catches up.
	}
}

async function submit() {
	// A second submit while the first is running must not send the code again.
	if (busy.value) {
		return
	}
	const digits = code.value.replace(/\s+/g, '')
	if (!/^\d{6}$/.test(digits)) {
		error.value = t('workoutcalendar', 'Please enter the 6-digit code.')
		return
	}
	busy.value = true
	error.value = ''
	try {
		emit('status', await verifyMfaCode(digits))
	} catch (e) {
		await fail(e)
	} finally {
		busy.value = false
	}
}

async function requestNew() {
	if (busy.value || waitSeconds.value > 0) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		const result = await requestMfaCode()
		code.value = ''
		emit('status', result.status)
	} catch (e) {
		await fail(e)
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.mfa-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 480px;
	align-items: flex-start;
}

.mfa-form :deep(.input-field) {
	width: 100%;
}

.mfa-form__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.mfa-form__wait {
	color: var(--color-text-maxcontrast);
}
</style>
