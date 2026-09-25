// Shared between the frontend lightbox and (later, if added there) the admin
// EXIF Inspector for the fixed-vocabulary "what's in this photo" checklist
// tags (rubriek "graven" — see Subject_Tags::TAGS on the PHP side). Both call
// the same REST route (GET/POST avpvh-gallery/v1/subject-tags).

export async function fetchSubjectTags(
	restUrl: string,
	fileId: string
): Promise<Array<string>> {
	const response = await fetch(
		`${restUrl}?file_id=${encodeURIComponent(fileId)}`,
		{ credentials: 'include' }
	);
	if (!response.ok) {
		throw new Error(`HTTP ${String(response.status)}`);
	}
	const data = (await response.json()) as { tags?: Array<string> };
	return data.tags ?? [];
}

export async function toggleSubjectTag(
	restUrl: string,
	nonce: string,
	fileId: string,
	tagSlug: string,
	active: boolean
): Promise<void> {
	const response = await fetch(restUrl, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce,
		},
		credentials: 'include',
		body: JSON.stringify({
			file_id: fileId,
			tag_slug: tagSlug,
			active,
		}),
	});
	if (!response.ok) {
		const error = (await response.json().catch(() => null)) as {
			message?: string;
		} | null;
		throw new Error(error?.message ?? `HTTP ${String(response.status)}`);
	}
}
