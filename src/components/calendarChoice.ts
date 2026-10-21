/** What the person picked in the calendar select: a calendar to create or an existing one. */
export type CalendarChoice
	= | { kind: 'new', name: string }
		| { kind: 'existing', id: number, name: string }

/** Select option: either a choice or a non-selectable group heading. */
export interface CalendarSelectOption {
	key: string
	label: string
	choice?: CalendarChoice
	heading?: boolean
}
