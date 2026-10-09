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

// A folder chosen in the folder tree: its whole branch is taken in, or
// left out when exclude is set (a choice deeper down overrules one above).
export interface FilterFolder {
	id: string;
	name: string;
	path: string;
	exclude?: boolean;
}

// The chosen folders as the server wants them (see Photo_Filter_Scope::
// within_many): top down, left-out ones marked with "!".
export function folderIds(folders: Array<FilterFolder>): Array<string> {
	return [...folders]
		.sort((a, b) => a.path.split('/').length - b.path.split('/').length)
		.map(({ id, exclude }) => (exclude === true ? `!${id}` : id));
}

// "✓ 03-Weekenden, ✗ 2024 Meerveld".
function folderNames(folders: Array<FilterFolder>): string {
	return folders
		.map(({ name, exclude }) => `${exclude === true ? '✗' : '✓'} ${name}`)
		.join(', ');
}

export interface FilterState {
	conditions: Array<FilterCondition>;
	folders: Array<FilterFolder>;
	sort: SortOrder;
}

export interface SavedFilter extends FilterState {
	id: string;
	name: string;
	// Whom the owner shares it with (own filters only; see Filter_Sharing).
	users?: Array<number>;
	groups?: Array<string>;
	// Whose it is, for filters shared with the user.
	owner?: string;
}

// Whom a filter can be shared with.
export interface FilterRecipients {
	users: Array<{ id: number; name: string }>;
	groups: Array<string>;
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
	state: FilterState | null
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
function describe(
	conditions: Array<FilterCondition>,
	folders: Array<FilterFolder>
): string {
	return (
		conditions
			.map((condition) => {
				const operator = OPERATORS.find(([op]) => op === condition.op);
				return `${operator?.[2] ?? ''} ${condition.label}`;
			})
			.join(', ') +
		(folders.length > 0 ? ` (mappen: ${folderNames(folders)})` : '')
	);
}

// What the user chose while confirming a share: captions ("jaar en
// opgraving erop"), cropping to A4, and their Google address if asked.
interface ShareOptions {
	captions: boolean;
	a4: boolean;
	google: string;
	// Close the oldest open share first when the user has the maximum open.
	replaceOldest: boolean;
}

// What the server answered: what to tell the user, and whether it refused
// because the user has the maximum number of shares open.
interface ShareResult {
	message: string;
	full: boolean;
}

// Asks the server to share the filter's photos via Google Drive (see
// Photo_Shares); resolves to what to tell the user.
async function requestShare(
	ajaxUrl: string,
	share: FilterShare,
	conditions: Array<FilterCondition>,
	description: string,
	options: ShareOptions
): Promise<ShareResult> {
	try {
		const response = await fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'include',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({
				action: 'gallery_share_create',
				conditions: conditionsParam(conditions),
				folders: JSON.stringify(folderIds(share.folders)),
				description,
				captions: options.captions ? '1' : '0',
				a4: options.a4 ? '1' : '0',
				google: options.google,
				replace_oldest: options.replaceOldest ? '1' : '0',
				state: JSON.stringify({
					conditions,
					folders: share.folders,
					sort: share.sort,
				}),
				page: window.location.origin + window.location.pathname,
				_ajax_nonce: share.nonce,
			}).toString(),
		});
		const data = (await response.json()) as {
			success?: boolean;
			data?: { recipient?: string; message?: string; full?: boolean };
		};
		return data.success === true
			? {
					message: `De map wordt gemaakt; je krijgt de link per e-mail op ${data.data?.recipient ?? 'je Google-adres'}.`,
					full: false,
				}
			: {
					message: data.data?.message ?? 'Delen mislukt',
					full: data.data?.full === true,
				};
	} catch {
		return { message: 'Delen mislukt', full: false };
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

export interface FilterScope {
	selected: Array<FilterFolder>;
	load(path: string): Promise<Array<FilterFolder>>;
	onChange(folders: Array<FilterFolder>): void;
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
	// The user's Google address, or '' when none is known (then it's asked).
	google: string;
	folders: Array<FilterFolder>;
	// The gallery's order, kept with the share so the profile can open it.
	sort: SortOrder;
	nonce: string;
}

export interface FilterLibrary {
	filters: Array<SavedFilter>;
	// Other members' filters shared with the user: apply only.
	shared: Array<SavedFilter>;
	recipients(): Promise<FilterRecipients>;
	onShare(
		id: string,
		users: Array<number>,
		groups: Array<string>
	): Promise<Array<SavedFilter>>;
	onApply(filter: SavedFilter): void;
	onDelete(id: string): Promise<Array<SavedFilter>>;
	onSave(name: string, id: string): Promise<Array<SavedFilter>>;
}

// "Oudste verwijderen en delen", offered when the user has the maximum
// number of shares open.
function replaceButton(onClick: () => void): HTMLButtonElement {
	const button = document.createElement('button');
	button.type = 'button';
	button.className = 'avpvh-filter-share';
	button.textContent = 'Oudste verwijderen en delen';
	button.title =
		'De map van je oudste deling wordt verwijderd (als die er nog is); op je profiel kun je hem later opnieuw laten maken';
	button.addEventListener('click', () => {
		button.disabled = true;
		onClick();
	});
	return button;
}

// A checkbox with its label, shown while confirming a share.
function shareOption(
	checkbox: HTMLInputElement,
	text: string,
	title: string
): HTMLElement {
	const label = document.createElement('label');
	label.className = 'avpvh-filter-share-captions';
	label.title = title;
	label.append(checkbox, document.createTextNode(` ${text}`));
	return label;
}

// "Delen via Google Drive": after confirming, starts the share and says
// where the link will be sent. While confirming, the photos can be chosen
// to be put upright with the dig's year and name written on them, and to
// be cropped to A4 for printing.
function shareButton(
	ajaxUrl: string,
	share: FilterShare,
	conditions: Array<FilterCondition>,
	total: number,
	folders: Array<FilterFolder>,
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
	const a4 = document.createElement('input');
	a4.type = 'checkbox';
	const google = document.createElement('input');
	google.type = 'email';
	google.className = 'avpvh-filter-share-google';
	google.placeholder = 'Je Google-adres, bijv. naam@gmail.com';
	google.title =
		'De map wordt gedeeld met dit Google-account; het adres wordt onthouden';
	// The first click asks for confirmation in the button itself, the second
	// one starts the share.
	button.addEventListener('click', () => {
		if (button.dataset['confirm'] !== '1') {
			button.dataset['confirm'] = '1';
			button.textContent = `Ja, ${String(total)} foto${total === 1 ? '' : "'s"} delen (link per e-mail, een week geldig)`;
			if (share.captions) {
				wrapper.append(
					shareOption(
						captions,
						'jaar en plaats erop',
						'De foto’s worden rechtop gezet zoals in de galerij, en foto’s van opgravingen krijgen rechtsonder het jaar en de plaats'
					),
					shareOption(
						a4,
						'A4-jpg’s + pdf’s (max. 80 MB per pdf)',
						'Elke foto wordt vanuit het midden bijgesneden tot A4-verhouding. De A4-jpg’s komen ook in genummerde pdf-bestanden van maximaal 80 MB, met dezelfde tekst binnen het beeld'
					)
				);
			}
			if (share.google === '') {
				wrapper.appendChild(google);
			}
			return;
		}
		if (
			share.google === '' &&
			(google.value.trim() === '' || !google.checkValidity())
		) {
			status.textContent =
				'Vul het Google-adres in waarmee je de map wilt openen';
			google.focus();
			return;
		}
		button.disabled = true;
		captions.disabled = true;
		a4.disabled = true;
		google.disabled = true;
		const send = (replaceOldest: boolean): void => {
			void requestShare(
				ajaxUrl,
				share,
				conditions,
				describe(conditions, folders),
				{
					captions: captions.checked,
					a4: a4.checked,
					google: share.google === '' ? google.value.trim() : '',
					replaceOldest,
				}
			).then(({ message, full }) => {
				status.textContent = message;
				if (full && !replaceOldest) {
					status.append(
						' ',
						replaceButton(() => {
							send(true);
						})
					);
				}
			});
		};
		send(false);
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

const FOLDER_DEBOUNCE_MS = 5000;

function isSameFolderSelection(
	a: Array<FilterFolder>,
	b: Array<FilterFolder>
): boolean {
	if (a.length !== b.length) {
		return false;
	}
	const aIds = new Set(a.map((f) => f.id));
	return b.every((f) => aIds.has(f.id));
}

// A compact, lazily loaded folder tree. Selecting a folder includes its
// complete branch; several separate branches may be selected together.
function folderPicker(scope: FilterScope): HTMLElement {
	const details = document.createElement('details');
	details.className = 'avpvh-filter-folders';
	const summary = document.createElement('summary');
	const selectedById = new Map(
		scope.selected.map((folder) => [folder.id, folder])
	);
	const updateSummary = (): void => {
		const selected = Array.from(selectedById.values());
		summary.textContent =
			selected.length === 0
				? 'Mappen: alles'
				: `Mappen: ${selected.map(({ name }) => name).join(', ')}`;
	};
	updateSummary();
	details.appendChild(summary);

	const panel = document.createElement('div');
	panel.className = 'avpvh-filter-folder-panel';

	let timer: number | null = null;
	let hasPendingChanges = false;

	const actions = document.createElement('div');
	actions.className = 'avpvh-filter-folder-actions';

	const applyBtn = document.createElement('button');
	applyBtn.type = 'button';
	applyBtn.className = 'avpvh-filter-folder-apply';
	applyBtn.textContent = 'Toepassen';
	applyBtn.disabled = true;

	const cancelTimer = (): void => {
		if (timer !== null) {
			window.clearTimeout(timer);
			timer = null;
		}
	};

	const flush = (): void => {
		cancelTimer();
		if (hasPendingChanges) {
			hasPendingChanges = false;
			applyBtn.disabled = true;
			scope.onChange(Array.from(selectedById.values()));
		}
	};

	const scheduleChange = (): void => {
		cancelTimer();
		const current = Array.from(selectedById.values());
		if (isSameFolderSelection(current, scope.selected)) {
			hasPendingChanges = false;
			applyBtn.disabled = true;
			return;
		}
		hasPendingChanges = true;
		applyBtn.disabled = false;
		timer = window.setTimeout(() => {
			flush();
		}, FOLDER_DEBOUNCE_MS);
	};

	applyBtn.addEventListener('click', (event) => {
		event.stopPropagation();
		details.open = false;
		flush();
	});

	actions.appendChild(applyBtn);
	panel.appendChild(actions);

	const tree = document.createElement('ul');
	tree.className = 'avpvh-filter-folder-tree';
	panel.appendChild(tree);
	details.appendChild(panel);

	const allRow = document.createElement('li');
	const allLabel = document.createElement('label');
	const allBox = document.createElement('input');
	allBox.type = 'checkbox';
	allBox.checked = scope.selected.length === 0;
	const updateTreeState = (): void => {
		const currentSelected = Array.from(selectedById.values());
		allBox.checked = currentSelected.length === 0;

		tree.querySelectorAll<HTMLInputElement>(
			'input[data-folder-id]'
		).forEach((b) => {
			const folderId = b.dataset['folderId'];
			const folderPath = b.dataset['folderPath'];
			if (
				folderId === undefined ||
				folderId === '' ||
				folderPath === undefined ||
				folderPath === ''
			) {
				return;
			}
			const isChecked = selectedById.has(folderId);
			b.checked = isChecked;
			const hasDescendant = currentSelected.some(
				(f) => f.id !== folderId && f.path.startsWith(`${folderPath}/`)
			);
			b.indeterminate = !isChecked && hasDescendant;
		});
	};

	allBox.addEventListener('change', () => {
		if (allBox.checked) {
			selectedById.clear();
			updateTreeState();
			updateSummary();
			scheduleChange();
		} else {
			allBox.checked = true;
		}
	});
	allLabel.append(allBox, document.createTextNode(' Alle mappen'));
	allRow.appendChild(allLabel);
	tree.appendChild(allRow);

	const renderChildren = async (
		parent: HTMLElement,
		path: string
	): Promise<void> => {
		parent.classList.add('loading');
		const folders = await scope.load(path);
		parent.classList.remove('loading');
		folders.forEach((folder) => {
			const item = document.createElement('li');
			item.className = 'avpvh-filter-folder-item closed';
			const row = document.createElement('div');
			row.className = 'avpvh-filter-folder-row';
			const toggle = document.createElement('button');
			toggle.type = 'button';
			toggle.className = 'avpvh-filter-folder-toggle';
			toggle.textContent = '▸';
			toggle.title = 'Submappen tonen';
			const label = document.createElement('label');
			const box = document.createElement('input');
			box.type = 'checkbox';
			box.dataset['folderId'] = folder.id;
			box.dataset['folderPath'] = folder.path;
			box.checked = selectedById.has(folder.id);

			const children = document.createElement('ul');
			children.className = 'avpvh-filter-folder-tree';

			const hasSelectedDescendant = Array.from(
				selectedById.values()
			).some(
				(f) =>
					f.id !== folder.id && f.path.startsWith(`${folder.path}/`)
			);
			box.indeterminate = !box.checked && hasSelectedDescendant;

			box.addEventListener('change', () => {
				if (box.checked) {
					allBox.checked = false;
					selectedById.set(folder.id, folder);
					// A chosen branch already contains its chosen descendants.
					for (const chosen of Array.from(selectedById.values())) {
						if (
							chosen.id !== folder.id &&
							chosen.path.startsWith(`${folder.path}/`)
						) {
							selectedById.delete(chosen.id);
						}
					}
				} else {
					selectedById.delete(folder.id);
				}
				updateTreeState();
				updateSummary();
				scheduleChange();
			});
			label.append(box, document.createTextNode(` ${folder.name}`));
			row.append(toggle, label);
			item.appendChild(row);
			item.appendChild(children);
			let loaded = false;
			if (hasSelectedDescendant) {
				item.classList.remove('closed');
				toggle.textContent = '▾';
				loaded = true;
				void renderChildren(children, folder.path);
			}
			toggle.addEventListener('click', () => {
				const opening = item.classList.contains('closed');
				item.classList.toggle('closed', !opening);
				toggle.textContent = opening ? '▾' : '▸';
				if (hasPendingChanges) {
					scheduleChange();
				}
				if (opening && !loaded) {
					loaded = true;
					void renderChildren(children, folder.path);
				}
			});
			parent.appendChild(item);
		});
	};

	details.addEventListener('toggle', () => {
		if (details.open) {
			if (tree.childElementCount === 1) {
				void renderChildren(tree, '');
			}
		} else if (hasPendingChanges) {
			flush();
		}
	});

	const onDocumentClick = (event: MouseEvent): void => {
		if (!details.isConnected) {
			document.removeEventListener('click', onDocumentClick);
			return;
		}
		if (details.open && !details.contains(event.target as Node)) {
			details.open = false;
			if (hasPendingChanges) {
				flush();
			}
		}
	};
	document.addEventListener('click', onDocumentClick);

	return details;
}

// The rows of the share panel: groups first, then members, each with a
// checkbox; returns the rows with what they stand for.
function recipientRows(
	recipients: FilterRecipients,
	filter: SavedFilter
): Array<{ row: HTMLLabelElement; box: HTMLInputElement; key: string }> {
	const entries: Array<[string, string, boolean]> = [
		...recipients.groups.map((group): [string, string, boolean] => [
			`g:${group}`,
			`👥 ${group}`,
			(filter.groups ?? []).includes(group),
		]),
		...recipients.users.map(({ id, name }): [string, string, boolean] => [
			`u:${String(id)}`,
			name,
			(filter.users ?? []).includes(id),
		]),
	];
	return entries.map(([key, text, checked]) => {
		const row = document.createElement('label');
		row.className = 'avpvh-filter-share-row';
		const box = document.createElement('input');
		box.type = 'checkbox';
		box.checked = checked;
		row.append(box, document.createTextNode(` ${text}`));
		return { row, box, key };
	});
}

// "Delen…": whom a named filter is shared with — groups and members, with
// a search field that narrows the list as you type.
function sharePanel(library: FilterLibrary, filter: SavedFilter): HTMLElement {
	const panel = document.createElement('div');
	panel.className = 'avpvh-filter-share-panel';
	const search = document.createElement('input');
	search.type = 'search';
	search.placeholder = 'Zoek een naam of groep';
	const list = document.createElement('div');
	list.className = 'avpvh-filter-share-list';
	list.textContent = 'Laden…';
	const save = document.createElement('button');
	save.type = 'button';
	save.className = 'avpvh-filter-saved-button';
	save.textContent = 'Delen opslaan';
	save.disabled = true;
	const status = document.createElement('span');
	status.setAttribute('role', 'status');
	panel.append(search, list, save, status);

	void library.recipients().then((recipients) => {
		const rows = recipientRows(recipients, filter);
		const summary = (): void => {
			const chosen = rows.filter(({ box }) => box.checked);
			const groups = chosen.filter(({ key }) => key.startsWith('g:'));
			status.textContent =
				chosen.length === 0
					? 'Niet gedeeld'
					: `Gedeeld met ${String(chosen.length - groups.length)} leden en ${String(groups.length)} groepen`;
		};
		list.textContent = '';
		rows.forEach(({ row, box }) => {
			box.addEventListener('change', summary);
			list.appendChild(row);
		});
		summary();
		save.disabled = false;
		search.addEventListener('input', () => {
			const query = search.value.trim().toLowerCase();
			rows.forEach(({ row }) => {
				row.hidden =
					query !== '' &&
					!row.textContent.toLowerCase().includes(query);
			});
		});
		save.addEventListener('click', () => {
			const keys = rows
				.filter(({ box }) => box.checked)
				.map(({ key }) => key);
			const users = keys
				.filter((key) => key.startsWith('u:'))
				.map((key) => Number(key.slice(2)));
			const groups = keys
				.filter((key) => key.startsWith('g:'))
				.map((key) => key.slice(2));
			save.disabled = true;
			void library
				.onShare(filter.id, users, groups)
				.then(() => {
					// So the panel shows it when opened again.
					filter.users = users;
					filter.groups = groups;
					status.textContent = `${status.textContent} — opgeslagen`;
				})
				.catch(() => {
					status.textContent = 'Opslaan mislukt';
				})
				.finally(() => {
					save.disabled = false;
				});
		});
	});
	return panel;
}

function savedFilterControls(
	library: FilterLibrary,
	canSave: boolean
): HTMLElement {
	const controls = document.createElement('div');
	controls.className = 'avpvh-filter-saved';
	const picker = select('avpvh-filter-select', [
		['', 'Opgeslagen filters…'],
		...library.filters.map(
			({ id, name }) => [id, name] as [string, string]
		),
	]);
	if (library.shared.length > 0) {
		const group = document.createElement('optgroup');
		group.label = 'Gedeeld met mij';
		library.shared.forEach(({ id, name, owner }) => {
			const option = document.createElement('option');
			option.value = id;
			option.textContent = `${name} (van ${owner ?? '?'})`;
			group.appendChild(option);
		});
		picker.appendChild(group);
	}
	const findChosen = (): SavedFilter | undefined =>
		[...library.filters, ...library.shared].find(
			({ id }) => id === picker.value
		);
	const isOwn = (): boolean =>
		library.filters.some(({ id }) => id === picker.value);
	const apply = document.createElement('button');
	apply.type = 'button';
	apply.className = 'avpvh-filter-saved-button';
	apply.textContent = 'Toepassen';
	apply.disabled = true;
	const remove = document.createElement('button');
	remove.type = 'button';
	remove.className = 'avpvh-filter-saved-button';
	remove.textContent = 'Verwijderen';
	remove.disabled = true;
	const share = document.createElement('button');
	share.type = 'button';
	share.className = 'avpvh-filter-saved-button';
	share.textContent = 'Delen…';
	share.title = 'Dit filter delen met andere leden of groepen';
	share.disabled = true;
	let panel: HTMLElement | null = null;
	picker.addEventListener('change', () => {
		apply.disabled = picker.value === '';
		remove.disabled = !isOwn();
		share.disabled = !isOwn();
		panel?.remove();
		panel = null;
	});
	apply.addEventListener('click', () => {
		const chosen = findChosen();
		if (chosen !== undefined) {
			library.onApply(chosen);
		}
	});
	share.addEventListener('click', () => {
		const chosen = library.filters.find(({ id }) => id === picker.value);
		if (panel !== null) {
			panel.remove();
			panel = null;
		} else if (chosen !== undefined) {
			panel = sharePanel(library, chosen);
			controls.appendChild(panel);
		}
	});
	remove.addEventListener('click', () => {
		if (picker.value === '') {
			return;
		}
		remove.disabled = true;
		void library
			.onDelete(picker.value)
			.then(() => {
				picker.selectedOptions.item(0)?.remove();
				picker.value = '';
				apply.disabled = true;
			})
			.catch(() => {
				remove.disabled = false;
			});
	});
	const name = document.createElement('input');
	name.type = 'text';
	name.maxLength = 80;
	name.placeholder = 'Naam voor dit filter';
	const save = document.createElement('button');
	save.type = 'button';
	save.className = 'avpvh-filter-saved-button';
	save.textContent = 'Filter opslaan';
	const status = document.createElement('span');
	status.setAttribute('role', 'status');
	save.addEventListener('click', () => {
		const filterName = name.value.trim();
		if (filterName === '') {
			name.focus();
			return;
		}
		save.disabled = true;
		void library
			.onSave(filterName, picker.value)
			.then((filters) => {
				const saved = filters.find(
					({ name: candidate }) => candidate === filterName
				);
				status.textContent = 'Opgeslagen';
				if (saved !== undefined) {
					const existing = Array.from(picker.options).find(
						(option) => option.value === saved.id
					);
					const option = existing ?? document.createElement('option');
					option.value = saved.id;
					option.textContent = saved.name;
					if (existing === undefined) {
						picker.appendChild(option);
					}
					picker.value = saved.id;
				}
				save.disabled = false;
			})
			.catch(() => {
				status.textContent = 'Opslaan mislukt';
				save.disabled = false;
			});
	});
	controls.append(picker, apply, remove, share);
	if (canSave) {
		controls.append(name, save, status);
	}
	return controls;
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
	library: FilterLibrary,
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
	if (isActiveFilter(conditions)) {
		adder.appendChild(folderPicker(scope));
	}
	adder.appendChild(sortPicker(sort, isActiveFilter(conditions)));
	bar.appendChild(adder);
	if (isActiveFilter(conditions) || library.filters.length > 0) {
		bar.appendChild(
			savedFilterControls(library, isActiveFilter(conditions))
		);
	}

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
					scope.selected,
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
