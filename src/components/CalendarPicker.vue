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
		@search="search = $event"
		@update:modelValue="onSelect">
		<template #option="option">
			<span v-if="option.heading" v-heading-option class="calendar-picker__heading">{{ option.label }}</span>
			<!-- Same rendering as the select's default option, including the search highlight. -->
			<NcEllipsisedOption v-else :name="option.label" :search="search" />
		</template>
	</NcSelect>
</template>

<script setup lang="ts">
import type { Directive } from 'vue'
import type { CalendarOption } from '../types.ts'
import type { CalendarChoice, CalendarSelectOption } from './calendarChoice.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcEllipsisedOption from '@nextcloud/vue/components/NcEllipsisedOption'
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

// The select renders every entry as role="option" and offers no way to mark one as disabled, so the
// heading marks its own entry; assistive technology then announces it as not selectable.
const vHeadingOption: Directive<HTMLElement> = {
	mounted(el) {
		el.closest('[role="option"]')?.setAttribute('aria-disabled', 'true')
	},
}

// The text in the search field, for highlighting it in the options.
const search = ref('')

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
	// The default name always comes first; a typed copy of it is not listed twice.
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

<style scoped>
.calendar-picker__heading {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}
</style>
