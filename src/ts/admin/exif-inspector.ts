import {
	getOrientationIconUrl,
	renderExifOrientationChain,
} from '../orientationVisualization';

interface FileData {
	id: string;
	name: string;
	thumbnailLink?: string;
	iconLink?: string;
	hasThumbnail?: boolean;
	webContentLink?: string;
	webViewLink?: string;
	mimeType?: string;
	md5Checksum?: string;
	modifiedTime?: string;
	size?: string;
	parents?: Array<string>;
	imageMediaMetadata?: {
		width: number;
		height: number;
		rotation: number;
		cameraMake?: string;
		cameraModel?: string;
		aperture?: number;
		exposureTime?: number;
		isoSpeed?: number;
		focalLength?: number;
		time?: string;
	};
	videoMediaMetadata?: {
		width?: number;
		height?: number;
		durationMillis?: number | string;
	};
	description?: string;
	createdTime?: string;
}

interface TimingData {
	networkTime: number;
	renderTime: number;
	fileSize?: number;
}

declare const avpvhExifInspector: {
	rest_url: string;
	root_id: string;
	nonce: string;
	grid_height: number;
	preview_size: number;
};

class ExifInspector {
	// Human-readable descriptions for common EXIF field keys (shown as tooltip on field name cell).
	private static readonly EXIF_DESCRIPTIONS: Record<string, string> = {
		// FILE section (PHP exif_read_data FILE block)
		FileName: 'Bestandsnaam op schijf',
		FileDateTime: 'Unix-tijdstempel van de laatste bestandswijziging',
		FileSize: 'Bestandsgrootte in bytes',
		FileType: 'Bestandstype-code (2=JPEG, 3=PNG, enz.)',
		MimeType: 'MIME-type van het bestand',
		SectionsFound: 'Welke EXIF-secties gevonden zijn in het bestand',
		// COMPUTED section
		html: 'HTML img-tag met breedte/hoogte attribuut (gegenereerd door PHP)',
		Height: 'Beeldhoogte in pixels (berekend)',
		Width: 'Beeldbreedte in pixels (berekend)',
		IsColor: '0=grijswaarden 1=kleur',
		ByteOrderMotorola: '1=big-endian (Motorola) 0=little-endian (Intel)',
		ApertureFNumber: 'Diafragma als f/getal (berekend uit ApertureValue)',
		UserComment: 'Opmerking van de gebruiker of camera-software',
		// IFD0 section
		Exif_IFD_Pointer:
			'Byte-offset naar het EXIF-subblok (intern, niet voor gebruikers)',
		GPS_IFD_Pointer: 'Byte-offset naar het GPS-blok (intern)',
		Orientation:
			'1=normaal 3=180° 6=90°CW 8=90°CCW — hoe de camera het beeld stuurde',
		ImageDescription: 'Omschrijving van de foto',
		Make: 'Cameramerk',
		Model: 'Cameramodel',
		Software: 'Software die het bestand aanmaakte of bewerkte',
		Artist: 'Naam van de fotograaf',
		Copyright: 'Auteursrechtmelding',
		DateTime: 'Datum/tijd laatste bestandswijziging (IFD0)',
		XResolution: 'Horizontale resolutie (dpi of ppcm)',
		YResolution: 'Verticale resolutie (dpi of ppcm)',
		ResolutionUnit: '2=inch 3=centimeter',
		// EXIF section
		DateTimeOriginal: 'Datum/tijd waarop de foto gemaakt is (cameraklok)',
		DateTimeDigitized: 'Datum/tijd van digitalisering',
		ExposureTime: 'Sluitertijd (bijv. 1/500 s)',
		FNumber: 'Diafragma (bijv. f/2.8)',
		ExposureProgram:
			'0=onbekend 1=handmatig 2=normaal 3=diafragmaprioriteit 4=sluitertijdprioriteit',
		ISOSpeedRatings: 'ISO-gevoeligheid',
		ExifVersion: 'EXIF-standaard versie',
		ComponentsConfiguration: 'Kleurcomponenten (1=Y 2=Cb 3=Cr 4=R 5=G 6=B)',
		CompressedBitsPerPixel: 'Compressieratio in bits per pixel',
		ShutterSpeedValue: 'Sluitertijdwaarde in APEX (log₂ van 1/t)',
		ApertureValue: 'Diafragmawaarde in APEX (log₂ van f²)',
		MaxApertureValue: 'Maximaal diafragma van de lens in APEX',
		ExposureBiasValue: 'Belichtingscorrectie in stops (EV)',
		MeteringMode: '0=onbekend 1=gemiddeld 2=centrumgewogen 3=spot 5=matrix',
		Flash: 'Of de flits is afgegaan en in welke modus',
		FocalLength: 'Brandpuntsafstand in mm',
		SubjectArea: 'Coördinaten van het hoofdonderwerp in het beeld',
		MakerNote: 'Fabrikantspecifieke data (onleesbaar voor anderen)',
		FlashPixVersion: 'FlashPix-versie',
		ColorSpace: '1=sRGB 65535=uncalibrated',
		PixelXDimension: 'Breedte van het beeld in pixels (EXIF)',
		PixelYDimension: 'Hoogte van het beeld in pixels (EXIF)',
		InteroperabilityIFDPointer:
			'Byte-offset naar interoperability-blok (intern)',
		ExposureMode: '0=auto 1=handmatig 2=auto bracket',
		WhiteBalance: '0=auto 1=handmatig',
		FocalLengthIn35mmFilm: 'Equivalent brandpuntsafstand voor 35mm-film',
		SceneCaptureType: '0=standaard 1=landschap 2=portret 3=nacht',
		LensSpecification:
			'Min/max brandpuntsafstand en min/max diafragma van de lens',
		LensMake: 'Lensmerk',
		LensModel: 'Lensmodel',
		// THUMBNAIL section
		JPEGInterchangeFormat:
			'Byte-offset naar de ingebedde JPEG-thumbnail (intern)',
		JPEGInterchangeFormatLength:
			'Lengte van de ingebedde JPEG-thumbnail in bytes',
		Compression: '1=ongecomprimeerd 6=JPEG (thumbnail)',
		// INTEROP section
		InteropIndex:
			'Interoperabiliteitsidentificatie (R98=standaard DCF, R03=DCF-optie)',
		InteropVersion: 'Interoperabiliteitsversie',
	};

	// Enum value → human-readable label.
	// Keys without section prefix apply to any section; keys with prefix (e.g. MAKERNOTE:Quality)
	// are manufacturer-specific and take precedence.
	private static readonly EXIF_ENUMS: Record<string, Record<string, string>> =
		{
			// ── Standard EXIF ────────────────────────────────────────────────────────
			ExposureProgram: {
				'0': 'Niet gedefinieerd',
				'1': 'Handmatig',
				'2': 'Normaal (auto)',
				'3': 'Diafragmavoorkeur (Av)',
				'4': 'Sluitertijdvoorkeur (Tv)',
				'5': 'Creatief',
				'6': 'Actie',
				'7': 'Portret',
				'8': 'Landschap',
			},
			MeteringMode: {
				'0': 'Onbekend',
				'1': 'Gemiddeld',
				'2': 'Centrum-gewogen',
				'3': 'Spot',
				'4': 'Multi-spot',
				'5': 'Matrixmeting',
				'6': 'Gedeeltelijk',
				'255': 'Andere',
			},
			WhiteBalance: { '0': 'Auto', '1': 'Handmatig' },
			ExposureMode: {
				'0': 'Auto',
				'1': 'Handmatig',
				'2': 'Auto-bracket',
			},
			SceneCaptureType: {
				'0': 'Standaard',
				'1': 'Landschap',
				'2': 'Portret',
				'3': 'Nachtscène',
			},
			ColorSpace: { '1': 'sRGB', '65535': 'Niet gekalibreerd' },
			ResolutionUnit: {
				'1': 'Geen absolute eenheid',
				'2': 'Inch',
				'3': 'Centimeter',
			},
			FocalPlaneResolutionUnit: {
				'1': 'Geen absolute eenheid',
				'2': 'Inch',
				'3': 'Centimeter',
				'4': 'Millimeter',
				'5': 'Micrometer',
			},
			Compression: { '1': 'Ongecomprimeerd', '6': 'JPEG' },
			LightSource: {
				'0': 'Auto',
				'1': 'Daglicht',
				'2': 'Fluorescent',
				'3': 'Kunstlicht (gloeilamp)',
				'4': 'Flash',
				'9': 'Bewolkt',
				'10': 'Schaduw',
				'11': 'Daglicht-fluorescent (D)',
				'12': 'Dagwit-fluorescent (N)',
				'13': 'Koel-wit-fluorescent (W)',
				'14': 'Wit-fluorescent (WW)',
				'17': 'Standaard licht A',
				'18': 'Standaard licht B',
				'19': 'Standaard licht C',
				'20': 'D55',
				'21': 'D65',
				'22': 'D75',
				'23': 'D50',
				'255': 'Andere',
			},
			Flash: {
				'0': 'Geen flash',
				'1': 'Flash',
				'5': 'Flash, geen terugkaatsing',
				'7': 'Flash, terugkaatsing gedetecteerd',
				'8': 'Flash uitgeschakeld',
				'9': 'Flash (dwang)',
				'16': 'Geen flash (auto)',
				'24': 'Geen flash (auto)',
				'25': 'Flash (auto)',
				'32': 'Geen flash-functie',
				'65': 'Flash + rode-ogenreductie',
				'89': 'Flash (auto) + rode-ogenreductie',
			},
			SensingMethod: {
				'1': 'Niet gedefinieerd',
				'2': 'Eén-chip kleur',
				'3': 'Twee-chip kleur',
				'4': 'Drie-chip kleur',
				'5': 'Kleursequentieel',
				'7': 'Drielineair',
				'8': 'Kleursequentieel lineair',
			},
			SubjectDistanceRange: {
				'0': 'Onbekend',
				'1': 'Macro',
				'2': 'Dichtbij',
				'3': 'Ver',
			},
			GainControl: {
				'0': 'Geen',
				'1': 'Lage versterking omhoog',
				'2': 'Hoge versterking omhoog',
				'3': 'Lage versterking omlaag',
				'4': 'Hoge versterking omlaag',
			},
			Contrast: { '0': 'Normaal', '1': 'Zacht', '2': 'Hard' },
			Saturation: { '0': 'Normaal', '1': 'Laag', '2': 'Hoog' },
			Sharpness: { '0': 'Normaal', '1': 'Zacht', '2': 'Hard' },
			// COMPUTED section
			IsColor: { '0': 'Zwart-wit', '1': 'Kleur' },
			ByteOrderMotorola: {
				'0': 'Little-endian (Intel)',
				'1': 'Big-endian (Motorola)',
			},
			FileType: {
				'2': 'JPEG',
				'3': 'PNG',
				'6': 'TIFF',
				'7': 'TIFF (BigTIFF)',
			},

			// ── Sony MakerNote (MAKERNOTE: prefix) ──────────────────────────────────
			'MAKERNOTE:Quality': {
				'0': 'RAW',
				'1': 'Super Fine',
				'2': 'Fine',
				'3': 'Standaard',
				'4': 'Economy',
				'5': 'Extra Fine',
				'6': 'RAW+JPEG',
				'7': 'Compressed RAW',
				'8': 'Compressed RAW+JPEG',
			},
			'MAKERNOTE:WhiteBalance': {
				'0': 'Auto',
				'4': 'Custom',
				'5': 'Daglicht',
				'6': 'Fluorescent',
				'7': 'Kunstlicht',
				'12': 'Bewolkt',
				'13': 'Schaduw',
				'14': 'Kleurtemperatuur / Filter',
			},
			'MAKERNOTE:WhiteBalance2': {
				'0': 'Auto',
				'4': 'Custom',
				'5': 'Daglicht',
				'6': 'Fluorescent',
				'7': 'Kunstlicht',
				'12': 'Bewolkt',
				'13': 'Schaduw',
				'14': 'Kleurtemperatuur / Filter',
			},
			'MAKERNOTE:SceneMode': {
				'0': 'Standaard',
				'1': 'Portret',
				'2': 'Tekst',
				'3': 'Nachtscène',
				'4': 'Zonsondergang',
				'5': 'Sport',
				'6': 'Landschap',
				'7': 'Nachtportret',
				'8': 'Macro',
				'9': 'Super Macro',
				'16': 'Auto',
				'17': 'Nacht / Portret',
				'18': 'Sweep Panorama',
				'19': 'Handgehouden nachtopname',
				'20': 'Anti-bewegingsonscherpte',
				'21': 'Continu prioriteit AE',
				'23': '3D Sweep Panorama',
				'26': 'Achtergrond onscherp',
				'27': 'Zachte huid',
				'33': 'Vuurwerk',
				'36': 'Huisdier',
				'40': 'Gerecht',
				'41': 'Baby',
				'43': 'Herfstbladeren',
				'54': 'Hoge gevoeligheid',
			},
			'MAKERNOTE:LongExposureNoiseReduction': { '0': 'Uit', '1': 'Aan' },
			'MAKERNOTE:HighISONoiseReduction': {
				'0': 'Normaal',
				'1': 'Laag',
				'2': 'Hoog',
				'3': 'Uit',
				'256': 'Auto',
			},
			'MAKERNOTE:HDR': {
				'0': 'Uit',
				'1': '1 EV',
				'2': '2 EV',
				'3': '3 EV',
				'4': 'Auto',
				'100': 'Automatische correctie',
			},
			'MAKERNOTE:DynamicRangeOptimizer': {
				'0': 'Uit',
				'1': 'Standaard',
				'2': 'Advanced Auto',
				'3': 'Advanced Lv.1',
				'4': 'Advanced Lv.2',
				'5': 'Advanced Lv.3',
				'6': 'Advanced Lv.4',
				'7': 'Advanced Lv.5',
				'8': 'Automatisch',
			},
			'MAKERNOTE:ZoneMatching': {
				'0': 'ISO-instelling',
				'1': 'High Key',
				'2': 'Low Key',
			},
			'MAKERNOTE:FaceDetection': { '0': 'Uit', '1': 'Aan' },
			'MAKERNOTE:AFTracking': { '0': 'Uit', '1': 'Aan' },
			'MAKERNOTE:AutoPortraitFramed': { '0': 'Nee', '1': 'Ja' },
			'MAKERNOTE:FocusMode2': { '0': 'AF', '4': 'MF', '65535': 'n.v.t.' },
			'MAKERNOTE:ReleaseMode': {
				'0': 'Normaal',
				'1': 'Continu',
				'2': 'Zelfontspanner',
				'5': 'Belichtingsbracketing',
				'6': 'Witbalansbracketing',
			},
			'MAKERNOTE:ReleaseMode2': {
				'0': 'Normaal',
				'1': 'Continu',
				'2': 'Zelfontspanner',
				'5': 'Belichtingsbracketing',
				'6': 'Witbalansbracketing',
				'8': 'Continu tot vol',
				'9': 'Continu – max. snelheid',
				'10': 'Continu – halve snelheid',
			},
			'MAKERNOTE:SelfTimer': {
				'0': 'Uit',
				'1': '10 seconden',
				'2': '2 seconden',
				'3': '10 sec. (3×)',
				'4': '10 sec. (5×)',
				'5': '2 sec. (3×)',
				'6': '2 sec. (5×)',
			},
			'MAKERNOTE:FileFormat': {
				'0': 'JPEG',
				'1': 'Ongecomprimeerd',
				'2': 'RAW',
				'3': 'TIFF',
			},
			'MAKERNOTE:ColorSpace': { '1': 'sRGB', '2': 'Adobe RGB' },

			// ── Canon MakerNote (MAKERNOTE: prefix) ──────────────────────────────────
			'MAKERNOTE:DaylightSavings': { '0': 'Uit', '1': 'Aan' },
		};

	private readonly rootId: string;
	private readonly restUrl: string;
	private readonly nonce: string;
	private currentFile: FileData | null = null;
	private allFiles: Array<FileData> = [];
	private displayFiles: Array<FileData> = [];
	private filterModel = '';
	private readonly filterOrientations = new Set<number>();
	private filterNotNormal = false;
	private exifOrientations = new Map<string, number>();
	private tableViewActive =
		localStorage.getItem('avpvh_exif_view') === 'table';
	private tableSort: { col: string; dir: 'asc' | 'desc' } = {
		col: '',
		dir: 'asc',
	};
	private orientationFetchEpoch = 0;
	private orientationFetchStartedEpoch = -1;
	private orientationRenderPending = false;
	private modelOptionsEpoch = 0;
	private currentFileIndex = -1;
	private previewTimings: Record<number, TimingData> = {};
	private fullExifData: Record<string, number | string> = {};
	private previewExifData: Record<string, Record<string, number | string>> =
		{};
	private transformsBySize: Record<
		string,
		{ r: number; h: boolean; v: boolean }
	> = {};
	private folderTransformsBySize: Record<
		string,
		{ r: number; h: boolean; v: boolean }
	> = {};
	private photoCorrectionsLoaded = false;
	private embeddedThumb: { src: string; w: number; h: number } | null = null;
	private readonly pendingCorrectionSaves = new Set<Promise<void>>();
	private excludedPhotoIds = new Set<string>();
	private folderStack: Array<{ id: string; name: string }> = [];
	private fullscreenViewerEl: HTMLDivElement | null = null;
	private fullscreenImageEl: HTMLImageElement | null = null;
	private fullscreenRotation = 0;
	private fullscreenFlipH = false;
	private fullscreenFlipV = false;
	private fullscreenZoom = 1;
	private fullscreenPanX = 0;
	private fullscreenPanY = 0;
	private fullscreenDragging = false;
	private fullscreenDragStartX = 0;
	private fullscreenDragStartY = 0;
	private fullscreenPanStartX = 0;
	private fullscreenPanStartY = 0;

	public constructor() {
		this.rootId = avpvhExifInspector.root_id;
		this.restUrl = avpvhExifInspector.rest_url;
		this.nonce = avpvhExifInspector.nonce;
		this.init();
	}

	// When the filter gives 0 results, fall back to all files so the user can still navigate.
	private get navFiles(): Array<FileData> {
		return this.displayFiles.length > 0 ? this.displayFiles : this.allFiles;
	}

	private static escapeHtml(text: string): string {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	// Widens an indexed-access result (Record/Array lookups, which TypeScript
	// treats as always-defined without `noUncheckedIndexedAccess`) back to an
	// honest `T | undefined` so callers keep the runtime-necessary null check.
	private static maybe<T>(value: T): T | undefined {
		return value;
	}

	private static identityTransform(t: {
		r: number;
		h: boolean;
		v: boolean;
	}): boolean {
		return t.r === 0 && !t.h && !t.v;
	}

	// Map Drive rotation degrees → approximate EXIF orientation (ignoring flip variants).
	private static driveRotToOrientation(deg: number): number {
		if (deg === 90) {
			return 6;
		}
		if (deg === 180) {
			return 3;
		}
		if (deg === 270) {
			return 8;
		}
		return 1;
	}

	private static orientLabel(o: number): string {
		const map: Record<number, string> = {
			1: 'Normaal',
			2: 'Mirror ↔',
			3: '180°',
			4: 'Flip ↕',
			5: 'Mirror+90CW',
			6: '90° CW',
			7: 'Mirror+90CCW',
			8: '90° CCW',
		};
		return map[o] ?? `(${String(o)})`;
	}

	private static clearCorrectionUiForVideo(): void {
		const summary = document.getElementById('photo-correction-summary');
		const reset = document.getElementById(
			'reset-photo-corrections'
		) as HTMLButtonElement | null;
		const exception = document.getElementById('photo-folder-exception');
		if (summary) {
			summary.textContent =
				'Oriëntatiecorrecties zijn niet van toepassing op video.';
		}
		if (reset) {
			reset.style.display = 'none';
		}
		if (exception) {
			exception.style.display = 'none';
		}
	}

	private static setExclusionDetailsVisible(visible: boolean): void {
		const details = document.getElementById('photo-exclusion-details');
		if (details) {
			details.style.display = visible ? '' : 'none';
		}
	}

	private static displayCorrectionsInTable(
		corrections: Record<string, { r: number; h: boolean; v: boolean }>
	): void {
		const tbody = document.querySelector('.exif-table tbody');
		if (!tbody) {
			return;
		}

		tbody.querySelectorAll('tr[data-correction-row]').forEach((r) => {
			r.remove();
		});

		const sizeLabel: Record<string, string> = {
			grid: 'Correctie grid thumbnail',
			lightbox: 'Correctie lightbox',
		};

		for (const [sizeKey, t] of Object.entries(corrections)) {
			if (t.r === 0 && !t.h && !t.v) {
				continue;
			}
			const row = document.createElement('tr');
			row.setAttribute('data-correction-row', '1');
			row.style.cssText = 'background: #fff3cd;';
			const th = document.createElement('td');
			th.style.fontWeight = 'bold';
			th.textContent = sizeLabel[sizeKey] ?? `Correctie ${sizeKey}`;
			const td = document.createElement('td');
			const parts: Array<string> = [];
			if (t.r !== 0) {
				parts.push(`${String(t.r)}° rechtsom`);
			}
			if (t.h) {
				parts.push('mirror ↔');
			}
			if (t.v) {
				parts.push('flip ↕');
			}
			td.textContent = parts.join(', ');
			row.appendChild(th);
			row.appendChild(td);
			tbody.appendChild(row);
		}
	}

	private static orientationChain(
		orientation: number,
		size = 14,
		portrait = false
	): string {
		return renderExifOrientationChain(orientation, portrait, size);
	}

	private static orientationSvg(
		orientation: number,
		cssTransform = '',
		color = '#333',
		size = 20
	): string {
		// SVG matrix transforms per EXIF orientation — letter F in a 20×20 box
		// Orientation 1 = normal; transforms match the standard EXIF orientation diagram
		const transforms: Record<number, string> = {
			1: '',
			2: 'matrix(-1,0,0,1,20,0)',
			3: 'matrix(-1,0,0,-1,20,20)',
			4: 'matrix(1,0,0,-1,0,20)',
			5: 'matrix(0,1,1,0,0,0)',
			6: 'matrix(0,1,-1,0,20,0)',
			7: 'matrix(0,-1,-1,0,20,20)',
			8: 'matrix(0,-1,1,0,0,20)',
		};
		const t = ExifInspector.maybe(transforms[orientation]);
		if (t === undefined) {
			return `<span style="font-size:16px">❓</span>`;
		}
		const g = t ? `<g transform="${t}">` : '<g>';
		const svgStyle = cssTransform
			? `display:inline-block;vertical-align:middle;margin-right:4px;transform:${cssTransform}`
			: 'display:inline-block;vertical-align:middle;margin-right:4px';
		const stroke = color === '#333' ? '#555' : color;
		return (
			`<svg width="${String(size)}" height="${String(size)}" viewBox="0 0 20 20" ` +
			`style="${svgStyle}" ` +
			`xmlns="http://www.w3.org/2000/svg">` +
			`<rect x="0.5" y="0.5" width="19" height="19" fill="none" stroke="${stroke}" stroke-width="1"/>` +
			g +
			`<rect x="3.5" y="2.5" width="3" height="15" fill="${color}"/>` +
			`<rect x="3.5" y="2.5" width="10" height="3" fill="${color}"/>` +
			`<rect x="3.5" y="9" width="6.5" height="3" fill="${color}"/>` +
			`</g></svg>`
		);
	}

	private static orientationDescription(rotation: number): string {
		// EXIF orientation tags (1-8) vs rotation degrees (0, 90, 180, 270)
		const descriptions: Record<number, string> = {
			// EXIF Orientation Tags
			1: 'Normal',
			2: 'Flipped (Horizontal)',
			3: 'Rotated 180°',
			4: 'Flipped (Vertical)',
			5: 'Rotated 90° CCW + Flipped',
			6: 'Rotated 90° CW',
			7: 'Rotated 90° CW + Flipped',
			8: 'Rotated 90° CCW',
			// Rotation Degrees (fallback)
			0: 'Normal',
			90: 'Rotated 90° CW',
			180: 'Rotated 180°',
			270: 'Rotated 90° CCW',
		};
		return descriptions[rotation] ?? `Unknown (${String(rotation)})`;
	}

	private static applyTransformToItem(
		item: HTMLElement,
		t: { r: number; h: boolean; v: boolean }
	): void {
		const img = item.querySelector<HTMLImageElement>(
			'.preview-image, .icon-image'
		);
		if (img) {
			const wrap = img.closest('.preview-image-wrap');
			const quarterTurn = t.r === 90 || t.r === 270;
			const layoutWidth = img.offsetWidth;
			const layoutHeight = img.offsetHeight;
			const transformedWidth = quarterTurn ? layoutHeight : layoutWidth;
			const transformedHeight = quarterTurn ? layoutWidth : layoutHeight;
			const fitScale =
				wrap !== null && transformedWidth > 0 && transformedHeight > 0
					? Math.min(
							1,
							wrap.clientWidth / transformedWidth,
							wrap.clientHeight / transformedHeight
						)
					: 1;
			const parts: Array<string> = [];
			if (t.h) {
				parts.push('scaleX(-1)');
			}
			if (t.v) {
				parts.push('scaleY(-1)');
			}
			if (t.r !== 0) {
				parts.push(`rotate(${String(t.r)}deg)`);
			}
			if (fitScale < 1) {
				parts.push(`scale(${String(fitScale)})`);
			}
			img.style.transform = parts.join(' ');
		}
		const badge = item.querySelector<HTMLElement>('.rotation-badge');
		if (badge) {
			const parts: Array<string> = [];
			if (t.r !== 0) {
				parts.push(`${String(t.r)}°`);
			}
			if (t.h) {
				parts.push('↔');
			}
			if (t.v) {
				parts.push('↕');
			}
			if (parts.length === 0) {
				badge.style.display = 'none';
				badge.textContent = '';
			} else {
				badge.style.display = '';
				badge.textContent = parts.join(' ');
			}
		}
	}

	private static transformDescription(t: {
		r: number;
		h: boolean;
		v: boolean;
	}): string {
		const parts: Array<string> = [];
		if (t.r !== 0) {
			parts.push(`${String(t.r)}° rechtsom`);
		}
		if (t.h) {
			parts.push('spiegel horizontaal');
		}
		if (t.v) {
			parts.push('spiegel verticaal');
		}
		return parts.length > 0 ? parts.join(', ') : 'geen bewerking';
	}

	private static previewSizeLabel(sizeKey: string): string {
		const item = document.querySelector<HTMLElement>(
			`.preview-item[data-size-key="${sizeKey}"]`
		);
		const text = item?.querySelector('h4')?.textContent?.trim();
		return text !== undefined && text !== '' ? text : sizeKey;
	}

	private static buildPreviewUrl(
		thumbnailLink: string,
		size: number
	): string {
		// Replace the size parameter in the thumbnail link
		// Thumbnail links end with =s256 or similar
		return thumbnailLink.replace(/=s\d+$/, `=s${String(size)}`);
	}

	private static buildIconUrl(iconLink: string, size: number): string {
		// Drive icon URLs look like https://drive-thirdparty.googleusercontent.com/16/type/<mime>
		// Swap the size segment after the host.
		return iconLink.replace(
			/googleusercontent\.com\/\d+\//,
			`googleusercontent.com/${String(size)}/`
		);
	}

	private static buildSortedPreviews(
		thumbLink: string
	): Array<{ key: string; label: string; url: string; sortPx: number }> {
		const ps = Number(avpvhExifInspector.preview_size);
		const gridH = Math.floor(1.25 * avpvhExifInspector.grid_height);
		const gridUrl = thumbLink.replace(/=s\d+$/, `=h${String(gridH)}`);

		const entries: Array<{
			key: string;
			label: string;
			url: string;
			sortPx: number;
		}> = [
			{
				key: 'grid',
				label: `Miniatuur (h${String(gridH)})`,
				url: gridUrl,
				sortPx: gridH,
			},
		];

		for (const size of [256, 512, 1024, 1920]) {
			const isLightbox = size === ps;
			entries.push({
				key: isLightbox ? 'lightbox' : `s${String(size)}`,
				label: isLightbox
					? `${String(size)}px (Lightbox)`
					: `${String(size)}px`,
				url: ExifInspector.buildPreviewUrl(thumbLink, size),
				sortPx: size,
			});
		}

		// Lightbox at a non-standard size — insert it
		if (![256, 512, 1024, 1920].includes(ps)) {
			entries.push({
				key: 'lightbox',
				label: `${String(ps)}px (Lightbox)`,
				url: ExifInspector.buildPreviewUrl(thumbLink, ps),
				sortPx: ps,
			});
		}

		entries.sort((a, b) => a.sortPx - b.sortPx);

		// Deduplicate by URL
		const seen = new Set<string>();
		return entries.filter((e) => {
			if (seen.has(e.url)) {
				return false;
			}
			seen.add(e.url);
			return true;
		});
	}

	private static showLoading(show: boolean): void {
		const loading = document.getElementById('loading');
		if (loading) {
			loading.style.display = show ? 'block' : 'none';
		}
	}

	private static showError(message: string): void {
		const pathSection = document.querySelector<HTMLElement>(
			'.path-input-section'
		);
		if (!pathSection) {
			return;
		}

		// Remove any existing error message
		ExifInspector.clearError();

		// Create and display error message
		const errorDiv = document.createElement('div');
		errorDiv.id = 'error-message';
		errorDiv.style.cssText = `
			margin-top: 10px;
			padding: 10px;
			background-color: #f8d7da;
			border: 1px solid #f5c6cb;
			border-radius: 4px;
			color: #721c24;
			font-size: 14px;
		`;
		errorDiv.textContent = message;
		pathSection.appendChild(errorDiv);
	}

	private static clearError(): void {
		const errorDiv = document.getElementById('error-message');
		if (errorDiv) {
			errorDiv.remove();
		}
	}

	private static showExifLoading(): void {
		const tbody = document.querySelector('.exif-table tbody');
		if (!tbody) {
			return;
		}
		// Reset to 2-column header while loading so the table doesn't look broken
		const table = document.querySelector<HTMLTableElement>('.exif-table');
		if (table) {
			let thead = table.querySelector('thead');
			if (!thead) {
				thead = table.createTHead();
			}
			thead.innerHTML = '<tr><th>Veld</th><th>Waarde</th></tr>';
		}
		tbody.innerHTML =
			'<tr><td colspan="2" style="color:#999;font-style:italic">EXIF laden…</td></tr>';
	}

	private static exifFieldTitle(key: string): string {
		const shortKey = key.includes(':')
			? key.slice(key.indexOf(':') + 1)
			: key;
		const desc = ExifInspector.maybe(
			ExifInspector.EXIF_DESCRIPTIONS[shortKey]
		);
		return desc !== undefined ? `${shortKey}: ${desc}` : shortKey;
	}

	private init(): void {
		const root = document.getElementById('avpvh-exif-inspector-root');
		if (!root) {
			return;
		}

		const lastPath =
			localStorage.getItem('avpvh_exif_inspector_last_path') ?? '';

		root.innerHTML = `
			<div class="avpvh-exif-inspector">
				<details class="inspector-work-section inspector-navigation-section" open>
					<summary class="inspector-work-heading">
						<span class="inspector-work-number">1</span>
					<div><h2>Navigeren en selecteren</h2><p>Zoek een foto of video, of laad deze via het bestandspad.</p></div>
					</summary>
				<div class="search-section">
					<label>Zoeken:
						<div class="search-input-wrap">
							<input type="text" id="search-input" placeholder="Typ naam (min. 3 tekens)…" autocomplete="off" />
							<ul id="search-results" class="search-results" style="display:none;"></ul>
						</div>
					</label>
					<label style="font-size:12px;margin-left:10px;white-space:nowrap;">
						<input type="checkbox" id="search-folders-only" />
						Alleen mappen
					</label>
				</div>

				<div class="path-input-section">
					<label>Bestandspad:
						<input type="text" id="path-input" placeholder="e.g., 01-Opgravingen / 1976 Grobbendonk / PICT0250.JPG" value="${ExifInspector.escapeHtml(lastPath)}" />
					</label>
					<button id="load-btn" type="button">Laden</button>
				</div>

				<div id="loading" style="display: none;">Laden...</div>

				<div id="navigation-results" style="display: none;">
					<div id="folder-breadcrumb" style="display:none;font-size:12px;color:#555;margin-bottom:6px;padding:4px 8px;background:#f5f5f5;border-radius:4px;border-left:3px solid #aaa;"></div>
					<div id="filter-bar" style="display:none;margin-bottom:6px;padding:4px 8px;background:#fff8e1;border-radius:4px;border-left:3px solid #f0ad4e;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
						<label style="font-size:12px;display:flex;align-items:center;gap:4px;">
							Model:
							<select id="filter-model" disabled style="font-size:12px;padding:2px 6px;border:1px solid #ccc;border-radius:3px;max-width:190px;width:190px;"><option value="">Modellen laden…</option></select>
						</label>
						<label style="display:flex;align-items:center;gap:4px;" title="Originele EXIF-oriëntatie of Drive-rotatie is niet de standaardwaarde 1"><input id="filter-not-normal" type="checkbox" /><span style="font-size:10px;line-height:1.05;">Afwijkende<br />oriëntatie</span></label>
						<span style="display:inline-block;width:1px;height:14px;background:#ccc;margin:0 2px;"></span>
						<span style="font-size:12px;font-weight:500;color:#666;">Rotatie:</span>
						<label style="font-size:12px;display:flex;align-items:center;gap:2px;" title="90° rechts / kloksgewijs"><input type="checkbox" class="filter-orient-cb" value="6" />${ExifInspector.orientationChain(6)}<span style="font-size:11px;color:#555;">90 CW</span></label>
						<label style="font-size:12px;display:flex;align-items:center;gap:2px;" title="90° links / tegen kloksgewijs"><input type="checkbox" class="filter-orient-cb" value="8" />${ExifInspector.orientationChain(8)}<span style="font-size:11px;color:#555;">90 CCW</span></label>
						<label style="font-size:12px;display:flex;align-items:center;gap:2px;" title="180° / ondersteboven"><input type="checkbox" class="filter-orient-cb" value="3" />${ExifInspector.orientationChain(3)}<span style="font-size:11px;color:#555;">180</span></label>
						<span style="display:inline-block;width:1px;height:14px;background:#ccc;margin:0 2px;"></span>
						<span style="font-size:12px;font-weight:500;color:#666;">Spiegel:</span>
						<label style="font-size:12px;display:flex;align-items:center;gap:2px;" title="Gespiegeld horizontaal / links-rechts"><input type="checkbox" class="filter-orient-cb" value="2" />${ExifInspector.orientationChain(2)}<span style="font-size:11px;color:#555;">mirror</span></label>
						<label style="font-size:12px;display:flex;align-items:center;gap:2px;" title="Gespiegeld verticaal / boven-onder"><input type="checkbox" class="filter-orient-cb" value="4" />${ExifInspector.orientationChain(4)}<span style="font-size:11px;color:#555;">flip</span></label>
						<span id="orientation-progress" style="font-size:11px;color:#aaa;"></span>
						<span id="filter-count" style="font-size:12px;color:#888;margin-left:auto;"></span>
						<span id="view-toggle" style="display:flex;gap:2px;">
							<button id="view-table-btn" type="button" title="Tabelweergave" style="font-size:12px;padding:1px 7px;border:1px solid #ccc;border-radius:3px 0 0 3px;cursor:pointer;background:#fff;">☰</button>
							<button id="view-thumbs-btn" type="button" title="Miniatuurweergave" style="font-size:12px;padding:1px 7px;border:1px solid #ccc;border-left:none;border-radius:0 3px 3px 0;cursor:pointer;background:#fff;">⊞</button>
						</span>
					</div>
					<div id="file-table-container" style="display:none;"></div>
					<div id="folder-thumbs" style="display:none;"></div>
					<div class="file-header">
						<h2 id="file-name" role="button" tabindex="0" title="Toon het actieve bestand in de iconenlijst"></h2>
						<div class="file-nav">
							<button id="prev-btn" disabled>&larr; Vorige</button>
							<span id="file-count"></span>
							<button id="next-btn" disabled>Volgende &rarr;</button>
							<button id="resume-slideshow-btn" type="button" disabled>Verder met diavoorstelling</button>
						</div>
						<label id="photo-folder-exception" class="photo-folder-exception" style="display:none;">
							<input id="photo-folder-exception-checkbox" type="checkbox" />
							Mapspiegeling negeren voor deze foto
						</label>
						<div id="photo-exclusion-panel" class="photo-exclusion-panel">
							<label class="photo-exclusion-toggle">
								<input id="photo-excluded-checkbox" type="checkbox" />
								<strong id="media-exclusion-label">Dit bestand uitsluiten van gallery en diavoorstelling</strong>
							</label>
							<div id="photo-exclusion-details" style="display:none;">
								<div class="photo-exclusion-reasons">
									<label><input type="checkbox" name="photo-exclusion-reason" value="poor_quality" /> Slechte kwaliteit</label>
									<label><input type="checkbox" name="photo-exclusion-reason" value="duplicate" /> Dubbel</label>
									<label><input type="checkbox" name="photo-exclusion-reason" value="privacy_objection" /> Bezwaar van afgebeelde personen</label>
									<label><input type="checkbox" name="photo-exclusion-reason" value="children" /> Kinderen</label>
									<label><input type="checkbox" name="photo-exclusion-reason" value="other" /> Anders</label>
								</div>
								<label class="photo-exclusion-note">Toelichting (alleen zichtbaar voor beheerders)
									<textarea id="photo-exclusion-note" rows="2" maxlength="1000"></textarea>
								</label>
							</div>
							<div class="photo-exclusion-actions">
								<button id="save-photo-exclusion" type="button">Wijziging opslaan</button>
								<span id="photo-exclusion-status"></span>
							</div>
						</div>
					</div>
					<div id="folder-correction-bar" class="folder-correction-bar" style="display:none;">
						<button id="folder-mirror-btn" type="button">Hele map spiegelen</button>
						<span id="folder-correction-status">Nieuwe foto's erven deze correctie automatisch.</span>
					</div>
				</div>
				</details>

				<div class="file-info-section" style="display: none;">
					<details class="inspector-work-section" open>
						<summary class="inspector-work-heading">
							<span class="inspector-work-number">2</span>
							<div><h2>Formaten vergelijken</h2><p>Bekijk het origineel, de voorbeeldformaten en miniaturen.</p></div>
						</summary>
						<div class="original-actions">
							<a id="original-download-link" target="_blank" class="download-link" style="display:none;font-size:13px;">Origineel downloaden</a>
							<button id="original-fullscreen-btn" type="button" class="fullscreen-btn" style="display:none;font-size:13px;">Volledig scherm (1920px)</button>
							<p id="original-size"></p>
						</div>
						<div class="previews-section">
							<h3>Voorbeeldformaten</h3>
							<div class="photo-correction-overview">
								<div id="photo-correction-summary"></div>
								<button id="reset-photo-corrections" type="button" style="display:none;">Individuele correcties ongedaan maken</button>
							</div>
							<div id="previews-container" class="previews-container"></div>
						</div>

						<div class="icons-section">
							<h3>Miniaturen (s64 / s64-c)</h3>
							<div id="icons-container" class="previews-container"></div>
						</div>
					</details>

					<details class="inspector-work-section" open>
						<summary class="inspector-work-heading">
							<span class="inspector-work-number">3</span>
							<div><h2>Bestandsmetadata en EXIF</h2><p>Bekijk Drive-metadata en, voor foto's, EXIF van het origineel en afgeleide formaten.</p></div>
						</summary>
						<div class="exif-section">
							<table id="exif-table" class="exif-table">
								<tbody></tbody>
							</table>
						</div>
					</details>
				</div>

				<style>
					.avpvh-exif-inspector {
						padding: 20px;
						max-width: 1600px;
					}

					.inspector-work-section {
						margin: 0 0 22px;
						padding: 18px;
						background: #fff;
						border: 1px solid #dcdcde;
						border-radius: 6px;
						box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
					}

					.inspector-work-heading {
						display: flex;
						align-items: center;
						gap: 12px;
						position: relative;
						padding-right: 30px;
						cursor: pointer;
						list-style: none;
					}

					.inspector-work-section[open] > .inspector-work-heading {
						margin-bottom: 16px;
					}

					.inspector-work-heading::-webkit-details-marker {
						display: none;
					}

					.inspector-work-heading::after {
						content: '';
						position: absolute;
						right: 5px;
						width: 8px;
						height: 8px;
						border-right: 2px solid #50575e;
						border-bottom: 2px solid #50575e;
						transform: rotate(45deg);
						transition: transform 120ms ease;
					}

					.inspector-work-section:not([open]) > .inspector-work-heading::after {
						transform: rotate(-45deg);
					}

					.inspector-work-heading:focus-visible {
						outline: 2px solid #2271b1;
						outline-offset: 4px;
					}

					.folder-correction-bar {
						display: flex;
						align-items: center;
						gap: 10px;
						margin: 8px 0;
						padding: 8px;
						background: #eef6fc;
						border-left: 3px solid #2271b1;
					}

					.folder-correction-bar.is-active {
						background: #fff8e1;
						border-left-color: #dba617;
					}

					.folder-correction-bar span,
					.photo-folder-exception {
						font-size: 12px;
					}

					.photo-exclusion-panel {
						background: #fff5f5;
						border-left: 3px solid #d63638;
						margin-top: 10px;
						padding: 9px 11px;
					}

					.photo-exclusion-toggle,
					.photo-exclusion-reasons label,
					.photo-exclusion-note {
						display: flex;
						gap: 6px;
					}

					.photo-exclusion-reasons {
						display: flex;
						flex-wrap: wrap;
						gap: 7px 16px;
						margin: 9px 0;
					}

					.photo-exclusion-note {
						flex-direction: column;
						font-size: 12px;
					}

					.photo-exclusion-note textarea {
						max-width: 700px;
						width: 100%;
					}

					.photo-exclusion-actions {
						align-items: center;
						display: flex;
						gap: 9px;
						margin-top: 8px;
					}

					#photo-exclusion-status {
						font-size: 12px;
					}

					.photo-folder-exception {
						align-items: center;
						gap: 5px;
						margin-top: 10px;
					}

					.inspector-work-number {
						display: inline-flex;
						align-items: center;
						justify-content: center;
						width: 30px;
						height: 30px;
						border-radius: 50%;
						background: #1d2327;
						color: #fff;
						font-size: 14px;
						font-weight: 600;
						flex: 0 0 auto;
					}

					.inspector-work-heading h2,
					.inspector-work-heading p {
						margin: 0;
					}

					.inspector-work-heading h2 {
						font-size: 18px;
						line-height: 1.35;
					}

					.inspector-work-heading p {
						color: #646970;
						font-size: 12px;
					}

					.search-section {
						margin-bottom: 16px;
					}

					.search-input-wrap {
						position: relative;
						display: inline-block;
						width: 100%;
						max-width: 500px;
					}

					.search-input-wrap input {
						width: 100%;
						padding: 8px;
						font-size: 14px;
						box-sizing: border-box;
					}

					.search-results {
						position: absolute;
						top: 100%;
						left: 0;
						right: 0;
						background: #fff;
						border: 1px solid #ccc;
						border-top: none;
						list-style: none;
						margin: 0;
						padding: 0;
						z-index: 100;
						max-height: 240px;
						overflow-y: auto;
						box-shadow: 0 4px 8px rgba(0,0,0,.15);
					}

					.search-result-item {
						padding: 8px 12px;
						cursor: pointer;
						font-size: 14px;
						border-bottom: 1px solid #f0f0f0;
					}

					.search-result-folder {
						background: #f0f6ff;
						border-bottom: 2px solid #c8d8f0;
						padding: 8px 12px;
					}

					.search-result-separator {
						padding: 4px 12px;
						font-size: 10px;
						text-transform: uppercase;
						letter-spacing: 0.5px;
						color: #999;
						background: #fafafa;
						border-bottom: 1px solid #eee;
						cursor: default;
					}

					.search-result-item:hover, .search-result-folder:hover,
					.search-result-active {
						background: #0073aa;
						color: #fff;
					}

					.search-result-empty {
						padding: 8px 12px;
						font-size: 13px;
						color: #888;
					}

					.path-input-section {
						margin-bottom: 20px;
					}

					.path-input-section input {
						width: 100%;
						max-width: 500px;
						padding: 8px;
						font-size: 14px;
					}

					.path-input-section button {
						padding: 8px 16px;
						margin-left: 10px;
						cursor: pointer;
					}

					#folder-thumbs {
						display: flex;
						flex-wrap: wrap;
						gap: 6px;
						padding: 8px 0 12px;
						border-bottom: 2px solid #ddd;
						margin-bottom: 12px;
						max-height: 180px;
						overflow-y: auto;
					}
					#folder-thumbs .file-thumb {
						position: relative;
						width: 64px;
						height: 64px;
						padding: 2px;
						cursor: pointer;
						border: 2px solid transparent;
						border-radius: 4px;
						background: #f6f7f7;
						opacity: 0.85;
						transition: opacity 0.1s, border-color 0.1s;
					}
					#folder-thumbs .file-thumb img {
						display: block;
						width: 100%;
						height: 100%;
						object-fit: cover;
					}
					#folder-thumbs .file-thumb img.fallback-icon {
						object-fit: contain;
						padding: 7px;
						box-sizing: border-box;
					}
					#folder-thumbs .file-thumb:hover { opacity: 1; border-color: #2271b1; }
					#folder-thumbs .file-thumb.selected { border-color: #2271b1; opacity: 1; box-shadow: 0 0 0 1px #2271b1; }
					#folder-thumbs .file-thumb.photo-excluded::before,
					#folder-thumbs .file-thumb.photo-excluded::after {
						background: #d63638;
						content: '';
						height: 4px;
						left: 5px;
						position: absolute;
						top: 28px;
						width: 50px;
						z-index: 2;
					}
					#folder-thumbs .file-thumb.photo-excluded::before { transform: rotate(45deg); }
					#folder-thumbs .file-thumb.photo-excluded::after { transform: rotate(-45deg); }
					.file-thumb-media-badge {
						align-items: center;
						background: rgba(0, 0, 0, 0.76);
						border-radius: 50%;
						bottom: 3px;
						color: #fff;
						display: inline-flex;
						font-size: 10px;
						height: 18px;
						justify-content: center;
						position: absolute;
						right: 3px;
						width: 18px;
						z-index: 1;
					}
					.file-name-media-badge {
						align-items: center;
						background: #1d2327;
						border-radius: 50%;
						color: #fff;
						display: inline-flex;
						font-size: 13px;
						height: 28px;
						justify-content: center;
						margin-right: 7px;
						width: 28px;
					}

					.file-list-table {
						width: 100%;
						border-collapse: collapse;
						font-size: 13px;
						margin-bottom: 12px;
					}
					.file-list-table th {
						background: #f5f5f5;
						border: 1px solid #ddd;
						padding: 5px 8px;
						text-align: left;
						white-space: nowrap;
					}
					.file-list-table th:hover { background: #e8e8e8; }
					.file-list-table td {
						border: 1px solid #eee;
						padding: 4px 8px;
					}
					.file-list-table .file-table-row {
						cursor: pointer;
					}
					.file-list-table .file-table-row:hover td { background: #f0f7ff; }
					.file-list-table .file-table-selected td {
						background: #ddeeff;
						font-weight: 500;
					}
					#view-table-btn.active, #view-thumbs-btn.active {
						background: #0073aa;
						color: #fff;
						border-color: #0073aa;
					}

					.file-header {
						margin-bottom: 20px;
						border-bottom: 2px solid #ddd;
						padding-bottom: 15px;
					}

					.file-header h2 {
						cursor: pointer;
						margin: 0 0 10px 0;
						display: flex;
						align-items: center;
						gap: 8px;
					}

					.file-header h2:focus-visible {
						outline: 2px solid #2271b1;
						outline-offset: 3px;
					}

					.file-nav {
						display: flex;
						gap: 10px;
						align-items: center;
					}

					.file-nav button {
						padding: 6px 12px;
						cursor: pointer;
					}

					.file-nav button:disabled {
						opacity: 0.5;
						cursor: not-allowed;
					}

					.exif-section {
						margin-bottom: 30px;
					}

					.exif-table {
						width: 100%;
						border-collapse: collapse;
						margin-top: 10px;
					}

					.exif-table thead th {
						background: #f0f0f0;
						position: sticky;
						top: 0;
						z-index: 1;
						white-space: nowrap;
					}

					.exif-table {
						table-layout: auto;
					}

					.exif-table th,
					.exif-table td {
						border: 1px solid #ddd;
						padding: 8px 12px;
						text-align: left;
					}

					.exif-table th {
						background-color: #f5f5f5;
						font-weight: bold;
					}

					.exif-table tr:nth-child(even) {
						background-color: #fafafa;
					}

					.exif-section h3,
					.previews-section h3,
					.icons-section h3 {
						margin-top: 0;
						margin-bottom: 15px;
						font-size: 16px;
						color: #333;
					}

					.icons-section {
						margin-top: 30px;
					}

					.icon-image {
						display: block;
						margin: 0 auto 10px;
						image-rendering: pixelated;
					}

					.icon-url {
						font-family: monospace;
						font-size: 10px;
						word-break: break-all;
						color: #555;
						margin-top: 6px;
						padding: 4px;
						background-color: #fff;
						border: 1px solid #eee;
						border-radius: 2px;
					}

.download-link {
						display: inline-block;
						padding: 10px 20px;
						background-color: #0073aa;
						color: white;
						text-decoration: none;
						border-radius: 4px;
						font-weight: 500;
						margin-bottom: 15px;
						cursor: pointer;
					}

					.download-link:hover {
						background-color: #005a87;
					}

					.original-actions {
						display: flex;
						align-items: center;
						gap: 10px;
						margin-bottom: 16px;
					}

					.original-actions .download-link {
						margin-bottom: 0;
					}

					.fullscreen-btn {
						display: inline-block;
						padding: 10px 20px;
						background-color: #fff;
						color: #0073aa;
						border: 1px solid #0073aa;
						border-radius: 4px;
						font-weight: 500;
						margin-bottom: 15px;
						cursor: pointer;
					}

					.fullscreen-btn:hover {
						background-color: #f0f6fa;
					}

					.original-actions .fullscreen-btn {
						margin-bottom: 0;
					}

					.inspector-fullscreen-viewer {
						position: fixed;
						top: 0;
						left: 0;
						width: 1px;
						height: 1px;
						overflow: hidden;
						opacity: 0;
						pointer-events: none;
						background: #000;
					}

					.inspector-fullscreen-viewer:fullscreen {
						display: flex;
						align-items: center;
						justify-content: center;
						width: 100%;
						height: 100%;
						opacity: 1;
						pointer-events: auto;
					}

					.inspector-fullscreen-image {
						max-width: 100%;
						max-height: 100%;
						cursor: grab;
						touch-action: none;
					}

					.inspector-fullscreen-toolbar {
						position: absolute;
						bottom: 24px;
						left: 50%;
						display: flex;
						gap: 8px;
						padding: 8px 12px;
						background: rgba(0, 0, 0, 0.6);
						border-radius: 8px;
						transform: translateX(-50%);
					}

					.inspector-fullscreen-toolbar button {
						padding: 6px 12px;
						font-size: 16px;
						color: #fff;
						cursor: pointer;
						background: rgba(255, 255, 255, 0.15);
						border: none;
						border-radius: 4px;
					}

					.inspector-fullscreen-toolbar button:hover {
						background: rgba(255, 255, 255, 0.3);
					}

					#original-size {
						margin: 0;
						font-size: 12px;
						color: #666;
					}

					.previews-container {
						display: grid;
						grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
						gap: 20px;
					}

					.preview-item {
						border: 1px solid #ddd;
						border-radius: 4px;
						padding: 15px;
						background-color: #fafafa;
					}

					.video-player-item {
						grid-column: 1 / -1;
					}

					.inspector-video-player {
						background: #111;
						display: block;
						max-height: min(70vh, 720px);
						width: 100%;
					}

					.preview-item h4 {
						margin: 0 0 10px 0;
						font-size: 14px;
						color: #333;
					}

					.preview-image-wrap {
						position: relative;
						display: flex;
						align-items: center;
						justify-content: center;
						height: 300px;
						overflow: hidden;
						width: 100%;
						margin-bottom: 6px;
					}

					.preview-image {
						max-width: 100%;
						max-height: 100%;
						height: auto;
						width: auto;
						border-radius: 4px;
						background-color: #fff;
						display: block;
						transition: transform 0.2s ease;
					}

					.preview-image.loading {
						opacity: 0.5;
					}

					.inspector-clickable-preview,
					.icon-image.inspector-clickable-preview {
						cursor: zoom-in;
					}

					.rotate-btn, .hflip-btn, .vflip-btn, .apply-all-btn, .reset-variant-btn {
						position: absolute;
						background: rgba(0,0,0,.55);
						color: #fff;
						border: none;
						border-radius: 3px;
						width: 28px;
						height: 28px;
						font-size: 18px;
						line-height: 1;
						cursor: pointer;
						opacity: 0;
						transition: opacity .15s;
						padding: 0;
					}

					.rotate-btn   { top: 4px; right: 4px; }
					.hflip-btn    { top: 4px; left: 4px; }
					.vflip-btn    { top: 50%; left: 50%; transform: translate(-50%,-50%); }
					.apply-all-btn {
						bottom: 4px; left: 50%; transform: translateX(-50%);
						width: auto; padding: 0 6px; font-size: 11px; border-radius: 10px;
						white-space: nowrap;
					}
					.reset-variant-btn {
						bottom: 4px; left: 4px; width: auto; padding: 0 6px;
						font-size: 11px; white-space: nowrap;
					}

					.preview-image-wrap:hover .rotate-btn,
					.preview-image-wrap:hover .hflip-btn,
					.preview-image-wrap:hover .vflip-btn,
					.preview-image-wrap:hover .apply-all-btn,
					.preview-image-wrap:hover .reset-variant-btn {
						opacity: 1;
					}

					.photo-correction-overview {
						align-items: center;
						background: #f0f6fc;
						border-left: 3px solid #2271b1;
						display: flex;
						gap: 12px;
						justify-content: space-between;
						margin-bottom: 12px;
						padding: 8px 10px;
					}

					#photo-correction-summary {
						font-size: 12px;
						line-height: 1.5;
					}

					.correction-scope {
						color: #50575e;
						font-size: 11px;
						margin-bottom: 6px;
						min-height: 17px;
					}

					.rotation-badge {
						position: absolute;
						bottom: 4px;
						right: 4px;
						background: rgba(0,0,0,.6);
						color: #fff;
						font-size: 11px;
						padding: 1px 5px;
						border-radius: 3px;
						pointer-events: none;
					}

					.rendered-dims {
						font-size: 11px;
						color: #555;
						margin-top: 3px;
					}

					.rendered-dims.dims-mismatch {
						color: #b00;
						font-weight: bold;
					}

					.timing-info {
						font-size: 12px;
						color: #666;
						margin-top: 10px;
						line-height: 1.4;
					}

					.timing-row {
						display: flex;
						justify-content: space-between;
						padding: 4px 0;
					}

					.timing-label {
						font-weight: 500;
					}

					.timing-value {
						color: #333;
						font-family: monospace;
					}

					.error-message {
						color: #d32f2f;
						font-size: 12px;
						margin-top: 10px;
					}

					#loading {
						padding: 20px;
						text-align: center;
						font-size: 16px;
						color: #666;
					}

					.corrections-section {
						margin-top: 30px;
						margin-bottom: 30px;
					}

					.corrections-section h3 {
						margin-top: 0;
						margin-bottom: 15px;
						font-size: 16px;
						color: #333;
					}

					.corrections-table {
						border-collapse: collapse;
						margin-top: 10px;
					}

					.corrections-table th,
					.corrections-table td {
						border: 1px solid #ddd;
						padding: 8px 12px;
						text-align: left;
					}

					.corrections-table th {
						background-color: #f5f5f5;
						font-weight: bold;
					}

					#correction-status {
						margin-top: 10px;
						font-size: 13px;
						color: #0073aa;
					}
				</style>
			</div>
		`;

		document.getElementById('load-btn')?.addEventListener('click', () => {
			void this.loadFile();
		});
		document.getElementById('prev-btn')?.addEventListener('click', () => {
			this.previousFile();
		});
		document.getElementById('next-btn')?.addEventListener('click', () => {
			this.nextFile();
		});
		const syncActiveThumb = (): void => {
			this.updateThumbSelection(true);
		};
		const fileName = document.getElementById('file-name');
		fileName?.addEventListener('click', syncActiveThumb);
		fileName?.addEventListener('keydown', (event) => {
			if (event.key === 'Enter' || event.key === ' ') {
				event.preventDefault();
				syncActiveThumb();
			}
		});
		document
			.getElementById('resume-slideshow-btn')
			?.addEventListener('click', () => {
				void this.resumeSlideshow();
			});
		document
			.getElementById('folder-mirror-btn')
			?.addEventListener('click', () => {
				void this.toggleFolderMirror();
			});
		document
			.getElementById('photo-folder-exception-checkbox')
			?.addEventListener('change', (event) => {
				const checkbox = event.currentTarget as HTMLInputElement;
				void this.setPhotoFolderException(checkbox.checked);
			});
		document
			.getElementById('reset-photo-corrections')
			?.addEventListener('click', () => {
				void this.resetPhotoCorrections();
			});
		document
			.getElementById('photo-excluded-checkbox')
			?.addEventListener('change', (event) => {
				const checked = (event.currentTarget as HTMLInputElement)
					.checked;
				ExifInspector.setExclusionDetailsVisible(checked);
			});
		document
			.getElementById('save-photo-exclusion')
			?.addEventListener('click', () => {
				void this.savePhotoExclusion();
			});

		const pathInput = document.getElementById(
			'path-input'
		) as HTMLInputElement | null;
		if (pathInput) {
			pathInput.addEventListener('keypress', (e) => {
				if (e.key === 'Enter') {
					void this.loadFile();
				}
			});
		}

		this.initSearch();
		this.initFilterBar();

		const params = new URLSearchParams(window.location.search);
		const fileId = params.get('avpvh_file_id');
		if (fileId !== null && fileId !== '') {
			void this.loadFileById(fileId);
		}
	}

	private initSearch(): void {
		const input = document.getElementById(
			'search-input'
		) as HTMLInputElement | null;
		const list = document.getElementById(
			'search-results'
		) as HTMLUListElement | null;
		if (!input || !list) {
			return;
		}

		let debounceTimer: ReturnType<typeof setTimeout> | null = null;
		let searchAbort: AbortController | null = null;
		let lastResults: {
			files: Array<{
				id: string;
				name: string;
				mimeType?: string;
				parents?: Array<string>;
			}>;
			folders: Array<{
				id: string;
				name: string;
				parents?: Array<string>;
				parentName?: string;
			}>;
		} | null = null;
		let activeIndex = -1;

		const hideResults = (): void => {
			list.style.display = 'none';
		};
		const foldersOnlyChk = document.getElementById(
			'search-folders-only'
		) as HTMLInputElement | null;
		const showResults = (
			files: Array<{
				id: string;
				name: string;
				mimeType?: string;
				parents?: Array<string>;
			}>,
			folders: Array<{
				id: string;
				name: string;
				parents?: Array<string>;
				parentName?: string;
			}>
		): void => {
			activeIndex = -1;
			const foldersOnly = foldersOnlyChk?.checked ?? false;
			const visibleFiles = foldersOnly ? [] : files;
			list.innerHTML = '';
			const total = folders.length + visibleFiles.length;
			if (total === 0) {
				const li = document.createElement('li');
				li.className = 'search-result-empty';
				li.textContent = 'Geen resultaten';
				list.appendChild(li);
			} else {
				// Folders first — clicking walks into the folder and loads its images
				folders.forEach((f) => {
					const li = document.createElement('li');
					li.className = 'search-result-item search-result-folder';
					const parentHint =
						f.parentName !== undefined && f.parentName !== ''
							? `<div style="font-size:10px;color:#888;margin-top:2px">in: ${ExifInspector.escapeHtml(f.parentName)}</div>`
							: '';
					li.innerHTML =
						`<div style="display:flex;justify-content:space-between;align-items:center">` +
						`<span>📁 <strong>${ExifInspector.escapeHtml(f.name)}</strong></span>` +
						`<span style="font-size:11px;color:#666">Laad media →</span>` +
						`</div>${parentHint}`;
					li.title = `Alle foto's en video's uit map "${f.name}" laden`;
					li.setAttribute('data-folder-id', f.id);
					li.addEventListener('mousedown', (e) => {
						e.preventDefault();
						input.value = f.name;
						hideResults();
						this.folderStack = [];
						void this.loadFilesByFolder(f.id, f.name);
					});
					list.appendChild(li);
				});
				// Then individual matching files (hidden when foldersOnly)
				if (!foldersOnly) {
					if (folders.length > 0 && visibleFiles.length > 0) {
						const sep = document.createElement('li');
						sep.className = 'search-result-separator';
						sep.textContent = 'Bestanden';
						list.appendChild(sep);
					}
					visibleFiles.forEach((f) => {
						const li = document.createElement('li');
						li.className = 'search-result-item';
						const isVideo =
							f.mimeType?.startsWith('video/') === true;
						li.textContent = `${isVideo ? '▶ ' : '▧ '}${f.name}`;
						li.setAttribute('data-file-id', f.id);
						li.addEventListener('mousedown', (e) => {
							e.preventDefault();
							input.value = f.name;
							hideResults();
							void this.loadFileById(f.id);
						});
						list.appendChild(li);
					});
				}
			}
			list.style.display = 'block';
		};

		input.addEventListener('input', () => {
			const q = input.value.trim();
			if (debounceTimer !== null) {
				clearTimeout(debounceTimer);
			}
			if (q.length < 3) {
				hideResults();
				return;
			}

			debounceTimer = setTimeout(() => {
				if (searchAbort !== null) {
					searchAbort.abort();
				}
				searchAbort = new AbortController();
				const { signal } = searchAbort;
				void fetch(this.restUrl + 'search?q=' + encodeURIComponent(q), {
					headers: { 'X-WP-Nonce': this.nonce },
					signal,
				})
					.then(
						async (r) =>
							r.json() as Promise<{
								files?: Array<{
									id: string;
									name: string;
									mimeType?: string;
								}>;
								folders?: Array<{ id: string; name: string }>;
							}>
					)
					.then((data) => {
						if (
							(data as Record<string, unknown>)['unavailable'] ===
							true
						) {
							const li = document.createElement('li');
							li.className = 'search-result-empty';
							li.textContent =
								'Zoeken tijdelijk niet beschikbaar — probeer opnieuw';
							list.innerHTML = '';
							list.appendChild(li);
							list.style.display = 'block';
							return;
						}
						lastResults = {
							files: data.files ?? [],
							folders: data.folders ?? [],
						};
						showResults(lastResults.files, lastResults.folders);
					})
					.catch((err: unknown) => {
						if (
							err instanceof DOMException &&
							'AbortError' === err.name
						) {
							return;
						}
						hideResults();
					});
			}, 500);
		});

		foldersOnlyChk?.addEventListener('change', () => {
			if (lastResults) {
				showResults(lastResults.files, lastResults.folders);
			}
		});
		input.addEventListener('blur', () => {
			setTimeout(hideResults, 150);
		});
		input.addEventListener('keydown', (e) => {
			if (e.key === 'Escape') {
				hideResults();
				input.blur();
				return;
			}
			if (list.style.display === 'none') {
				return;
			}
			const items = list.querySelectorAll<HTMLLIElement>(
				'li.search-result-item'
			);
			if (items.length === 0) {
				return;
			}
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				activeIndex = Math.min(activeIndex + 1, items.length - 1);
				items.forEach((li, i) => {
					li.classList.toggle(
						'search-result-active',
						i === activeIndex
					);
				});
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				activeIndex = Math.max(activeIndex - 1, -1);
				items.forEach((li, i) => {
					li.classList.toggle(
						'search-result-active',
						i === activeIndex
					);
				});
			} else if (e.key === 'Enter') {
				e.preventDefault();
				const target = activeIndex >= 0 ? items[activeIndex] : items[0];
				target.dispatchEvent(
					new MouseEvent('mousedown', { bubbles: true })
				);
			}
		});
	}

	private async loadFile(): Promise<void> {
		const pathInput = document.getElementById(
			'path-input'
		) as HTMLInputElement;
		const path = pathInput.value.trim();

		if (!path) {
			return; // nothing to load
		}

		// Save the path for next time
		localStorage.setItem('avpvh_exif_inspector_last_path', path);

		ExifInspector.showLoading(true);
		ExifInspector.clearError();
		try {
			// Parse the path and navigate to the file
			const parts = path
				.split('/')
				.map((p) => p.trim())
				.filter((p) => p);

			if (parts.length === 0) {
				ExifInspector.showError('Voer een geldig bestandspad in');
				ExifInspector.showLoading(false);
				return;
			}

			const fileName = parts.pop();
			if (fileName === undefined) {
				ExifInspector.showError('Voer een geldig bestandspad in');
				ExifInspector.showLoading(false);
				return;
			}

			this.folderStack = [];
			// Fast path: if the typed path exactly matches the last navigated path
			// and we have the folder ID (saved when the folder was reached via
			// search, which doesn't walk the full ancestor chain), skip hierarchical
			// navigation and jump directly to the folder.
			let currentId = this.rootId;
			try {
				const navJson = localStorage.getItem(
					'avpvh_exif_inspector_last_nav'
				);
				if (navJson !== null) {
					const nav = JSON.parse(navJson) as {
						path?: string;
						folderId?: string;
					};
					if (
						nav.folderId !== undefined &&
						nav.folderId !== '' &&
						nav.path === path
					) {
						const folderId = nav.folderId;
						currentId = folderId;
						this.folderStack = parts.map((name, i) => ({
							id:
								i === parts.length - 1
									? folderId
									: `_anc_${String(i)}`,
							name,
						}));
						parts.length = 0; // skip the loop below
					}
				}
			} catch {
				/* non-fatal — fall through to normal navigation */
			}

			// Hierarchical navigation (when fast path isn't available)
			for (const folderName of parts) {
				currentId = await this.navigateToFolder(currentId, folderName);
				this.folderStack.push({ id: currentId, name: folderName });
			}

			// List files in the folder and find the matching file
			await this.listFilesInFolder(currentId);
			const fileIndex = this.allFiles.findIndex(
				(f) => f.name === fileName
			);

			if (fileIndex === -1) {
				ExifInspector.showError(`Bestand niet gevonden: ${fileName}`);
				ExifInspector.showLoading(false);
				return;
			}

			this.startOrientationFetch();
			this.applyFilter();
			this.currentFileIndex = fileIndex;
			this.renderFilesView();
			this.displayCurrentFile();
		} catch (error) {
			ExifInspector.showError(
				`Fout: ${error instanceof Error ? error.message : 'Onbekende fout'}`
			);
		} finally {
			ExifInspector.showLoading(false);
		}
	}

	private async loadFileById(fileId: string): Promise<void> {
		ExifInspector.showLoading(true);
		ExifInspector.clearError();
		try {
			const response = await fetch(`${this.restUrl}file-data`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': this.nonce,
				},
				body: JSON.stringify({ file_id: fileId }),
				credentials: 'include',
			});

			if (!response.ok) {
				let errorMsg = response.statusText;
				try {
					const errorData = (await response.json()) as {
						message?: string;
					};
					errorMsg = errorData.message ?? errorMsg;
				} catch (e) {
					// Could not parse JSON error response
				}
				throw new Error(
					`Bestand niet gevonden (HTTP ${String(response.status)}: ${errorMsg})`
				);
			}

			const data = (await response.json()) as { file: FileData };
			const file = data.file;

			// If we know the parent folder, load the whole folder for context
			// (breadcrumb, thumbnail strip, full path)
			const parentId = file.parents?.[0];
			if (parentId !== undefined && parentId !== '') {
				// Fetch parent folder name, then load folder
				const parentMeta = await fetch(`${this.restUrl}file-data`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': this.nonce,
					},
					body: JSON.stringify({ file_id: parentId }),
					credentials: 'include',
				})
					.then(async (r) =>
						r.ok ? (r.json() as Promise<{ file: FileData }>) : null
					)
					.catch(() => null);

				const parentName =
					(parentMeta as { file: FileData } | null)?.file.name ??
					parentId;
				this.folderStack = [];
				await this.loadFilesByFolderSelectingId(
					parentId,
					parentName,
					fileId
				);
				return;
			}

			// Fallback: no parent info — show single file
			this.allFiles = [file];
			this.displayFiles = [file];
			this.currentFileIndex = 0;
			this.folderStack = [];
			this.applyFilter();
			this.renderBreadcrumb();
			this.renderFilesView();
			this.displayCurrentFile();
		} catch (error) {
			ExifInspector.showError(
				`Fout: ${error instanceof Error ? error.message : 'Onbekende fout'}`
			);
		} finally {
			ExifInspector.showLoading(false);
		}
	}

	private async navigateToFolder(
		parentId: string,
		folderName: string
	): Promise<string> {
		const response = await fetch(`${this.restUrl}list-folders`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': this.nonce,
			},
			body: JSON.stringify({
				parent_id: parentId,
				folder_name: folderName,
			}),
			credentials: 'include',
		});

		if (!response.ok) {
			let errorMsg = response.statusText;
			try {
				const errorData = (await response.json()) as {
					message?: string;
					code?: string;
				};
				errorMsg = errorData.message ?? errorMsg;
			} catch (e) {
				// Could not parse JSON error response
			}
			throw new Error(
				`Map niet gevonden: "${folderName}" (HTTP ${String(response.status)}: ${errorMsg})`
			);
		}

		const data = (await response.json()) as { folder_id: string };
		return data.folder_id;
	}

	private renderFilesView(): void {
		if (this.tableViewActive) {
			const thumbsEl = document.getElementById('folder-thumbs');
			if (thumbsEl) {
				thumbsEl.style.display = 'none';
			}
			this.renderFileTable();
		} else {
			const tc = document.getElementById('file-table-container');
			if (tc) {
				tc.style.display = 'none';
				tc.innerHTML = '';
			}
			this.renderFolderThumbs();
		}
	}

	private renderFileTable(): void {
		const container = document.getElementById('file-table-container');
		if (!container) {
			return;
		}
		const files = this.navFiles;

		if (files.length === 0) {
			container.innerHTML =
				'<p style="color:#888;font-size:13px;padding:8px 0;">Geen bestanden gevonden</p>';
			container.style.display = 'block';
			return;
		}

		const { col, dir } = this.tableSort;
		const sorted = [...files].sort((a, b) => {
			let av = '',
				bv = '';
			if (col === 'naam') {
				av = a.name.toLowerCase();
				bv = b.name.toLowerCase();
			} else if (col === 'type') {
				av = this.isVideo(a) ? 'video' : 'foto';
				bv = this.isVideo(b) ? 'video' : 'foto';
			} else if (col === 'camera') {
				av = (
					(a.imageMediaMetadata?.cameraModel ?? '') +
					(a.imageMediaMetadata?.cameraMake ?? '')
				).toLowerCase();
				bv = (
					(b.imageMediaMetadata?.cameraModel ?? '') +
					(b.imageMediaMetadata?.cameraMake ?? '')
				).toLowerCase();
			} else if (col === 'exif') {
				av = String(this.resolveOrientation(a));
				bv = String(this.resolveOrientation(b));
			} else if (col === 'drive') {
				av = String(a.imageMediaMetadata?.rotation ?? 0).padStart(
					3,
					'0'
				);
				bv = String(b.imageMediaMetadata?.rotation ?? 0).padStart(
					3,
					'0'
				);
			}
			if (!col) {
				return 0;
			}
			let cmp = 0;
			if (av < bv) {
				cmp = -1;
			} else if (av > bv) {
				cmp = 1;
			}
			return dir === 'asc' ? cmp : -cmp;
		});

		const arrow = (c: string): string => {
			if (col !== c) {
				return '';
			}
			return dir === 'asc' ? ' ↑' : ' ↓';
		};
		const th = (c: string, label: string): string =>
			`<th data-sort="${c}">${label}${arrow(c)}</th>`;

		let html =
			`<table class="file-list-table"><thead><tr>` +
			`<th style="width:2.5em;text-align:right;">#</th>` +
			th('naam', 'Naam') +
			th('type', 'Type') +
			th('camera', 'Camera') +
			th('exif', 'EXIF oriëntatie') +
			th('drive', 'Drive rotatie') +
			`</tr></thead><tbody>`;

		for (const file of sorted) {
			const overallIdx = this.allFiles.indexOf(file) + 1;
			const isSel = this.currentFile?.id === file.id;
			const orient = this.resolveOrientation(file);
			const isVideo = this.isVideo(file);
			const cam = [
				file.imageMediaMetadata?.cameraMake,
				file.imageMediaMetadata?.cameraModel,
			]
				.filter(Boolean)
				.join(' ');
			const driveRot = file.imageMediaMetadata?.rotation ?? 0;
			let driveRotLabel = '—';
			if (isVideo) {
				driveRotLabel = 'n.v.t.';
			} else if (driveRot !== 0) {
				driveRotLabel = `${String(driveRot)}°`;
			}
			html +=
				`<tr class="file-table-row${isSel ? ' file-table-selected' : ''}${this.excludedPhotoIds.has(file.id) ? ' photo-excluded' : ''}" data-file-id="${ExifInspector.escapeHtml(file.id)}">` +
				`<td style="color:#aaa;text-align:right;width:2.5em;">${String(overallIdx)}</td>` +
				`<td title="${ExifInspector.escapeHtml(file.name)}" style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${this.excludedPhotoIds.has(file.id) ? '<span style="color:#d63638;font-weight:700;" aria-label="Uitgesloten">✕</span> ' : ''}${ExifInspector.escapeHtml(file.name)}</td>` +
				`<td>${isVideo ? '▶ Video' : '▧ Foto'}</td>` +
				`<td>${ExifInspector.escapeHtml(cam)}</td>` +
				`<td${!isVideo && orient !== 1 ? ' style="color:#b04000;font-weight:500;"' : ''}>${isVideo ? 'n.v.t.' : ExifInspector.orientLabel(orient)}</td>` +
				`<td>${driveRotLabel}</td>` +
				`</tr>`;
		}
		html += '</tbody></table>';
		container.innerHTML = html;
		container.style.display = 'block';

		container
			.querySelectorAll<HTMLTableRowElement>('.file-table-row')
			.forEach((row) => {
				row.addEventListener('click', () => {
					const idx = this.navFiles.findIndex(
						(f) => f.id === row.dataset['fileId']
					);
					if (idx !== -1) {
						this.currentFileIndex = idx;
						this.updateTableSelection(container);
						this.displayCurrentFile();
					}
				});
			});

		container
			.querySelectorAll<HTMLTableCellElement>('th[data-sort]')
			.forEach((th2) => {
				th2.addEventListener('click', () => {
					const c = th2.dataset['sort'] ?? '';
					if (!c) {
						return;
					}
					this.tableSort = {
						col: c,
						dir:
							this.tableSort.col === c &&
							this.tableSort.dir === 'asc'
								? 'desc'
								: 'asc',
					};
					this.renderFileTable();
				});
			});
	}

	private updateTableSelection(container?: HTMLElement): void {
		const c = container ?? document.getElementById('file-table-container');
		if (!c) {
			return;
		}
		c.querySelectorAll<HTMLTableRowElement>('.file-table-row').forEach(
			(row) => {
				const isSel = row.dataset['fileId'] === this.currentFile?.id;
				row.classList.toggle('file-table-selected', isSel);
				if (isSel) {
					row.scrollIntoView({ block: 'nearest' });
				}
			}
		);
	}

	private renderFolderThumbs(): void {
		const strip = document.getElementById('folder-thumbs');
		if (!strip) {
			return;
		}
		const files = this.navFiles;
		if (files.length <= 1) {
			strip.style.display = 'none';
			strip.innerHTML = '';
			return;
		}
		const isFallback =
			this.displayFiles.length === 0 && this.allFiles.length > 0;
		strip.innerHTML = '';
		strip.style.display = 'flex';
		if (isFallback) {
			const note = document.createElement('div');
			note.style.cssText =
				'font-size:11px;color:#999;padding:3px 6px;flex-basis:100%;';
			note.textContent = `Filter: 0 gevonden — alle ${String(this.allFiles.length)} getoond`;
			strip.appendChild(note);
		}
		files.forEach((file, idx) => {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'file-thumb';
			button.dataset['fileId'] = file.id;
			button.title = this.fileTooltip(file, idx, files);
			button.setAttribute('aria-label', file.name);
			button.classList.toggle(
				'photo-excluded',
				this.excludedPhotoIds.has(file.id)
			);
			if (isFallback) {
				button.style.opacity = '0.5';
			}
			if (file.id === this.currentFile?.id) {
				button.classList.add('selected');
			}

			const img = document.createElement('img');
			const fallbackIcon = this.orientationIconForFile(file);
			img.src = file.thumbnailLink ?? fallbackIcon;
			img.alt = '';
			if (file.thumbnailLink === undefined || file.thumbnailLink === '') {
				img.classList.add('fallback-icon');
			}
			img.addEventListener(
				'error',
				() => {
					if (img.src !== fallbackIcon) {
						img.src = fallbackIcon;
						img.classList.add('fallback-icon');
					}
				},
				{ once: true }
			);
			button.addEventListener('click', () => {
				const currentIndex = this.navFiles.findIndex(
					(item) => item.id === file.id
				);
				if (currentIndex >= 0) {
					this.currentFileIndex = currentIndex;
					this.displayCurrentFile();
				}
			});
			button.appendChild(img);
			if (this.isVideo(file)) {
				const badge = document.createElement('span');
				badge.className = 'file-thumb-media-badge';
				badge.textContent = '▶';
				badge.setAttribute('aria-label', 'Video');
				button.appendChild(badge);
			}
			strip.appendChild(button);
		});
	}

	private updateThumbSelection(center = false): void {
		const strip = document.getElementById('folder-thumbs');
		if (!strip) {
			return;
		}
		const files = this.navFiles;
		strip
			.querySelectorAll<HTMLButtonElement>('.file-thumb')
			.forEach((button, idx) => {
				const file = ExifInspector.maybe(files[idx]);
				button.classList.toggle(
					'selected',
					button.dataset['fileId'] === this.currentFile?.id
				);
				if (file) {
					button.title = this.fileTooltip(file, idx, files);
				}
			});
		// Scroll selected thumb into view
		const selected = strip.querySelector('.file-thumb.selected');
		if (selected) {
			selected.scrollIntoView({
				block: center ? 'center' : 'nearest',
				inline: center ? 'center' : 'nearest',
			});
		}
	}

	private fileTooltip(
		file: FileData,
		index: number,
		files: Array<FileData>
	): string {
		const folderIndex = this.allFiles.findIndex(
			(item) => item.id === file.id
		);
		const folderPosition = folderIndex >= 0 ? folderIndex + 1 : index + 1;
		const folderTotal =
			this.allFiles.length > 0 ? this.allFiles.length : files.length;
		const excluded = this.excludedPhotoIds.has(file.id);
		if (excluded) {
			return `${String(folderPosition)} / ${String(folderTotal)} · ${file.name} · uitgesloten; niet in lightbox`;
		}
		const visible = this.allFiles.filter(
			(item) => !this.excludedPhotoIds.has(item.id)
		);
		const lightboxIndex = visible.findIndex((item) => item.id === file.id);
		return `${String(folderPosition)} / ${String(folderTotal)} · zichtbaar ${String(lightboxIndex + 1)} / ${String(visible.length)} · ${file.name}`;
	}

	private updateExclusionIndicators(): void {
		const fileId = this.currentFile?.id;
		if (fileId === undefined) {
			return;
		}
		document
			.querySelectorAll<HTMLElement>(
				`[data-file-id="${CSS.escape(fileId)}"]`
			)
			.forEach((element) => {
				element.classList.toggle(
					'photo-excluded',
					this.excludedPhotoIds.has(fileId)
				);
			});
		this.renderFilesView();
	}

	private async fetchSubfolders(
		folderId: string
	): Promise<Array<{ id: string; name: string }>> {
		const response = await fetch(`${this.restUrl}list-subfolders`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': this.nonce,
			},
			body: JSON.stringify({ parent_id: folderId }),
			credentials: 'include',
		});
		if (!response.ok) {
			return [];
		}
		const data = (await response.json()) as {
			folders?: Array<{ id: string; name: string }>;
		};
		return data.folders ?? [];
	}

	private showSubfolderPicker(
		subfolders: Array<{ id: string; name: string }>
	): void {
		const navigationResults = document.getElementById('navigation-results');
		if (navigationResults) {
			navigationResults.style.display = 'block';
		}
		this.renderBreadcrumb('kies een submap');

		const strip = document.getElementById('folder-thumbs');
		if (!strip) {
			return;
		}
		strip.innerHTML = '';
		strip.style.cssText = 'display:block;padding:0;';

		// Sort state
		let sortedFolders = [...subfolders].sort((a, b) =>
			a.name.localeCompare(b.name, 'nl', {
				numeric: true,
				sensitivity: 'base',
			})
		);
		let sortAsc = true;
		let filterText = '';

		// — Controls row —
		const controls = document.createElement('div');
		controls.style.cssText =
			'display:flex;align-items:center;gap:8px;padding:6px 0 10px;flex-wrap:wrap;';

		// — Grid — (created before renderGrid() so the closure can reference it)
		const grid = document.createElement('div');
		grid.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;';

		const countEl = document.createElement('span');
		countEl.style.cssText = 'font-size:11px;color:#888;margin-left:auto;';

		const renderGrid = (): void => {
			const q = filterText.trim().toLowerCase();
			const visible = q
				? sortedFolders.filter((sf) =>
						sf.name.toLowerCase().includes(q)
					)
				: sortedFolders;
			grid.innerHTML = '';
			visible.forEach((sf) => {
				const btn = document.createElement('button');
				btn.type = 'button';
				btn.style.cssText =
					'padding:5px 10px;cursor:pointer;border:1px solid #ccc;border-radius:3px;background:#f0f6ff;font-size:13px;white-space:nowrap;';
				btn.textContent = `📁 ${sf.name}`;
				btn.title = `Laad media uit ${sf.name}`;
				btn.addEventListener(
					'click',
					() => void this.loadFilesByFolder(sf.id, sf.name)
				);
				grid.appendChild(btn);
			});
			countEl.textContent = q
				? `${String(visible.length)} van ${String(subfolders.length)} mappen`
				: `${String(subfolders.length)} mappen`;
		};

		const filterInput = document.createElement('input');
		filterInput.type = 'text';
		filterInput.placeholder = 'Filter mappen…';
		filterInput.style.cssText =
			'padding:4px 8px;font-size:12px;border:1px solid #ccc;border-radius:3px;width:200px;';
		filterInput.addEventListener('input', () => {
			filterText = filterInput.value;
			renderGrid();
		});
		filterInput.addEventListener('keydown', (e) => {
			if (e.key === 'Escape') {
				filterInput.value = '';
				filterText = '';
				renderGrid();
			}
		});

		const sortBtn = document.createElement('button');
		sortBtn.type = 'button';
		sortBtn.style.cssText =
			'padding:4px 10px;font-size:12px;border:1px solid #ccc;border-radius:3px;cursor:pointer;background:#f5f5f5;';
		sortBtn.textContent = 'A → Z';
		sortBtn.addEventListener('click', () => {
			sortAsc = !sortAsc;
			sortedFolders = [...sortedFolders].reverse();
			sortBtn.textContent = sortAsc ? 'A → Z' : 'Z → A';
			renderGrid();
		});

		controls.appendChild(filterInput);
		controls.appendChild(sortBtn);
		controls.appendChild(countEl);
		strip.appendChild(controls);
		strip.appendChild(grid);

		renderGrid();
		filterInput.focus();
	}

	private renderBreadcrumb(suffix = ''): void {
		const el = document.getElementById('folder-breadcrumb');
		if (!el) {
			return;
		}
		if (this.folderStack.length === 0) {
			el.style.display = 'none';
			return;
		}
		el.style.display = 'block';
		el.innerHTML = '';

		if (this.folderStack.length >= 1) {
			const upBtn = document.createElement('button');
			upBtn.type = 'button';
			upBtn.textContent = '↑ Omhoog';
			upBtn.style.cssText =
				'margin-right:8px;padding:2px 8px;font-size:12px;cursor:pointer;border:1px solid #aaa;border-radius:3px;background:#f5f5f5;';
			upBtn.addEventListener('click', () => {
				void this.navigateUp();
			});
			el.appendChild(upBtn);
		}

		this.folderStack.forEach((entry, idx) => {
			if (idx > 0) {
				const sep = document.createElement('span');
				sep.textContent = ' / ';
				sep.style.color = '#aaa';
				el.appendChild(sep);
			}
			if (idx < this.folderStack.length - 1) {
				const link = document.createElement('a');
				link.href = '#';
				link.textContent = '📁 ' + entry.name;
				link.style.cssText =
					'font-size:12px;color:#0073aa;text-decoration:none;';
				link.addEventListener('click', (e) => {
					e.preventDefault();
					this.navigateToStackIndex(idx);
				});
				el.appendChild(link);
			} else {
				const span = document.createElement('span');
				span.style.cssText = 'font-size:12px;font-weight:bold;';
				span.textContent =
					'📁 ' + entry.name + (suffix ? ` — ${suffix}` : '');
				el.appendChild(span);
			}
		});
	}

	private async navigateUp(): Promise<void> {
		if (this.folderStack.length === 0) {
			return;
		}

		if (this.folderStack.length === 1) {
			// Only one level known — go back to root subfolder picker
			const rootId = avpvhExifInspector.root_id;
			this.folderStack = [];
			this.allFiles = [];
			this.displayFiles = [];
			this.renderFilesView();
			if (rootId) {
				const subfolders = await this.fetchSubfolders(rootId);
				if (subfolders.length > 0) {
					this.showSubfolderPicker(subfolders);
				} else {
					this.renderBreadcrumb();
				}
			} else {
				this.renderBreadcrumb();
			}
			return;
		}

		this.folderStack.pop();
		const parent = this.folderStack.pop();
		if (parent === undefined) {
			return;
		}
		void this.loadFilesByFolder(parent.id, parent.name);
	}

	private navigateToStackIndex(idx: number): void {
		const target = this.folderStack[idx];
		this.folderStack = this.folderStack.slice(0, idx);
		void this.loadFilesByFolder(target.id, target.name);
	}

	private startOrientationFetch(): void {
		this.exifOrientations = new Map();
		++this.orientationFetchEpoch;
		this.orientationFetchStartedEpoch = -1;
		this.updateOrientationProgress();
		if (this.filterNotNormal || this.filterOrientations.size > 0) {
			this.doStartOrientationFetch();
		}
	}

	private doStartOrientationFetch(): void {
		if (this.orientationFetchStartedEpoch === this.orientationFetchEpoch) {
			return;
		}
		if (this.allFiles.length === 0) {
			return;
		}
		this.orientationFetchStartedEpoch = this.orientationFetchEpoch;
		this.updateOrientationProgress();
		this.fetchOrientationsInBackground(this.orientationFetchEpoch);
	}

	private async loadFilesByFolder(
		folderId: string,
		folderName: string
	): Promise<void> {
		this.folderStack.push({ id: folderId, name: folderName });
		ExifInspector.showLoading(true);
		ExifInspector.clearError();
		try {
			await this.listFilesInFolder(folderId);
			await this.loadFolderCorrections(folderId);
			if (this.allFiles.length === 0) {
				const subfolders = await this.fetchSubfolders(folderId);
				if (subfolders.length > 0) {
					this.showSubfolderPicker(subfolders);
				} else {
					ExifInspector.showError(
						`Geen foto's of video's gevonden in map "${folderName}"`
					);
				}
				return;
			}
			this.startOrientationFetch();
			this.applyFilter();
			this.renderBreadcrumb(
				`${String(this.displayFiles.length)}${this.displayFiles.length !== this.allFiles.length ? ` van ${String(this.allFiles.length)}` : ''} bestanden`
			);
			this.currentFileIndex = 0;
			this.renderFilesView();
			this.displayCurrentFile();
		} catch (error) {
			ExifInspector.showError(
				`Fout: ${error instanceof Error ? error.message : 'Onbekende fout'}`
			);
		} finally {
			ExifInspector.showLoading(false);
		}
	}

	private async loadFilesByFolderSelectingId(
		folderId: string,
		folderName: string,
		selectFileId: string
	): Promise<void> {
		this.folderStack.push({ id: folderId, name: folderName });
		ExifInspector.showLoading(true);
		ExifInspector.clearError();
		try {
			await this.listFilesInFolder(folderId);
			await this.loadFolderCorrections(folderId);
			if (this.allFiles.length === 0) {
				ExifInspector.showError(
					`Geen foto's of video's gevonden in map "${folderName}"`
				);
				return;
			}
			this.startOrientationFetch();
			this.applyFilter();
			const idx = this.displayFiles.findIndex(
				(f) => f.id === selectFileId
			);
			this.currentFileIndex = idx >= 0 ? idx : 0;
			this.renderBreadcrumb(
				`${String(this.displayFiles.length)}${this.displayFiles.length !== this.allFiles.length ? ` van ${String(this.allFiles.length)}` : ''} bestanden`
			);
			this.renderFilesView();
			this.displayCurrentFile();
		} catch (error) {
			ExifInspector.showError(
				`Fout: ${error instanceof Error ? error.message : 'Onbekende fout'}`
			);
		} finally {
			ExifInspector.showLoading(false);
		}
	}

	private async loadFolderCorrections(folderId: string): Promise<void> {
		this.folderTransformsBySize = {};
		try {
			const response = await fetch(
				`${this.restUrl}folder-corrections?folder_id=${encodeURIComponent(folderId)}`,
				{
					headers: { 'X-WP-Nonce': this.nonce },
					credentials: 'include',
				}
			);
			if (response.ok) {
				const data = (await response.json()) as {
					corrections?: Record<
						string,
						{ r: number; h: boolean; v: boolean }
					>;
				};
				this.folderTransformsBySize = data.corrections ?? {};
			}
		} catch (e) {
			/* non-fatal */
		}
		this.updateFolderCorrectionUi();
	}

	private folderMirrorActive(): boolean {
		return (
			ExifInspector.maybe(this.folderTransformsBySize['grid'])?.h ===
				true &&
			ExifInspector.maybe(this.folderTransformsBySize['lightbox'])?.h ===
				true
		);
	}

	private async toggleFolderMirror(): Promise<void> {
		const folder = ExifInspector.maybe(
			this.folderStack[this.folderStack.length - 1]
		);
		if (!folder) {
			return;
		}
		const mirror = !this.folderMirrorActive();
		if (mirror) {
			// eslint-disable-next-line no-alert -- deliberate confirmation for a destructive, folder-wide action
			const confirmed = window.confirm(
				`Alle foto's in "${folder.name}" standaard spiegelen? Individuele fotocorrecties blijven behouden.`
			);
			if (!confirmed) {
				return;
			}
		}
		const button = document.getElementById(
			'folder-mirror-btn'
		) as HTMLButtonElement | null;
		if (button) {
			button.disabled = true;
		}
		try {
			const response = await fetch(`${this.restUrl}folder-corrections`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': this.nonce,
				},
				body: JSON.stringify({ folder_id: folder.id, mirror }),
				credentials: 'include',
			});
			if (!response.ok) {
				throw new Error('Mapcorrectie kon niet worden opgeslagen');
			}
			this.folderTransformsBySize = mirror
				? {
						grid: { r: 0, h: true, v: false },
						lightbox: { r: 0, h: true, v: false },
					}
				: {};
			this.applyAllPreviewTransforms();
		} catch (error) {
			ExifInspector.showError(
				error instanceof Error
					? error.message
					: 'Mapcorrectie kon niet worden opgeslagen'
			);
		} finally {
			if (button) {
				button.disabled = false;
			}
			this.updateFolderCorrectionUi();
			this.updateCorrectionIndicators();
		}
	}

	private effectiveTransform(sizeKey: string): {
		r: number;
		h: boolean;
		v: boolean;
	} {
		if (
			Object.prototype.hasOwnProperty.call(this.transformsBySize, sizeKey)
		) {
			return this.transformsBySize[sizeKey];
		}
		return (
			this.folderTransformsBySize[sizeKey] ?? { r: 0, h: false, v: false }
		);
	}

	private updateFolderCorrectionUi(): void {
		const bar = document.getElementById('folder-correction-bar');
		const button = document.getElementById('folder-mirror-btn');
		const status = document.getElementById('folder-correction-status');
		const control = document.getElementById('photo-folder-exception');
		const checkbox = document.getElementById(
			'photo-folder-exception-checkbox'
		) as HTMLInputElement | null;
		const hasFolder = this.folderStack.length > 0;
		const active = this.folderMirrorActive();
		if (bar) {
			bar.style.display = hasFolder ? 'flex' : 'none';
			bar.classList.toggle('is-active', active);
		}
		if (button) {
			button.textContent = active
				? 'Mapspiegeling uitschakelen'
				: 'Hele map spiegelen';
		}
		if (status) {
			status.textContent = active
				? 'Actief voor grid en lightbox; individuele fotocorrecties hebben voorrang.'
				: 'Nieuwe foto’s erven deze correctie automatisch.';
		}
		if (!control || !checkbox) {
			return;
		}
		control.style.display =
			active && this.currentFile && this.photoCorrectionsLoaded
				? 'flex'
				: 'none';
		const grid = ExifInspector.maybe(this.transformsBySize['grid']);
		const light = ExifInspector.maybe(this.transformsBySize['lightbox']);
		const custom =
			(grid !== undefined && !ExifInspector.identityTransform(grid)) ||
			(light !== undefined && !ExifInspector.identityTransform(light));
		checkbox.disabled = custom;
		checkbox.checked =
			grid !== undefined &&
			light !== undefined &&
			ExifInspector.identityTransform(grid) &&
			ExifInspector.identityTransform(light);
		control.title = custom
			? 'Deze foto heeft al een individuele correctie en overschrijft de mapcorrectie.'
			: '';
	}

	private async setPhotoFolderException(
		ignoreFolder: boolean
	): Promise<void> {
		if (!this.currentFile || !this.folderMirrorActive()) {
			return;
		}
		const currentFileId = this.currentFile.id;
		const identity = { r: 0, h: false, v: false };
		await Promise.all(
			['grid', 'lightbox'].map(async (sizeKey) =>
				this.saveTransform(
					currentFileId,
					sizeKey,
					identity,
					!ignoreFolder
				)
			)
		);
		if (ignoreFolder) {
			this.transformsBySize['grid'] = { ...identity };
			this.transformsBySize['lightbox'] = { ...identity };
		} else {
			delete this.transformsBySize['grid'];
			delete this.transformsBySize['lightbox'];
		}
		this.applyAllPreviewTransforms();
		ExifInspector.displayCorrectionsInTable(this.transformsBySize);
		this.updateFolderCorrectionUi();
		this.updateCorrectionIndicators();
	}

	private resolveOrientation(f: FileData): number {
		const exif = this.exifOrientations.get(f.id);
		return (
			exif ??
			ExifInspector.driveRotToOrientation(
				f.imageMediaMetadata?.rotation ?? 0
			)
		);
	}

	private isVideo(file: FileData | null = this.currentFile): boolean {
		return file?.mimeType?.startsWith('video/') === true;
	}

	private orientationIconForFile(file: FileData): string {
		if (
			this.isVideo(file) &&
			file.iconLink !== undefined &&
			file.iconLink !== ''
		) {
			return ExifInspector.buildIconUrl(file.iconLink, 64);
		}
		const width = file.imageMediaMetadata?.width ?? 0;
		const height = file.imageMediaMetadata?.height ?? 0;
		const orientation = this.resolveOrientation(file);
		const swapsAxes = orientation >= 5 && orientation <= 8;
		const portrait = swapsAxes ? width > height : height > width;
		return getOrientationIconUrl(portrait);
	}

	private applyFilter(): void {
		const model = this.filterModel.trim().toLowerCase();
		const orientations = this.filterOrientations;
		const notNormal = this.filterNotNormal;
		const orientationActive = notNormal || orientations.size > 0;

		this.displayFiles = this.allFiles.filter((f) => {
			if (model) {
				const m = (
					f.imageMediaMetadata?.cameraModel ?? ''
				).toLowerCase();
				if (m !== model) {
					return false;
				}
			}
			if (orientationActive) {
				const o = this.resolveOrientation(f);
				if (orientations.size > 0) {
					// Specific orientations selected: show only those.
					if (!orientations.has(o)) {
						return false;
					}
				} else if (o === 1) {
					// Only "niet normaal" checked: exclude orientation 1.
					return false;
				}
			}
			return true;
		});

		const active = model !== '' || orientationActive;
		const bar = document.getElementById('filter-bar');
		if (bar) {
			bar.style.display = this.allFiles.length > 1 ? 'flex' : 'none';
		}
		const countEl = document.getElementById('filter-count');
		if (countEl) {
			countEl.textContent = active
				? `${String(this.displayFiles.length)} van ${String(this.allFiles.length)}`
				: `${String(this.allFiles.length)} bestanden`;
		}
	}

	private updateViewToggleButtons(): void {
		const tableBtn = document.getElementById('view-table-btn');
		const thumbBtn = document.getElementById('view-thumbs-btn');
		if (tableBtn) {
			tableBtn.classList.toggle('active', this.tableViewActive);
		}
		if (thumbBtn) {
			thumbBtn.classList.toggle('active', !this.tableViewActive);
		}
	}

	private initFilterBar(): void {
		// View toggle
		const tableBtn = document.getElementById('view-table-btn');
		const thumbBtn = document.getElementById('view-thumbs-btn');
		this.updateViewToggleButtons();
		tableBtn?.addEventListener('click', () => {
			this.tableViewActive = true;
			localStorage.setItem('avpvh_exif_view', 'table');
			this.updateViewToggleButtons();
			this.renderFilesView();
		});
		thumbBtn?.addEventListener('click', () => {
			this.tableViewActive = false;
			localStorage.setItem('avpvh_exif_view', 'thumbs');
			this.updateViewToggleButtons();
			this.renderFilesView();
		});

		const modelInput = document.getElementById(
			'filter-model'
		) as HTMLSelectElement | null;

		const rerender = (): void => {
			this.applyFilter();
			this.renderBreadcrumb(
				`${String(this.displayFiles.length)}${this.displayFiles.length !== this.allFiles.length ? ` van ${String(this.allFiles.length)}` : ''} bestanden`
			);
			this.currentFileIndex = 0;
			this.renderFilesView();
			if (this.displayFiles.length > 0) {
				this.displayCurrentFile();
			}
		};

		modelInput?.addEventListener('change', () => {
			this.filterModel = modelInput.value;
			rerender();
		});

		const notNormalCb = document.getElementById(
			'filter-not-normal'
		) as HTMLInputElement | null;
		notNormalCb?.addEventListener('change', () => {
			this.filterNotNormal = notNormalCb.checked;
			if (notNormalCb.checked) {
				this.doStartOrientationFetch();
			}
			rerender();
		});

		document
			.querySelectorAll<HTMLInputElement>('.filter-orient-cb')
			.forEach((cb) => {
				cb.addEventListener('change', () => {
					const val = parseInt(cb.value, 10);
					if (cb.checked) {
						this.filterOrientations.add(val);
						this.doStartOrientationFetch();
					} else {
						this.filterOrientations.delete(val);
					}
					rerender();
				});
			});
	}

	private updateOrientationProgress(): void {
		const el = document.getElementById('orientation-progress');
		if (!el) {
			return;
		}
		if (this.orientationFetchStartedEpoch !== this.orientationFetchEpoch) {
			el.textContent = '';
			return;
		}
		const images = this.allFiles.filter((file) => !this.isVideo(file));
		const total = images.length;
		const known = images.filter((f) =>
			this.exifOrientations.has(f.id)
		).length;
		if (known >= total) {
			el.innerHTML = '';
			return;
		}
		const found = images.filter((f) => {
			const o = this.exifOrientations.get(f.id);
			return o !== undefined && o !== 1;
		}).length;
		const pct = total > 0 ? (known / total) * 100 : 0;
		el.innerHTML =
			`<span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;color:#888;">` +
			`<span style="display:inline-block;width:70px;height:5px;background:#e0e0e0;border-radius:3px;overflow:hidden;vertical-align:middle;">` +
			`<span style="display:block;width:${pct.toFixed(1)}%;height:100%;background:#0073aa;"></span>` +
			`</span>` +
			`<span>${String(known)}/${String(total)} gescand — ${String(found)} niet normaal</span>` +
			`</span>`;
	}

	private fetchOrientationsInBackground(epoch: number): void {
		const CONCURRENCY = 8;
		const files = this.allFiles.filter((file) => !this.isVideo(file));
		let idx = 0;

		const next = async (): Promise<void> => {
			if (epoch !== this.orientationFetchEpoch) {
				return;
			}
			if (idx >= files.length) {
				return;
			}
			const file = files[idx++];
			if (!this.exifOrientations.has(file.id)) {
				try {
					const resp = await fetch(`${this.restUrl}orientation`, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': this.nonce,
						},
						body: JSON.stringify({ file_id: file.id }),
						credentials: 'include',
					});
					if (resp.ok && epoch === this.orientationFetchEpoch) {
						const data = (await resp.json()) as {
							orientation: number;
						};
						this.exifOrientations.set(file.id, data.orientation);
						this.updateOrientationProgress();
						if (
							this.filterOrientations.size > 0 ||
							this.filterNotNormal
						) {
							if (!this.orientationRenderPending) {
								this.orientationRenderPending = true;
								requestAnimationFrame(() => {
									this.orientationRenderPending = false;
									this.applyFilter();
									this.renderFilesView();
									if (
										this.currentFileIndex >=
										this.navFiles.length
									) {
										this.currentFileIndex = Math.max(
											0,
											this.navFiles.length - 1
										);
										this.displayCurrentFile();
									}
								});
							}
						}
					}
				} catch (_) {
					/* ignore per-file errors */
				}
			}
			return next();
		};

		void Promise.all(
			Array.from({ length: CONCURRENCY }, async () => next())
		).then(() => {
			this.updateOrientationProgress();
		});
	}

	private async listFilesInFolder(folderId: string): Promise<void> {
		this.filterModel = '';
		void this.loadCameraModelOptions(folderId);
		const response = await fetch(`${this.restUrl}list-files`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': this.nonce,
			},
			body: JSON.stringify({ parent_id: folderId }),
			credentials: 'include',
		});

		if (!response.ok) {
			let errorMsg = response.statusText;
			try {
				const errorData = (await response.json()) as {
					message?: string;
					code?: string;
				};
				errorMsg = errorData.message ?? errorMsg;
			} catch (e) {
				// Could not parse JSON error response
			}
			throw new Error(
				`Laden mislukt: HTTP ${String(response.status)} - ${errorMsg}`
			);
		}

		const data = (await response.json()) as {
			excluded_ids?: Array<string>;
			files: Array<FileData>;
		};
		this.allFiles = data.files;
		this.excludedPhotoIds = new Set(data.excluded_ids ?? []);
	}

	private async loadCameraModelOptions(folderId: string): Promise<void> {
		const epoch = ++this.modelOptionsEpoch;
		const select = document.getElementById(
			'filter-model'
		) as HTMLSelectElement | null;
		if (!select) {
			return;
		}
		select.disabled = true;
		select.replaceChildren(new Option('Modellen laden…', ''));

		try {
			const response = await fetch(
				`${this.restUrl}model-index/models?folder_id=${encodeURIComponent(folderId)}`,
				{
					headers: { 'X-WP-Nonce': this.nonce },
					credentials: 'include',
				}
			);
			if (!response.ok) {
				throw new Error(`HTTP ${String(response.status)}`);
			}
			const data = (await response.json()) as {
				indexed?: boolean;
				models?: Array<string>;
			};
			if (epoch !== this.modelOptionsEpoch) {
				return;
			}
			if (data.indexed !== true) {
				select.replaceChildren(
					new Option('Index ontbreekt (zie instellingen)', '')
				);
				return;
			}

			select.replaceChildren(new Option('Alle modellen', ''));
			for (const model of data.models ?? []) {
				select.appendChild(new Option(model, model));
			}
			select.disabled = false;
		} catch (_) {
			if (epoch === this.modelOptionsEpoch) {
				select.replaceChildren(
					new Option('Modelindex niet beschikbaar', '')
				);
			}
		}
	}

	private displayCurrentFile(): void {
		const nav = this.navFiles;
		if (this.currentFileIndex < 0 || this.currentFileIndex >= nav.length) {
			return;
		}

		const fileSection =
			document.querySelector<HTMLElement>('.file-info-section');
		if (fileSection) {
			fileSection.style.display = 'block';
		}
		const navigationResults = document.getElementById('navigation-results');
		if (navigationResults) {
			navigationResults.style.display = 'block';
		}

		this.currentFile = nav[this.currentFileIndex];
		const isVideo = this.isVideo(this.currentFile);
		this.previewTimings = {};
		this.fullExifData = {};
		this.previewExifData = {};
		this.transformsBySize = {};
		this.photoCorrectionsLoaded = false;
		this.embeddedThumb = null;
		this.updateFolderCorrectionUi();
		this.renderPhotoExclusion(false, [], '', 'Laden…');

		// Use the same orientation-aware artwork as the thumbnail fallback.
		const fileName = document.getElementById('file-name');
		if (fileName) {
			fileName.textContent = '';
			if (isVideo) {
				const badge = document.createElement('span');
				badge.className = 'file-name-media-badge';
				badge.textContent = '▶';
				badge.setAttribute('aria-label', 'Video');
				fileName.appendChild(badge);
			} else {
				const icon = document.createElement('img');
				icon.src = this.orientationIconForFile(this.currentFile);
				icon.alt = '';
				icon.width = 28;
				icon.height = 28;
				fileName.appendChild(icon);
			}
			fileName.appendChild(
				document.createTextNode(this.currentFile.name)
			);
		}
		const exclusionLabel = document.getElementById('media-exclusion-label');
		if (exclusionLabel) {
			exclusionLabel.textContent = `${isVideo ? 'Deze video' : 'Deze foto'} uitsluiten van gallery en diavoorstelling`;
		}

		const fileCount = document.getElementById('file-count');
		if (fileCount) {
			fileCount.textContent = `${String(this.currentFileIndex + 1)} / ${String(nav.length)}`;
		}
		this.updateThumbSelection();
		this.updateTableSelection();

		// Update path input with current folder stack + filename
		const pathInput = document.getElementById(
			'path-input'
		) as HTMLInputElement | null;
		if (pathInput) {
			const currentFile = this.currentFile;
			const parts = [
				...this.folderStack.map((f) => f.name),
				currentFile.name,
			];
			const path = parts.join(' / ');
			pathInput.value = path;
			localStorage.setItem('avpvh_exif_inspector_last_path', path);
			// Also save the innermost folder ID so "Laden" can navigate directly
			// when the folder was reached via search (folderStack has no full ancestor chain).
			const innerFolder = ExifInspector.maybe(
				this.folderStack[this.folderStack.length - 1]
			);
			if (innerFolder) {
				localStorage.setItem(
					'avpvh_exif_inspector_last_nav',
					JSON.stringify({
						path,
						folderId: innerFolder.id,
						fileId: currentFile.id,
					})
				);
			}
		}

		// Update nav buttons
		const prevBtn = document.getElementById(
			'prev-btn'
		) as HTMLButtonElement | null;
		const nextBtn = document.getElementById(
			'next-btn'
		) as HTMLButtonElement | null;
		if (prevBtn) {
			prevBtn.disabled = this.currentFileIndex === 0;
		}
		if (nextBtn) {
			nextBtn.disabled = this.currentFileIndex === nav.length - 1;
		}
		const resumeBtn = document.getElementById(
			'resume-slideshow-btn'
		) as HTMLButtonElement | null;
		if (resumeBtn) {
			resumeBtn.disabled = false;
		}

		// Show loading placeholder in EXIF table while fetching
		ExifInspector.showExifLoading();

		if (isVideo) {
			this.displayExifData();
		} else {
			// Full image EXIF and preview EXIF are loaded only when applicable.
			this.fetchFullExifData();
		}

		// Display original image
		this.displayOriginalImage();

		// Display previews
		this.displayPreviews();

		// Display all Google Drive icon sizes
		this.displayIcons();

		// Orientation corrections apply only to images.
		if (!isVideo) {
			void this.loadCorrections();
		} else {
			ExifInspector.clearCorrectionUiForVideo();
		}
		void this.loadPhotoExclusion();
	}

	private async loadCorrections(): Promise<void> {
		if (!this.currentFile) {
			return;
		}
		const file = this.currentFile;

		this.transformsBySize = {};
		let corrections: Record<string, { r: number; h: boolean; v: boolean }> =
			{};

		try {
			const response = await fetch(
				`${this.restUrl}corrections?file_id=${encodeURIComponent(file.id)}`,
				{
					headers: { 'X-WP-Nonce': this.nonce },
					credentials: 'include',
				}
			);
			if (response.ok) {
				const data = (await response.json()) as {
					corrections?: Record<
						string,
						{ r: number; h: boolean; v: boolean }
					>;
				};
				corrections = data.corrections ?? {};
			}
		} catch (e) {
			/* non-fatal */
		}
		if (this.currentFile !== file) {
			return;
		}
		this.transformsBySize = corrections;

		this.applyAllPreviewTransforms();
		ExifInspector.displayCorrectionsInTable(this.transformsBySize);
		this.photoCorrectionsLoaded = true;
		this.updateFolderCorrectionUi();
		this.updateCorrectionIndicators();
	}

	private renderPhotoExclusion(
		excluded: boolean,
		reasons: Array<string>,
		note: string,
		status = ''
	): void {
		const currentFileId = this.currentFile?.id;
		if (currentFileId !== undefined) {
			if (excluded) {
				this.excludedPhotoIds.add(currentFileId);
			} else {
				this.excludedPhotoIds.delete(currentFileId);
			}
		}
		const checkbox = document.getElementById(
			'photo-excluded-checkbox'
		) as HTMLInputElement | null;
		if (checkbox) {
			checkbox.checked = excluded;
		}
		document
			.querySelectorAll<HTMLInputElement>(
				'input[name="photo-exclusion-reason"]'
			)
			.forEach((input) => {
				input.checked = reasons.includes(input.value);
			});
		const noteInput = document.getElementById(
			'photo-exclusion-note'
		) as HTMLTextAreaElement | null;
		if (noteInput) {
			noteInput.value = note;
		}
		const statusElement = document.getElementById('photo-exclusion-status');
		if (statusElement) {
			statusElement.textContent =
				status ||
				(excluded
					? 'Uitgesloten van gallery, lightbox en diavoorstelling.'
					: `${this.isVideo() ? 'Deze video' : 'Deze foto'} wordt getoond.`);
			statusElement.style.color = excluded ? '#b32d2e' : '#50575e';
		}
		ExifInspector.setExclusionDetailsVisible(excluded);
		this.updateExclusionIndicators();
	}

	private async loadPhotoExclusion(): Promise<void> {
		if (!this.currentFile) {
			return;
		}
		const file = this.currentFile;
		try {
			const response = await fetch(
				`${this.restUrl}exclusion?file_id=${encodeURIComponent(file.id)}`,
				{
					headers: { 'X-WP-Nonce': this.nonce },
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
			};
			if (this.currentFile !== file) {
				return;
			}
			this.renderPhotoExclusion(
				data.excluded === true,
				data.reasons ?? [],
				data.note ?? ''
			);
		} catch {
			if (this.currentFile === file) {
				this.renderPhotoExclusion(
					false,
					[],
					'',
					'Uitsluitingsstatus kon niet worden geladen.'
				);
			}
		}
	}

	private async savePhotoExclusion(): Promise<void> {
		if (!this.currentFile) {
			return;
		}
		const file = this.currentFile;
		const checkbox = document.getElementById(
			'photo-excluded-checkbox'
		) as HTMLInputElement | null;
		const excluded = checkbox?.checked === true;
		const reasons = Array.from(
			document.querySelectorAll<HTMLInputElement>(
				'input[name="photo-exclusion-reason"]:checked'
			)
		).map((input) => input.value);
		if (excluded && reasons.length === 0) {
			const status = document.getElementById('photo-exclusion-status');
			if (status) {
				status.textContent = 'Kies minimaal één reden.';
				status.style.color = '#b32d2e';
			}
			return;
		}
		const note =
			(
				document.getElementById(
					'photo-exclusion-note'
				) as HTMLTextAreaElement | null
			)?.value ?? '';
		const innerFolder = ExifInspector.maybe(
			this.folderStack[this.folderStack.length - 1]
		);
		const folderId = file.parents?.[0] ?? innerFolder?.id ?? '';
		const button = document.getElementById(
			'save-photo-exclusion'
		) as HTMLButtonElement | null;
		if (button) {
			button.disabled = true;
		}
		try {
			const response = await fetch(`${this.restUrl}exclusion`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': this.nonce,
				},
				credentials: 'include',
				body: JSON.stringify({
					file_id: file.id,
					folder_id: folderId,
					mime_type: file.mimeType ?? '',
					excluded,
					reasons,
					note,
				}),
			});
			if (!response.ok) {
				const error = (await response.json().catch(() => null)) as {
					message?: string;
				} | null;
				throw new Error(
					error?.message ?? `HTTP ${String(response.status)}`
				);
			}
			if (this.currentFile !== file) {
				return;
			}
			this.renderPhotoExclusion(
				excluded,
				excluded ? reasons : [],
				excluded ? note : '',
				'Opgeslagen.'
			);
		} catch (error) {
			if (this.currentFile === file) {
				const status = document.getElementById(
					'photo-exclusion-status'
				);
				if (status) {
					status.textContent = `Opslaan mislukt: ${error instanceof Error ? error.message : 'onbekende fout'}`;
					status.style.color = '#b32d2e';
				}
			}
		} finally {
			if (button) {
				button.disabled = false;
			}
		}
	}

	private fetchFullExifData(): void {
		if (!this.currentFile) {
			return;
		}
		const file = this.currentFile;
		const thumbLink = file.thumbnailLink;

		const previewSources =
			thumbLink !== undefined && thumbLink !== ''
				? ExifInspector.buildSortedPreviews(thumbLink)
				: [];

		const fetchOriginal = fetch(this.restUrl + 'full-exif', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': this.nonce,
			},
			body: JSON.stringify({ file_id: file.id }),
		})
			.then(async (r) => {
				if (!r.ok) {
					return null;
				}
				return r.json() as Promise<{
					exif?: Record<string, number | string>;
					corrections?: Record<
						string,
						{ r: number; h: boolean; v: boolean }
					>;
					embedded_thumb?: string | null;
					embedded_thumb_w?: number;
					embedded_thumb_h?: number;
				}>;
			})
			.catch(() => null);

		const fetchPreview = async (
			key: string,
			url: string
		): Promise<{ key: string; exif: Record<string, number | string> }> =>
			fetch(this.restUrl + 'preview-exif', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': this.nonce,
				},
				body: JSON.stringify({ url }),
			})
				.then(async (r) => {
					if (!r.ok) {
						return { key, exif: {} };
					}
					const d = (await r.json()) as {
						exif?: Record<string, number | string>;
					};
					return { key, exif: d.exif ?? {} };
				})
				.catch(() => ({ key, exif: {} }));

		void Promise.all([
			fetchOriginal,
			...previewSources.map(async ({ key, url }) =>
				fetchPreview(key, url)
			),
		]).then(([origData, ...previewResults]) => {
			// Discard results if the user navigated away while this fetch was in flight
			if (this.currentFile !== file) {
				return;
			}

			if (origData?.exif) {
				this.fullExifData = origData.exif;
			}
			if (
				origData?.embedded_thumb !== undefined &&
				origData.embedded_thumb !== null &&
				origData.embedded_thumb !== ''
			) {
				this.embeddedThumb = {
					src: origData.embedded_thumb,
					w: origData.embedded_thumb_w ?? 0,
					h: origData.embedded_thumb_h ?? 0,
				};
				this.insertEmbeddedThumbCard();
			}
			this.previewExifData = {};
			for (const result of previewResults as Array<{
				key: string;
				exif: Record<string, number | string>;
			}>) {
				this.previewExifData[result.key] = result.exif;
			}
			this.displayExifData();
			ExifInspector.displayCorrectionsInTable(this.transformsBySize);
			this.updateCorrectionIndicators();
		});
	}

	private displayExifData(): void {
		const tbody =
			document.querySelector<HTMLTableSectionElement>(
				'.exif-table tbody'
			);
		const table = document.querySelector<HTMLTableElement>('#exif-table');
		if (!tbody || !table || !this.currentFile) {
			return;
		}

		tbody.innerHTML = '';
		table.querySelector('thead')?.remove();

		// When we have the full original EXIF AND at least one preview EXIF, show comparison table
		if (
			Object.keys(this.fullExifData).length > 0 &&
			Object.keys(this.previewExifData).length > 0
		) {
			this.displayExifComparisonTable(table, tbody);
			return;
		}

		// Add file info
		if (
			this.currentFile.size !== undefined &&
			this.currentFile.size !== ''
		) {
			const sizeInMB = (
				parseInt(this.currentFile.size, 10) /
				(1024 * 1024)
			).toFixed(2);
			this.addTableRow(tbody, 'Bestandsgrootte', `${sizeInMB} MB`);
		}

		if (
			this.currentFile.mimeType !== undefined &&
			this.currentFile.mimeType !== ''
		) {
			this.addTableRow(tbody, 'MIME Type', this.currentFile.mimeType);
		}

		if (this.isVideo()) {
			const metadata = this.currentFile.videoMediaMetadata;
			if (
				metadata?.width !== undefined &&
				metadata.height !== undefined
			) {
				this.addTableRow(
					tbody,
					'Afmetingen',
					`${String(metadata.width)} × ${String(metadata.height)} px`
				);
			}
			if (metadata?.durationMillis !== undefined) {
				const seconds = Math.round(
					Number(metadata.durationMillis) / 1000
				);
				const minutes = Math.floor(seconds / 60);
				this.addTableRow(
					tbody,
					'Duur',
					`${String(minutes)}:${String(seconds % 60).padStart(2, '0')} (${String(seconds)} seconden)`
				);
			}
			if (
				this.currentFile.createdTime !== undefined &&
				this.currentFile.createdTime !== ''
			) {
				this.addTableRow(
					tbody,
					'Aangemaakt',
					this.currentFile.createdTime
				);
			}
			if (
				this.currentFile.modifiedTime !== undefined &&
				this.currentFile.modifiedTime !== ''
			) {
				this.addTableRow(
					tbody,
					'Gewijzigd',
					this.currentFile.modifiedTime
				);
			}
			if (
				this.currentFile.description !== undefined &&
				this.currentFile.description !== ''
			) {
				this.addTableRow(
					tbody,
					'Beschrijving',
					this.currentFile.description
				);
			}
			this.addTableRow(
				tbody,
				'Drive-thumbnail',
				this.currentFile.hasThumbnail === true
					? 'Beschikbaar'
					: 'Niet beschikbaar'
			);
			if (
				this.currentFile.md5Checksum !== undefined &&
				this.currentFile.md5Checksum !== ''
			) {
				this.addTableRow(
					tbody,
					'MD5-controlesom',
					this.currentFile.md5Checksum
				);
			}
			if (
				this.currentFile.webViewLink !== undefined &&
				this.currentFile.webViewLink !== ''
			) {
				this.addTableRow(
					tbody,
					'Openen in Google Drive',
					this.currentFile.webViewLink
				);
			}
			return;
		}

		// If we have full EXIF data, display it all
		if (Object.keys(this.fullExifData).length > 0) {
			this.displayFullExifFields(tbody);
			return;
		}

		// Otherwise, fall back to API metadata — show all KV pairs
		const metadata = this.currentFile.imageMediaMetadata;
		if (!metadata) {
			tbody.innerHTML +=
				'<tr><td colspan="2"><em>Geen EXIF-gegevens beschikbaar</em></td></tr>';
			return;
		}

		// File-level fields
		if (
			this.currentFile.createdTime !== undefined &&
			this.currentFile.createdTime !== ''
		) {
			this.addTableRow(
				tbody,
				'Created Time',
				this.currentFile.createdTime
			);
		}
		if (
			this.currentFile.description !== undefined &&
			this.currentFile.description !== ''
		) {
			this.addTableRow(
				tbody,
				'Description',
				this.currentFile.description
			);
		}

		// Dump every key in imageMediaMetadata as-is. The API can return an
		// explicit `null` for an absent optional field, which the FileData
		// interface doesn't model — cast so that case is still handled.
		const metadataEntries = Object.entries(metadata) as Array<
			[string, number | string | null | undefined]
		>;
		for (const [key, value] of metadataEntries) {
			if (value === undefined || value === null) {
				continue;
			}
			const label = key
				.replace(/([A-Z])/g, ' $1')
				.replace(/^./, (s) => s.toUpperCase());
			let display = String(value);
			if (key === 'aperture') {
				display = `f/${String(value)}`;
			} else if (key === 'exposureTime') {
				const n = Number(value);
				display =
					n < 1 ? `1/${String(Math.round(1 / n))}s` : `${String(n)}s`;
			} else if (key === 'focalLength') {
				display = `${String(value)} mm`;
			} else if (key === 'rotation') {
				display = `${String(value)} (${ExifInspector.orientationDescription(Number(value))})`;
			} else if (key === 'width' || key === 'height') {
				display = `${String(value)} px`;
			}
			this.addTableRow(tbody, label, display);
		}
	}

	private displayFullExifFields(tbody: HTMLTableSectionElement): void {
		// Map of EXIF section:field names to user-friendly display names
		const fieldLabels: Record<string, string> = {
			// IFD0 (Image)
			'IFD0:ImageDescription': 'Image Description',
			'IFD0:Make': 'Camera Make',
			'IFD0:Model': 'Camera Model',
			'IFD0:XResolution': 'X Resolution',
			'IFD0:YResolution': 'Y Resolution',
			'IFD0:ResolutionUnit': 'Resolution Unit',
			'IFD0:Software': 'Software',
			'IFD0:DateTime': 'Date/Time',
			'IFD0:Orientation': 'Orientation',

			// EXIF
			'EXIF:ExposureTime': 'Exposure Time',
			'EXIF:FNumber': 'F-Number',
			'EXIF:ISOSpeedRatings': 'ISO Speed',
			'EXIF:ISO': 'ISO Speed',
			'EXIF:ExifVersion': 'EXIF Version',
			'EXIF:DateTimeOriginal': 'Date/Time Original',
			'EXIF:DateTimeDigitized': 'Date/Time Digitized',
			'EXIF:FocalLength': 'Focal Length',
			'EXIF:FocalLengthIn35mmFilm': 'Focal Length (35mm)',
			'EXIF:Flash': 'Flash',
			'EXIF:WhiteBalance': 'White Balance',
			'EXIF:MeteringMode': 'Metering Mode',
			'EXIF:ExposureMode': 'Exposure Mode',
			'EXIF:Contrast': 'Contrast',
			'EXIF:Saturation': 'Saturation',
			'EXIF:Sharpness': 'Sharpness',
			'EXIF:LensModel': 'Lens Model',
			'EXIF:LensMake': 'Lens Make',
			'EXIF:ColorSpace': 'Color Space',
			'EXIF:ExposureProgram': 'Exposure Program',

			// GPS
			'GPS:GPSLatitude': 'GPS Latitude',
			'GPS:GPSLongitude': 'GPS Longitude',
			'GPS:GPSAltitude': 'GPS Altitude',
		};

		// Sort EXIF data by section and field name
		const sortedEntries = Object.entries(this.fullExifData).sort(
			([a], [b]) => {
				const aSection = a.split(':')[0];
				const bSection = b.split(':')[0];
				if (aSection !== bSection) {
					return aSection.localeCompare(bSection);
				}
				return a.localeCompare(b);
			}
		);

		// Display each EXIF field
		for (const [key, value] of sortedEntries) {
			if (typeof value === 'object') {
				continue; // Skip complex objects
			}

			const label =
				fieldLabels[key] ||
				(key.includes(':') ? key.slice(key.indexOf(':') + 1) : key);
			const displayValue = this.formatExifValue(key, value);
			this.addTableRow(tbody, label, displayValue, key);
		}

		if (sortedEntries.length === 0) {
			tbody.innerHTML +=
				'<tr><td colspan="2"><em>Geen EXIF-gegevens beschikbaar</em></td></tr>';
		}
	}

	private displayExifComparisonTable(
		table: HTMLTableElement,
		tbody: HTMLTableSectionElement
	): void {
		const sources = ['original', ...Object.keys(this.previewExifData)];
		const ps = Number(avpvhExifInspector.preview_size);
		const sourceLabels: Record<string, string> = {
			original: 'Origineel',
			grid: `Miniatuur (h${String(Math.floor(1.25 * avpvhExifInspector.grid_height))})`,
			lightbox: `${String(ps)}px (Lightbox)`,
			s256: '256px',
			s512: '512px',
			s1024: '1024px',
			s1920: '1920px',
		};

		// Rebuild thead with one column per source
		let thead = table.querySelector('thead');
		if (!thead) {
			thead = table.createTHead();
		}
		thead.innerHTML = '';
		const headerRow = thead.insertRow();
		const thField = document.createElement('th');
		thField.textContent = 'Veld';
		headerRow.appendChild(thField);
		for (const src of sources) {
			const th = document.createElement('th');
			th.textContent = sourceLabels[src] ?? src;
			if (src === 'original') {
				th.style.fontWeight = 'bold';
			}
			headerRow.appendChild(th);
		}

		// Collect all field keys across all sources
		const allKeys = new Set<string>(Object.keys(this.fullExifData));
		for (const exif of Object.values(this.previewExifData)) {
			for (const k of Object.keys(exif)) {
				allKeys.add(k);
			}
		}

		// Sort: IFD0 first, then EXIF, then GPS, then rest
		const sectionOrder = ['IFD0', 'EXIF', 'GPS'];
		const sortedKeys = Array.from(allKeys).sort((a, b) => {
			const sa = a.split(':')[0] ?? '';
			const sb = b.split(':')[0] ?? '';
			const ia = sectionOrder.indexOf(sa);
			const ib = sectionOrder.indexOf(sb);
			if (ia !== ib) {
				return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
			}
			return a.localeCompare(b);
		});

		for (const key of sortedKeys) {
			const origVal = ExifInspector.maybe(this.fullExifData[key]);
			const origDisplay =
				origVal !== undefined
					? this.formatExifValue(key, origVal)
					: '—';

			const row = tbody.insertRow();
			// Highlight the Orientation row — most important for diagnosing rotation issues
			if (key.endsWith(':Orientation') || key === 'Orientation') {
				const manuallyCorrected = !ExifInspector.identityTransform(
					this.effectiveTransform('lightbox')
				);
				row.style.background = manuallyCorrected
					? '#fde8e7'
					: '#f0f8ff';
				row.style.borderTop = manuallyCorrected
					? '2px solid #d63638'
					: '2px solid #6af';
				row.style.borderBottom = manuallyCorrected
					? '2px solid #d63638'
					: '2px solid #6af';
			}

			const cellKey = row.insertCell();
			cellKey.textContent = key.includes(':')
				? key.slice(key.indexOf(':') + 1)
				: key;
			cellKey.title = ExifInspector.exifFieldTitle(key);
			cellKey.style.fontSize = '11px';

			for (const src of sources) {
				const cell = row.insertCell();
				const val: number | string | undefined =
					src === 'original'
						? origVal
						: ExifInspector.maybe(this.previewExifData[src])?.[key];

				const isOrientation =
					key.endsWith(':Orientation') || key === 'Orientation';
				if (val === undefined) {
					cell.textContent = '—';
					cell.style.color = '#bbb';
				} else {
					const display = this.formatExifValue(key, val);
					if (src === 'original') {
						if (isOrientation) {
							cell.innerHTML = display;
						} else {
							cell.textContent = display;
						}
						cell.style.fontWeight = 'bold';
					} else if (String(val) === String(origVal)) {
						// Same as original: show actual value but dimmed
						if (isOrientation) {
							cell.innerHTML = display;
						} else {
							cell.textContent = display;
						}
						cell.style.color = '#999';
					} else {
						// Different from original: highlight yellow; blue if no original value
						if (isOrientation) {
							cell.innerHTML = display;
						} else {
							cell.textContent = display;
						}
						cell.style.background =
							origVal !== undefined ? '#fff3cd' : '#e8f4fd';
						cell.style.fontWeight = 'bold';
						if (origVal !== undefined) {
							cell.title = `Origineel: ${origDisplay}`;
						}
					}
				}
			}
		}

		if (sortedKeys.length === 0) {
			const row = tbody.insertRow();
			const cell = row.insertCell();
			cell.colSpan = sources.length + 1;
			cell.textContent = 'Geen EXIF data beschikbaar';
		}
	}

	private formatExifValue(
		key: string,
		value: number | string | null | undefined
	): string {
		if (value === null || value === undefined) {
			return '—';
		}
		if (typeof value === 'number') {
			value = String(value);
		}

		// Format specific fields
		if (key.includes('ExposureTime')) {
			const num = parseFloat(value);
			if (num < 1) {
				return `1/${String(Math.round(1 / num))}s`;
			}
			return `${String(num)}s`;
		}

		if (key.includes('FocalLength') || key.includes('Focal')) {
			return `${value}mm`;
		}

		if (key.includes('Resolution')) {
			return `${value}dpi`;
		}

		if (key.endsWith(':Orientation') || key === 'Orientation') {
			const orientation = parseInt(value, 10);
			const lightT = this.effectiveTransform('lightbox');
			const lightRot = lightT.r;
			const lightH = lightT.h;
			const lightV = lightT.v;
			const width = this.currentFile?.imageMediaMetadata?.width ?? 0;
			const height = this.currentFile?.imageMediaMetadata?.height ?? 0;
			const swapsAxes = orientation >= 5 && orientation <= 8;
			const portrait = swapsAxes ? width > height : height > width;
			let html = ExifInspector.orientationChain(
				orientation,
				20,
				portrait
			);
			html += ` <strong>${value}</strong>`;
			if (orientation !== 1) {
				html += ` — ${ExifInspector.orientationDescription(orientation)}`;
			}
			if (lightRot !== 0 || lightH || lightV) {
				const cssParts: Array<string> = [];
				if (lightH) {
					cssParts.push('scaleX(-1)');
				}
				if (lightV) {
					cssParts.push('scaleY(-1)');
				}
				if (lightRot !== 0) {
					cssParts.push(`rotate(${String(lightRot)}deg)`);
				}
				html +=
					` <span style="color:#c00;font-size:16px;font-weight:bold;vertical-align:middle">→</span>` +
					` <span style="color:#c00">(</span>` +
					ExifInspector.orientationSvg(
						1,
						cssParts.join(' '),
						'#c00'
					) +
					`<span style="color:#c00">) Handmatig: ${ExifInspector.escapeHtml(ExifInspector.transformDescription(lightT))}</span>`;
			}
			return html;
		}

		if (key.endsWith(':FileDateTime') || key === 'FileDateTime') {
			const ts = parseInt(value, 10);
			if (!isNaN(ts) && ts > 0) {
				const d = new Date(ts * 1000);
				const fmt = d.toLocaleString('nl-NL', {
					year: 'numeric',
					month: '2-digit',
					day: '2-digit',
					hour: '2-digit',
					minute: '2-digit',
					second: '2-digit',
				});
				return `${value} — ${fmt}`;
			}
		}

		if (key.endsWith(':FileSize') || key === 'FileSize') {
			const bytes = parseInt(value, 10);
			if (!isNaN(bytes) && bytes > 0) {
				const sizeInKB = bytes / 1024;
				const sizeInMB = bytes / (1024 * 1024);
				const human =
					sizeInMB >= 1
						? `${sizeInMB.toFixed(2)} MB`
						: `${sizeInKB.toFixed(1)} KB`;
				return `${value} B — ${human}`;
			}
		}

		// Enum lookup — try full key first (section-specific), then short key
		const shortKey = key.includes(':')
			? key.slice(key.indexOf(':') + 1)
			: key;
		const enumMap =
			ExifInspector.maybe(ExifInspector.EXIF_ENUMS[key]) ??
			ExifInspector.maybe(ExifInspector.EXIF_ENUMS[shortKey]);
		if (enumMap !== undefined) {
			const label = ExifInspector.maybe(enumMap[value]);
			if (label !== undefined) {
				return `${value} — ${label}`;
			}
		}

		// Truncate very long strings
		if (value.length > 100) {
			return value.substring(0, 100) + '...';
		}

		return value;
	}

	private addTableRow(
		tbody: HTMLTableSectionElement,
		label: string,
		value: string,
		fieldKey?: string
	): void {
		const row = tbody.insertRow();
		const cellLabel = row.insertCell(0);
		const cellValue = row.insertCell(1);
		cellLabel.textContent = label;
		if (fieldKey !== undefined) {
			cellLabel.title = ExifInspector.exifFieldTitle(fieldKey);
		}
		const isOrientation =
			fieldKey !== undefined &&
			(fieldKey.endsWith(':Orientation') || fieldKey === 'Orientation');
		if (
			isOrientation &&
			!ExifInspector.identityTransform(
				this.effectiveTransform('lightbox')
			)
		) {
			row.style.background = '#fde8e7';
			row.style.borderTop = '2px solid #d63638';
			row.style.borderBottom = '2px solid #d63638';
		}
		if (isOrientation) {
			cellValue.innerHTML = value;
		} else {
			cellValue.textContent = value;
		}
	}

	private displayOriginalImage(): void {
		if (!this.currentFile) {
			return;
		}

		const downloadLink = document.getElementById(
			'original-download-link'
		) as HTMLAnchorElement | null;
		const sizeInfo = document.getElementById('original-size');

		if (downloadLink && this.currentFile.id) {
			downloadLink.href =
				this.restUrl +
				'download-original?file_id=' +
				encodeURIComponent(this.currentFile.id) +
				'&_wpnonce=' +
				encodeURIComponent(this.nonce);
			downloadLink.textContent = `Origineel downloaden`;
			downloadLink.style.display = 'inline-block';
		} else if (downloadLink) {
			downloadLink.style.display = 'none';
		}

		const fullscreenBtn = document.getElementById(
			'original-fullscreen-btn'
		) as HTMLButtonElement | null;

		if (fullscreenBtn) {
			const thumbLink = this.currentFile.thumbnailLink;
			if (
				!this.isVideo() &&
				thumbLink !== undefined &&
				thumbLink !== ''
			) {
				const url = ExifInspector.buildPreviewUrl(thumbLink, 1920);
				fullscreenBtn.style.display = 'inline-block';
				fullscreenBtn.onclick = (): void => {
					this.openFullscreenImage(url);
				};
			} else {
				fullscreenBtn.style.display = 'none';
				fullscreenBtn.onclick = null;
			}
		}

		if (
			sizeInfo &&
			this.currentFile.size !== undefined &&
			this.currentFile.size !== ''
		) {
			const bytes = parseInt(this.currentFile.size, 10);
			const sizeInMB = (bytes / (1024 * 1024)).toFixed(2);
			const metadata = this.isVideo()
				? this.currentFile.videoMediaMetadata
				: this.currentFile.imageMediaMetadata;
			const widthLabel = String(metadata?.width ?? '?');
			const heightLabel = String(metadata?.height ?? '?');
			sizeInfo.textContent = `(${sizeInMB} MB — ${widthLabel}×${heightLabel}px)`;
		}
	}

	private ensureFullscreenViewer(): {
		viewer: HTMLDivElement;
		img: HTMLImageElement;
	} {
		if (this.fullscreenViewerEl && this.fullscreenImageEl) {
			return {
				viewer: this.fullscreenViewerEl,
				img: this.fullscreenImageEl,
			};
		}

		const viewer = document.createElement('div');
		viewer.id = 'inspector-fullscreen-viewer';
		viewer.className = 'inspector-fullscreen-viewer';

		const img = document.createElement('img');
		img.className = 'inspector-fullscreen-image';
		img.alt = '';

		const toolbar = document.createElement('div');
		toolbar.className = 'inspector-fullscreen-toolbar';
		toolbar.innerHTML = `
			<button type="button" data-action="zoom-out" title="Uitzoomen">−</button>
			<button type="button" data-action="zoom-in" title="Inzoomen">+</button>
			<button type="button" data-action="rotate" title="Roteer 90° rechtsom">↻</button>
			<button type="button" data-action="flip-h" title="Spiegel horizontaal">↔</button>
			<button type="button" data-action="flip-v" title="Spiegel verticaal">↕</button>
			<button type="button" data-action="reset" title="Reset weergave">Reset</button>
			<button type="button" data-action="close" title="Sluiten">✕</button>
		`;

		toolbar
			.querySelector('[data-action="zoom-in"]')
			?.addEventListener('click', () => {
				this.adjustFullscreenZoom(0.25);
			});
		toolbar
			.querySelector('[data-action="zoom-out"]')
			?.addEventListener('click', () => {
				this.adjustFullscreenZoom(-0.25);
			});
		toolbar
			.querySelector('[data-action="rotate"]')
			?.addEventListener('click', () => {
				this.fullscreenRotation = (this.fullscreenRotation + 90) % 360;
				this.applyFullscreenTransform();
			});
		toolbar
			.querySelector('[data-action="flip-h"]')
			?.addEventListener('click', () => {
				this.fullscreenFlipH = !this.fullscreenFlipH;
				this.applyFullscreenTransform();
			});
		toolbar
			.querySelector('[data-action="flip-v"]')
			?.addEventListener('click', () => {
				this.fullscreenFlipV = !this.fullscreenFlipV;
				this.applyFullscreenTransform();
			});
		toolbar
			.querySelector('[data-action="reset"]')
			?.addEventListener('click', () => {
				this.resetFullscreenTransform();
			});
		toolbar
			.querySelector('[data-action="close"]')
			?.addEventListener('click', () => {
				void document.exitFullscreen();
			});

		img.addEventListener(
			'wheel',
			(event) => {
				event.preventDefault();
				this.adjustFullscreenZoom(event.deltaY < 0 ? 0.25 : -0.25);
			},
			{ passive: false }
		);

		img.addEventListener('pointerdown', (event) => {
			this.fullscreenDragging = true;
			this.fullscreenDragStartX = event.clientX;
			this.fullscreenDragStartY = event.clientY;
			this.fullscreenPanStartX = this.fullscreenPanX;
			this.fullscreenPanStartY = this.fullscreenPanY;
			img.setPointerCapture(event.pointerId);
		});
		img.addEventListener('pointermove', (event) => {
			if (!this.fullscreenDragging) {
				return;
			}
			this.fullscreenPanX =
				this.fullscreenPanStartX +
				(event.clientX - this.fullscreenDragStartX);
			this.fullscreenPanY =
				this.fullscreenPanStartY +
				(event.clientY - this.fullscreenDragStartY);
			this.applyFullscreenTransform();
		});
		const stopDragging = (): void => {
			this.fullscreenDragging = false;
		};
		img.addEventListener('pointerup', stopDragging);
		img.addEventListener('pointercancel', stopDragging);

		viewer.appendChild(img);
		viewer.appendChild(toolbar);
		document.body.appendChild(viewer);

		document.addEventListener('fullscreenchange', () => {
			if (document.fullscreenElement !== viewer) {
				this.resetFullscreenTransform();
			}
		});

		this.fullscreenViewerEl = viewer;
		this.fullscreenImageEl = img;

		return { viewer, img };
	}

	private adjustFullscreenZoom(delta: number): void {
		this.fullscreenZoom = Math.min(
			6,
			Math.max(1, this.fullscreenZoom + delta)
		);
		this.applyFullscreenTransform();
	}

	private applyFullscreenTransform(): void {
		if (!this.fullscreenImageEl) {
			return;
		}
		const scaleX = (this.fullscreenFlipH ? -1 : 1) * this.fullscreenZoom;
		const scaleY = (this.fullscreenFlipV ? -1 : 1) * this.fullscreenZoom;
		this.fullscreenImageEl.style.transform =
			`translate(${String(this.fullscreenPanX)}px, ${String(this.fullscreenPanY)}px) ` +
			`rotate(${String(this.fullscreenRotation)}deg) ` +
			`scaleX(${String(scaleX)}) scaleY(${String(scaleY)})`;
	}

	private resetFullscreenTransform(): void {
		this.fullscreenRotation = 0;
		this.fullscreenFlipH = false;
		this.fullscreenFlipV = false;
		this.fullscreenZoom = 1;
		this.fullscreenPanX = 0;
		this.fullscreenPanY = 0;
		this.applyFullscreenTransform();
	}

	private openFullscreenImage(url: string): void {
		const { viewer, img } = this.ensureFullscreenViewer();
		this.resetFullscreenTransform();
		img.src = url;
		void viewer.requestFullscreen();
	}

	private bindEnlargeOnClick(img: HTMLImageElement, url: string): void {
		img.classList.add('inspector-clickable-preview');
		img.addEventListener('click', () => {
			this.openFullscreenImage(url);
		});
	}

	private insertEmbeddedThumbCard(): void {
		if (!this.embeddedThumb) {
			return;
		}
		const container = document.getElementById('previews-container');
		if (!container) {
			return;
		}
		// Remove existing card if a re-fetch came in
		container.querySelector('.preview-item--embedded')?.remove();
		const { src, w, h } = this.embeddedThumb;
		const sizeKB = (Math.round(src.length * 0.75) / 1024).toFixed(1);
		const card = document.createElement('div');
		card.className = 'preview-item preview-item--embedded';
		card.innerHTML = `
			<h4>Ingebedde thumbnail</h4>
			<div class="preview-image-wrap">
				<img src="${src}" alt="Embedded thumbnail" style="max-width:100%;max-height:200px;" />
			</div>
			<div class="timing-info">
				<div class="timing-row"><span class="timing-label">Afmetingen:</span>
					<span class="timing-value">${String(w)}×${String(h)}px</span></div>
				<div class="timing-row"><span class="timing-label">Bestandsgrootte:</span>
					<span class="timing-value">~${sizeKB} KB</span></div>
			</div>
		`;
		container.insertBefore(card, container.firstChild);

		const embeddedImg = card.querySelector('img');
		if (embeddedImg) {
			this.bindEnlargeOnClick(embeddedImg, src);
		}
	}

	private displayPreviews(): void {
		const container = document.getElementById('previews-container');
		if (!container || !this.currentFile) {
			return;
		}

		container.innerHTML = '';

		const thumbLink = this.currentFile.thumbnailLink;
		if (this.isVideo()) {
			this.displayVideoPreviews(container, thumbLink ?? '');
			return;
		}
		if (thumbLink === undefined || thumbLink === '') {
			container.innerHTML =
				'<p><em>Google Drive heeft voor deze afbeelding geen voorbeeld beschikbaar.</em></p>';
			return;
		}

		for (const {
			key: sizeKey,
			label,
			url,
		} of ExifInspector.buildSortedPreviews(thumbLink)) {
			const item = document.createElement('div');
			item.className = 'preview-item';
			item.setAttribute('data-size-key', sizeKey);

			item.innerHTML = `
				<h4>${label}</h4>
				<div class="correction-scope"></div>
				<div class="preview-image-wrap">
					<img class="preview-image loading" alt="Preview ${label}" />
					<button class="hflip-btn" type="button" title="Spiegel horizontaal (links-rechts)">↔</button>
					<button class="rotate-btn" type="button" title="Roteer 90° rechtsom">↻</button>
					<button class="vflip-btn" type="button" title="Spiegel verticaal (boven-onder)">↕</button>
					<button class="apply-all-btn" type="button" title="Deze correctie voor deze foto op alle formaten toepassen">→ alle formaten</button>
					<button class="reset-variant-btn" type="button" title="Individuele correctie voor alleen deze variant verwijderen" style="display:none;">Herstel variant</button>
					<span class="rotation-badge" style="display:none;"></span>
				</div>
				<div class="timing-info">
					<div class="timing-row">
						<span class="timing-label">Bestandsgrootte:</span>
						<span class="timing-value">...</span>
					</div>
					<div class="timing-row">
						<span class="timing-label">Netwerk:</span>
						<span class="timing-value">...</span>
					</div>
					<div class="timing-row">
						<span class="timing-label">Render:</span>
						<span class="timing-value">...</span>
					</div>
				</div>
			`;

			container.appendChild(item);

			const img = item.querySelector('img');
			if (img) {
				this.fetchAndDisplayPreview(img, url, sizeKey);
				this.bindEnlargeOnClick(img, url);
			}

			item.querySelector('.rotate-btn')?.addEventListener('click', () => {
				this.rotatePreview(sizeKey);
			});
			item.querySelector('.hflip-btn')?.addEventListener('click', () => {
				this.flipPreview(sizeKey, 'h');
			});
			item.querySelector('.vflip-btn')?.addEventListener('click', () => {
				this.flipPreview(sizeKey, 'v');
			});
			item
				.querySelector('.apply-all-btn')
				?.addEventListener('click', () => {
					this.applyToAllSizes(sizeKey);
				});
			item
				.querySelector('.reset-variant-btn')
				?.addEventListener('click', () => {
					void this.resetVariantCorrection(sizeKey);
				});
		}
		this.updateCorrectionIndicators();
	}

	private displayVideoPreviews(
		container: HTMLElement,
		thumbLink: string
	): void {
		if (!this.currentFile) {
			return;
		}
		const player = document.createElement('div');
		player.className = 'preview-item video-player-item';
		const streamUrl =
			this.restUrl +
			'video-stream?file_id=' +
			encodeURIComponent(this.currentFile.id) +
			'&mime_type=' +
			encodeURIComponent(this.currentFile.mimeType ?? 'video/mp4') +
			'&size=' +
			encodeURIComponent(this.currentFile.size ?? '0') +
			'&_wpnonce=' +
			encodeURIComponent(this.nonce);
		player.innerHTML = `
			<h4>Video afspelen</h4>
			<div class="correction-scope">Originele video, beveiligd gestreamd via de website</div>
			<video class="inspector-video-player" controls playsinline preload="metadata"${thumbLink ? ` poster="${ExifInspector.escapeHtml(thumbLink)}"` : ''}>
				<source src="${ExifInspector.escapeHtml(streamUrl)}" type="${ExifInspector.escapeHtml(this.currentFile.mimeType ?? 'video/mp4')}" />
				Deze browser kan deze video niet afspelen.
			</video>`;
		container.appendChild(player);

		if (!thumbLink) {
			const note = document.createElement('p');
			note.innerHTML =
				'<em>Google Drive heeft voor deze video geen thumbnail beschikbaar.</em>';
			container.appendChild(note);
			return;
		}

		for (const {
			key: sizeKey,
			label,
			url,
		} of ExifInspector.buildSortedPreviews(thumbLink)) {
			const item = document.createElement('div');
			item.className = 'preview-item';
			item.innerHTML = `
				<h4>${label}</h4>
				<div class="correction-scope">Door Google Drive gegenereerde videothumbnail</div>
				<div class="preview-image-wrap">
					<img class="preview-image loading" alt="Videothumbnail ${label}" />
					<span class="file-thumb-media-badge">▶</span>
				</div>
				<div class="timing-info">
					<div class="timing-row"><span class="timing-label">Bestandsgrootte:</span><span class="timing-value">...</span></div>
					<div class="timing-row"><span class="timing-label">Netwerk:</span><span class="timing-value">...</span></div>
					<div class="timing-row"><span class="timing-label">Render:</span><span class="timing-value">...</span></div>
				</div>`;
			container.appendChild(item);
			const img = item.querySelector('img');
			if (img) {
				this.fetchAndDisplayPreview(img, url, sizeKey, false);
				this.bindEnlargeOnClick(img, url);
			}
		}
	}

	private rotatePreview(sizeKey: string): void {
		const cur = this.effectiveTransform(sizeKey);
		const next = { ...cur, r: (cur.r + 90) % 360 };
		this.transformsBySize[sizeKey] = next;
		this.applyAllPreviewTransforms();
		if (this.currentFile) {
			void this.saveTransform(this.currentFile.id, sizeKey, next);
		}
		ExifInspector.displayCorrectionsInTable(this.transformsBySize);
		this.updateFolderCorrectionUi();
		this.updateCorrectionIndicators();
	}

	private flipPreview(sizeKey: string, axis: 'h' | 'v'): void {
		const cur = this.effectiveTransform(sizeKey);
		const next =
			axis === 'h' ? { ...cur, h: !cur.h } : { ...cur, v: !cur.v };
		this.transformsBySize[sizeKey] = next;
		this.applyAllPreviewTransforms();
		if (this.currentFile) {
			void this.saveTransform(this.currentFile.id, sizeKey, next);
		}
		ExifInspector.displayCorrectionsInTable(this.transformsBySize);
		this.updateFolderCorrectionUi();
		this.updateCorrectionIndicators();
	}

	private applyToAllSizes(sourceSizeKey: string): void {
		const container = document.getElementById('previews-container');
		if (!container || !this.currentFile) {
			return;
		}
		const src = this.effectiveTransform(sourceSizeKey);
		const file = this.currentFile;
		container
			.querySelectorAll<HTMLElement>('.preview-item[data-size-key]')
			.forEach((item) => {
				const sizeKey = item.getAttribute('data-size-key') ?? '';
				this.transformsBySize[sizeKey] = { ...src };
				ExifInspector.applyTransformToItem(item, src);
				void this.saveTransform(file.id, sizeKey, src);
			});
		this.applyAllPreviewTransforms();
		ExifInspector.displayCorrectionsInTable(this.transformsBySize);
		this.updateFolderCorrectionUi();
		this.updateCorrectionIndicators();
	}

	private updateCorrectionIndicators(): void {
		const container = document.getElementById('previews-container');
		const summary = document.getElementById('photo-correction-summary');
		const resetAll = document.getElementById(
			'reset-photo-corrections'
		) as HTMLButtonElement | null;
		if (!container || !summary) {
			return;
		}

		const items = Array.from(
			container.querySelectorAll<HTMLElement>(
				'.preview-item[data-size-key]'
			)
		);
		const keys = items
			.map((item) => item.dataset['sizeKey'] ?? '')
			.filter(Boolean);
		const ownKeys = keys.filter((key) =>
			Object.prototype.hasOwnProperty.call(this.transformsBySize, key)
		);
		const activeOwnKeys = ownKeys.filter((key) => {
			const t = ExifInspector.maybe(this.transformsBySize[key]);
			return t !== undefined && !ExifInspector.identityTransform(t);
		});
		const firstOwn =
			activeOwnKeys.length > 0
				? this.transformsBySize[activeOwnKeys[0]]
				: undefined;
		const sameOnAllFormats =
			keys.length > 0 &&
			activeOwnKeys.length === keys.length &&
			firstOwn !== undefined &&
			activeOwnKeys.every((key) => {
				const t = ExifInspector.maybe(this.transformsBySize[key]);
				return (
					t !== undefined &&
					t.r === firstOwn.r &&
					t.h === firstOwn.h &&
					t.v === firstOwn.v
				);
			});

		const lines: Array<string> = [];
		const rawExifOrientation =
			this.fullExifData['IFD0:Orientation'] ??
			this.fullExifData['Orientation'];
		const exifOrientation = Number(rawExifOrientation);
		if (
			Number.isInteger(exifOrientation) &&
			exifOrientation >= 1 &&
			exifOrientation <= 8
		) {
			lines.push(
				`EXIF origineel: Orientation ${String(exifOrientation)} · ${ExifInspector.orientationDescription(exifOrientation)}`
			);
		} else {
			lines.push('EXIF origineel: geen Orientation-tag gevonden');
		}

		if (sameOnAllFormats) {
			lines.push(
				`Handmatig: deze foto · alle formaten · ${ExifInspector.transformDescription(firstOwn)}`
			);
		} else if (activeOwnKeys.length > 0) {
			const labels = activeOwnKeys
				.map((key) => ExifInspector.previewSizeLabel(key))
				.join(', ');
			lines.push(`Handmatig: deze foto · alleen ${labels}`);
		} else if (ownKeys.length > 0) {
			lines.push(
				'Handmatig: deze foto · mapcorrectie expliciet genegeerd'
			);
		} else {
			lines.push('Handmatig: geen individuele fotocorrectie');
		}

		const folderKeys = Object.keys(this.folderTransformsBySize).filter(
			(key) => {
				const t = ExifInspector.maybe(this.folderTransformsBySize[key]);
				return t !== undefined && !ExifInspector.identityTransform(t);
			}
		);
		if (folderKeys.length > 0) {
			lines.push(
				`Mapcorrectie: hele map · ${folderKeys.map((key) => ExifInspector.previewSizeLabel(key)).join(', ')}`
			);
		}
		summary.innerHTML = lines
			.map((line) => `<div>${ExifInspector.escapeHtml(line)}</div>`)
			.join('');
		if (resetAll) {
			resetAll.style.display = ownKeys.length > 0 ? '' : 'none';
		}

		for (const item of items) {
			const sizeKey = item.dataset['sizeKey'] ?? '';
			const own = Object.prototype.hasOwnProperty.call(
				this.transformsBySize,
				sizeKey
			);
			const inherited =
				!own &&
				Object.prototype.hasOwnProperty.call(
					this.folderTransformsBySize,
					sizeKey
				);
			const t = this.effectiveTransform(sizeKey);
			const scope = item.querySelector<HTMLElement>('.correction-scope');
			const reset = item.querySelector<HTMLElement>('.reset-variant-btn');
			if (scope) {
				if (own && ExifInspector.identityTransform(t)) {
					scope.textContent = 'Deze foto · mapcorrectie genegeerd';
				} else if (own) {
					scope.textContent = sameOnAllFormats
						? `Deze foto · alle formaten · ${ExifInspector.transformDescription(t)}`
						: `Deze foto · alleen deze variant · ${ExifInspector.transformDescription(t)}`;
				} else if (inherited) {
					scope.textContent = `Hele map · deze variant · ${ExifInspector.transformDescription(t)}`;
				} else {
					scope.textContent = 'Geen handmatige correctie';
				}
			}
			if (reset) {
				reset.style.display = own ? '' : 'none';
			}
		}
	}

	private async resetVariantCorrection(sizeKey: string): Promise<void> {
		if (
			!this.currentFile ||
			!Object.prototype.hasOwnProperty.call(
				this.transformsBySize,
				sizeKey
			)
		) {
			return;
		}
		await this.saveTransform(
			this.currentFile.id,
			sizeKey,
			{ r: 0, h: false, v: false },
			true
		);
		// eslint-disable-next-line @typescript-eslint/no-dynamic-delete -- sizeKey is a controlled internal key ('grid'/'lightbox'/'sNNN'), not user input
		delete this.transformsBySize[sizeKey];
		this.applyAllPreviewTransforms();
		ExifInspector.displayCorrectionsInTable(this.transformsBySize);
		this.updateFolderCorrectionUi();
		this.updateCorrectionIndicators();
	}

	private async resetPhotoCorrections(): Promise<void> {
		if (!this.currentFile) {
			return;
		}
		const currentFileId = this.currentFile.id;
		const keys = Object.keys(this.transformsBySize);
		if (keys.length === 0) {
			return;
		}
		await Promise.all(
			keys.map(async (sizeKey) =>
				this.saveTransform(
					currentFileId,
					sizeKey,
					{ r: 0, h: false, v: false },
					true
				)
			)
		);
		this.transformsBySize = {};
		this.applyAllPreviewTransforms();
		ExifInspector.displayCorrectionsInTable(this.transformsBySize);
		this.updateFolderCorrectionUi();
		this.updateCorrectionIndicators();
	}

	private applyAllPreviewTransforms(): void {
		document
			.querySelectorAll<HTMLElement>(
				'#previews-container .preview-item[data-size-key], ' +
					'#icons-container .preview-item[data-size-key]'
			)
			.forEach((item) => {
				const sizeKey = item.getAttribute('data-size-key') ?? '';
				const t = this.effectiveTransform(sizeKey);
				ExifInspector.applyTransformToItem(item, t);
			});
	}

	private async saveTransform(
		fileId: string,
		sizeKey: string,
		t: { r: number; h: boolean; v: boolean },
		inherit = false
	): Promise<void> {
		const save = fetch(`${this.restUrl}corrections`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': this.nonce,
			},
			body: JSON.stringify({
				file_id: fileId,
				size_key: sizeKey,
				rotation: t.r,
				h_flip: t.h,
				v_flip: t.v,
				inherit,
			}),
			credentials: 'include',
		})
			.then(() => undefined)
			.catch(() => {
				/* non-fatal */
			})
			.finally(() => this.pendingCorrectionSaves.delete(save));
		this.pendingCorrectionSaves.add(save);
		return save;
	}

	private displayIcons(): void {
		const container = document.getElementById('icons-container');
		if (!container) {
			return;
		}

		container.innerHTML = '';
		if (
			this.currentFile?.thumbnailLink === undefined ||
			this.currentFile.thumbnailLink === ''
		) {
			container.innerHTML =
				'<p><em>Geen Google Drive-miniaturen beschikbaar.</em></p>';
			return;
		}

		// s64 = 64px bounded; s64-c = 64px square crop (what Drive uses in list view)
		const base = this.currentFile.thumbnailLink.replace(/=s\d+(-c)?$/, '');
		const variants: Array<{ key: string; url: string; label: string }> = [
			{ key: 's64', url: base + '=s64', label: 's64 (thumbnail)' },
			{
				key: 's64-c',
				url: base + '=s64-c',
				label: 's64-c (vierkant crop)',
			},
		];

		for (const { url, label } of variants) {
			const item = document.createElement('div');
			item.className = 'preview-item';
			item.setAttribute('data-size-key', 'grid');

			item.innerHTML = `
				<h4>${label}</h4>
				<div class="preview-image-wrap" style="height:100px;">
					<img class="icon-image loading" alt="${label}" style="max-width:64px;max-height:64px;" />
					<span class="rotation-badge" style="display:none;"></span>
				</div>
				<div class="timing-info">
					<div class="timing-row">
						<span class="timing-label">Bestandsgrootte:</span>
						<span class="timing-value">...</span>
					</div>
					<div class="timing-row">
						<span class="timing-label">Netwerk:</span>
						<span class="timing-value">...</span>
					</div>
				</div>
			`;

			container.appendChild(item);

			ExifInspector.applyTransformToItem(
				item,
				this.isVideo()
					? { r: 0, h: false, v: false }
					: this.effectiveTransform('grid')
			);
			const img = item.querySelector('img');
			if (img) {
				this.fetchAndDisplayIcon(img, url);
				this.bindEnlargeOnClick(img, url);
			}
		}
	}

	private fetchAndDisplayIcon(img: HTMLImageElement, iconUrl: string): void {
		const previewItem = img.closest<HTMLElement>('.preview-item');
		if (!previewItem) {
			return;
		}
		const startTime = performance.now();

		const proxyUrl =
			this.restUrl + 'proxy-image?url=' + encodeURIComponent(iconUrl);
		fetch(proxyUrl, {
			method: 'GET',
			headers: { 'X-WP-Nonce': this.nonce },
			credentials: 'include',
		})
			.then(async (response) => {
				if (!response.ok) {
					throw new Error(`HTTP ${String(response.status)}`);
				}
				return response.blob();
			})
			.then((blob) => {
				const networkTime = performance.now() - startTime;
				img.onload = (): void => {
					img.classList.remove('loading');
					ExifInspector.applyTransformToItem(
						previewItem,
						this.isVideo()
							? { r: 0, h: false, v: false }
							: this.effectiveTransform('grid')
					);
				};
				img.src = URL.createObjectURL(blob);

				const timingInfo =
					previewItem.querySelector<HTMLElement>('.timing-info');
				if (timingInfo) {
					const sizeInKB = blob.size / 1024;
					const fileSizeText =
						blob.size < 1024
							? `${String(blob.size)} B`
							: `${sizeInKB.toFixed(2)} KB`;
					const rows = timingInfo.querySelectorAll('.timing-value');
					const row0 = ExifInspector.maybe(rows.item(0));
					if (row0) {
						row0.textContent = fileSizeText;
					}
					const row1 = ExifInspector.maybe(rows.item(1));
					if (row1) {
						row1.textContent = `${networkTime.toFixed(2)}ms`;
					}
				}
			})
			.catch(() => {
				img.classList.remove('loading');
				const timingInfo =
					previewItem.querySelector<HTMLElement>('.timing-info');
				if (timingInfo) {
					timingInfo.innerHTML =
						'<div class="error-message">Laden mislukt</div>';
				}
			});
	}

	private fetchAndDisplayPreview(
		img: HTMLImageElement,
		imageUrl: string,
		sizeKey: string,
		applyTransform = true
	): void {
		const previewItem = img.closest<HTMLElement>('.preview-item');
		if (!previewItem) {
			return;
		}
		const startTime = performance.now();
		// Use a numeric key for timing storage; derive from sizeKey or 0 for named keys
		const numericSize = sizeKey.startsWith('s')
			? parseInt(sizeKey.slice(1), 10)
			: 0;

		const proxyUrl =
			this.restUrl + 'proxy-image?url=' + encodeURIComponent(imageUrl);
		fetch(proxyUrl, {
			method: 'GET',
			headers: { 'X-WP-Nonce': this.nonce },
			credentials: 'include',
		})
			.then(async (response) => {
				if (!response.ok) {
					throw new Error(`HTTP ${String(response.status)}`);
				}
				return response.blob();
			})
			.then((blob) => {
				const networkTime = performance.now() - startTime;
				const renderStart = performance.now();

				if (numericSize > 0) {
					this.previewTimings[numericSize] = {
						networkTime,
						renderTime: 0,
						fileSize: blob.size,
					};
				}

				img.onload = (): void => {
					const renderTime = performance.now() - renderStart;
					const timing = ExifInspector.maybe(
						this.previewTimings[numericSize]
					);
					if (numericSize > 0 && timing) {
						timing.renderTime = renderTime;
					}
					img.classList.remove('loading');
					// Fit and apply either the photo correction or inherited folder correction.
					if (applyTransform) {
						ExifInspector.applyTransformToItem(
							previewItem,
							this.effectiveTransform(sizeKey)
						);
					}
					if (numericSize > 0) {
						this.updateTimingDisplay(previewItem, numericSize);
					} else {
						// Named size keys (grid, lightbox): fill in the placeholder rows
						const rows =
							previewItem.querySelectorAll('.timing-value');
						const sizeInKB = blob.size / 1024;
						const sizeInMB = blob.size / (1024 * 1024);
						const sizeDisplay =
							sizeInMB > 1
								? `${sizeInMB.toFixed(2)} MB`
								: `${sizeInKB.toFixed(2)} KB`;
						const row0 = ExifInspector.maybe(rows.item(0));
						if (row0) {
							row0.textContent = sizeDisplay;
						}
						const row1 = ExifInspector.maybe(rows.item(1));
						if (row1) {
							row1.textContent = `${networkTime.toFixed(2)}ms`;
						}
						const row2 = ExifInspector.maybe(rows.item(2));
						if (row2) {
							row2.textContent = `${renderTime.toFixed(2)}ms`;
						}
					}
					// Show actual rendered dimensions; highlight if they differ from API metadata
					this.showRenderedDimensions(previewItem, img);
				};
				img.src = URL.createObjectURL(blob);
			})
			.catch(() => {
				img.classList.remove('loading');
				const timingInfo =
					previewItem.querySelector<HTMLElement>('.timing-info');
				if (timingInfo) {
					timingInfo.innerHTML =
						'<div class="error-message">Laden mislukt</div>';
				}
			});
	}

	private updateTimingDisplay(previewItem: HTMLElement, size: number): void {
		const timing = ExifInspector.maybe(this.previewTimings[size]);
		if (!timing) {
			return;
		}

		const timingInfo =
			previewItem.querySelector<HTMLElement>('.timing-info');
		if (!timingInfo) {
			return;
		}

		let fileSizeHtml = '';
		if (timing.fileSize !== undefined && timing.fileSize > 0) {
			const sizeInKB = timing.fileSize / 1024;
			const sizeInMB = timing.fileSize / (1024 * 1024);
			const sizeDisplay =
				sizeInMB > 1
					? `${sizeInMB.toFixed(2)} MB`
					: `${sizeInKB.toFixed(2)} KB`;
			fileSizeHtml = `
				<div class="timing-row">
					<span class="timing-label">Bestandsgrootte:</span>
					<span class="timing-value">${sizeDisplay}</span>
				</div>
			`;
		}

		timingInfo.innerHTML = `
			${fileSizeHtml}
			<div class="timing-row">
				<span class="timing-label">Netwerk:</span>
				<span class="timing-value">${timing.networkTime.toFixed(2)}ms</span>
			</div>
			<div class="timing-row">
				<span class="timing-label">Render:</span>
				<span class="timing-value">${timing.renderTime.toFixed(2)}ms</span>
			</div>
		`;
	}

	private previousFile(): void {
		if (this.currentFileIndex > 0) {
			this.currentFileIndex--;
			this.updateThumbSelection();
			this.updateTableSelection();
			this.displayCurrentFile();
		}
	}

	private async resumeSlideshow(): Promise<void> {
		if (!this.currentFile) {
			return;
		}
		await Promise.all(Array.from(this.pendingCorrectionSaves));
		const command = {
			type: 'avpvh-resume-slideshow',
			fileId: this.currentFile.id,
			gridCorrection: this.effectiveTransform('grid'),
			lightboxCorrection: this.effectiveTransform('lightbox'),
			sentAt: Date.now(),
		};
		localStorage.setItem(
			'avpvh_slideshow_command',
			JSON.stringify(command)
		);
		const opener = window.opener as Window | null;
		if (opener !== null && !opener.closed) {
			opener.postMessage(command, window.location.origin);
			opener.focus();
			setTimeout(() => {
				window.close();
			}, 100);
			return;
		}

		const button = document.getElementById('resume-slideshow-btn');
		if (button) {
			button.textContent = 'Diavoorstelling hervat';
			setTimeout(() => {
				button.textContent = 'Verder met diavoorstelling';
			}, 1500);
		}
	}

	private gcd(a: number, b: number): number {
		return b === 0 ? a : this.gcd(b, a % b);
	}

	private aspectFraction(w: number, h: number): string {
		const g = this.gcd(w, h);
		return `${String(w / g)}:${String(h / g)}`;
	}

	private showRenderedDimensions(
		previewItem: HTMLElement,
		img: HTMLImageElement
	): void {
		const nw = img.naturalWidth;
		const nh = img.naturalHeight;
		if (!nw || !nh) {
			return;
		}

		const apiW = this.currentFile?.imageMediaMetadata?.width ?? 0;
		const apiH = this.currentFile?.imageMediaMetadata?.height ?? 0;

		const renderedRatio = nw / nh;
		const apiRatio = apiW && apiH ? apiW / apiH : 0;
		const mismatch =
			apiRatio > 0 && Math.abs(renderedRatio - apiRatio) > 0.05;

		const label = document.createElement('div');
		label.className = 'rendered-dims' + (mismatch ? ' dims-mismatch' : '');

		const renderedFrac = this.aspectFraction(nw, nh);
		const apiFrac = apiW && apiH ? this.aspectFraction(apiW, apiH) : '';
		let renderedOrient = 'vierkant';
		if (nw > nh) {
			renderedOrient = 'liggend';
		} else if (nw < nh) {
			renderedOrient = 'staand';
		}
		let apiOrient = '';
		if (apiW && apiH) {
			if (apiW > apiH) {
				apiOrient = 'liggend';
			} else if (apiW < apiH) {
				apiOrient = 'staand';
			} else {
				apiOrient = 'vierkant';
			}
		}

		if (mismatch && apiW && apiH) {
			label.innerHTML =
				`<span title="Afmeting van dit preview-bestand">${String(nw)}×${String(nh)} (${renderedFrac}) ${renderedOrient}</span>` +
				` <span style="color:#c00;font-weight:bold" title="Google heeft dit preview geroteerd t.o.v. het origineel (${String(apiW)}×${String(apiH)})">` +
				`⚠ origineel: ${apiFrac} ${apiOrient}</span>`;
		} else {
			label.textContent = `${String(nw)}×${String(nh)} (${renderedFrac}) ${renderedOrient}`;
			if (apiW && apiH) {
				label.title = `Origineel (API): ${String(apiW)}×${String(apiH)} (${apiFrac})`;
			}
		}

		const existing = previewItem.querySelector('.rendered-dims');
		if (existing) {
			existing.remove();
		}
		const wrap = previewItem.querySelector('.preview-image-wrap');
		if (wrap) {
			wrap.after(label);
		}
	}

	private nextFile(): void {
		if (this.currentFileIndex < this.allFiles.length - 1) {
			this.currentFileIndex++;
			this.updateThumbSelection();
			this.updateTableSelection();
			this.displayCurrentFile();
		}
	}
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', () => {
		new ExifInspector();
	});
} else {
	new ExifInspector();
}
