declare interface ImageExif {
	aperture?: number;
	exposure?: number;
	focal?: number;
	iso?: number;
	make?: string;
	model?: string;
	time?: string;
}

declare interface Image {
	description: string;
	exif: ImageExif;
	height: number;
	id: string;
	image: string;
	thumbnail: string;
	width: number;
}
