// Per-photo place (see Photo_Places on the PHP side): where a photo was
// taken when that isn't the place in its folder name, e.g. an excursion
// to a city during a dig.

export interface PhotoPlaceState {
	// The photo's own place, or '' when it follows its folder.
	place: string;
	// Who set it and when (empty when there is no own place).
	by: string;
	at: string;
	// Places entered on other photos, most used first.
	suggestions: Array<string>;
}

export async function fetchPhotoPlace(
	restUrl: string,
	fileId: string
): Promise<PhotoPlaceState> {
	const response = await fetch(
		`${restUrl}?file_id=${encodeURIComponent(fileId)}`,
		{ credentials: 'include' }
	);
	if (!response.ok) {
		throw new Error(`HTTP ${String(response.status)}`);
	}
	const data = (await response.json()) as Partial<PhotoPlaceState>;
	return {
		place: data.place ?? '',
		by: data.by ?? '',
		at: data.at ?? '',
		suggestions: data.suggestions ?? [],
	};
}

// An empty place removes the photo's own place (back to its folder's).
export async function savePhotoPlace(
	restUrl: string,
	nonce: string,
	fileId: string,
	place: string
): Promise<void> {
	const response = await fetch(restUrl, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce,
		},
		credentials: 'include',
		body: JSON.stringify({ file_id: fileId, place }),
	});
	if (!response.ok) {
		const error = (await response.json().catch(() => null)) as {
			message?: string;
		} | null;
		throw new Error(error?.message ?? `HTTP ${String(response.status)}`);
	}
}
