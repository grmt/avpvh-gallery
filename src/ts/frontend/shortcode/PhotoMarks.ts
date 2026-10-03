// Marking photos in the grid (see Photo_Marks on the PHP side): right-click
// a thumbnail to raise its mark (★ → ★★ → …), Shift+right-click to lower
// it, in the chosen circle — the family, or an LDAP group whose members
// share one selection. Filter on "Gemarkeerd" to look at the marked photos
// only and mark again. Logged-in users only.

export interface MarkCircle {
	key: string;
	label: string;
}

const CIRCLE_STORAGE_KEY = 'avpvh_mark_circle';

let circlesPromise: Promise<Array<MarkCircle>> | null = null;

export async function fetchCircles(
	ajaxUrl: string
): Promise<Array<MarkCircle>> {
	circlesPromise ??= fetch(`${ajaxUrl}?action=gallery_mark_circles`, {
		credentials: 'include',
	})
		.then(async (response) => {
			const data = (await response.json()) as {
				data?: { circles?: Array<MarkCircle> };
			};
			return data.data?.circles ?? [];
		})
		.catch(() => []);
	return circlesPromise;
}

// The circle marks go to, remembered per browser; the family by default.
export function activeCircle(circles: Array<MarkCircle>): string {
	let stored = '';
	try {
		stored = localStorage.getItem(CIRCLE_STORAGE_KEY) ?? '';
	} catch {
		// No storage: fall back to the family.
	}
	return circles.some((circle) => circle.key === stored)
		? stored
		: (circles[0]?.key ?? '');
}

export function setActiveCircle(circle: string): void {
	try {
		localStorage.setItem(CIRCLE_STORAGE_KEY, circle);
	} catch {
		// Not remembered; still used for this page view.
	}
}

async function fetchLevels(
	ajaxUrl: string,
	circle: string,
	ids: Array<string>
): Promise<Record<string, number>> {
	const params = new URLSearchParams({
		action: 'gallery_marks',
		circle,
		ids: ids.join(','),
	});
	try {
		const response = await fetch(`${ajaxUrl}?${params.toString()}`, {
			credentials: 'include',
		});
		const data = (await response.json()) as {
			data?: { levels?: Record<string, number> };
		};
		return data.data?.levels ?? {};
	} catch {
		return {};
	}
}

async function changeMark(
	ajaxUrl: string,
	nonce: string,
	circle: string,
	imageId: string,
	delta: number
): Promise<number | null> {
	try {
		const response = await fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'include',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({
				action: 'gallery_mark',
				circle,
				image_id: imageId,
				delta: String(delta),
				_ajax_nonce: nonce,
			}).toString(),
		});
		const data = (await response.json()) as {
			success?: boolean;
			data?: { level?: number };
		};
		return data.success === true ? (data.data?.level ?? 0) : null;
	} catch {
		return null;
	}
}

function showLevel(link: HTMLElement, level: number): void {
	let badge = link.querySelector<HTMLElement>(':scope > .avpvh-mark-badge');
	if (level <= 0) {
		badge?.remove();
		return;
	}
	if (badge === null) {
		badge = document.createElement('span');
		badge.className = 'avpvh-mark-badge';
		link.appendChild(badge);
	}
	badge.textContent = '★'.repeat(level);
	badge.title = `Gemarkeerd: ${String(level)} ster${level === 1 ? '' : 'ren'}`;
}

function thumbnails(container: HTMLElement): Array<HTMLElement> {
	return Array.from(
		container.querySelectorAll<HTMLElement>('a.avpvh-grid-a[data-avpvh-id]')
	);
}

// Shows the active circle's marks on every thumbnail in the container.
export async function showMarks(
	ajaxUrl: string,
	container: HTMLElement,
	circle: string
): Promise<void> {
	const links = thumbnails(container);
	const ids = links.map((link) => link.dataset['avpvhId'] ?? '');
	const levels =
		circle === '' || ids.length === 0
			? {}
			: await fetchLevels(ajaxUrl, circle, ids);
	links.forEach((link) => {
		showLevel(link, levels[link.dataset['avpvhId'] ?? ''] ?? 0);
	});
}

// Right-click raises a thumbnail's mark, Shift+right-click lowers it.
export function enableMarking(
	ajaxUrl: string,
	nonce: string,
	container: HTMLElement,
	circle: () => string
): void {
	container.addEventListener('contextmenu', (e) => {
		const link =
			e.target instanceof Element
				? e.target.closest<HTMLElement>('a.avpvh-grid-a[data-avpvh-id]')
				: null;
		const active = circle();
		if (link === null || active === '') {
			return;
		}
		e.preventDefault();
		void changeMark(
			ajaxUrl,
			nonce,
			active,
			link.dataset['avpvhId'] ?? '',
			e.shiftKey ? -1 : 1
		).then((level) => {
			if (level !== null) {
				showLevel(link, level);
			}
		});
	});
}

// "Markeren voor: [circle]" — which circle right-clicks mark in.
export function buildCirclePicker(
	circles: Array<MarkCircle>,
	active: string,
	onChange: (circle: string) => void
): HTMLElement {
	const label = document.createElement('label');
	label.className = 'avpvh-mark-circle';
	label.title =
		'Rechtsklik op een foto: markering ophogen · Shift+rechtsklik: verlagen';
	label.appendChild(document.createTextNode('Markeren voor '));
	const select = document.createElement('select');
	circles.forEach((circle) => {
		const option = document.createElement('option');
		option.value = circle.key;
		option.textContent = circle.label;
		option.selected = circle.key === active;
		select.appendChild(option);
	});
	select.addEventListener('change', () => {
		setActiveCircle(select.value);
		onChange(select.value);
	});
	label.appendChild(select);
	return label;
}
