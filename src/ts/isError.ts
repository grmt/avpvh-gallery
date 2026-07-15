export function isError(
	data:
		| GalleryResponse
		| ListGalleryDirResponse
		| ListGdriveDirResponse
		| PageResponse
): data is ErrorResponse {
	return typeof data === 'object' && data !== null && 'error' in data;
}
