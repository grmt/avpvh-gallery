// Voting on photos in the grid with stars (see Photo_Marks on the PHP side):
// right-click a thumbnail to give it a star, Shift+right-click to take one
// of your own back. Everyone sees everyone's stars — your own in gold, the
// others' in grey — and a person and a photo can only get so many (the
// server says which limit was hit). Filter on "Gemarkeerd" to see the
// photos with at least so many stars. Logged-in users only.

export interface MarkTally {
	mine: number;
	others: number;
	// Who gave how many, e.g. "Annet 2, Lisette 1".
	voters: string;
}

async function fetchTallies(
	ajaxUrl: string,
	ids: Array<string>
): Promise<Record<string, MarkTally>> {
	const params = new URLSearchParams({
		action: 'gallery_marks',
		ids: ids.join(','),
	});
	try {
		const response = await fetch(`${ajaxUrl}?${params.toString()}`, {
			credentials: 'include',
		});
		const data = (await response.json()) as {
			data?: { marks?: Record<string, MarkTally> };
		};
		return data.data?.marks ?? {};
	} catch {
		return {};
	}
}

// The photo's new tally, or why the star couldn't be given or taken back.
async function changeMark(
	ajaxUrl: string,
	nonce: string,
	imageId: string,
	delta: number
): Promise<MarkTally | string> {
	try {
		const response = await fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'include',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({
				action: 'gallery_mark',
				image_id: imageId,
				delta: String(delta),
				_ajax_nonce: nonce,
			}).toString(),
		});
		const data = (await response.json()) as {
			success?: boolean;
			data?: { mark?: MarkTally; message?: string };
		};
		if (data.success === true && data.data?.mark !== undefined) {
			return data.data.mark;
		}
		return data.data?.message ?? 'Markeren is mislukt';
	} catch {
		return 'Markeren is mislukt';
	}
}

function stars(className: string, count: number): HTMLElement {
	const span = document.createElement('span');
	span.className = className;
	span.textContent = '★'.repeat(count);
	return span;
}

function showTally(link: HTMLElement, tally: MarkTally | undefined): void {
	let badge = link.querySelector<HTMLElement>(':scope > .avpvh-mark-badge');
	const total = (tally?.mine ?? 0) + (tally?.others ?? 0);
	if (tally === undefined || total <= 0) {
		badge?.remove();
		return;
	}
	if (badge === null) {
		badge = document.createElement('span');
		badge.className = 'avpvh-mark-badge';
		link.appendChild(badge);
	}
	badge.replaceChildren(
		stars('avpvh-mark-mine', tally.mine),
		stars('avpvh-mark-others', tally.others)
	);
	badge.title =
		`${String(total)} ster${total === 1 ? '' : 'ren'}` +
		(tally.mine > 0 ? ` (${String(tally.mine)} van jou)` : '') +
		`: ${tally.voters}`;
}

// Briefly shows why a right-click did nothing.
function showRefusal(link: HTMLElement, message: string): void {
	const note = document.createElement('span');
	note.className = 'avpvh-mark-refusal';
	note.textContent = message;
	link.appendChild(note);
	setTimeout(() => {
		note.remove();
	}, 2500);
}

function thumbnails(container: HTMLElement): Array<HTMLElement> {
	return Array.from(
		container.querySelectorAll<HTMLElement>('a.avpvh-grid-a[data-avpvh-id]')
	);
}

// Shows the stars on every thumbnail in the container.
export async function showMarks(
	ajaxUrl: string,
	container: HTMLElement
): Promise<void> {
	const links = thumbnails(container);
	const ids = links.map((link) => link.dataset['avpvhId'] ?? '');
	const tallies = ids.length === 0 ? {} : await fetchTallies(ajaxUrl, ids);
	links.forEach((link) => {
		showTally(link, tallies[link.dataset['avpvhId'] ?? '']);
	});
}

// Right-click gives a thumbnail a star, Shift+right-click takes one of
// your own back.
export function enableMarking(
	ajaxUrl: string,
	nonce: string,
	container: HTMLElement
): void {
	container.addEventListener('contextmenu', (e) => {
		const link =
			e.target instanceof Element
				? e.target.closest<HTMLElement>('a.avpvh-grid-a[data-avpvh-id]')
				: null;
		if (link === null) {
			return;
		}
		e.preventDefault();
		void changeMark(
			ajaxUrl,
			nonce,
			link.dataset['avpvhId'] ?? '',
			e.shiftKey ? -1 : 1
		).then((result) => {
			if (typeof result === 'string') {
				showRefusal(link, result);
			} else {
				showTally(link, result);
			}
		});
	});
}
