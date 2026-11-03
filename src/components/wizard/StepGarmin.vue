<template>
	<div class="step">
		<h3>{{ t('workoutcalendar', 'Sign in to Garmin') }}</h3>
		<SignInMethods
			v-model:email="email"
			:submitLabel="t('workoutcalendar', 'Connect and continue')"
			:maskedEmail="maskedEmail"
			:pending="pending"
			:nextRequestAt="nextRequestAt"
			@status="emit('status', $event)"
			@back="emit('status', $event)" />
	</div>
</template>

<script setup lang="ts">
import type { PendingMfa, Status } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import SignInMethods from '../signin/SignInMethods.vue'

// Bound by the page so the address survives a step back from the calendar; the password never is.
const email = defineModel<string>('email', { default: '' })

defineProps<{
	/** Masked address of the account that was connected before, if any. */
	maskedEmail?: string | null
	/** Code request from the setup login that waits for its code. */
	pending?: PendingMfa | null
	/** Earliest time for the next code request, Unix seconds. */
	nextRequestAt?: number | null
}>()

const emit = defineEmits<{ status: [status: Status] }>()
</script>

<style scoped>
.step {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 480px;
	align-items: flex-start;
}
</style>
