// The "Tags" admin page (see Tag_Tree_Page / Tag_Tree_REST): edit the tree
// of sections, groups and tags used by the lightbox's tagging panel.
// Sections hold sections and groups, groups hold tags.

declare const avpvhTagTree: {
	nonce: string;
	url: string;
};

type NodeType = 'group' | 'section' | 'tag';

interface TagNode {
	id: number;
	parent_id: number | null;
	type: NodeType;
	label: string;
	single: boolean;
	taggable: boolean;
	sort_order: number;
}

interface TreeResponse {
	nodes: Array<TagNode>;
	usage: Partial<Record<string, number>>;
	removed?: number;
}

const TYPE_NAMES: Record<NodeType, string> = {
	group: 'Groep',
	section: 'Sectie',
	tag: 'Tag',
};

// What each type may contain ('' is the top level).
const CHILD_TYPES: Record<NodeType | '', Array<NodeType>> = {
	'': ['section'],
	group: ['tag'],
	section: ['section', 'group', 'tag'],
	tag: [],
};

const root = document.getElementById('avpvh-tag-tree');
let nodes: Array<TagNode> = [];
let usage: Partial<Record<string, number>> = {};
// Redraws the tree; assigned below, once everything it uses exists.
let render = (): void => {
	/* replaced below */
};

function element<K extends keyof HTMLElementTagNameMap>(
	tag: K,
	text = '',
	className = ''
): HTMLElementTagNameMap[K] {
	const created = document.createElement(tag);
	created.textContent = text;
	if (className !== '') {
		created.className = className;
	}
	return created;
}

function button(
	text: string,
	title: string,
	onClick: () => void
): HTMLButtonElement {
	const created = element('button', text, 'button button-small');
	created.type = 'button';
	created.title = title;
	created.addEventListener('click', onClick);
	return created;
}

function showStatus(message: string, failed = false): void {
	const status = document.getElementById('avpvh-tag-tree-status');
	if (status !== null) {
		status.textContent = message;
		status.style.color = failed ? '#b32d2e' : '#1d6f42';
	}
}

async function call(
	path: string,
	body?: Record<string, unknown>
): Promise<TreeResponse | null> {
	const response = await fetch(avpvhTagTree.url + path, {
		method: body === undefined ? 'GET' : 'POST',
		credentials: 'include',
		headers: {
			'X-WP-Nonce': avpvhTagTree.nonce,
			'Content-Type': 'application/json',
		},
		...(body === undefined ? {} : { body: JSON.stringify(body) }),
	});
	const data = (await response.json().catch(() => null)) as
		(TreeResponse & { message?: string }) | null;
	if (!response.ok || data === null) {
		showStatus(
			data?.message ??
				`Opslaan mislukt (HTTP ${String(response.status)})`,
			true
		);
		return null;
	}
	nodes = data.nodes;
	usage = data.usage;
	render();
	return data;
}

function childrenOf(parentId: number | null): Array<TagNode> {
	return nodes
		.filter((node) => node.parent_id === parentId)
		.sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
}

function photosWith(node: TagNode): number {
	if (node.type === 'tag') {
		return usage[`t${String(node.id)}`] ?? 0;
	}
	return childrenOf(node.id).reduce(
		(sum, child) => sum + photosWith(child),
		0
	);
}

function pathOf(node: TagNode): string {
	const parent = nodes.find((other) => other.id === node.parent_id);
	return parent === undefined
		? node.label
		: `${pathOf(parent)} › ${node.label}`;
}

function isBelow(candidate: TagNode, node: TagNode): boolean {
	const parent = nodes.find((other) => other.id === candidate.parent_id);
	return (
		parent !== undefined && (parent.id === node.id || isBelow(parent, node))
	);
}

// Where a node may move to: parents of a type that may contain it (not
// itself, nor anything below it).
function targetsFor(
	node: TagNode
): Array<{ id: number | null; label: string }> {
	const targets: Array<{ id: number | null; label: string }> = [];
	if (CHILD_TYPES[''].includes(node.type)) {
		targets.push({ id: null, label: '(hoogste niveau)' });
	}
	nodes
		.filter(
			(other) =>
				other.id !== node.id &&
				other.id !== node.parent_id &&
				CHILD_TYPES[other.type].includes(node.type) &&
				!isBelow(other, node)
		)
		.map((other) => ({ id: other.id, label: pathOf(other) }))
		.sort((a, b) => a.label.localeCompare(b.label))
		.forEach((target) => targets.push(target));
	return targets;
}

function nameEditor(node: TagNode, label: HTMLElement): void {
	const input = element('input');
	input.type = 'text';
	input.value = node.label;
	input.maxLength = 100;
	let done = false;
	const finish = (save: boolean): void => {
		if (done) {
			return;
		}
		done = true;
		const value = input.value.trim();
		if (save && value !== '' && value !== node.label) {
			void call('/update', { id: node.id, label: value }).then(() => {
				showStatus(`Hernoemd naar "${value}".`);
			});
		} else {
			render();
		}
	};
	input.addEventListener('keydown', (e) => {
		if (e.key === 'Enter') {
			finish(true);
		} else if (e.key === 'Escape') {
			finish(false);
		}
	});
	input.addEventListener('blur', () => {
		finish(true);
	});
	label.replaceWith(input);
	input.focus();
	input.select();
}

function addForm(
	parentId: number | null,
	type: NodeType,
	after: HTMLElement
): void {
	const form = element('div', '', 'avpvh-tag-tree-add');
	const input = element('input');
	input.type = 'text';
	input.placeholder = `Naam van de nieuwe ${TYPE_NAMES[type].toLowerCase()}`;
	input.maxLength = 100;
	const save = (): void => {
		const label = input.value.trim();
		if (label === '') {
			return;
		}
		void call('/create', { parent_id: parentId, type, label }).then(
			(result) => {
				if (result !== null) {
					showStatus(`${TYPE_NAMES[type]} "${label}" toegevoegd.`);
				}
			}
		);
	};
	input.addEventListener('keydown', (e) => {
		if (e.key === 'Enter') {
			save();
		} else if (e.key === 'Escape') {
			form.remove();
		}
	});
	form.append(
		input,
		button('Toevoegen', 'Toevoegen', save),
		button('Annuleren', 'Annuleren', () => {
			form.remove();
		})
	);
	after.after(form);
	input.focus();
}

function moveForm(node: TagNode, after: HTMLElement): void {
	const form = element('div', '', 'avpvh-tag-tree-add');
	const select = element('select');
	select.appendChild(element('option', 'Verplaatsen naar…'));
	targetsFor(node).forEach((target) => {
		const option = element('option', target.label);
		option.value = target.id === null ? 'top' : String(target.id);
		select.appendChild(option);
	});
	select.addEventListener('change', () => {
		const parentId = select.value === 'top' ? null : Number(select.value);
		void call('/update', { id: node.id, parent_id: parentId }).then(
			(result) => {
				if (result !== null) {
					showStatus(`"${node.label}" verplaatst.`);
				}
			}
		);
	});
	form.append(
		select,
		button('Annuleren', 'Annuleren', () => {
			form.remove();
		})
	);
	after.after(form);
	select.focus();
}

function deleteForm(node: TagNode, after: HTMLElement): void {
	const photos = photosWith(node);
	const below = node.type === 'tag' ? '' : ' en alles eronder';
	const form = element(
		'div',
		`"${node.label}"${below} verwijderen?` +
			(photos > 0
				? ` ${String(photos)} foto${photos === 1 ? '' : "'s"} verliezen deze tag${node.type === 'tag' ? '' : 's'}.`
				: " Geen foto's hebben deze tag.") +
			' ',
		'avpvh-tag-tree-add'
	);
	form.append(
		button('Ja, verwijderen', 'Verwijderen', () => {
			void call('/delete', { id: node.id }).then((result) => {
				if (result !== null) {
					showStatus(
						`"${node.label}" verwijderd` +
							((result.removed ?? 0) > 0
								? `; tag van ${String(result.removed)} foto's gehaald.`
								: '.')
					);
				}
			});
		}),
		button('Nee', 'Annuleren', () => {
			form.remove();
		})
	);
	after.after(form);
}

function nodeRow(node: TagNode): HTMLElement {
	const row = element(
		'div',
		'',
		`avpvh-tag-tree-row avpvh-tag-tree-${node.type}`
	);
	row.appendChild(
		element('span', TYPE_NAMES[node.type], 'avpvh-tag-tree-type')
	);
	const label = element('button', node.label, 'avpvh-tag-tree-label');
	label.type = 'button';
	label.title = 'Klik om te hernoemen';
	label.addEventListener('click', () => {
		nameEditor(node, label);
	});
	row.appendChild(label);

	if (node.type === 'tag') {
		const photos = photosWith(node);
		row.appendChild(
			element(
				'span',
				`${String(photos)} foto${photos === 1 ? '' : "'s"}`,
				'avpvh-tag-tree-usage'
			)
		);
	}
	if (node.type === 'group') {
		const single = element('label', '', 'avpvh-tag-tree-single');
		const checkbox = element('input');
		checkbox.type = 'checkbox';
		checkbox.checked = node.single;
		checkbox.addEventListener('change', () => {
			void call('/update', { id: node.id, single: checkbox.checked });
		});
		single.append(checkbox, document.createTextNode(' één keuze per foto'));
		row.appendChild(single);
		const taggable = element('label', '', 'avpvh-tag-tree-single');
		taggable.title =
			'De groep zelf kan ook getagd worden; een tag erin tagt de groep mee';
		const taggableBox = element('input');
		taggableBox.type = 'checkbox';
		taggableBox.checked = node.taggable;
		taggableBox.addEventListener('change', () => {
			void call('/update', {
				id: node.id,
				taggable: taggableBox.checked,
			});
		});
		taggable.append(
			taggableBox,
			document.createTextNode(' zelf aan te vinken')
		);
		row.appendChild(taggable);
	}

	row.append(
		button('↑', 'Omhoog', () => {
			void call('/shift', { id: node.id, direction: -1 });
		}),
		button('↓', 'Omlaag', () => {
			void call('/shift', { id: node.id, direction: 1 });
		}),
		button(
			'Verplaatsen…',
			'Onder een andere sectie of groep zetten',
			() => {
				moveForm(node, row);
			}
		)
	);
	CHILD_TYPES[node.type].forEach((type) => {
		row.appendChild(
			button(
				`+ ${TYPE_NAMES[type]}`,
				`${TYPE_NAMES[type]} toevoegen onder "${node.label}"`,
				() => {
					addForm(node.id, type, row);
				}
			)
		);
	});
	row.appendChild(
		button('Verwijderen', 'Verwijderen', () => {
			deleteForm(node, row);
		})
	);
	return row;
}

function branch(parentId: number | null): HTMLElement {
	const list = element('ul', '', 'avpvh-tag-tree-list');
	childrenOf(parentId).forEach((node) => {
		const item = element('li');
		item.appendChild(nodeRow(node));
		if (node.type !== 'tag') {
			item.appendChild(branch(node.id));
		}
		list.appendChild(item);
	});
	return list;
}

render = (): void => {
	if (root === null) {
		return;
	}
	const status = document.getElementById('avpvh-tag-tree-status');
	const message = status?.textContent ?? '';
	root.innerHTML = '';
	const top = element('p');
	top.append(
		button('+ Sectie', 'Sectie toevoegen op het hoogste niveau', () => {
			addForm(null, 'section', top);
		})
	);
	const newStatus = element('span', message);
	newStatus.id = 'avpvh-tag-tree-status';
	newStatus.style.marginLeft = '1em';
	top.appendChild(newStatus);
	root.append(top, branch(null));
};

function addStyles(): void {
	const style = element(
		'style',
		`
		.avpvh-tag-tree-list { margin: 2px 0 2px 22px; }
		#avpvh-tag-tree > .avpvh-tag-tree-list { margin-left: 0; }
		.avpvh-tag-tree-row { align-items: center; display: flex; flex-wrap: wrap; gap: 4px; padding: 2px 0; }
		.avpvh-tag-tree-type { color: #646970; font-size: 11px; text-transform: uppercase; width: 52px; }
		.avpvh-tag-tree-label { background: none; border: none; cursor: text; font-size: 14px; padding: 2px 4px; }
		.avpvh-tag-tree-section > .avpvh-tag-tree-label { font-weight: 700; }
		.avpvh-tag-tree-group > .avpvh-tag-tree-label { font-weight: 600; }
		.avpvh-tag-tree-label:hover { background: #f0f0f1; }
		.avpvh-tag-tree-usage, .avpvh-tag-tree-single { color: #646970; font-size: 12px; margin-right: 6px; }
		.avpvh-tag-tree-add { align-items: center; display: flex; flex-wrap: wrap; gap: 4px; margin: 2px 0 6px 56px; }
		`
	);
	document.head.appendChild(style);
}

if (root !== null) {
	addStyles();
	void call('').then((result) => {
		if (result === null && root.textContent === 'Laden…') {
			root.textContent = 'De tags konden niet worden geladen.';
		}
	});
}
