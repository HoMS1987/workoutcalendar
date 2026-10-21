<template>
	<CollapsibleSection data-test="account" :title="t('workoutcalendar', 'Garmin account')">
		<div class="account__buttons">
			<NcButton data-test="logout" @click="open = 'logout'">
				{{ t('workoutcalendar', 'Sign out') }}
			</NcButton>
			<NcButton data-test="reset" variant="error" @click="open = 'reset'">
				{{ t('workoutcalendar', 'Reset app') }}
			</NcButton>
		</div>

		<LogoutDialog
			v-if="open === 'logout'"
			:status="status"
			:locked="locked"
			:busy="busy"
			:error="error"
			@cancel="close"
			@confirm="run('logout', $event)" />
		<ResetDialog
			v-if="open === 'reset'"
			:status="status"
			:locked="locked"
			:busy="busy"
			:error="error"
			@cancel="close"
			@confirm="run('reset', $event)" />
	</CollapsibleSection>
</template>

<script setup lang="ts">
import type { Cleanup, Status } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import LogoutDialog from '../dialogs/LogoutDialog.vue'
import ResetDialog from '../dialogs/ResetDialog.vue'
import CollapsibleSection from './CollapsibleSection.vue'
import { ApiError, logout, reset } from '../../api.ts'
import { apiErrorText } from '../../messages.ts'

defineProps<{
	status: Status
	/** True while maintenance runs (locks.cleanup). */
	locked: boolean
}>()

const emit = defineEmits<{ status: [status: Status] }>()

const open = ref<'logout' | 'reset' | null>(null)
const busy = ref(false)
const error = ref('')

function close() {
	if (!busy.value) {
		open.value = null
		error.value = ''
	}
}

async function run(kind: 'logout' | 'reset', cleanup: Cleanup) {
	if (busy.value) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		const next = kind === 'logout' ? await logout(cleanup) : await reset(cleanup)
		open.value = null
		emit('status', next)
	} catch (e) {
		error.value = apiErrorText(e instanceof ApiError ? e : new ApiError(0, 'internal'))
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.account__buttons {
	display: flex;
	gap: 8px;
}
</style>
