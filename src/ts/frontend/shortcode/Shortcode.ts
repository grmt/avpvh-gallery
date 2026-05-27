import $ from 'jquery';
import { default as justifiedLayout } from 'justified-layout';
import PhotoSwipe from 'photoswipe';
import PhotoSwipeLightbox from 'photoswipe/lightbox';

import { isError } from '../../isError';
import { printError } from '../../printError';
import { QueryParameter } from './QueryParameter';
import { ShortcodeRegistry } from './ShortcodeRegistry';

export class Shortcode {
	private readonly container: JQuery;
	private readonly hash: string;
	private readonly shortHash: string;

	private readonly pageQueryParameter: QueryParameter;
	private readonly pathQueryParameter: QueryParameter;

	private readonly lightbox: PhotoSwipeLightbox;
	private hasMore = false;
	private path = '';
	private lastPage = 1;
	private loading = false;

	public constructor(container: HTMLElement, hash: string) {
		this.container = $(container);
		this.hash = hash;
		this.shortHash = hash.substring(0, 8);
		this.pageQueryParameter = new QueryParameter(this.shortHash, 'page');
		this.pathQueryParameter = new QueryParameter(this.shortHash, 'path');
		this.path = this.pathQueryParameter.get();
		this.lightbox = this.createLightbox();
		this.lightbox.init();
		this.get();
		$(window).on('popstate', () => {
			this.init();
		});
		$(window).on('resize', () => {
			this.reflow();
		});
	}

	private createLightbox(): PhotoSwipeLightbox {
		const lightbox = new PhotoSwipeLightbox({
			gallery: this.container[0],
			children: 'a.avpvh-grid-a[data-pswp-width]',
			pswpModule: PhotoSwipe,
			showHideAnimationType: 'fade',
			loop: 'true' !== avpvhShortcodeLocalize.preview_quitOnEnd,
			showHideAnimationDuration: parseInt(
				avpvhShortcodeLocalize.preview_speed,
				10
			),
			close: 'true' === avpvhShortcodeLocalize.preview_closebutton,
		});

		lightbox.addFilter('itemData', (itemData) => {
			const el = itemData.element;
			if (el instanceof HTMLElement && 'video' === el.dataset['pswpType']) {
				return {
					...itemData,
					type: 'video',
					videoSrc: el.dataset['avpvhVideoSrc'] ?? '',
					videoMime: el.dataset['avpvhVideoMime'] ?? '',
				};
			}
			return itemData;
		});

		lightbox.on('contentLoad', (e) => {
			if ('video' === e.content.type) {
				e.preventDefault();
				const wrap = document.createElement('div');
				wrap.className = 'avpvh-pswp-video';
				const videoEl = document.createElement('video');
				videoEl.controls = true;
				videoEl.autoplay = true;
				videoEl.style.maxWidth = '100%';
				videoEl.style.maxHeight = '100%';
				const data = e.content.data as Record<string, unknown>;
				const src = data['videoSrc'];
				const mime = data['videoMime'];
				if (typeof src === 'string') {
					const source = document.createElement('source');
					source.src = src;
					if (typeof mime === 'string') {
						source.type = mime;
					}
					videoEl.appendChild(source);
				}
				wrap.appendChild(videoEl);
				e.content.element = wrap;
			}
		});

		if ('true' === avpvhShortcodeLocalize.preview_captions) {
			lightbox.on('uiRegister', () => {
				const pswp = lightbox.pswp;
				if (!pswp) {
					return;
				}
				pswp.ui?.registerElement({
					name: 'avpvh-caption',
					order: 9,
					isButton: false,
					appendTo: 'root',
					onInit: (el, instance) => {
						el.classList.add('avpvh-pswp-caption');
						instance.on('change', () => {
							const slideEl = instance.currSlide?.data.element;
							const caption =
								slideEl instanceof HTMLElement
									? (slideEl.dataset['avpvhCaption'] ?? '')
									: '';
							// Use textContent (NOT innerHTML) - the caption originates from
							// the Drive file description and must not be rendered as HTML.
							el.textContent = caption;
						});
					},
				});
			});
		}

		lightbox.on('change', () => {
			const slide = lightbox.pswp?.currSlide;
			const slideEl = slide?.data.element;
			if (slideEl instanceof HTMLAnchorElement) {
				const id = slideEl.dataset['avpvhId'];
				if (id !== undefined && '' !== id) {
					history.replaceState(history.state, '', '#' + id);
				}
				this.onLightboxNavigation($(slideEl));
			}
		});

		lightbox.on('close', () => {
			this.onLightboxQuit();
			if ('' !== window.location.hash) {
				history.replaceState(
					history.state,
					'',
					window.location.pathname + window.location.search
				);
			}
		});

		return lightbox;
	}

	private static renderMoreButton(): string {
		return (
			'<div class="avpvh-more-button">' +
			'<div>' +
			avpvhShortcodeLocalize.load_more +
			'</div>' +
			'</div>'
		);
	}

	public onLightboxNavigation(e: JQuery): void {
		const page = $(e).data('avpvh-page') as string;
		const children = $(e).parent().children().length;
		history.replaceState(
			history.state,
			'',
			this.pageQueryParameter.add(page)
		);
		if (
			'true' === avpvhShortcodeLocalize.page_autoload &&
			this.hasMore &&
			$(e).index() >= Math.min(children - 2, Math.floor(0.9 * children))
		) {
			this.add();
		}
	}

	public onLightboxQuit(): void {
		history.replaceState(
			history.state,
			'',
			this.pageQueryParameter.remove()
		);
	}

	public reflow(): void {
		const loaded: Array<boolean> = [];
		const ratios: Array<number> = [];
		this.container
			.find('.avpvh-gallery')
			.children()
			.each((i, child) => {
				$(child).css('display', 'inline-block');
				const image = child.firstChild as HTMLImageElement;
				let ratio = image.naturalWidth / image.naturalHeight;
				if (0 < $(child).find('svg').length) {
					const bbox = (
						$(child).find('svg')[0] as SVGGraphicsElement
					).getBBox();
					ratio = bbox.width / bbox.height;
				}
				if ($(child).hasClass('avpvh-grid-square')) {
					ratio = 1;
				}
				if (isNaN(ratio)) {
					loaded[i] = false;
				} else {
					loaded[i] = true;
					ratios.push(ratio);
				}
				$(child).css('position', 'absolute');
			});
		if (0 < ratios.length) {
			this.container.find('.avpvh-loading').remove();
		}
		const positions = justifiedLayout(ratios, {
			containerWidth: this.container.find('.avpvh-gallery').width(),
			containerPadding: { top: 10, left: 0, right: 0, bottom: 0 },
			boxSpacing: parseInt(avpvhShortcodeLocalize.grid_spacing),
			targetRowHeight: parseInt(avpvhShortcodeLocalize.grid_height),
			targetRowHeightTolerance: 0.15,
			edgeCaseMinRowHeight: 0,
		});
		let j = 0;
		this.container
			.find('.avpvh-gallery')
			.children()
			.each((i, child) => {
				if (!loaded[i]) {
					$(child).css('display', 'none');
					return;
				}
				const box = positions.boxes[j];
				const containerPosition = this.container
					.find('.avpvh-gallery')
					.position();
				$(child).css('top', box.top + containerPosition.top);
				$(child).css('left', box.left + containerPosition.left);
				$(child).width(box.width);
				$(child).height(box.height);
				j++;
			});
		this.container.find('.avpvh-gallery').height(positions.containerHeight);
	}

	private reflowTimer(): void {
		ShortcodeRegistry.reflowAll();
		if (this.loading) {
			setTimeout(() => {
				this.reflowTimer();
			}, 250);
		}
	}

	private init(): void {
		const newPath = this.pathQueryParameter.get();
		if (this.path !== newPath) {
			this.path = newPath;
			this.get();
		}
	}

	private get(): void {
		this.path = this.pathQueryParameter.get();
		this.lastPage = parseInt(this.pageQueryParameter.get()) || 1;
		this.container
			.find('.avpvh-gallery')
			.replaceWith('<div class="avpvh-loading"><div></div></div>');
		this.container.find('.avpvh-more-button').remove();
		ShortcodeRegistry.reflowAll();
		void $.get(
			avpvhShortcodeLocalize.ajax_url,
			{
				action: 'gallery',
				hash: this.hash,
				path: this.path,
				page: this.lastPage,
			},
			(data: GalleryResponse) => {
				if (isError(data)) {
					this.container.html(
						printError(data, avpvhShortcodeLocalize)
					);
					return;
				}
				this.getSuccess(data);
			}
		);
	}

	private getSuccess(data: GallerySuccessResponse): void {
		const pageLength =
			((data.directories ? data.directories.length : 0) +
				(data.images ? data.images.length : 0) +
				(data.videos ? data.videos.length : 0)) /
			this.lastPage;
		let html = '';
		let currentPage = 1;
		let remaining = pageLength;
		if (
			(data.path !== undefined && 0 < data.path.length) ||
			(data.directories !== undefined && 0 < data.directories.length)
		) {
			html += this.renderBreadcrumbs(data.path ?? []);
		}
		if (
			(data.directories !== undefined && 0 < data.directories.length) ||
			(data.images !== undefined && 0 < data.images.length) ||
			(data.videos !== undefined && 0 < data.videos.length)
		) {
			html +=
				'<div class="avpvh-loading">' +
				'<div>' +
				'</div>' +
				'</div>' +
				'<div class="avpvh-gallery">';
			if (data.directories) {
				$.each(data.directories, (_, directory) => {
					html += this.renderDirectory(directory);
					remaining--;
					if (0 === remaining) {
						remaining = pageLength;
						currentPage++;
					}
				});
			}
			if (data.images) {
				$.each(data.images, (_, image) => {
					html += this.renderImage(currentPage, image);
					remaining--;
					if (0 === remaining) {
						remaining = pageLength;
						currentPage++;
					}
				});
			}
			if (data.videos) {
				$.each(data.videos, (_, video) => {
					if (
						'' !==
						document
							.createElement('video')
							.canPlayType(video.mimeType)
					) {
						html += this.renderVideo(currentPage, video);
					}
					remaining--;
					if (0 === remaining) {
						remaining = pageLength;
						currentPage++;
					}
				});
			}
			html += '</div>';
			if (data.more === true) {
				html += Shortcode.renderMoreButton();
			}
		} else {
			html +=
				'<div class="avpvh-gallery">' +
				avpvhShortcodeLocalize.empty_gallery +
				'</div>';
		}
		this.container.html(html);
		this.hasMore = data.more ?? false;
		this.postLoad();
		this.openFromHash();
	}

	private openFromHash(): void {
		const hash = window.location.hash.replace(/^#/, '');
		if ('' === hash) {
			return;
		}
		const links = this.container
			.find('a.avpvh-grid-a[data-pswp-width]')
			.get();
		const index = links.findIndex(
			(el) => el.getAttribute('data-avpvh-id') === hash
		);
		if (0 <= index) {
			this.lightbox.loadAndOpen(index);
		}
	}

	private add(): void {
		this.lastPage += 1;
		this.container
			.find('.avpvh-gallery')
			.after(
				'<div class="avpvh-loading">' + '<div>' + '</div>' + '</div>'
			);
		this.container.find('.avpvh-more-button').remove();
		void $.get(
			avpvhShortcodeLocalize.ajax_url,
			{
				action: 'page',
				hash: this.hash,
				path: this.pathQueryParameter.get(),
				page: this.lastPage,
			},
			(data: PageResponse) => {
				if (isError(data)) {
					this.container
						.find('.avpvh-loading')
						.replaceWith(printError(data, avpvhShortcodeLocalize));
					this.container.find('.avpvh-more-button').remove();
					return;
				}
				this.addSuccess(data);
			}
		);
	}

	private addSuccess(data: PageSuccessResponse): void {
		let html = '';
		$.each(data.directories, (_, directory) => {
			html += this.renderDirectory(directory);
		});
		$.each(data.images, (_, image) => {
			html += this.renderImage(this.lastPage, image);
		});
		$.each(data.videos, (_, video) => {
			html += this.renderVideo(this.lastPage, video);
		});
		this.container.find('.avpvh-gallery').append(html);
		this.hasMore = data.more ?? false;
		if (data.more === true) {
			this.container.append(Shortcode.renderMoreButton());
		}
		this.container.find('.avpvh-loading').remove();
		this.postLoad();
	}

	private fixPhotoSwipeDimensions(): void {
		this.container.find('a.avpvh-grid-a[data-pswp-width]').each((_, el) => {
			const img = el.querySelector('img');
			if (img === null || img.naturalWidth === 0 || img.naturalHeight === 0) {
				return;
			}
			// Extract the preview size from the href URL which ends with =s{size}
			const sizeMatch = /=s(\d+)$/.exec((el as HTMLAnchorElement).href);
			if (sizeMatch === null) {
				return;
			}
			const size = parseInt(sizeMatch[1], 10);
			// Use the thumbnail's natural dimensions to determine the true display aspect ratio.
			// Google Drive serves =h{n} thumbnails in display orientation (EXIF rotation applied),
			// so naturalWidth/naturalHeight reflects the correct portrait/landscape proportions.
			const ratio = img.naturalWidth / img.naturalHeight;
			let newW: number;
			let newH: number;
			if (ratio >= 1) {
				// Landscape: width is the longest side
				newW = size;
				newH = Math.round(size / ratio);
			} else {
				// Portrait: height is the longest side
				newH = size;
				newW = Math.round(size * ratio);
			}
			el.setAttribute('data-pswp-width', String(newW));
			el.setAttribute('data-pswp-height', String(newH));
		});
	}

	private postLoad(): void {
		this.container
			.find('a[data-avpvh-path]')
			.off('click.avpvh')
			.on('click.avpvh', (e) => {
				history.pushState(
					{},
					'',
					this.pathQueryParameter.add(
						$(e.currentTarget).data('avpvhPath') as string
					)
				);
				this.get();
				return false;
			});
		this.container.find('.avpvh-more-button').on('click', () => {
			this.add();
			return false;
		});

		this.loading = true;
		void this.container
			.find('.avpvh-gallery')
			.imagesLoaded({ background: true }, () => {
				this.loading = false;
				this.fixPhotoSwipeDimensions();
				ShortcodeRegistry.reflowAll();
			});
		this.reflowTimer();

		if ('true' === avpvhShortcodeLocalize.page_autoload) {
			$(window)
				.off('scroll.avpvh')
				.on('scroll.avpvh', (event) => {
					const moreButton = $('.avpvh-more-button');
					const targetScrollTop = $(event.currentTarget).scrollTop();
					const windowHeight = $(window).height();
					const moreButtonOffset = moreButton.offset();
					const moreButtonOuterHeight = moreButton.outerHeight();
					if (
						targetScrollTop === undefined ||
						windowHeight === undefined ||
						moreButtonOffset === undefined ||
						moreButtonOuterHeight === undefined
					) {
						return;
					}
					const inView =
						targetScrollTop + windowHeight >
						moreButtonOffset.top + moreButtonOuterHeight;
					if (inView && !this.loading) {
						this.add();
					}
				});
		}
	}

	private renderBreadcrumbs(path: Array<PartialDirectory>): string {
		let html =
			'<div>' +
			'<a data-avpvh-path="" href="' +
			this.pathQueryParameter.remove() +
			'">' +
			avpvhShortcodeLocalize.breadcrumbs_top +
			'</a>';
		let field = '';
		$.each(path, (_, crumb) => {
			field += crumb.id;
			html +=
				' > ' +
				'<a data-avpvh-path="' +
				field +
				'" href="' +
				this.pathQueryParameter.add(field) +
				'">' +
				crumb.name +
				'</a>';
			field += '/';
		});
		html += '</div>';
		return html;
	}

	private renderDirectory(directory: Directory): string {
		let newPath = this.pathQueryParameter.get();
		newPath = (newPath ? newPath + '/' : '') + directory.id;
		let html =
			'<a class="avpvh-grid-a avpvh-grid-square" data-avpvh-path="' +
			newPath +
			'" href="' +
			this.pathQueryParameter.add(newPath) +
			'"';
		if (directory.thumbnail) {
			html +=
				' style="background-image: url(\'' +
				directory.thumbnail +
				'\');">';
		} else {
			html +=
				'>' +
				'<svg class="avpvh-dir-icon" x="0px" y="0px" focusable="false" viewBox="0 0 24 24" fill="#8f8f8f">' +
				'<path d="M10 4H4c-1.1 0-2 .9-2 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z">' +
				'</path>' +
				'</svg>';
		}
		html +=
			'<div class="avpvh-dir-overlay">' +
			'<div class="avpvh-dir-name">' +
			directory.name +
			'</div>';
		if (directory.dircount !== undefined) {
			html +=
				'<span class="avpvh-count-icon dashicons dashicons-category">' +
				'</span> ' +
				directory.dircount.toString() +
				(1000 === directory.dircount ? '+' : '');
		}
		if (directory.imagecount !== undefined) {
			let iconClass = '';
			if (directory.dircount !== undefined) {
				iconClass = ' avpvh-count-icon-indent';
			}
			html +=
				'<span class="avpvh-count-icon dashicons dashicons-format-image' +
				iconClass +
				'">' +
				'</span> ' +
				directory.imagecount.toString() +
				(1000 === directory.imagecount ? '+' : '');
		}
		if (directory.videocount !== undefined) {
			let iconClass = '';
			if (
				directory.dircount !== undefined ||
				directory.imagecount !== undefined
			) {
				iconClass = ' avpvh-count-icon-indent';
			}
			html +=
				'<span class="avpvh-count-icon dashicons dashicons-video-alt3' +
				iconClass +
				'">' +
				'</span> ' +
				directory.videocount.toString() +
				(1000 === directory.videocount ? '+' : '');
		}
		html += '</div></a>';
		return html;
	}

	private static formatExposure(exposure: number): string {
		if (exposure >= 1) {
			return String(Math.round(exposure)) + 's';
		}
		return '1/' + String(Math.round(1 / exposure)) + 's';
	}

	private static formatExifDate(time: string): string {
		// EXIF time format: "YYYY:MM:DD HH:MM:SS"
		const match = /^(\d{4}):(\d{2}):(\d{2})/.exec(time);
		if (match === null) {
			return '';
		}
		return match[1] + '-' + match[2] + '-' + match[3];
	}

	private static renderExifOverlay(name: string, exif: ImageExif | undefined): string {
		const parts: Array<string> = [];
		if (exif !== undefined) {
			if (exif.time !== undefined) {
				const d = Shortcode.formatExifDate(exif.time);
				if ('' !== d) {
					parts.push(d);
				}
			}
			const camera = [exif.make, exif.model].filter((x) => x !== undefined).join(' ');
			if ('' !== camera) {
				parts.push(camera);
			}
			if (exif.focal !== undefined) {
				parts.push(String(exif.focal) + 'mm');
			}
			if (exif.aperture !== undefined) {
				parts.push('f/' + String(exif.aperture));
			}
			if (exif.exposure !== undefined) {
				parts.push(Shortcode.formatExposure(exif.exposure));
			}
			if (exif.iso !== undefined) {
				parts.push('ISO ' + String(exif.iso));
			}
		}
		if ('' === name && 0 === parts.length) {
			return '';
		}
		const nameHtml = '' !== name
			? '<div class="avpvh-exif-filename">' + name + '</div>'
			: '';
		const exifHtml = 0 < parts.length
			? '<div class="avpvh-exif-data">' + parts.join(' · ') + '</div>'
			: '';
		return '<div class="avpvh-exif-overlay">' + nameHtml + exifHtml + '</div>';
	}

	private renderImage(page: number, image: Image): string {
		const width = 0 < image.width ? image.width : 2000;
		const height = 0 < image.height ? image.height : 1500;
		return (
			'<a class="avpvh-grid-a" ' +
			'data-pswp-width="' +
			width.toString() +
			'" ' +
			'data-pswp-height="' +
			height.toString() +
			'" ' +
			'data-avpvh-id="' +
			image.id +
			'" ' +
			'data-avpvh-caption="' +
			image.description +
			'" ' +
			'data-avpvh-page="' +
			page.toString() +
			'" ' +
			'href="' +
			image.image +
			'">' +
			'<img class="avpvh-grid-img" src="' +
			image.thumbnail +
			'">' +
			Shortcode.renderExifOverlay(image.name, image.exif) +
			'</a>'
		);
	}

	private renderVideo(page: number, video: Video): string {
		const width = 'number' === typeof video.width ? video.width : 1920;
		const height = 'number' === typeof video.height ? video.height : 1080;
		return (
			'<a class="avpvh-grid-a" ' +
			'data-pswp-width="' +
			width.toString() +
			'" ' +
			'data-pswp-height="' +
			height.toString() +
			'" ' +
			'data-pswp-type="video" ' +
			'data-avpvh-id="' +
			video.id +
			'" ' +
			'data-avpvh-page="' +
			page.toString() +
			'" ' +
			'data-avpvh-video-src="' +
			video.src +
			'" ' +
			'data-avpvh-video-mime="' +
			video.mimeType +
			'" ' +
			'href="' +
			video.src +
			'">' +
			'<img class="avpvh-grid-img" src="' +
			video.thumbnail +
			'">' +
			'</a>'
		);
	}
}
