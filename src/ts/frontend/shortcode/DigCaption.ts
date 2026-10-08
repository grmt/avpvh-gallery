import type PhotoSwipe from 'photoswipe';

// "Jaar en opgraving" in the lightbox and slideshow: for photos from a dig
// (below 01-Opgravingen) the dig's folder name, e.g. "1987 Berismenil",
// shown in the photo's lower right corner as on shared copies (see
// Share_Caption and Share_Image). A button in the top bar switches it on
// and off; the choice is remembered in this browser.

const STORAGE_KEY = 'avpvh_dig_caption';

// Text height as a share of the photo's short side, and the distance from
// the right and bottom edges in text heights (as Share_Image).
const TEXT_SIZE = 0.035;
const RIGHT = 2.4;
const BOTTOM = 1.2;

function remembered(): boolean {
	try {
		return window.localStorage.getItem(STORAGE_KEY) === '1';
	} catch {
		return false;
	}
}

function remember(on: boolean): void {
	try {
		window.localStorage.setItem(STORAGE_KEY, on ? '1' : '0');
	} catch {
		// Not remembered; it still works for this visit.
	}
}

// Captions by folder ID, fetched once per folder.
const captions = new Map<string, Promise<string>>();

async function captionFor(ajaxUrl: string, folderId: string): Promise<string> {
	let caption = captions.get(folderId);
	if (caption === undefined) {
		const query = new URLSearchParams({
			action: 'gallery_dig_captions',
			folders: JSON.stringify([folderId]),
		});
		caption = fetch(`${ajaxUrl}?${query.toString()}`, {
			credentials: 'include',
		})
			.then(
				async (response) =>
					(await response.json()) as {
						success?: boolean;
						data?: Record<string, string>;
					}
			)
			.then((data) => data.data?.[folderId] ?? '')
			.catch(() => '');
		captions.set(folderId, caption);
	}
	return caption;
}

const ICON =
	'<svg class="pswp__icn" viewBox="0 0 32 32" width="32" height="32" aria-hidden="true">' +
	'<rect x="5" y="8" width="22" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="2"/>' +
	'<path d="M14 20h10" stroke="currentColor" stroke-width="2.5"/></svg>';

// Registers the toggle button and the caption itself; call on uiRegister.
export function registerDigCaption(pswp: PhotoSwipe, ajaxUrl: string): void {
	let enabled = remembered();
	let caption: HTMLElement | null = null;
	let button: HTMLElement | null = null;

	// Puts the caption at the lower right of the photo as shown (rotated,
	// zoomed), sized to it.
	const place = (): void => {
		if (caption === null) {
			return;
		}
		const photo = pswp.currSlide?.content.element;
		const root = pswp.element;
		if (
			!enabled ||
			caption.textContent === '' ||
			photo === undefined ||
			root === undefined
		) {
			caption.hidden = true;
			return;
		}
		const box = photo.getBoundingClientRect();
		const frame = root.getBoundingClientRect();
		const size = Math.max(12, Math.min(box.width, box.height) * TEXT_SIZE);
		caption.style.fontSize = `${String(size)}px`;
		caption.style.right = `${String(frame.right - box.right + size * RIGHT)}px`;
		caption.style.bottom = `${String(frame.bottom - box.bottom + size * BOTTOM)}px`;
		caption.hidden = false;
	};

	const update = (): void => {
		if (caption === null) {
			return;
		}
		caption.textContent = '';
		caption.hidden = true;
		const slide = pswp.currSlide?.data.element;
		const folderId =
			slide instanceof HTMLElement
				? (slide.dataset['avpvhFolderId'] ?? '')
				: '';
		if (!enabled || folderId === '') {
			return;
		}
		const index = pswp.currIndex;
		void captionFor(ajaxUrl, folderId).then((text) => {
			if (caption === null || pswp.currIndex !== index) {
				return;
			}
			caption.textContent = text;
			place();
			// Corrections are applied as the photo loads; place it again.
			window.setTimeout(place, 400);
		});
	};

	const showState = (): void => {
		button?.classList.toggle('avpvh-pswp-dig-on', enabled);
		button?.setAttribute('aria-pressed', enabled ? 'true' : 'false');
	};

	pswp.ui?.registerElement({
		name: 'avpvh-dig-toggle',
		title: 'Jaar en opgraving op de foto tonen',
		order: 14,
		isButton: true,
		html: ICON,
		onInit: (el) => {
			button = el;
			el.classList.add('avpvh-pswp-dig-toggle');
			showState();
		},
		onClick: () => {
			enabled = !enabled;
			remember(enabled);
			showState();
			update();
		},
	});
	pswp.ui?.registerElement({
		name: 'avpvh-dig',
		order: 9,
		isButton: false,
		appendTo: 'root',
		onInit: (el) => {
			caption = el;
			el.classList.add('avpvh-pswp-dig');
			el.hidden = true;
			pswp.on('change', update);
			pswp.on('resize', place);
			pswp.on('zoomPanUpdate', place);
			pswp.on('loadComplete', place);
			update();
		},
	});
}
