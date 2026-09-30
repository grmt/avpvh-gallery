declare interface SubDir {
	name: string;
	thumbnail: string | false;
}

declare interface Directory {
	// How to show the cover so it's upright (the grid correction of the
	// photo it comes from); null/absent when there's no cover.
	cover?: { rotation: number; h_flip: boolean; v_flip: boolean } | null;
	dircount?: number;
	id: string;
	imagecount?: number;
	mediacount?: number;
	name: string;
	subdirs?: SubDir[];
	thumbnail: string;
	videocount?: number;
}
