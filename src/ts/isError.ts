export function isError(
	data:
		| GalleryResponse
		| ListGalleryDirResponse
		| ListGdriveDirResponse
		| PageResponse
): data is ErrorResponse {
	return typeof data === 'object' && 'error' in data;
}
