import { n, register, setLanguage, unregister } from '@nextcloud/l10n'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, describe, expect, it } from 'vitest'
import { intervalLabel } from '../../src/intervalLabel.ts'

// These tests use the real @nextcloud/l10n, so the plural form is chosen by its plural rules
// and not by the "count === 1" shortcut of the component test mocks.

const APP = 'workoutcalendar'
const root = join(import.meta.dirname, '..', '..')
const RAW = { escape: false }

function germanTranslations(): Record<string, string | string[]> {
	return JSON.parse(readFileSync(join(root, 'l10n', 'de.json'), 'utf8')).translations
}

type PluralEntry = { singular: string, plural: string, forms: string[] }

// Nextcloud stores plural translations under "_singular_::_plural_".
function pluralEntries(): PluralEntry[] {
	return Object.entries(germanTranslations()).flatMap(([key, value]) => {
		const match = /^_(.*)_::_(.*)_$/s.exec(key)
		return match && Array.isArray(value) ? [{ singular: match[1], plural: match[2], forms: value }] : []
	})
}

const fill = (text: string, count: number) => text.replaceAll('{n}', String(count))

function useLanguage(language: 'en' | 'de') {
	setLanguage(language)
	if (language === 'de') {
		register(APP, germanTranslations())
	}
}

afterEach(() => {
	unregister(APP)
	setLanguage('en')
})

describe('plural strings', () => {
	it('finds the plural strings of the translation', () => {
		expect(pluralEntries().length).toBeGreaterThanOrEqual(6)
	})

	it.each([0, 1, 2, 5])('picks the English form for %i without a translation', (count) => {
		useLanguage('en')
		for (const { singular, plural } of pluralEntries()) {
			const expected = fill(count === 1 ? singular : plural, count)
			expect(n(APP, singular, plural, count, { n: count }, RAW), singular).toBe(expected)
		}
	})

	it.each([0, 1, 2, 5])('picks the German form for %i', (count) => {
		useLanguage('de')
		for (const { singular, plural, forms } of pluralEntries()) {
			const expected = fill(forms[count === 1 ? 0 : 1], count)
			expect(n(APP, singular, plural, count, { n: count }, RAW), singular).toBe(expected)
		}
	})

	it.each([
		[1, 'every minute'],
		[15, 'every 15 minutes'],
		[60, 'every hour'],
		[120, 'every 2 hours'],
		[1440, 'once a day'],
	])('words an interval of %i minutes in English', (minutes, text) => {
		useLanguage('en')
		expect(intervalLabel(minutes)).toBe(text)
	})

	it.each([
		[1, 'jede Minute'],
		[15, 'alle 15 Minuten'],
		[60, 'jede Stunde'],
		[120, 'alle 2 Stunden'],
	])('words an interval of %i minutes in German', (minutes, text) => {
		useLanguage('de')
		expect(intervalLabel(minutes)).toBe(text)
	})
})
