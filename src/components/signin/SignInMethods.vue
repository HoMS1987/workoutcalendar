<template>
	<div class="methods">
		<div class="methods__tabs">
			<NcButton
				type="button"
				data-test="method-apikey"
				:variant="method === 'apiKey' ? 'primary' : 'secondary'"
				:aria-pressed="method === 'apiKey' ? 'true' : 'false'"
				@click="method = 'apiKey'">
				{{ t('workoutcalendar', 'API key (recommended)') }}
			</NcButton>
			<NcButton
				type="button"
				data-test="method-password"
				:variant="method === 'password' ? 'primary' : 'secondary'"
				:aria-pressed="method === 'password' ? 'true' : 'false'"
				@click="method = 'password'">
				{{ t('workoutcalendar', 'Email and password') }}
			</NcButton>
		</div>

		<ApiKeyForm
			v-if="method === 'apiKey'"
			:submitLabel="submitLabel"
			@status="emit('status', $event)" />
		<PasswordSignIn
			v-else
			v-model:email="email"
			:submitLabel="passwordSubmitLabel"
			:maskedEmail="maskedEmail"
			:pending="pending"
			:nextRequestAt="nextRequestAt"
			@status="emit('status', $event)"
			@back="emit('back', $event)" />
	</div>
</template>

<script setup lang="ts">
import type { PendingMfa, Status } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import ApiKeyForm from './ApiKeyForm.vue'
import PasswordSignIn from './PasswordSignIn.vue'

const email = defineModel<string>('email', { default: '' })

const props = withDefaults(defineProps<{
	/** Tab that is open first. */
	initial?: 'apiKey' | 'password'
	/** Text of the button that connects with the API key. */
	submitLabel: string
	/** Text of the button that signs in with email and password; PasswordSignIn has its own default. */
	passwordSubmitLabel?: string
	maskedEmail?: string | null
	/** Code request from the setup login that waits for its code. */
	pending?: PendingMfa | null
	nextRequestAt?: number | null
}>(), { initial: 'apiKey', passwordSubmitLabel: undefined, maskedEmail: null, pending: null, nextRequestAt: null })

// "back" is a given-up code request, not a sign-in.
const emit = defineEmits<{ status: [status: Status], back: [status: Status] }>()

// WHY: A waiting setup code belongs to the email and password sign-in, so its form must be visible.
const method = ref<'apiKey' | 'password'>(props.pending?.origin === 'setup' ? 'password' : props.initial)

watch(() => props.pending, (pending) => {
	if (pending?.origin === 'setup') {
		method.value = 'password'
	}
})
</script>

<style scoped>
.methods {
	display: flex;
	flex-direction: column;
	gap: 12px;
	align-items: flex-start;
}

.methods__tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
