declare const avpvhFolderAuthors: {
	folder_authors_url: string;
	members_url: string;
	nonce: string;
};

const AVPVH_SENTINEL = 0;

type EffectiveSource = 'default' | 'explicit' | 'inherited';

interface FolderRow {
	folder_id: string;
	name: string;
	path: string;
	camera_models: Array<string>;
	explicit_member_ids: Array<number>;
	effective_member_ids: Array<number>;
	effective_source: EffectiveSource;
}

interface Member {
	id: number;
	name: string;
	status: string;
}

type SortKey = 'models' | 'name' | 'path' | 'source';

const container = document.getElementById('avpvh-folder-authors');
const statusEl = document.getElementById('avpvh-folder-authors-status');

let allFolders: Array<FolderRow> = [];
let memberNames = new Map<number, string>();
let searchTerm = '';
let sortKey: SortKey = 'path';
let sortAsc = true;

async function fetchJson<T>(url: string): Promise<T> {
	const response = await fetch(url, {
		headers: { 'X-WP-Nonce': avpvhFolderAuthors.nonce },
		credentials: 'include',
	});

	if (!response.ok) {
		throw new Error(`HTTP ${String(response.status)}`);
	}

	return response.json() as Promise<T>;
}

async function loadMembers(): Promise<void> {
	memberNames = new Map([[AVPVH_SENTINEL, 'AVPvH']]);

	try {
		const result = await fetchJson<{ data: Array<Member> }>(
			avpvhFolderAuthors.members_url
		);

		for (const member of result.data) {
			memberNames.set(member.id, member.name);
		}
	} catch {
		// The members plugin may not be active — AVPvH remains the only option.
	}
}

async function loadFolders(): Promise<void> {
	const result = await fetchJson<{ folders: Array<FolderRow> }>(
		avpvhFolderAuthors.folder_authors_url
	);
	allFolders = result.folders;
}

function memberLabel(id: number): string {
	return memberNames.get(id) ?? `#${String(id)}`;
}

function sourceLabel(row: FolderRow): string {
	if ('explicit' === row.effective_source) {
		return 'Direct toegewezen';
	}

	if ('inherited' === row.effective_source) {
		return 'Overgenomen van bovenliggende map';
	}

	return 'Standaard (AVPvH)';
}

function matchesSearch(row: FolderRow): boolean {
	if ('' === searchTerm) {
		return true;
	}

	const haystack = (row.name + ' ' + row.path).toLowerCase();

	return haystack.includes(searchTerm.toLowerCase());
}

function sortValue(row: FolderRow, key: SortKey): number | string {
	switch (key) {
		case 'name':
			return row.name.toLowerCase();
		case 'path':
			return row.path.toLowerCase();
		case 'models':
			return row.camera_models.length;
		case 'source':
			return row.effective_source;
	}
}

function visibleFolders(): Array<FolderRow> {
	const filtered = allFolders.filter(matchesSearch);
	const direction = sortAsc ? 1 : -1;

	return filtered.slice().sort((a, b) => {
		const av = sortValue(a, sortKey);
		const bv = sortValue(b, sortKey);

		if (av < bv) {
			return -1 * direction;
		}

		return av > bv ? direction : 0;
	});
}

function buildHeaderCell(label: string, key: SortKey): HTMLTableCellElement {
	const th = document.createElement('th');
	th.textContent = label;

	if (key === sortKey) {
		th.setAttribute('aria-sort', sortAsc ? 'ascending' : 'descending');
	}

	th.addEventListener('click', () => {
		if (key === sortKey) {
			sortAsc = !sortAsc;
		} else {
			sortKey = key;
			sortAsc = true;
		}

		// eslint-disable-next-line @typescript-eslint/no-use-before-define -- Cyclical dependency
		render();
	});

	return th;
}

function buildModelChips(models: Array<string>): HTMLSpanElement {
	const wrap = document.createElement('span');
	wrap.className = 'avpvh-fa-models';

	for (const model of models) {
		const chip = document.createElement('span');
		chip.className = 'avpvh-fa-model';
		chip.textContent = model;
		wrap.appendChild(chip);
	}

	return wrap;
}

function buildAuthorsSelect(row: FolderRow): HTMLSelectElement {
	const select = document.createElement('select');
	select.className = 'avpvh-fa-authors';
	select.multiple = true;
	select.size = 4;

	const selected = new Set(row.explicit_member_ids);

	memberNames.forEach((name, id) => {
		const option = document.createElement('option');
		option.value = String(id);
		option.textContent = name;
		option.selected = selected.has(id);
		select.appendChild(option);
	});

	return select;
}

function selectedMemberIds(select: HTMLSelectElement): Array<number> {
	const ids: Array<number> = [];

	select.querySelectorAll<HTMLOptionElement>('option').forEach((option) => {
		if (option.selected) {
			ids.push(parseInt(option.value, 10));
		}
	});

	return ids;
}

async function saveFolder(
	folderId: string,
	mode: 'inherit' | 'members',
	memberIds: Array<number>
): Promise<void> {
	const response = await fetch(avpvhFolderAuthors.folder_authors_url, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': avpvhFolderAuthors.nonce,
		},
		credentials: 'include',
		body: JSON.stringify({
			folder_id: folderId,
			mode,
			member_ids: memberIds,
		}),
	});

	if (!response.ok) {
		throw new Error(`HTTP ${String(response.status)}`);
	}
}

function buildRowActions(
	row: FolderRow,
	select: HTMLSelectElement,
	tr: HTMLTableRowElement
): HTMLDivElement {
	const actions = document.createElement('div');
	actions.className = 'avpvh-fa-row-actions';

	const saveButton = document.createElement('button');
	saveButton.type = 'button';
	saveButton.className = 'button button-primary';
	saveButton.textContent = 'Opslaan';
	saveButton.addEventListener('click', () => {
		void (async (): Promise<void> => {
			tr.classList.add('avpvh-fa-row-saving');

			try {
				await saveFolder(
					row.folder_id,
					'members',
					selectedMemberIds(select)
				);
				await loadFolders();
				// eslint-disable-next-line @typescript-eslint/no-use-before-define -- Cyclical dependency
				render();
			} catch {
				tr.classList.remove('avpvh-fa-row-saving');
			}
		})();
	});

	const inheritButton = document.createElement('button');
	inheritButton.type = 'button';
	inheritButton.className = 'button';
	inheritButton.textContent = 'Erf over';
	inheritButton.disabled = 0 === row.explicit_member_ids.length;
	inheritButton.addEventListener('click', () => {
		void (async (): Promise<void> => {
			tr.classList.add('avpvh-fa-row-saving');

			try {
				await saveFolder(row.folder_id, 'inherit', []);
				await loadFolders();
				// eslint-disable-next-line @typescript-eslint/no-use-before-define -- Cyclical dependency
				render();
			} catch {
				tr.classList.remove('avpvh-fa-row-saving');
			}
		})();
	});

	actions.appendChild(saveButton);
	actions.appendChild(inheritButton);

	return actions;
}

function buildRow(row: FolderRow): HTMLTableRowElement {
	const tr = document.createElement('tr');

	const nameCell = document.createElement('td');
	const nameEl = document.createElement('div');
	nameEl.className = 'avpvh-fa-name';
	nameEl.textContent = row.name;
	const pathEl = document.createElement('div');
	pathEl.className = 'avpvh-fa-path';
	pathEl.textContent = row.path;
	nameCell.appendChild(nameEl);
	nameCell.appendChild(pathEl);

	const modelsCell = document.createElement('td');
	modelsCell.appendChild(buildModelChips(row.camera_models));

	const authorsCell = document.createElement('td');
	const select = buildAuthorsSelect(row);
	authorsCell.appendChild(select);
	authorsCell.appendChild(buildRowActions(row, select, tr));

	const sourceCell = document.createElement('td');
	const badge = document.createElement('span');
	badge.className = `avpvh-fa-source avpvh-fa-source-${row.effective_source}`;
	badge.textContent = sourceLabel(row);
	sourceCell.appendChild(badge);
	const effectiveNames = row.effective_member_ids.map(memberLabel).join(', ');
	const effectiveEl = document.createElement('div');
	effectiveEl.className = 'avpvh-fa-path';
	effectiveEl.textContent = effectiveNames;
	sourceCell.appendChild(effectiveEl);

	tr.appendChild(nameCell);
	tr.appendChild(modelsCell);
	tr.appendChild(authorsCell);
	tr.appendChild(sourceCell);

	return tr;
}

function render(): void {
	if (!container) {
		return;
	}

	container.innerHTML = '';

	const toolbar = document.createElement('div');
	toolbar.className = 'avpvh-fa-toolbar';

	const search = document.createElement('input');
	search.type = 'search';
	search.className = 'avpvh-fa-search';
	search.placeholder = 'Zoek op mapnaam of pad…';
	search.value = searchTerm;
	search.addEventListener('input', () => {
		searchTerm = search.value;
		render();
	});

	const folders = visibleFolders();
	const count = document.createElement('span');
	count.className = 'avpvh-fa-count';
	count.textContent = `${String(folders.length)} / ${String(allFolders.length)} mappen`;

	toolbar.appendChild(search);
	toolbar.appendChild(count);
	container.appendChild(toolbar);

	if (0 === allFolders.length) {
		const empty = document.createElement('p');
		empty.textContent =
			'Nog geen cameramodel-index beschikbaar. Werk eerst de cameramodel-index hierboven bij.';
		container.appendChild(empty);

		return;
	}

	const table = document.createElement('table');
	table.className = 'avpvh-fa-table widefat';

	const thead = document.createElement('thead');
	const headRow = document.createElement('tr');
	const authorsHeader = document.createElement('th');
	authorsHeader.textContent = 'Auteur(s)';

	headRow.appendChild(buildHeaderCell('Map', 'name'));
	headRow.appendChild(buildHeaderCell('Camera’s', 'models'));
	headRow.appendChild(authorsHeader);
	headRow.appendChild(buildHeaderCell('Bron', 'source'));
	thead.appendChild(headRow);

	const tbody = document.createElement('tbody');

	for (const row of folders) {
		tbody.appendChild(buildRow(row));
	}

	table.appendChild(thead);
	table.appendChild(tbody);
	container.appendChild(table);
}

void (async (): Promise<void> => {
	try {
		await Promise.all([loadMembers(), loadFolders()]);
		render();
	} catch (error) {
		if (statusEl) {
			statusEl.textContent = `Laden mislukt: ${error instanceof Error ? error.message : 'onbekende fout'}`;
		}
	}
})();
