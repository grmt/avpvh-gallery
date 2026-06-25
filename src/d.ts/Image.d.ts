declare interface ImageExif {
	aperture?: number;
	exposure?: number;
	focal?: number;
	iso?: number;
	make?: string;
	model?: string;
	orientation?: number;
	time?: string;
}

declare interface Image {
	description: string;
	exif?: ImageExif;
	height: number;
	id: string;
	image: string;
	name: string;
	thumbnail: string;
	thumb_rotation?: number;
	light_rotation?: number;
	width: number;
}
