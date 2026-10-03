// Gallery-wide photo filters (see Photo_Filter on the PHP side): who liked
// a photo, who's tagged in it, a subject tag, or its own place — any number
// of them, each as "Alle" (the photo must match), "Een van" (it must match
// at least one of these) or "Niet" (it must not match). Logged-in users
// only; likes only of people whose likes the viewer may see.

export type FilterKind = 'liked_by' | 'marked' | 'person' | 'place' | 'tag';
export type FilterOperator = 'and' | 'not' | 'or';

export interface FilterCondition {
	kind: FilterKind;
	value: string;
	op: FilterOperator;
	// For display only; not sent to the server.
	label: string;
}

interface FilterOption {
	value: string;
	label: string;
	count: number;
}

type FilterOptions = Record<FilterKind, Array<FilterOption>>;

const KINDS: Array<[FilterKind, string]> = [
	['tag', 'Tag'],
	['person', 'Persoon'],
	['liked_by', 'Geliket door'],
	['place', 'Locatie'],
	['marked', 'Gemarkeerd'],
];

const OPERATORS: Array<[FilterOperator, string, string]> = [
	['and', 'Alle', '✓'],
	['or', 'Een van', '∨'],
	['not', 'Niet', '✗'],
];

let optionsPromise: Promise<FilterOptions> | null = null;

// The options are fetched once per page view; filtering doesn't change them.
async function fetchFilterOptions(ajaxUrl: string): Promise<FilterOptions> {
	optionsPromise ??= fetch(`${ajaxUrl}?action=gallery_filter_options`, {
		credentials: 'include',
	})
		.then(async (response) => {
			const data = (await response.json()) as {
				data?: {
					liked_by?: Array<FilterOption>;
					marked?: Array<FilterOption>;
					persons?: Array<FilterOption>;
					tags?: Array<FilterOption>;
					places?: Array<FilterOption>;
				};
			};
			return {
				liked_by: data.data?.liked_by ?? [],
				marked: data.data?.marked ?? [],
				person: data.data?.persons ?? [],
				tag: data.data?.tags ?? [],
				place: data.data?.places ?? [],
			};
		})
		.catch(() => ({
			liked_by: [],
			marked: [],
			person: [],
			tag: [],
			place: [],
		}));
	return optionsPromise;
}

// A filter needs at least one "Alle" or "Een van" condition: "Niet" alone
// would mean "every photo except…".
export function isActiveFilter(conditions: Array<FilterCondition>): boolean {
	return conditions.some((condition) => condition.op !== 'not');
}

// What the server needs: the conditions without their display labels.
export function conditionsParam(conditions: Array<FilterCondition>): string {
	return JSON.stringify(
		conditions.map(({ kind, value, op }) => ({ kind, value, op }))
	);
}

function select(
	className: string,
	options: Array<[string, string]>
): HTMLSelectElement {
	const element = document.createElement('select');
	element.className = className;
	options.forEach(([value, label]) => {
		const option = document.createElement('option');
		option.value = value;
		option.textContent = label;
		element.appendChild(option);
	});
	return element;
}

// "Alleen deze map": whether it can be offered (not in the gallery's top
// folder), whether it's on, and what to do when it's switched.
export interface FilterScope {
	available: boolean;
	here: boolean;
	onToggle(here: boolean): void;
}

// The filter bar shown above the gallery: the current conditions as
// removable chips, a row to add one (how · what kind · which) with the
// "only this folder" switch, and while filtering the number of photos
// found and a button to clear the filter.
export function buildFilterBar(
	ajaxUrl: string,
	conditions: Array<FilterCondition>,
	total: number | null,
	scope: FilterScope,
	onChange: (conditions: Array<FilterCondition>) => void
): HTMLElement {
	const bar = document.createElement('div');
	bar.className = 'avpvh-filter-bar';

	const adder = document.createElement('div');
	adder.className = 'avpvh-filter-add';
	const opSelect = select(
		'avpvh-filter-select',
		OPERATORS.map(([op, label]) => [op, label])
	);
	const kindSelect = select('avpvh-filter-select', [
		['', 'Filteren op…'],
		...KINDS,
	]);
	const valueSelect = select('avpvh-filter-select', []);
	valueSelect.hidden = true;
	const addButton = document.createElement('button');
	addButton.type = 'button';
	addButton.className = 'avpvh-filter-add-button';
	addButton.textContent = '+ Toevoegen';
	addButton.hidden = true;
	adder.append(opSelect, kindSelect, valueSelect, addButton);
	if (scope.available) {
		const hereLabel = document.createElement('label');
		hereLabel.className = 'avpvh-filter-here';
		const hereBox = document.createElement('input');
		hereBox.type = 'checkbox';
		hereBox.checked = scope.here;
		hereBox.addEventListener('change', () => {
			scope.onToggle(hereBox.checked);
		});
		hereLabel.append(hereBox, document.createTextNode(' Alleen deze map'));
		hereLabel.title = 'Alleen foto’s in deze map en de mappen eronder';
		adder.appendChild(hereLabel);
	}
	bar.appendChild(adder);

	kindSelect.addEventListener('change', () => {
		const kind = kindSelect.value as FilterKind | '';
		valueSelect.innerHTML = '';
		valueSelect.hidden = kind === '';
		addButton.hidden = true;
		if (kind === '') {
			return;
		}
		void fetchFilterOptions(ajaxUrl).then((options) => {
			const taken = new Set(
				conditions
					.filter((condition) => condition.kind === kind)
					.map((condition) => condition.value)
			);
			const choices = options[kind].filter(
				(option) => !taken.has(option.value)
			);
			const prompt = document.createElement('option');
			prompt.value = '';
			prompt.textContent =
				choices.length > 0 ? 'Kies…' : 'Niets om op te filteren';
			valueSelect.appendChild(prompt);
			choices.forEach((option) => {
				const element = document.createElement('option');
				element.value = option.value;
				element.textContent = `${option.label} (${String(option.count)})`;
				element.dataset['label'] = option.label;
				valueSelect.appendChild(element);
			});
		});
	});
	valueSelect.addEventListener('change', () => {
		addButton.hidden = valueSelect.value === '';
	});
	addButton.addEventListener('click', () => {
		const chosen = valueSelect.selectedOptions.item(0);
		if (chosen === null || chosen.value === '') {
			return;
		}
		onChange([
			...conditions,
			{
				kind: kindSelect.value as FilterKind,
				value: chosen.value,
				op: opSelect.value as FilterOperator,
				label: chosen.dataset['label'] ?? chosen.value,
			},
		]);
	});

	if (conditions.length > 0) {
		const chips = document.createElement('div');
		chips.className = 'avpvh-filter-chips';
		conditions.forEach((condition, index) => {
			const operator = OPERATORS.find(([op]) => op === condition.op);
			const kind = KINDS.find(([key]) => key === condition.kind);
			const chip = document.createElement('span');
			chip.className = `avpvh-filter-chip avpvh-filter-chip-${condition.op}`;
			chip.title = `${operator?.[1] ?? ''} · ${kind?.[1] ?? ''}`;
			chip.textContent = `${operator?.[2] ?? ''} ${condition.label}`;
			const remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'avpvh-filter-chip-remove';
			remove.textContent = '×';
			remove.title = 'Verwijderen';
			remove.addEventListener('click', () => {
				onChange(conditions.filter((_, other) => other !== index));
			});
			chip.appendChild(remove);
			chips.appendChild(chip);
		});
		bar.appendChild(chips);

		const status = document.createElement('span');
		status.className = 'avpvh-filter-found';
		if (!isActiveFilter(conditions)) {
			status.textContent = 'Voeg een "Alle"- of "Een van"-voorwaarde toe';
		} else if (total === null) {
			status.textContent = 'Zoeken…';
		} else {
			status.textContent = `${String(total)} foto${total === 1 ? '' : "'s"} gevonden`;
		}
		bar.appendChild(status);
		const clear = document.createElement('button');
		clear.type = 'button';
		clear.className = 'avpvh-filter-clear';
		clear.textContent = 'Filter wissen';
		clear.addEventListener('click', () => {
			onChange([]);
		});
		bar.appendChild(clear);
	}

	return bar;
}
