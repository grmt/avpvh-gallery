// Gallery-wide photo filters (see Photo_Filter on the PHP side): who liked
// a photo, who's tagged in it, a subject tag, or its own place. Logged-in
// users only.

export interface FilterCriteria {
	liked_by?: string;
	person?: string;
	tag?: string;
	place?: string;
}

export type FilterKey = keyof FilterCriteria;

interface FilterOption {
	value: string;
	label: string;
	count: number;
}

type FilterOptions = Record<FilterKey, Array<FilterOption>>;

const FILTERS: Array<[FilterKey, string]> = [
	['liked_by', 'Geliket door'],
	['person', 'Persoon'],
	['tag', 'Tag'],
	['place', 'Locatie'],
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
					persons?: Array<FilterOption>;
					tags?: Array<FilterOption>;
					places?: Array<FilterOption>;
				};
			};
			return {
				liked_by: data.data?.liked_by ?? [],
				person: data.data?.persons ?? [],
				tag: data.data?.tags ?? [],
				place: data.data?.places ?? [],
			};
		})
		.catch(() => ({ liked_by: [], person: [], tag: [], place: [] }));
	return optionsPromise;
}

export function hasCriteria(criteria: FilterCriteria | null): boolean {
	return (
		criteria !== null &&
		FILTERS.some(([key]) => (criteria[key] ?? '') !== '')
	);
}

// The filter bar shown above the gallery: one dropdown per kind of filter
// (only kinds that have anything to filter on), plus, while filtering, the
// number of photos found and a button to go back to the folders.
export function buildFilterBar(
	ajaxUrl: string,
	criteria: FilterCriteria | null,
	total: number | null,
	onChange: (criteria: FilterCriteria | null) => void
): HTMLElement {
	const bar = document.createElement('div');
	bar.className = 'avpvh-filter-bar';
	const selects = new Map<FilterKey, HTMLSelectElement>();
	const readCriteria = (): FilterCriteria => {
		const next: FilterCriteria = {};
		selects.forEach((select, key) => {
			if (select.value !== '') {
				next[key] = select.value;
			}
		});
		return next;
	};

	FILTERS.forEach(([key, label]) => {
		const select = document.createElement('select');
		select.className = 'avpvh-filter-select';
		select.dataset['filter'] = key;
		select.disabled = true;
		select.hidden = true;
		const all = document.createElement('option');
		all.value = '';
		all.textContent = label;
		select.appendChild(all);
		select.addEventListener('change', () => {
			const next = readCriteria();
			onChange(hasCriteria(next) ? next : null);
		});
		selects.set(key, select);
		bar.appendChild(select);
	});

	if (hasCriteria(criteria)) {
		const found = document.createElement('span');
		found.className = 'avpvh-filter-found';
		found.textContent =
			total === null
				? 'Zoeken…'
				: `${String(total)} foto${total === 1 ? '' : "'s"} gevonden`;
		bar.appendChild(found);
		const clear = document.createElement('button');
		clear.type = 'button';
		clear.className = 'avpvh-filter-clear';
		clear.textContent = 'Filter wissen';
		clear.addEventListener('click', () => {
			onChange(null);
		});
		bar.appendChild(clear);
	}

	void fetchFilterOptions(ajaxUrl).then((options) => {
		selects.forEach((select, key) => {
			const selected = criteria?.[key] ?? '';
			options[key].forEach((option) => {
				const element = document.createElement('option');
				element.value = option.value;
				element.textContent = `${option.label} (${String(option.count)})`;
				element.selected = option.value === selected;
				select.appendChild(element);
			});
			select.disabled = false;
			select.hidden = options[key].length === 0 && selected === '';
		});
	});

	return bar;
}
