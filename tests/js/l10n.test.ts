import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = join(import.meta.dirname, '..', '..')

function sourceFiles(dir: string, extensions = /\.(ts|vue)$/): string[] {
	return readdirSync(dir).flatMap((entry) => {
		const path = join(dir, entry)
		if (statSync(path).isDirectory()) {
			return sourceFiles(path, extensions)
		}
		return extensions.test(entry) ? [path] : []
	})
}

// A single- or double-quoted string literal; group content is unescaped by unquote().
const STRING = String.raw`(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")`
const CALL = new RegExp(String.raw`\b(t|n)\(\s*(?:APP|'workoutcalendar'|"workoutcalendar")\s*,\s*${STRING}(?:\s*,\s*${STRING})?`, 'g')

function unquote(single: string | undefined, double: string | undefined): string {
	return (single ?? double ?? '').replace(/\\(['"\\])/g, '$1')
}

// Every call of t()/n() for this app, up to the opening of its first text argument.
const CALL_START = /\b(t|n)\(\s*(?:APP|'workoutcalendar'|"workoutcalendar")\s*,\s*/g
// The text arguments a call must start with: one literal for t(), two for n(), each ending at "," or ")".
const LITERAL_T = new RegExp(String.raw`^${STRING}\s*[,)]`)
const LITERAL_N = new RegExp(String.raw`^${STRING}\s*,\s*${STRING}\s*[,)]`)

/**
 * Calls whose text is not a plain string literal; such a text cannot be extracted for translation.
 *
 * @param code Source code to scan
 */
function nonLiteralCalls(code: string): string[] {
	const found: string[] = []
	for (const match of code.matchAll(CALL_START)) {
		const rest = code.slice(match.index + match[0].length)
		const literal = match[1] === 'n' ? LITERAL_N : LITERAL_T
		if (!literal.test(rest)) {
			found.push(code.slice(match.index, match.index + match[0].length + 40).split('\n')[0])
		}
	}
	return found
}

type Source = { singular: string, plural: string | null }

function uiStrings(): Source[] {
	const found = new Map<string, Source>()
	for (const file of sourceFiles(join(root, 'src'))) {
		for (const match of readFileSync(file, 'utf8').matchAll(CALL)) {
			const singular = unquote(match[2], match[3])
			const plural = match[1] === 'n' ? unquote(match[4], match[5]) : null
			found.set(`${singular}::${plural}`, { singular, plural })
		}
	}
	return [...found.values()]
}

// Nextcloud stores plural translations under "_singular_::_plural_" with one entry per plural form.
function key({ singular, plural }: Source): string {
	return plural === null ? singular : `_${singular}_::_${plural}_`
}

function germanJson(): Record<string, string | string[]> {
	return JSON.parse(readFileSync(join(root, 'l10n', 'de.json'), 'utf8')).translations
}

function germanJs(): Record<string, string | string[]> {
	const js = readFileSync(join(root, 'l10n', 'de.js'), 'utf8')
	const match = /^OC\.L10N\.register\(\s*"workoutcalendar",\s*(\{.*\}),\s*"([^"]+)"\);\s*$/s.exec(js)
	expect(match).not.toBeNull()
	return JSON.parse(match![1])
}

/**
 * Every string literal in the PHP code. PHP reads the same l10n/de.json, partly with texts from
 * constants (sport names) that are passed to $l->t() later, so any literal counts as a possible key.
 */
function phpStrings(): Set<string> {
	const found = new Set<string>()
	const files = [...sourceFiles(join(root, 'lib'), /\.php$/), ...sourceFiles(join(root, 'templates'), /\.php$/)]
	for (const file of files) {
		for (const literal of phpLiterals(readFileSync(file, 'utf8'))) {
			found.add(literal)
		}
	}
	return found
}

/**
 * The contents of the quoted strings in PHP code, skipping comments (an apostrophe in a comment
 * would otherwise pair up with the next quote).
 *
 * @param code PHP source
 */
function phpLiterals(code: string): string[] {
	const literals: string[] = []
	let i = 0
	while (i < code.length) {
		const char = code[i]
		if (code.startsWith('//', i) || char === '#') {
			const end = code.indexOf('\n', i)
			i = end === -1 ? code.length : end
		} else if (code.startsWith('/*', i)) {
			const end = code.indexOf('*/', i + 2)
			i = end === -1 ? code.length : end + 2
		} else if (char === '\'' || char === '"') {
			let text = ''
			i++
			while (i < code.length && code[i] !== char) {
				if (code[i] === '\\' && i + 1 < code.length) {
					text += code[i + 1] === char || code[i + 1] === '\\' ? code[i + 1] : code[i] + code[i + 1]
					i += 2
				} else {
					text += code[i]
					i++
				}
			}
			literals.push(text)
			i++
		} else {
			i++
		}
	}
	return literals
}

describe('German translation', () => {
	it('finds the UI strings in the sources', () => {
		expect(uiStrings().length).toBeGreaterThan(100)
		expect(uiStrings().some((s) => s.plural !== null)).toBe(true)
	})

	it('has a German translation for every UI string', () => {
		const translations = germanJson()
		const missing = uiStrings().map(key).filter((k) => !(k in translations))

		expect(missing).toEqual([])
	})

	it('translates plural strings into one text per plural form', () => {
		const translations = germanJson()
		for (const source of uiStrings().filter((s) => s.plural !== null)) {
			const value = translations[key(source)]
			expect(Array.isArray(value), key(source)).toBe(true)
			expect(value, key(source)).toHaveLength(2)
			for (const form of value as string[]) {
				expect(form).not.toBe('')
			}
		}
	})

	it('keeps the placeholders of the source in every translation', () => {
		const translations = germanJson()
		const placeholders = (text: string) => [...text.matchAll(/\{\w+\}/g)].map((m) => m[0]).sort()
		for (const source of uiStrings()) {
			const value = translations[key(source)]
			// Each translated form carries the placeholders of the matching source form.
			const sources = source.plural === null ? [source.singular] : [source.singular, source.plural]
			const forms = Array.isArray(value) ? value : [value]
			expect(forms, key(source)).toHaveLength(sources.length)
			forms.forEach((form, index) => {
				expect(placeholders(form), key(source)).toEqual(placeholders(sources[index]))
			})
		}
	})

	it('finds the PHP texts that share the translation file', () => {
		expect(phpStrings()).toContain('Garmin activities')
		expect(phpStrings()).toContain('Strength training')
		expect(phpStrings()).toContain('Workout Calendar needs your attention')
	})

	it('reads PHP strings without being misled by comments or escapes', () => {
		const code = "// it's a comment\n/* don't */ $a = 'It\\'s'; $b = \"say \\\"hi\\\"\"; $u = 'https://x.org/a'; # isn't\n$c = 'last';"
		expect(phpLiterals(code)).toEqual(['It\'s', 'say "hi"', 'https://x.org/a', 'last'])
	})

	it('has no translation that no source uses any more', () => {
		const used = new Set([...uiStrings().map(key), ...phpStrings()])
		const orphans = Object.keys(germanJson()).filter((k) => !used.has(k))

		expect(orphans).toEqual([])
	})

	it('has no empty translation', () => {
		for (const [source, value] of Object.entries(germanJson())) {
			for (const form of Array.isArray(value) ? value : [value]) {
				expect(form, source).not.toBe('')
			}
		}
	})

	it('recognises texts that cannot be extracted', () => {
		expect(nonLiteralCalls("t('workoutcalendar', text)")).toHaveLength(1)
		expect(nonLiteralCalls('t(APP, `Hello ${name}`)')).toHaveLength(1)
		expect(nonLiteralCalls("t('workoutcalendar', 'Hello ' + name)")).toHaveLength(1)
		expect(nonLiteralCalls("n('workoutcalendar', '{n} day', plural, count)")).toHaveLength(1)
		expect(nonLiteralCalls("n(APP, singular, '{n} days', count)")).toHaveLength(1)
		expect(nonLiteralCalls(String.raw`t('workoutcalendar', 'It\'s fine', { n: 1 })`)).toEqual([])
		expect(nonLiteralCalls("t(\n\t'workoutcalendar',\n\t'Multi-line'\n)")).toEqual([])
		expect(nonLiteralCalls("n(APP, '{n} day', \"{n} days\", count)")).toEqual([])
	})

	it('only uses string literals as texts, so every text can be extracted', () => {
		const offenders = sourceFiles(join(root, 'src')).flatMap((file) => nonLiteralCalls(readFileSync(file, 'utf8')).map((call) => `${file}: ${call}`))

		expect(offenders).toEqual([])
	})

	it('holds identical translations in de.js and de.json', () => {
		expect(germanJs()).toEqual(germanJson())
	})
})
