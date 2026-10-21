<template>
	<ol class="wizard-steps" :aria-label="t('workoutcalendar', 'Setup steps')">
		<li
			v-for="(step, index) in steps"
			:key="step.id"
			class="wizard-steps__step"
			:class="{ 'wizard-steps__step--current': step.id === current }"
			:aria-current="step.id === current ? 'step' : undefined">
			<span class="wizard-steps__number">{{ index + 1 }}</span>
			<span>{{ step.label }}</span>
		</li>
	</ol>
</template>

<script setup lang="ts">
import type { SetupStep } from '../../types.ts'

import { t } from '@nextcloud/l10n'

defineProps<{ current: SetupStep }>()

const steps: Array<{ id: SetupStep, label: string }> = [
	{ id: 'garmin', label: t('workoutcalendar', 'Garmin') },
	{ id: 'calendar', label: t('workoutcalendar', 'Calendar') },
	{ id: 'history', label: t('workoutcalendar', 'History') },
	{ id: 'done', label: t('workoutcalendar', 'Done') },
]
</script>

<style scoped>
.wizard-steps {
	display: flex;
	gap: 16px;
	list-style: none;
	padding: 0;
	margin: 0 0 24px;
	flex-wrap: wrap;
}

.wizard-steps__step {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast);
}

.wizard-steps__step--current {
	color: var(--color-main-text);
	font-weight: bold;
}

.wizard-steps__number {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	border-radius: 50%;
	border: 2px solid currentColor;
}

.wizard-steps__step--current .wizard-steps__number {
	background: var(--color-primary-element);
	border-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
}
</style>
