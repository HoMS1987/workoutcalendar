<template>
	<NcDialog
		:name="t('workoutcalendar', 'Sign out of Garmin?')"
		:buttons="buttons"
		:closeOnClickOutside="false"
		:noClose="busy"
		size="small"
		@closing="emit('cancel')">
		<p>{{ t('workoutcalendar', 'Signing out deletes your Garmin password and tokens and stops the sync. The events stay in the calendar.') }}</p>
		<p>{{ t('workoutcalendar', 'When you sign in again, you continue with the same calendar and settings.') }}</p>
		<CleanupOptions
			v-model="cleanup"
			:eventCount="status.sync.eventCount"
			:calendarName="status.setup.calendar?.name ?? ''"
			:createdByApp="status.setup.calendar?.createdByApp ?? false"
			:locked="locked"
			:busy="busy" />
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</NcDialog>
</template>

<script setup lang="ts">
import type { Cleanup, Status } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import CleanupOptions from './CleanupOptions.vue'

const props = defineProps<{
	status: Status
	locked: boolean
	busy: boolean
	error: string
}>()

const emit = defineEmits<{
	cancel: []
	confirm: [cleanup: Cleanup]
}>()

const cleanup = ref<Cleanup>('none')

const buttons = computed(() => [
	{
		label: t('workoutcalendar', 'Cancel'),
		variant: 'secondary' as const,
		disabled: props.busy,
		callback: (): false | void => {
			emit('cancel')
		},
	},
	{
		label: t('workoutcalendar', 'Sign out'),
		variant: 'primary' as const,
		disabled: props.busy,
		callback: (): false | void => {
			emit('confirm', cleanup.value)
			// The parent closes the dialog once the request is done.
			return false
		},
	},
])
</script>
