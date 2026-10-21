<template>
	<section class="collapsible">
		<h3 class="collapsible__heading">
			<button
				type="button"
				class="collapsible__toggle"
				data-test="toggle"
				:aria-expanded="open ? 'true' : 'false'"
				:aria-controls="panelId"
				@click="open = !open">
				<span aria-hidden="true">{{ open ? '▾' : '▸' }}</span>
				{{ title }}
			</button>
		</h3>
		<div v-if="open" :id="panelId" class="collapsible__panel">
			<slot />
		</div>
	</section>
</template>

<script setup lang="ts">
import { ref, useId } from 'vue'

defineProps<{ title: string }>()

const open = ref(false)
const panelId = useId()
</script>

<style scoped>
.collapsible__heading {
	margin: 0;
}

.collapsible__toggle {
	background: none;
	border: 0;
	padding: 8px 0;
	font: inherit;
	font-weight: bold;
	cursor: pointer;
	color: var(--color-main-text);
}

.collapsible__panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
	align-items: flex-start;
	padding-bottom: 8px;
}
</style>
