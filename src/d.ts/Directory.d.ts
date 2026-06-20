declare interface SubDir {
	name: string;
	thumbnail: string | false;
}

declare interface Directory {
	dircount?: number;
	id: string;
	imagecount?: number;
	name: string;
	subdirs?: SubDir[];
	thumbnail: string;
	videocount?: number;
}
