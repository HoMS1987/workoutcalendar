import type { DeletionMode, Settings } from '../../types.ts'

/** What the settings form shows; the two number fields stay strings until they are saved. */
export interface SettingsDraft {
	intervalMinutes: number
	historyDays: number | null
	jitterEnabled: boolean
	jitterPercent: string
	deletionMode: DeletionMode
	deletionCheckDays: string
}

export type SettingsPayload = Pick<Settings, 'intervalMinutes' | 'jitterPercent' | 'deletionMode' | 'deletionCheckDays'>

const DEFAULT_JITTER = 10

export function draftFromSettings(settings: Settings): SettingsDraft {
	return {
		intervalMinutes: settings.intervalMinutes,
		historyDays: settings.historyDays,
		jitterEnabled: settings.jitterPercent > 0,
		jitterPercent: String(settings.jitterPercent > 0 ? settings.jitterPercent : DEFAULT_JITTER),
		deletionMode: settings.deletionMode,
		deletionCheckDays: String(settings.deletionCheckDays),
	}
}

// Rounds to a whole number inside [min, max]; unusable input falls back to the current value.
function clampInt(raw: string, min: number, max: number, fallback: number): number {
	if (raw.trim() === '') {
		return fallback
	}
	const value = Math.round(Number(raw))
	if (!Number.isFinite(value)) {
		return fallback
	}
	return Math.min(max, Math.max(min, value))
}

/**
 * The numbers and the mode as the API takes them (JSON numbers, clamped to the allowed ranges).
 *
 * @param draft Form values
 * @param current Settings the server currently has
 */
export function payloadFromDraft(draft: SettingsDraft, current: Settings): SettingsPayload {
	return {
		intervalMinutes: clampInt(String(draft.intervalMinutes), 15, 1440, current.intervalMinutes),
		jitterPercent: draft.jitterEnabled ? clampInt(draft.jitterPercent, 0, 50, current.jitterPercent) : 0,
		deletionMode: draft.deletionMode,
		deletionCheckDays: clampInt(draft.deletionCheckDays, 7, 365, current.deletionCheckDays),
	}
}

export function payloadChanged(payload: SettingsPayload, current: Settings): boolean {
	return payload.intervalMinutes !== current.intervalMinutes
		|| payload.jitterPercent !== current.jitterPercent
		|| payload.deletionMode !== current.deletionMode
		|| payload.deletionCheckDays !== current.deletionCheckDays
}

export function historyChanged(draft: SettingsDraft, current: Settings): boolean {
	return draft.historyDays !== current.historyDays
}
