<template>
	<form class="step" @submit.prevent="submit">
		<ol class="step__guide">
			<li>
				<template v-for="(part, index) in linkParts" :key="index">
					<a
						v-if="part === null"
						:href="GUIDE_URL"
						target="_blank"
						rel="noopener noreferrer">{{ t('workoutcalendar', 'Personal API Key') }}</a>
					<template v-else>
						{{ part }}
					</template>
				</template>
			</li>
			<li>{{ t('workoutcalendar', 'Select Create Key, read the terms and accept them.') }}</li>
			<li>{{ t('workoutcalendar', 'Choose 1 year as the duration and copy the key. Garmin shows it only once.') }}</li>
		</ol>

		<NcPasswordField
			v-model="apiKey"
			autocomplete="off"
			:label="t('workoutcalendar', 'Garmin API key')"
			:disabled="busy" />

		<p class="step__hint">
			{{ t('workoutcalendar', 'A key is valid for one year at most. When it expires, Workout Calendar lets you know through a notification, and you enter a new one.') }}
		</p>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcButton variant="primary" type="submit" :disabled="busy">
			{{ submitLabel }}
		</NcButton>
	</form>
</template>

<script setup lang="ts">
import type { Status } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import { ApiError, connectApiKey } from '../../api.ts'
import { apiErrorText, isLoginFailure, loginError } from '../../messages.ts'

defineProps<{
	/** Text of the button that sends the key. */
	submitLabel: string
}>()

const emit = defineEmits<{ status: [status: Status] }>()

const GUIDE_URL = 'https://connect.garmin.com/app/settings/personalApiToken'

const apiKey = ref('')
const busy = ref(false)
const error = ref('')

// The first step carries the link as a placeholder so a translation can place the words freely.
// null marks where the link goes.
const linkParts = computed<Array<string | null>>(() => t('workoutcalendar', 'Open {link} in the Garmin Connect settings.', { link: '{link}' })
	.split('{link}')
	.flatMap((part, index) => (index === 0 ? [part] : [null, part])))

async function submit() {
	const key = apiKey.value.trim()
	// An empty key and a second submit while the first runs must not send a request.
	if (busy.value || key === '') {
		return
	}
	busy.value = true
	error.value = ''
	try {
		emit('status', await connectApiKey(key))
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

.step__guide {
	margin: 0;
	padding-left: 20px;
}

.step__hint {
	color: var(--color-text-maxcontrast);
}
</style>
