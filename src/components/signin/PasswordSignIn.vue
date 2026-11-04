<template>
	<div v-if="pending" class="step">
		<h3>{{ t('workoutcalendar', 'Enter the verification code') }}</h3>
		<MfaCodeForm
			:method="pending.method"
			:nextRequestAt="nextRequestAt"
			@ended="error = $event"
			@status="emit('status', $event)" />
		<p class="step__hint">
			{{ t('workoutcalendar', 'Your Garmin account uses two-step verification. If the Garmin sign-in expires at some point, Workout Calendar pauses and lets you know through a notification. The app only requests a new code when you ask for it.') }}
		</p>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcButton
			type="button"
			variant="tertiary"
			:disabled="busy"
			@click="back">
			{{ t('workoutcalendar', 'Back') }}
		</NcButton>
	</div>
	<form v-else class="step" @submit.prevent="submit">
		<p>{{ t('workoutcalendar', 'Enter the email address and password of your Garmin account. The password is stored encrypted and only used to sign in.') }}</p>

		<p v-if="maskedEmail" class="step__last">
			{{ t('workoutcalendar', 'Last connected as {email}.', { email: maskedEmail }, { escape: false }) }}
		</p>

		<NcTextField
			v-model="email"
			type="email"
			autocomplete="username"
			:label="t('workoutcalendar', 'Garmin email address')"
			:disabled="busy"
			required />
		<NcPasswordField
			v-model="password"
			autocomplete="current-password"
			:label="t('workoutcalendar', 'Garmin password')"
			:disabled="busy"
			required />

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<p v-if="busy" class="step__waiting" role="status">
			<NcLoadingIcon :size="20" />
			{{ t('workoutcalendar', 'Signing in to Garmin …') }}
		</p>

		<NcButton variant="primary" type="submit" :disabled="busy">
			{{ submitLabel ?? t('workoutcalendar', 'Sign in and continue') }}
		</NcButton>
	</form>
</template>

<script setup lang="ts">
import type { PendingMfa, Status } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import MfaCodeForm from '../MfaCodeForm.vue'
import { ApiError, cancelMfa, login } from '../../api.ts'
import { apiErrorText, isLoginFailure, loginError } from '../../messages.ts'

// Bound by the page so the address survives a step back from the calendar; the password never is.
const email = defineModel<string>('email', { default: '' })

defineProps<{
	/** Text of the submit button, by default the one of the setup. */
	submitLabel?: string
	/** Masked address of the account that was connected before, if any. */
	maskedEmail?: string | null
	/** Code request from the setup login that waits for its code. */
	pending?: PendingMfa | null
	/** Earliest time for the next code request, Unix seconds. */
	nextRequestAt?: number | null
}>()

// "back" carries the status after the code request was given up; it is not a sign-in.
const emit = defineEmits<{ status: [status: Status], back: [status: Status] }>()

const password = ref('')
const busy = ref(false)
const error = ref('')

async function submit() {
	// A second submit while the first is running must not send another login.
	if (busy.value) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		emit('status', await login(email.value, password.value))
	} catch (e) {
		const apiError = e instanceof ApiError ? e : new ApiError(0, 'internal')
		error.value = isLoginFailure(apiError.code) ? loginError(apiError.code) : apiErrorText(apiError)
	} finally {
		busy.value = false
	}
}

// Gives up the pending code request and shows the login form again.
async function back() {
	if (busy.value) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		emit('back', await cancelMfa())
	} catch (e) {
		const apiError = e instanceof ApiError ? e : new ApiError(0, 'internal')
		error.value = apiErrorText(apiError)
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.step {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 480px;
	align-items: flex-start;
}

.step :deep(.input-field) {
	width: 100%;
}

.step__hint {
	color: var(--color-text-maxcontrast);
}

.step__waiting {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
