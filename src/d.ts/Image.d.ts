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
	rotation?: number;
	thumb_rotation?: number;
	thumb_h_flip?: boolean;
	thumb_v_flip?: boolean;
	light_rotation?: number;
	light_h_flip?: boolean;
	light_v_flip?: boolean;
	light_has_correction?: boolean;
	width: number;
}
