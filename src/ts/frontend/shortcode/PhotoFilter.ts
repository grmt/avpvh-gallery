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

// The options are fetched each time a kind is picked: marking, liking or
// tagging photos since the page loaded changes them (a new ★★ level, say).
async function fetchFilterOptions(ajaxUrl: string): Promise<FilterOptions> {
	return fetch(`${ajaxUrl}?action=gallery_filter_options`, {
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
}

// Remembers the current filter for the user (see Photo_Filter::handle_save),
// or that there is none, so it's back after a reload or the next login.
export function rememberFilter(
	ajaxUrl: string,
	nonce: string,
	state: { conditions: Array<FilterCondition>; here: boolean } | null
): void {
	void fetch(ajaxUrl, {
		method: 'POST',
		credentials: 'include',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams({
			action: 'gallery_filter_save',
			state: JSON.stringify(state),
			_ajax_nonce: nonce,
		}).toString(),
	}).catch(() => {
		// Not remembered this time; the filter itself still works.
	});
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

// The filter in words, e.g. "✓ Overleden, ✗ Kamp (alleen deze map)".
function describe(conditions: Array<FilterCondition>, here: boolean): string {
	return (
		conditions
			.map((condition) => {
				const operator = OPERATORS.find(([op]) => op === condition.op);
				return `${operator?.[2] ?? ''} ${condition.label}`;
			})
			.join(', ') + (here ? ' (alleen deze map)' : '')
	);
}

// Asks the server to share the filter's photos via Google Drive (see
// Photo_Shares); resolves to what to tell the user.
async function requestShare(
	ajaxUrl: string,
	share: FilterShare,
	conditions: Array<FilterCondition>,
	description: string,
	captions: boolean
): Promise<string> {
	try {
		const response = await fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'include',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({
				action: 'gallery_share_create',
				conditions: conditionsParam(conditions),
				folder: share.folder,
				description,
				captions: captions ? '1' : '0',
				_ajax_nonce: share.nonce,
			}).toString(),
		});
		const data = (await response.json()) as {
			success?: boolean;
			data?: { recipient?: string; message?: string };
		};
		return data.success === true
			? `De map wordt gemaakt; je krijgt de link per e-mail op ${data.data?.recipient ?? 'je Google-adres'}.`
			: (data.data?.message ?? 'Delen mislukt');
	} catch {
		return 'Delen mislukt';
	}
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

// How photos are ordered: by name (folders only), or by date old to new or
// new to old (see Photo_Date_Order).
export type SortOrder = 'date_desc' | 'date' | 'name';

// The folder order picker: the current order and what to do on a change.
export interface FilterSort {
	order: SortOrder;
	onChange(order: SortOrder): void;
}

// Sharing the filter's photos via Google Drive: whether it's set up,
// whether photos can be captioned, the folder the filter is limited to (''
// for the whole gallery) and the nonce.
export interface FilterShare {
	enabled: boolean;
	captions: boolean;
	folder: string;
	nonce: string;
}

// The "jaar en opgraving erop" checkbox shown while confirming a share.
function captionsOption(checkbox: HTMLInputElement): HTMLElement {
	const label = document.createElement('label');
	label.className = 'avpvh-filter-share-captions';
	label.title =
		'De foto’s worden rechtop gezet zoals in de galerij, en foto’s van opgravingen krijgen rechtsonder het jaar en de plaats';
	label.append(checkbox, document.createTextNode(' jaar en opgraving erop'));
	return label;
}

// "Delen via Google Drive": after confirming, starts the share and says
// where the link will be sent. While confirming, the photos can be chosen
// to be put upright with the dig's year and name written on them.
function shareButton(
	ajaxUrl: string,
	share: FilterShare,
	conditions: Array<FilterCondition>,
	total: number,
	here: boolean,
	status: HTMLElement
): HTMLElement {
	const wrapper = document.createElement('span');
	wrapper.className = 'avpvh-filter-share-wrapper';
	const button = document.createElement('button');
	button.type = 'button';
	button.className = 'avpvh-filter-share';
	button.textContent = 'Delen via Google Drive';
	button.title =
		'Kopieer deze foto’s naar een map in Google Drive die alleen jij een week lang kunt openen; de link komt per e-mail';
	const captions = document.createElement('input');
	captions.type = 'checkbox';
	// The first click asks for confirmation in the button itself, the second
	// one starts the share.
	button.addEventListener('click', () => {
		if (button.dataset['confirm'] !== '1') {
			button.dataset['confirm'] = '1';
			button.textContent = `Ja, ${String(total)} foto${total === 1 ? '' : "'s"} delen (link per e-mail, een week geldig)`;
			if (share.captions) {
				wrapper.appendChild(captionsOption(captions));
			}
			return;
		}
		button.disabled = true;
		captions.disabled = true;
		void requestShare(
			ajaxUrl,
			share,
			conditions,
			describe(conditions, here),
			captions.checked
		).then((message) => {
			status.textContent = message;
		});
	});
	wrapper.appendChild(button);
	return wrapper;
}

// "Volgorde": by name, or by date either way. Filter results are always by
// date (first the folder's year), so there only the direction is offered.
function sortPicker(sort: FilterSort, filtering: boolean): HTMLElement {
	const label = document.createElement('label');
	label.className = 'avpvh-filter-sort';
	label.title =
		'Datum: op opnamedatum (EXIF), anders de datum die Google Drive kent';
	const byDate: Array<[string, string]> = [
		['date', 'Datum (oud → nieuw)'],
		['date_desc', 'Datum (nieuw → oud)'],
	];
	const picker = select(
		'avpvh-filter-select',
		filtering ? byDate : [['name', 'Naam'], ...byDate]
	);
	picker.value = filtering && sort.order === 'name' ? 'date' : sort.order;
	picker.addEventListener('change', () => {
		sort.onChange(
			picker.value === 'date' || picker.value === 'date_desc'
				? picker.value
				: 'name'
		);
	});
	label.append(document.createTextNode('Volgorde '), picker);
	return label;
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
	sort: FilterSort,
	share: FilterShare,
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
	adder.appendChild(sortPicker(sort, isActiveFilter(conditions)));
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
			// Another kind was picked while these were on their way.
			if (kindSelect.value !== kind) {
				return;
			}
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
		if (
			share.enabled &&
			isActiveFilter(conditions) &&
			total !== null &&
			total > 0
		) {
			bar.appendChild(
				shareButton(
					ajaxUrl,
					share,
					conditions,
					total,
					scope.here,
					status
				)
			);
		}
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
