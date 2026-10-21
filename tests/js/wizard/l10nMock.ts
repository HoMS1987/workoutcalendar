type Vars = Record<string, string | number>
type Options = { escape?: boolean }

// Like the real t(): placeholder values are HTML-escaped unless { escape: false } is passed.
const escapeHtml = (value: string) => value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
function fill(text: string, vars: Vars | undefined, options: Options | undefined) {
	return text.replace(/\{(\w+)\}/g, (_m, key: string) => {
		const value = String(vars?.[key] ?? '')
		return options?.escape === false ? value : escapeHtml(value)
	})
}

/** Factory for vi.mock('@nextcloud/l10n'): keeps the real module for @nextcloud/vue and replaces the translators. */
export async function l10nFactory(importOriginal: () => Promise<Record<string, unknown>>) {
	const original = await importOriginal()
	const t = (_app: string, text: string, vars?: Vars, options?: Options) => fill(text, vars, options)
	return {
		...original,
		t,
		translate: t,
		n: (_app: string, singular: string, plural: string, count: number, vars?: Vars, options?: Options) => fill(count === 1 ? singular : plural, { ...vars, n: count }, options),
		getCanonicalLocale: () => 'en-US',
	}
}
