<template>
	<form class="step" @submit.prevent="submit">
		<h3>{{ t('workoutcalendar', 'Sign in to Garmin') }}</h3>
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
			{{ t('workoutcalendar', 'Sign in and continue') }}
		</NcButton>
	</form>
</template>

<script setup lang="ts">
import type { Status } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ApiError, login } from '../../api.ts'
import { apiErrorText, isLoginFailure, loginError } from '../../messages.ts'

// Bound by the page so the address survives a step back from the calendar; the password never is.
const email = defineModel<string>('email', { default: '' })

defineProps<{
	/** Masked address of the account that was connected before, if any. */
	maskedEmail?: string | null
}>()

const emit = defineEmits<{ status: [status: Status] }>()

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

.step__waiting {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
