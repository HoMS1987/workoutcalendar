<template>
	<NcDialog
		:name="t('workoutcalendar', 'Use an existing calendar?')"
		:buttons="buttons"
		:closeOnClickOutside="false"
		size="small"
		@closing="emit('cancel')">
		<p>
			{{ n('workoutcalendar', 'The calendar “{name}” already contains {n} event.', 'The calendar “{name}” already contains {n} events.', existingEvents, { name: calendarName, n: existingEvents }, { escape: false }) }}
		</p>
		<p>{{ t('workoutcalendar', 'Your own events stay unchanged. The app only adds its Garmin activities.') }}</p>
		<p>{{ t('workoutcalendar', 'When you sign out later, the app can remove only its own events, not the calendar itself.') }}</p>
	</NcDialog>
</template>

<script setup lang="ts">
import { n, t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'

const props = defineProps<{
	calendarName: string
	existingEvents: number
	busy?: boolean
}>()

const emit = defineEmits<{
	cancel: []
	confirm: []
}>()

const buttons = computed(() => [
	{
		label: t('workoutcalendar', 'Choose another'),
		variant: 'secondary' as const,
		disabled: props.busy,
		callback: (): false | void => {
			emit('cancel')
		},
	},
	{
		label: t('workoutcalendar', 'Use “{name}”', { name: props.calendarName }, { escape: false }),
		variant: 'primary' as const,
		disabled: props.busy,
		callback: (): false | void => {
			emit('confirm')
			// The parent closes the dialog once the request is done.
			return false
		},
	},
])
</script>
