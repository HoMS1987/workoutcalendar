<template>
	<NcSelect
		:modelValue="selected"
		:options="options"
		:inputLabel="t('workoutcalendar', 'Calendar')"
		label="label"
		taggable
		:createOption="createOption"
		:selectable="isSelectable"
		:clearable="false"
		:disabled="disabled"
		@update:modelValue="onSelect" />
</template>

<script setup lang="ts">
import type { CalendarOption } from '../types.ts'
import type { CalendarChoice, CalendarSelectOption } from './calendarChoice.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'

const props = defineProps<{
	modelValue: CalendarChoice
	calendars: CalendarOption[]
	defaultName: string
	disabled?: boolean
}>()

const emit = defineEmits<{
	'update:modelValue': [choice: CalendarChoice]
}>()

// Names the person typed into the field; each one becomes a "new calendar" option.
const typed = ref<CalendarSelectOption[]>([])

function newOption(name: string): CalendarSelectOption {
	return {
		key: 'new:' + name,
		label: t('workoutcalendar', 'New calendar “{name}”', { name }, { escape: false }),
		choice: { kind: 'new', name },
	}
}

const options = computed<CalendarSelectOption[]>(() => {
	const list: CalendarSelectOption[] = [newOption(props.defaultName)]
	for (const option of typed.value) {
		if (option.key !== list[0].key) {
			list.push(option)
		}
	}
	if (props.calendars.length > 0) {
		list.push({ key: 'heading', label: t('workoutcalendar', 'Existing calendars'), heading: true })
		for (const calendar of props.calendars) {
			list.push({
				key: 'existing:' + calendar.id,
				label: calendar.name,
				choice: { kind: 'existing', id: calendar.id, name: calendar.name },
			})
		}
	}
	return list
})

const selected = computed(() => {
	const choice = props.modelValue
	const key = choice.kind === 'new' ? 'new:' + choice.name : 'existing:' + choice.id
	return options.value.find((option) => option.key === key) ?? newOption(choice.name)
})

function createOption(name: string): CalendarSelectOption {
	return newOption(name.trim())
}

function isSelectable(option: CalendarSelectOption): boolean {
	return !option.heading
}

function onSelect(option: CalendarSelectOption | null) {
	// Whitespace-only typed names are ignored.
	if (!option?.choice || option.choice.name === '') {
		return
	}
	if (option.choice.kind === 'new' && !typed.value.some((o) => o.key === option.key)) {
		typed.value.push(option)
	}
	emit('update:modelValue', option.choice)
}
</script>
