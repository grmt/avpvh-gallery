import $ from 'jquery';
import { default as justifiedLayout } from 'justified-layout';
import PhotoSwipe from 'photoswipe';
import PhotoSwipeLightbox from 'photoswipe/lightbox';

import { isError } from '../../isError';
import { printError } from '../../printError';
import { QueryParameter } from './QueryParameter';
import { ShortcodeRegistry } from './ShortcodeRegistry';
import { PhotoTagger } from '../photo-tagger/PhotoTagger';

export class Shortcode {
	private readonly container: JQuery;
	private readonly hash: string;
	private readonly shortHash: string;

	private readonly pageQueryParameter: QueryParameter;
	private readonly pathQueryParameter: QueryParameter;

	private readonly lightbox: PhotoSwipeLightbox;
	private readonly photoTagger: PhotoTagger;
	private hasMore = false;
	private path = '';
	private lastPage = 1;
	private loading = false;
	private currentPathNames = '';
	private slideshowTimer: ReturnType<typeof setTimeout> | null = null;
	private idleTimer: ReturnType<typeof setTimeout> | null = null;
	private pendingLightboxOpen: 'first' | 'last' | null = null;
	private pendingLightboxAdvance = false;
	private folderNavigating = false;
	private slideshowPaused = false;

	private readonly SLIDESHOW_DELAY_MS = 5000;
	private readonly IDLE_HIDE_MS = 3000;

	// Resolved once at construction from the page's <link rel="icon"> so it
	// works regardless of whether WordPress's site-icon setting is configured.
	private readonly faviconUrl: string;

	public constructor(container: HTMLElement, hash: string) {
		this.container = $(container);
		this.hash = hash;
		this.shortHash = hash.substring(0, 8);
		const faviconEl =
			document.querySelector<HTMLLinkElement>('link[rel~="icon"]') ??
			document.querySelector<HTMLLinkElement>('link[rel="shortcut icon"]');
		this.faviconUrl = faviconEl !== null ? faviconEl.href : avpvhShortcodeLocalize.favicon_url;
		this.pageQueryParameter = new QueryParameter(this.shortHash, 'page');
		this.pathQueryParameter = new QueryParameter(this.shortHash, 'path');
		this.path = this.pathQueryParameter.get();
		this.lightbox = this.createLightbox();
		this.lightbox.init();
		this.photoTagger = new PhotoTagger();
		this.photoTagger.init(container);
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
			} else if ('image' === e.content.type && e.content.element instanceof HTMLImageElement) {
				// Check if this image needs horizontal flip correction by comparing aspect ratios
				// When Google Drive serves preview with different EXIF handling, aspect ratios mismatch
				const slideEl = e.slide?.data.element;
				if (slideEl instanceof HTMLAnchorElement) {
					const thumb = slideEl.querySelector('img');
					if (thumb !== null && thumb.naturalWidth > 0 && thumb.naturalHeight > 0) {
						const thumbRatio = thumb.naturalWidth / thumb.naturalHeight;
						const pswpWidth = parseInt(slideEl.getAttribute('data-pswp-width') ?? '1', 10);
						const pswpHeight = parseInt(slideEl.getAttribute('data-pswp-height') ?? '1', 10);
						const pswpRatio = pswpWidth / pswpHeight;
						const ratioDiff = Math.abs(thumbRatio - pswpRatio);
						// Apply horizontal flip if ratios differ
						if (ratioDiff > 0.01) {
							e.content.element.style.transform = 'scaleX(-1)';
						}
					}
				}
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

		// Always register the path/filename bar at the top of the lightbox
		lightbox.on('uiRegister', () => {
			const pswp = lightbox.pswp;
			if (!pswp) {
				return;
			}
			pswp.ui?.registerElement({
				name: 'avpvh-path',
				order: 5,
				isButton: false,
				appendTo: 'root',
				onInit: (el, instance) => {
					el.classList.add('avpvh-pswp-path');
					el.title = 'Klik om pad te kopiëren';
					const update = (): void => {
						const slideEl = instance.currSlide?.data.element;
						const fullPath = slideEl instanceof HTMLElement
							? (slideEl.dataset['avpvhFullpath'] ?? '')
							: '';
						el.textContent = fullPath;
					};
					instance.on('change', update);
					el.addEventListener('click', () => {
						const text = el.textContent ?? '';
						void navigator.clipboard.writeText(text).then(() => {
							const original = el.textContent;
							el.textContent = 'Gekopieerd!';
							setTimeout(() => { el.textContent = original; }, 1200);
						});
					});
				},
			});

			// Register EXIF info button for the lightbox
			pswp.ui?.registerElement({
				name: 'avpvh-lightbox-info',
				order: 6,
				isButton: true,
				appendTo: 'root',
				onInit: (el, instance) => {
					el.classList.add('avpvh-lightbox-info-btn');
					el.innerHTML = 'ℹ';
					el.title = 'Image information';
					el.setAttribute('aria-label', 'Toggle image information');
					const exifOverlay = document.createElement('div');
					exifOverlay.className = 'avpvh-lightbox-exif-overlay';
					el.appendChild(exifOverlay);

					const update = (): void => {
						const slideEl = instance.currSlide?.data.element;
						if (slideEl instanceof HTMLElement) {
							const fullPath = slideEl.dataset['avpvhFullpath'] ?? '';
							const exifStr = slideEl.dataset['avpvhExif'] ?? '';
							let html = '';
							if (fullPath) {
								html += '<div class="avpvh-exif-filename">' + fullPath + '</div>';
							}
							if (exifStr) {
								html += '<div class="avpvh-exif-data">' + exifStr + '</div>';
							}
							exifOverlay.innerHTML = html;
							el.style.display = html ? 'block' : 'none';
						}
					};

					el.addEventListener('click', (e) => {
						e.preventDefault();
						e.stopPropagation();
						exifOverlay.classList.toggle('avpvh-exif-visible');
					});

					instance.on('change', update);
					update();
				},
			});
		});

		lightbox.on('change', () => {
			const pswp = lightbox.pswp;
			if (pswp !== undefined) {
				// Disable looping whenever more pages can be loaded so we can
				// intercept the boundary and advance into newly loaded items instead.
				pswp.options.loop = !this.hasMore;
			}
			const slide = pswp?.currSlide;
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
			if (this.slideshowTimer !== null) {
				clearTimeout(this.slideshowTimer);
				this.slideshowTimer = null;
			}
			if (this.idleTimer !== null) {
				clearTimeout(this.idleTimer);
				this.idleTimer = null;
			}
			this.slideshowPaused = false;
			if ('' !== window.location.hash) {
				history.replaceState(
					history.state,
					'',
					window.location.pathname + window.location.search
				);
			}
		});

		lightbox.on('uiRegister', () => {
			const pswp = lightbox.pswp;
			if (!pswp) {
				return;
			}
			this.setupLightboxBehavior(pswp);
		});

		return lightbox;
	}

	private setupLightboxBehavior(pswp: PhotoSwipe): void {
		const el = pswp.element;
		if (el === undefined) {
			return;
		}

		// ── Replace arrow buttons with the actual favicon as a white silhouette ──
		// Uses an SVG <image> element with a feColorMatrix filter that:
		//   1. Converts white background → transparent (alpha based on darkness)
		//   2. Replaces remaining pixels with pure white
		// Result: exact silhouette of the favicon trowel as a white icon
		// The trowel originally points upper-right; CSS rotate(45deg) makes it
		// point right (next button); scaleX(-1) rotate(45deg) mirrors it to point left (prev).
		if ('' !== this.faviconUrl) {
			const faviconUrl = this.faviconUrl;
			setTimeout(() => {
				// transform tuple: [selector, transform, extraMargin]
				// Prev button sits flush with the viewport's left edge, so nudge
				// its trowel inward (to the right) for visual symmetry with next.
				const arrowConfig: Array<[string, string, string]> = [
					['.pswp__button--arrow--prev', 'scaleX(-1) rotate(45deg)', 'margin-left:12px;'],
					['.pswp__button--arrow--next', 'rotate(45deg)', 'margin-right:12px;'],
				];
				// Unique filter ID to avoid collisions if multiple galleries share the page
				const filterId = 'avpvh-whitemask-' + Math.random().toString(36).substring(2, 9);
				const svgPrefix = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="44" height="44">' +
					'<defs>' +
					'<filter id="' + filterId + '" x="0" y="0" width="100%" height="100%">' +
					// Step 1: Set alpha based on darkness. White (1,1,1) → alpha 0; black (0,0,0) → alpha 1.
					'<feColorMatrix type="matrix" values="1 0 0 0 0  0 1 0 0 0  0 0 1 0 0  -1 -1 -1 0 2"/>' +
					// Step 2: Set RGB to pure white, keep new alpha.
					'<feColorMatrix type="matrix" values="0 0 0 0 1  0 0 0 0 1  0 0 0 0 1  0 0 0 1 0"/>' +
					'</filter>' +
					'</defs>' +
					'<image href="' + faviconUrl + '" x="0" y="0" width="24" height="24" preserveAspectRatio="xMidYMid meet" filter="url(#' + filterId + ')"/>' +
					'</svg>';

				arrowConfig.forEach(([selector, transform, extraMargin]) => {
					const btn = el.querySelector(selector);
					if (btn === null) {
						return;
					}
					// Hide PhotoSwipe's default arrow SVGs
					btn.querySelectorAll('svg').forEach((s) => {
						(s as SVGElement).style.display = 'none';
					});
					btn.querySelectorAll('.avpvh-trowel').forEach((n) => { n.remove(); });

					const wrapper = document.createElement('div');
					wrapper.className = 'avpvh-trowel';
					// Let PhotoSwipe's own button layout center the wrapper; we only
					// size it and apply the orientation rotation.
					wrapper.style.cssText =
						'width:44px;height:44px;display:block;' +
						'pointer-events:none;' +
						extraMargin +
						'transform:' + transform + ';';
					wrapper.innerHTML = svgPrefix;
					btn.appendChild(wrapper);
				});
			}, 0);
		}

		// ── Prevent scrollbar during slide transitions ────────────────
		// PhotoSwipe briefly moves slides outside the viewport while animating.
		// In fullscreen mode this makes a horizontal scrollbar flash into view.
		document.documentElement.classList.add('pswp-is-open');
		pswp.on('close', () => {
			document.documentElement.classList.remove('pswp-is-open');
		});

		// ── Idle arrow hiding ─────────────────────────────────────────
		const resetIdle = (): void => {
			el.classList.remove('pswp--ui-idle');
			if (this.idleTimer !== null) {
				clearTimeout(this.idleTimer);
			}
			this.idleTimer = setTimeout(() => {
				el.classList.add('pswp--ui-idle');
			}, this.IDLE_HIDE_MS);
		};
		el.addEventListener('mousemove', resetIdle);
		el.addEventListener('touchstart', resetIdle);
		resetIdle();

		// ── Slideshow: pause while hovering, resume on mouse leave ───
		// In windowed mode mouseleave fires normally. In fullscreen mode the
		// mouse can never leave the viewport, so we also treat reaching the
		// viewport border (within EDGE_PX pixels) as "leaving".
		const EDGE_PX = 5;
		this.slideshowPaused = false;
		this.startSlideshow(pswp);
		el.addEventListener('mouseenter', () => {
			this.slideshowPaused = true;
			if (this.slideshowTimer !== null) {
				clearTimeout(this.slideshowTimer);
				this.slideshowTimer = null;
			}
		});
		el.addEventListener('mouseleave', () => {
			this.slideshowPaused = false;
			this.startSlideshow(pswp);
		});
		el.addEventListener('mousemove', (e: MouseEvent) => {
			const atEdge =
				e.clientX <= EDGE_PX ||
				e.clientY <= EDGE_PX ||
				e.clientX >= window.innerWidth - EDGE_PX ||
				e.clientY >= window.innerHeight - EDGE_PX;
			if (atEdge && this.slideshowPaused) {
				this.slideshowPaused = false;
				this.startSlideshow(pswp);
			}
		});

		// ── Touch: reset the countdown after each interaction ─────────
		// No hover concept on touch; just ensure the slide doesn't
		// auto-advance while the user is actively swiping.
		el.addEventListener('touchstart', () => {
			this.startSlideshow(pswp);
		});

		// ── Boundary navigation ───────────────────────────────────────
		// Capture click on arrow buttons before PhotoSwipe sees them
		el.addEventListener('click', (e: MouseEvent) => {
			const target = e.target;
			if (!(target instanceof Element)) {
				return;
			}
			if (
				target.closest('.pswp__button--arrow--next') !== null &&
				pswp.currIndex === pswp.getNumItems() - 1
			) {
				e.stopImmediatePropagation();
				e.preventDefault();
				this.nextBoundary(pswp);
			} else if (
				target.closest('.pswp__button--arrow--prev') !== null &&
				pswp.currIndex === 0
			) {
				e.stopImmediatePropagation();
				e.preventDefault();
				this.prevBoundary(pswp);
			}
		}, true);

		// Capture keyboard before PhotoSwipe's document-level handler
		const handleKeydown = (e: KeyboardEvent): void => {
			if (!pswp.isOpen) {
				document.removeEventListener('keydown', handleKeydown, true);
				return;
			}
			if (e.key === 'ArrowRight' && pswp.currIndex === pswp.getNumItems() - 1) {
				e.stopImmediatePropagation();
				this.nextBoundary(pswp);
			} else if (e.key === 'ArrowLeft' && pswp.currIndex === 0) {
				e.stopImmediatePropagation();
				this.prevBoundary(pswp);
			}
		};
		document.addEventListener('keydown', handleKeydown, true);
		pswp.on('close', () => {
			document.removeEventListener('keydown', handleKeydown, true);
		});
	}

	private nextBoundary(pswp: PhotoSwipe): void {
		if (this.hasMore) {
			// A load is already in progress (triggered by onLightboxNavigation
			// when the user neared the end). Mark that we want to advance once
			// the new items land in the DOM.
			this.pendingLightboxAdvance = true;
			if (!this.loading) {
				this.add();
			}
		} else if (!this.folderNavigating) {
			this.folderNavigating = true;
			this.navigateToAdjacentFolder('next', pswp);
		}
	}

	private prevBoundary(pswp: PhotoSwipe): void {
		if (!this.folderNavigating) {
			this.folderNavigating = true;
			this.navigateToAdjacentFolder('prev', pswp);
		}
	}

	private startSlideshow(pswp: PhotoSwipe): void {
		if (this.slideshowTimer !== null) {
			clearTimeout(this.slideshowTimer);
		}
		if (this.slideshowPaused) {
			this.slideshowTimer = null;
			return;
		}
		this.slideshowTimer = setTimeout(() => {
			this.slideshowTimer = null;
			if (this.lightbox.pswp !== pswp || this.loading) {
				return;
			}
			if (pswp.currIndex === pswp.getNumItems() - 1) {
				// Reuse the same boundary traversal as the arrow / keyboard handlers
				this.nextBoundary(pswp);
			} else {
				pswp.next();
				this.startSlideshow(pswp);
			}
		}, this.SLIDESHOW_DELAY_MS);
	}

	private navigateToAdjacentFolder(direction: 'next' | 'prev', pswp: PhotoSwipe): void {
		const currentPath = this.pathQueryParameter.get();
		if ('' === currentPath) {
			this.folderNavigating = false;
			return;
		}
		const lastSlash = currentPath.lastIndexOf('/');
		const parentPath = lastSlash >= 0 ? currentPath.substring(0, lastSlash) : '';
		const currentFolderId = lastSlash >= 0 ? currentPath.substring(lastSlash + 1) : currentPath;

		void $.get(
			avpvhShortcodeLocalize.ajax_url,
			{
				action: 'gallery',
				hash: this.hash,
				path: parentPath,
				page: 1,
			},
			(data: GalleryResponse) => {
				this.folderNavigating = false;
				if (isError(data)) {
					return;
				}
				const siblings = (data as GallerySuccessResponse).directories ?? [];
				const currentIndex = siblings.findIndex((d) => d.id === currentFolderId);
				if (currentIndex < 0) {
					return;
				}
				const targetIndex = 'next' === direction ? currentIndex + 1 : currentIndex - 1;
				if (targetIndex < 0 || targetIndex >= siblings.length) {
					return;
				}
				const targetDir = siblings[targetIndex];
				const newPath = ('' !== parentPath ? parentPath + '/' : '') + targetDir.id;
				pswp.close();
				this.pendingLightboxOpen = 'next' === direction ? 'first' : 'last';
				history.pushState({}, '', this.pathQueryParameter.add(newPath));
				this.path = newPath;
				this.get();
			}
		);
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
		this.currentPathNames = (data.path ?? []).map((c) => c.name).join(' / ');
		const pageLength =
			((data.directories ? data.directories.length : 0) +
				(data.images ? data.images.length : 0) +
				(data.videos ? data.videos.length : 0)) /
			this.lastPage;
		let html = '';
		let currentPage = 1;
		let remaining = pageLength;
		if (data.path !== undefined && 0 < data.path.length) {
			html += this.renderBreadcrumbs(data.path);
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
		if (this.pendingLightboxOpen !== null) {
			const action = this.pendingLightboxOpen;
			this.pendingLightboxOpen = null;
			const links = this.container.find('a.avpvh-grid-a[data-pswp-width]').get();
			if (0 < links.length) {
				const index = 'first' === action ? 0 : links.length - 1;
				this.lightbox.loadAndOpen(index);
			}
		} else {
			this.openFromHash();
		}
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

		// Let PhotoSwipe re-query the DOM for newly added gallery items
		const pswp = this.lightbox.pswp;
		if (pswp !== undefined) {
			const ds = pswp.options.dataSource as Record<string, unknown> | undefined;
			if (ds !== undefined) {
				delete ds['items'];
			}
			// Keep loop disabled while more items remain; re-enable when fully loaded
			pswp.options.loop = !this.hasMore;

			if (this.pendingLightboxAdvance) {
				this.pendingLightboxAdvance = false;
				setTimeout(() => {
					pswp.next();
					this.startSlideshow(pswp);
				}, 50);
			}
		}
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

		// Click on EXIF overlay: copy full path to clipboard, don't open lightbox
		this.container
			.find('.avpvh-exif-overlay')
			.off('click.avpvh-copy')
			.on('click.avpvh-copy', (e) => {
				e.preventDefault();
				e.stopPropagation();
				const anchor = (e.currentTarget as HTMLElement).closest('a');
				const fullPath = anchor?.dataset['avpvhFullpath'] ?? '';
				void navigator.clipboard.writeText(fullPath).then(() => {
					const el = e.currentTarget as HTMLElement;
					const original = el.innerHTML;
					el.innerHTML = '<div class="avpvh-exif-filename">Gekopieerd!</div>';
					setTimeout(() => { el.innerHTML = original; }, 1200);
				});
			});

		// Use event delegation for info button clicks - prevent opening lightbox
		this.container.off('click.avpvh-info').on('click.avpvh-info', '.avpvh-info-btn', (e) => {
			e.preventDefault();
			e.stopPropagation();
			e.stopImmediatePropagation();
			const btn = e.currentTarget as HTMLElement;
			const anchor = btn.closest('a.avpvh-grid-a') as HTMLElement;
			if (!anchor) {
				return;
			}
			anchor.classList.toggle('avpvh-exif-visible');
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
		const faviconUrl = this.faviconUrl;
		// Parent path = all but last segment, joined with /
		const parentPath = path.slice(0, -1).map((c) => c.id).join('/');
		const upIcon = '' !== faviconUrl
			? '<img src="' + faviconUrl + '" alt="Up" style="height:1.2em;width:1.2em;vertical-align:middle;border-radius:2px;object-fit:contain;transform:rotate(-45deg)">'
			: '&#8679;';
		let html =
			'<div>' +
			'<a data-avpvh-path="' + parentPath + '" href="' + this.pathQueryParameter.add(parentPath) + '">' +
			upIcon +
			'</a>';
		let field = '';
		path.forEach((crumb) => {
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

	// Inline SVG icons for the count row — dashicons are not loaded on the frontend
	private static readonly SVG_FOLDER =
		'<svg class="avpvh-count-svg" viewBox="0 0 24 24" aria-hidden="true">' +
		'<path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8' +
		'c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>';

	private static readonly SVG_IMAGE =
		'<svg class="avpvh-count-svg" viewBox="0 0 24 24" aria-hidden="true">' +
		'<path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14' +
		'c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>';

	private static readonly SVG_VIDEO =
		'<svg class="avpvh-count-svg" viewBox="0 0 24 24" aria-hidden="true">' +
		'<path d="M8 5v14l11-7z"/></svg>';

	private renderDirectory(directory: Directory): string {
		let newPath = this.pathQueryParameter.get();
		newPath = (newPath ? newPath + '/' : '') + directory.id;
		const hasThumb = !!directory.thumbnail;
		let html =
			'<a class="avpvh-grid-a avpvh-grid-square' +
			(hasThumb ? '' : ' avpvh-grid-no-thumb') +
			'" data-avpvh-path="' +
			newPath +
			'" href="' +
			this.pathQueryParameter.add(newPath) +
			'"';
		if (hasThumb) {
			html +=
				' style="background-image: url(\'' +
				directory.thumbnail +
				'\')">'; 
		} else {
			html += '>';

			// Subfolder previews listed inside the folder body
			const subdirs = directory.subdirs ?? [];
			if (0 < subdirs.length) {
				// If PHP returned 7 items and there are more than 7 total,
				// show items 0–5 in full and truncate item 6 to signal overflow.
				const hasTruncated =
					subdirs.length === 7 &&
					directory.dircount !== undefined &&
					directory.dircount > 7;
				html += '<div class="avpvh-dir-sublist">';
				for (let si = 0; si < subdirs.length; si++) {
					const sub = subdirs[si];
					const isTruncated = hasTruncated && si === 6;
					const displayName = isTruncated
						? sub.name.substring(0, 5) + '…'
						: sub.name;
					html += '<div class="avpvh-dir-sublist-item' +
						(isTruncated ? ' avpvh-dir-sublist-more' : '') +
						'">' +
						displayName +
						'</div>';
				}
				html += '</div>';
			}
		}

		// Name + counts — always at the bottom of the card
		html +=
			'<div class="avpvh-dir-overlay">' +
			'<div class="avpvh-dir-name">' +
			directory.name +
			'</div>';
		const countParts: Array<string> = [];
		if (directory.dircount !== undefined && directory.dircount > 0) {
			countParts.push(
				'<span>' +
				Shortcode.SVG_FOLDER + ' ' +
				directory.dircount.toString() +
				(1000 === directory.dircount ? '+' : '') +
				'</span>'
			);
		}
		if (directory.imagecount !== undefined && directory.imagecount > 0) {
			countParts.push(
				'<span>' +
				Shortcode.SVG_IMAGE + ' ' +
				directory.imagecount.toString() +
				(1000 === directory.imagecount ? '+' : '') +
				'</span>'
			);
		}
		if (directory.videocount !== undefined && directory.videocount > 0) {
			countParts.push(
				'<span>' +
				Shortcode.SVG_VIDEO + ' ' +
				directory.videocount.toString() +
				(1000 === directory.videocount ? '+' : '') +
				'</span>'
			);
		}
		if (0 < countParts.length) {
			html += '<div class="avpvh-dir-counts">' + countParts.join('') + '</div>';
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

	private static renderExifOverlay(fullPath: string, exif: ImageExif | undefined): string {
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

			if (exif.orientation !== undefined && 0 !== exif.orientation) {
				parts.push('Rotation ' + String(exif.orientation) + '°');
			}
		}
		if ('' === fullPath && 0 === parts.length) {
			return '';
		}
		const nameHtml = '' !== fullPath
			? '<div class="avpvh-exif-filename">' + fullPath + '</div>'
			: '';
		const exifHtml = 0 < parts.length
			? '<div class="avpvh-exif-data">' + parts.join(' · ') + '</div>'
			: '';
		return '<div class="avpvh-exif-overlay">' + nameHtml + exifHtml + '</div>';
	}

	private renderImage(page: number, image: Image): string {
		const width = 0 < image.width ? image.width : 2000;
		const height = 0 < image.height ? image.height : 1500;
		const orientationAttr = image.exif?.orientation ? ' data-exif-orientation="' + image.exif.orientation + '"' : '';
		const hasExif = image.exif !== undefined && Object.keys(image.exif).length > 0;
		const infoBtn = hasExif
			? '<button type="button" class="avpvh-info-btn" title="Image information" aria-label="Toggle image information">ℹ</button>'
			: '';

		// Format EXIF data for data attribute (used by lightbox)
		const exifParts: Array<string> = [];
		if (image.exif !== undefined) {
			if (image.exif.time !== undefined) {
				const d = Shortcode.formatExifDate(image.exif.time);
				if ('' !== d) {
					exifParts.push(d);
				}
			}
			const camera = [image.exif.make, image.exif.model].filter((x) => x !== undefined).join(' ');
			if ('' !== camera) {
				exifParts.push(camera);
			}
			if (image.exif.focal !== undefined) {
				exifParts.push(String(image.exif.focal) + 'mm');
			}
			if (image.exif.aperture !== undefined) {
				exifParts.push('f/' + String(image.exif.aperture));
			}
			if (image.exif.exposure !== undefined) {
				exifParts.push(Shortcode.formatExposure(image.exif.exposure));
			}
			if (image.exif.iso !== undefined) {
				exifParts.push('ISO ' + String(image.exif.iso));
			}
			if (image.exif.orientation !== undefined && 0 !== image.exif.orientation) {
				exifParts.push('Rotation ' + String(image.exif.orientation) + '°');
			}
		}
		const exifAttr = 0 < exifParts.length ? ' data-avpvh-exif="' + exifParts.join(' · ') + '"' : '';

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
			'" data-avpvh-fullpath="' +
			('' !== this.currentPathNames ? this.currentPathNames + ' / ' : '') + image.name +
			'"' +
			orientationAttr +
			exifAttr +
			'>' +
			'<img class="avpvh-grid-img" src="' +
			image.thumbnail +
			'">' +
			infoBtn +
			Shortcode.renderExifOverlay(
				('' !== this.currentPathNames ? this.currentPathNames + ' / ' : '') + image.name,
				image.exif
			) +
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
