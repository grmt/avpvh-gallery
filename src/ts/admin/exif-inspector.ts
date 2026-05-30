interface ExifData {
	aperture?: number;
	exposure?: number;
	focal?: number;
	iso?: number;
	make?: string;
	model?: string;
	time?: string;
	orientation?: number;
}

interface FileData {
	id: string;
	name: string;
	thumbnailLink: string;
	webContentLink?: string;
	mimeType?: string;
	size?: string;
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
	description?: string;
	createdTime?: string;
}

interface TimingData {
	networkTime: number;
	renderTime: number;
	fileSize?: number;
}

interface PreviewSize {
	size: number;
	timing?: TimingData;
	error?: string;
}

declare const avpvhExifInspector: {
	rest_url: string;
	root_id: string;
	nonce: string;
};

class ExifInspector {
	private rootId: string;
	private restUrl: string;
	private nonce: string;
	private currentPath: string[] = [];
	private currentFile: FileData | null = null;
	private allFiles: FileData[] = [];
	private currentFileIndex: number = -1;
	private previewTimings: Record<number, TimingData> = {};
	private fullExifData: Record<string, string | number> = {};

	constructor() {
		this.rootId = avpvhExifInspector.root_id;
		this.restUrl = avpvhExifInspector.rest_url;
		this.nonce = avpvhExifInspector.nonce;
		this.init();
	}

	private init() {
		const root = document.getElementById('avpvh-exif-inspector-root');
		if (!root) return;

		const lastPath = localStorage.getItem('avpvh_exif_inspector_last_path') || '';

		root.innerHTML = `
			<div class="avpvh-exif-inspector">
				<div class="path-input-section">
					<label>File Path:
						<input type="text" id="path-input" placeholder="e.g., 01-Opgravingen / 1976 Grobbendonk / PICT0250.JPG" value="${this.escapeHtml(lastPath)}" />
					</label>
					<button id="load-btn">Load</button>
				</div>

				<div id="loading" style="display: none;">Loading...</div>

				<div class="file-info-section" style="display: none;">
					<div class="file-header">
						<h2 id="file-name"></h2>
						<div class="file-nav">
							<button id="prev-btn" disabled>&larr; Previous</button>
							<span id="file-count"></span>
							<button id="next-btn" disabled>Next &rarr;</button>
						</div>
					</div>

					<div class="exif-section">
						<h3>EXIF Data & File Info</h3>
						<table id="exif-table" class="exif-table">
							<tbody></tbody>
						</table>
					</div>

					<div class="original-section">
						<h3>Original Image</h3>
						<div id="original-container" class="original-container">
							<img id="original-image" alt="Original" class="original-image" />
							<div id="original-info" class="original-info">
								<a id="original-download-link" target="_blank" class="download-link">Download Original</a>
								<p id="original-size"></p>
							</div>
						</div>
					</div>

					<div class="previews-section">
						<h3>Preview Sizes with Timings</h3>
						<div id="previews-container" class="previews-container"></div>
					</div>
				</div>

				<style>
					.avpvh-exif-inspector {
						padding: 20px;
						max-width: 1600px;
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

					.file-header {
						margin-bottom: 20px;
						border-bottom: 2px solid #ddd;
						padding-bottom: 15px;
					}

					.file-header h2 {
						margin: 0 0 10px 0;
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
					.original-section h3,
					.previews-section h3 {
						margin-top: 0;
						margin-bottom: 15px;
						font-size: 16px;
						color: #333;
					}

					.original-section {
						margin-bottom: 30px;
					}

					.original-container {
						border: 1px solid #ddd;
						border-radius: 4px;
						padding: 15px;
						background-color: #fafafa;
						display: flex;
						gap: 20px;
						align-items: flex-start;
					}

					.original-image {
						max-width: 400px;
						max-height: 400px;
						border-radius: 4px;
						background-color: #fff;
					}

					.original-info {
						flex: 1;
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

					#original-size {
						margin: 10px 0 0 0;
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

					.preview-item h4 {
						margin: 0 0 10px 0;
						font-size: 14px;
						color: #333;
					}

					.preview-image {
						max-width: 100%;
						height: auto;
						border-radius: 4px;
						margin-bottom: 10px;
						background-color: #fff;
						display: block;
					}

					.preview-image.loading {
						opacity: 0.5;
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
				</style>
			</div>
		`;

		document.getElementById('load-btn')?.addEventListener('click', () => this.loadFile());
		document.getElementById('prev-btn')?.addEventListener('click', () => this.previousFile());
		document.getElementById('next-btn')?.addEventListener('click', () => this.nextFile());

		const pathInput = document.getElementById('path-input') as HTMLInputElement;
		if (pathInput) {
			pathInput.addEventListener('keypress', (e) => {
				if (e.key === 'Enter') {
					this.loadFile();
				}
			});
		}
	}

	private escapeHtml(text: string): string {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	private async loadFile() {
		console.log('loadFile called');
		const pathInput = document.getElementById('path-input') as HTMLInputElement;
		const path = pathInput.value.trim();
		console.log('Path:', path);

		if (!path) {
			this.showError('Please enter a file path');
			return;
		}

		// Save the path for next time
		localStorage.setItem('avpvh_exif_inspector_last_path', path);

		this.showLoading(true);
		this.clearError();
		try {
			// Parse the path and navigate to the file
			const parts = path.split('/').map((p) => p.trim()).filter((p) => p);

			if (parts.length === 0) {
				this.showError('Please enter a valid file path');
				this.showLoading(false);
				return;
			}

			const fileName = parts.pop()!;

			// Navigate to the folder
			let currentId = this.rootId;
			console.log('Starting folder navigation with', parts.length, 'parts:', parts);
			for (const folderName of parts) {
				console.log('Navigating to:', folderName);
				currentId = await this.navigateToFolder(currentId, folderName);
				console.log('Found folder:', currentId);
			}

			// List files in the folder and find the matching file
			console.log('Listing files in folder:', currentId);
			await this.listFilesInFolder(currentId);
			console.log('Found', this.allFiles.length, 'files');
			const fileIndex = this.allFiles.findIndex((f) => f.name === fileName);
			console.log('File index:', fileIndex, 'looking for:', fileName);

			if (fileIndex === -1) {
				this.showError(`File not found: ${fileName}`);
				this.showLoading(false);
				return;
			}

			this.currentFileIndex = fileIndex;
			this.displayCurrentFile();
		} catch (error) {
			this.showError(`Error: ${error instanceof Error ? error.message : 'Unknown error'}`);
		} finally {
			this.showLoading(false);
		}
	}

	private async navigateToFolder(parentId: string, folderName: string): Promise<string> {
		const response = await fetch(`${this.restUrl}list-folders`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': this.nonce,
			},
			body: JSON.stringify({ parent_id: parentId, folder_name: folderName }),
			credentials: 'include',
		});

		if (!response.ok) {
			let errorMsg = response.statusText;
			try {
				const errorData = (await response.json()) as { message?: string; code?: string };
				errorMsg = errorData.message || errorMsg;
			} catch (e) {
				// Could not parse JSON error response
			}
			throw new Error(`Folder not found: "${folderName}" (HTTP ${response.status}: ${errorMsg})`);
		}

		const data = (await response.json()) as { folder_id: string };
		return data.folder_id;
	}

	private async listFilesInFolder(folderId: string) {
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
				const errorData = (await response.json()) as { message?: string; code?: string };
				errorMsg = errorData.message || errorMsg;
			} catch (e) {
				// Could not parse JSON error response
			}
			throw new Error(`Failed to load files: HTTP ${response.status} - ${errorMsg}`);
		}

		const data = (await response.json()) as { files: FileData[] };
		this.allFiles = data.files;
	}

	private displayCurrentFile() {
		if (this.currentFileIndex < 0 || this.currentFileIndex >= this.allFiles.length) {
			return;
		}

		const fileSection = document.querySelector('.file-info-section') as HTMLElement;
		if (fileSection) {
			fileSection.style.display = 'block';
		}

		this.currentFile = this.allFiles[this.currentFileIndex];
		this.previewTimings = {};
		this.fullExifData = {};

		// Update file header
		const fileName = document.getElementById('file-name');
		if (fileName) {
			fileName.textContent = this.currentFile.name;
		}

		const fileCount = document.getElementById('file-count');
		if (fileCount) {
			fileCount.textContent = `${this.currentFileIndex + 1} / ${this.allFiles.length}`;
		}

		// Update nav buttons
		const prevBtn = document.getElementById('prev-btn') as HTMLButtonElement;
		const nextBtn = document.getElementById('next-btn') as HTMLButtonElement;
		if (prevBtn) prevBtn.disabled = this.currentFileIndex === 0;
		if (nextBtn) nextBtn.disabled = this.currentFileIndex === this.allFiles.length - 1;

		// Fetch full EXIF data
		this.fetchFullExifData();

		// Display EXIF data
		this.displayExifData();

		// Display original image
		this.displayOriginalImage();

		// Display previews
		this.displayPreviews();
	}

	private fetchFullExifData() {
		if (!this.currentFile) {
			return;
		}

		if (!this.currentFile.webContentLink) {
			console.log('No download link available for file:', this.currentFile.id);
			return;
		}

		console.log('Fetching full EXIF data for file:', this.currentFile.id);

		fetch(this.restUrl + 'full-exif', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': this.nonce,
			},
			body: JSON.stringify({
				file_id: this.currentFile.id,
				download_link: this.currentFile.webContentLink,
			}),
		})
			.then(response => {
				if (!response.ok) {
					console.log('Full EXIF fetch returned', response.status, '- will use API metadata only');
					return null;
				}
				return response.json();
			})
			.then(data => {
				if (data && data.exif) {
					console.log('Received full EXIF data:', data.exif);
					this.fullExifData = data.exif;
					// Update the EXIF display with new data
					this.displayExifData();
				}
			})
			.catch(error => {
				console.log('Error fetching full EXIF data:', error);
				// Continue with API metadata only
			});
	}

	private displayExifData() {
		const tbody = document.querySelector('.exif-table tbody') as HTMLTableSectionElement;
		if (!tbody || !this.currentFile) {
			return;
		}

		tbody.innerHTML = '';

		// Add file info
		if (this.currentFile.size) {
			const sizeInMB = (parseInt(this.currentFile.size, 10) / (1024 * 1024)).toFixed(2);
			this.addTableRow(tbody, 'File Size', `${sizeInMB} MB`);
		}

		if (this.currentFile.mimeType) {
			this.addTableRow(tbody, 'MIME Type', this.currentFile.mimeType);
		}

		// If we have full EXIF data, display it all
		if (Object.keys(this.fullExifData).length > 0) {
			console.log('Displaying full EXIF data');
			this.displayFullExifFields(tbody);
			return;
		}

		// Otherwise, fall back to API metadata
		const metadata = this.currentFile.imageMediaMetadata;
		if (!metadata) {
			tbody.innerHTML += '<tr><td colspan="2"><em>No EXIF data available</em></td></tr>';
			return;
		}

		if (metadata.width && metadata.height) {
			this.addTableRow(tbody, 'Dimensions', `${metadata.width} × ${metadata.height} px`);
		}

		// Camera info
		if (metadata.cameraMake) this.addTableRow(tbody, 'Camera Make', metadata.cameraMake);
		if (metadata.cameraModel) this.addTableRow(tbody, 'Camera Model', metadata.cameraModel);

		// Exposure info
		if (metadata.aperture) this.addTableRow(tbody, 'Aperture', `f/${metadata.aperture}`);
		if (metadata.exposureTime) {
			const expTime = metadata.exposureTime < 1 ? `1/${Math.round(1 / metadata.exposureTime)}` : metadata.exposureTime;
			this.addTableRow(tbody, 'Exposure Time', `${expTime}s`);
		}
		if (metadata.isoSpeed) this.addTableRow(tbody, 'ISO Speed', String(metadata.isoSpeed));

		// Focus info
		if (metadata.focalLength) this.addTableRow(tbody, 'Focal Length', `${metadata.focalLength} mm`);

		// Date info
		if (metadata.time) this.addTableRow(tbody, 'Date/Time', metadata.time);
		if (metadata.rotation !== undefined && metadata.rotation !== 0) {
			this.addTableRow(tbody, 'Orientation', `${metadata.rotation} - ${this.orientationDescription(metadata.rotation)}`);
		}
	}

	private displayFullExifFields(tbody: HTMLTableSectionElement) {
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
		const sortedEntries = Object.entries(this.fullExifData).sort(([a], [b]) => {
			const aSection = a.split(':')[0];
			const bSection = b.split(':')[0];
			if (aSection !== bSection) {
				return aSection.localeCompare(bSection);
			}
			return a.localeCompare(b);
		});

		// Display each EXIF field
		for (const [key, value] of sortedEntries) {
			if (typeof value === 'object') {
				continue; // Skip complex objects
			}

			const label = fieldLabels[key] || key;
			const displayValue = this.formatExifValue(key, value);
			this.addTableRow(tbody, label, displayValue);
		}

		if (sortedEntries.length === 0) {
			tbody.innerHTML += '<tr><td colspan="2"><em>No EXIF data available</em></td></tr>';
		}
	}

	private formatExifValue(key: string, value: string | number): string {
		if (typeof value === 'number') {
			value = String(value);
		}

		// Format specific fields
		if (key.includes('ExposureTime')) {
			const num = parseFloat(value);
			if (num < 1) {
				return `1/${Math.round(1 / num)}s`;
			}
			return `${num}s`;
		}

		if (key.includes('FocalLength') || key.includes('Focal')) {
			return `${value}mm`;
		}

		if (key.includes('Resolution')) {
			return `${value}dpi`;
		}

		if (key === 'IFD0:Orientation') {
			const orientation = parseInt(value, 10);
			return `${value} - ${this.orientationDescription(orientation)}`;
		}

		// Truncate very long strings
		if (value.length > 100) {
			return value.substring(0, 100) + '...';
		}

		return value;
	}

	private addTableRow(tbody: HTMLTableSectionElement, label: string, value: string) {
		const row = tbody.insertRow();
		const cellLabel = row.insertCell(0);
		const cellValue = row.insertCell(1);
		cellLabel.textContent = label;
		cellValue.textContent = value;
	}

	private orientationDescription(rotation: number): string {
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
		return descriptions[rotation] || `Unknown (${rotation})`;
	}

	private displayOriginalImage() {
		if (!this.currentFile) {
			return;
		}

		const img = document.getElementById('original-image') as HTMLImageElement;
		const downloadLink = document.getElementById('original-download-link') as HTMLAnchorElement;
		const sizeInfo = document.getElementById('original-size') as HTMLElement;

		if (img && this.currentFile.thumbnailLink) {
			// Show a large preview from the thumbnail
			img.src = this.buildPreviewUrl(this.currentFile.thumbnailLink, 1920);
			img.alt = this.currentFile.name;
		}

		if (downloadLink && this.currentFile.webContentLink) {
			downloadLink.href = this.currentFile.webContentLink;
			downloadLink.textContent = `Download Original (${this.currentFile.mimeType || 'Image'})`;
			downloadLink.style.display = 'inline-block';
		} else if (downloadLink) {
			downloadLink.style.display = 'none';
		}

		if (sizeInfo && this.currentFile.size) {
			const sizeInMB = (parseInt(this.currentFile.size, 10) / (1024 * 1024)).toFixed(2);
			sizeInfo.textContent = `File size: ${sizeInMB} MB`;
		}
	}

	private displayPreviews() {
		const container = document.getElementById('previews-container');
		if (!container || !this.currentFile) {
			return;
		}

		container.innerHTML = '';

		const sizes = [256, 512, 1024, 1920];
		for (const size of sizes) {
			const previewUrl = this.buildPreviewUrl(this.currentFile.thumbnailLink, size);
			const item = document.createElement('div');
			item.className = 'preview-item';

			item.innerHTML = `
				<h4>${size}px</h4>
				<img class="preview-image loading" alt="Preview ${size}px" src="${previewUrl}" data-size="${size}" />
				<div class="timing-info">
					<div class="timing-row">
						<span class="timing-label">Network:</span>
						<span class="timing-value">...</span>
					</div>
					<div class="timing-row">
						<span class="timing-label">Render:</span>
						<span class="timing-value">...</span>
					</div>
				</div>
			`;

			container.appendChild(item);

			const img = item.querySelector('img') as HTMLImageElement;
			this.measureImageLoad(img, size);
		}
	}

	private buildPreviewUrl(thumbnailLink: string, size: number): string {
		// Replace the size parameter in the thumbnail link
		// Thumbnail links end with =s256 or similar
		return thumbnailLink.replace(/=s\d+$/, `=s${size}`);
	}

	private measureImageLoad(img: HTMLImageElement, size: number) {
		const startTime = performance.now();

		const onLoad = () => {
			const totalTime = performance.now() - startTime;
			if (!this.previewTimings[size]) {
				this.previewTimings[size] = { networkTime: 0, renderTime: 0 };
			}
			this.previewTimings[size].networkTime = totalTime;
			this.previewTimings[size].renderTime = 0;

			img.classList.remove('loading');
			this.updateTimingDisplay(img.closest('.preview-item') as HTMLElement, size);
		};

		const onError = () => {
			img.classList.remove('loading');
			const parent = img.closest('.preview-item') as HTMLElement;
			const timingInfo = parent.querySelector('.timing-info') as HTMLElement;
			if (timingInfo) {
				timingInfo.innerHTML =
					'<div class="error-message">Failed to load image</div>';
			}
		};

		img.addEventListener('load', onLoad, { once: true });
		img.addEventListener('error', onError, { once: true });
		img.src = img.dataset['src'] || img.src;
	}

	private updateTimingDisplay(previewItem: HTMLElement, size: number) {
		const timing = this.previewTimings[size];
		if (!timing) {
			return;
		}

		const timingInfo = previewItem.querySelector('.timing-info') as HTMLElement;
		if (!timingInfo) {
			return;
		}

		timingInfo.innerHTML = `
			<div class="timing-row">
				<span class="timing-label">Network:</span>
				<span class="timing-value">${timing.networkTime.toFixed(2)}ms</span>
			</div>
			<div class="timing-row">
				<span class="timing-label">Render:</span>
				<span class="timing-value">${timing.renderTime.toFixed(2)}ms</span>
			</div>
		`;
	}

	private previousFile() {
		if (this.currentFileIndex > 0) {
			this.currentFileIndex--;
			this.displayCurrentFile();
		}
	}

	private nextFile() {
		if (this.currentFileIndex < this.allFiles.length - 1) {
			this.currentFileIndex++;
			this.displayCurrentFile();
		}
	}

	private showLoading(show: boolean) {
		const loading = document.getElementById('loading');
		if (loading) {
			loading.style.display = show ? 'block' : 'none';
		}
	}

	private showError(message: string) {
		const pathSection = document.querySelector('.path-input-section') as HTMLElement;
		if (!pathSection) return;

		// Remove any existing error message
		this.clearError();

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

	private clearError() {
		const errorDiv = document.getElementById('error-message');
		if (errorDiv) {
			errorDiv.remove();
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
