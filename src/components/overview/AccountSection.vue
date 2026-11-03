<template>
	<CollapsibleSection data-test="account" :title="t('workoutcalendar', 'Garmin account')">
		<NcNoteCard v-if="signedInAgain" type="success">
			{{ t('workoutcalendar', 'Signed in to Garmin again.') }}
		</NcNoteCard>
		<NcNoteCard v-if="reloginError" type="error">
			{{ reloginError }}
		</NcNoteCard>
		<div class="account__buttons">
			<NcButton data-test="relogin" :disabled="busy" @click="signInAgain">
				{{ t('workoutcalendar', 'Sign in again') }}
			</NcButton>
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
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import LogoutDialog from '../dialogs/LogoutDialog.vue'
import ResetDialog from '../dialogs/ResetDialog.vue'
import CollapsibleSection from './CollapsibleSection.vue'
import { ApiError, logout, requestMfaCode, reset } from '../../api.ts'
import { apiErrorText, isLoginFailure, loginError } from '../../messages.ts'

defineProps<{
	status: Status
	/** True while maintenance runs (locks.cleanup). */
	locked: boolean
}>()

const emit = defineEmits<{ status: [status: Status] }>()

const open = ref<'logout' | 'reset' | null>(null)
const busy = ref(false)
const error = ref('')

const signedInAgain = ref(false)
const reloginError = ref('')

// Signs in with the saved password. Garmin may answer with a code request; the box at the top of
// the overview takes over from there.
async function signInAgain() {
	if (busy.value) {
		return
	}
	busy.value = true
	signedInAgain.value = false
	reloginError.value = ''
	try {
		const result = await requestMfaCode()
		signedInAgain.value = result.outcome === 'signedIn'
		emit('status', result.status)
	} catch (e) {
		const apiError = e instanceof ApiError ? e : new ApiError(0, 'internal')
		reloginError.value = isLoginFailure(apiError.code) ? loginError(apiError.code) : apiErrorText(apiError)
	} finally {
		busy.value = false
	}
}

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
