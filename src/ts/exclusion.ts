// Shared between the admin EXIF Inspector and the frontend lightbox, so
// excluding a photo behaves identically no matter which UI triggers it —
// only the surrounding interface differs. Both call the same REST route
// (GET/POST avpvh-gallery/v1/exif-inspector/exclusion), gated by
// Exclusion_Permission on the PHP side (admins, or "boek" group members).

export interface ExclusionState {
	excluded: boolean;
	reasons: Array<string>;
	note: string;
	// Hidden as a duplicate: the Drive ID and name of the photo it copies.
	duplicateOf: string;
	duplicateOfName: string;
}

export interface SaveExclusionParams {
	fileId: string;
	folderId: string;
	mimeType: string;
	excluded: boolean;
	reasons: Array<string>;
	note: string;
}

export async function fetchExclusion(
	restUrl: string,
	nonce: string,
	fileId: string
): Promise<ExclusionState> {
	const response = await fetch(
		`${restUrl}?file_id=${encodeURIComponent(fileId)}`,
		{
			headers: { 'X-WP-Nonce': nonce },
			credentials: 'include',
		}
	);
	if (!response.ok) {
		throw new Error(`HTTP ${String(response.status)}`);
	}
	const data = (await response.json()) as {
		excluded?: boolean;
		reasons?: Array<string>;
		note?: string;
		duplicate_of?: string;
		duplicate_of_name?: string;
	};
	return {
		excluded: data.excluded === true,
		reasons: data.reasons ?? [],
		note: data.note ?? '',
		duplicateOf: data.duplicate_of ?? '',
		duplicateOfName: data.duplicate_of_name ?? '',
	};
}

export async function saveExclusion(
	restUrl: string,
	nonce: string,
	params: SaveExclusionParams
): Promise<void> {
	const response = await fetch(restUrl, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce,
		},
		credentials: 'include',
		body: JSON.stringify({
			file_id: params.fileId,
			folder_id: params.folderId,
			mime_type: params.mimeType,
			excluded: params.excluded,
			reasons: params.reasons,
			note: params.note,
		}),
	});
	if (!response.ok) {
		const error = (await response.json().catch(() => null)) as {
			message?: string;
		} | null;
		throw new Error(error?.message ?? `HTTP ${String(response.status)}`);
	}
}
