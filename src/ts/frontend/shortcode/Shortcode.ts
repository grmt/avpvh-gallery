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
	// Set when a full-size image fails to load (Google rate-limit or an expired
	// URL). While set, the auto-slideshow stays paused so we don't keep firing
	// doomed requests; it clears again as soon as a full-size image loads.
	private rateLimited = false;

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
			// Preload only one neighbour each way (default is [1, 2]). The images
			// come from Google Drive, which throttles bursts of full-size requests;
			// fewer simultaneous requests means fewer throttled/blocked responses
			// (in Firefox these surface as ORB "could not load" errors).
			preload: [1, 1],
			// Suppress PhotoSwipe's built-in "image cannot be loaded" text; we show
			// our own friendlier rate-limit notice instead (see loadError below).
			errorMsg: '',
			close: 'true' === avpvhShortcodeLocalize.preview_closebutton,
		});

		// A full-size image can fail because Google Drive is rate-limiting us
		// (HTTP 403 after a few hundred fetches) or because its per-load URL token
		// has expired. Retrying the same URL can't fix either, so instead:
		//  • Fall back to the grid thumbnail, which was already downloaded for the
		//    folder view and is therefore served from the browser cache — no new
		//    Google request, no expiry/rate-limit. The viewer sees the photo at
		//    lower resolution instead of a broken frame (see contentErrorElement).
		//  • Pause the auto-slideshow so we stop firing doomed full-size requests;
		//    it resumes once a full-size image loads again.
		lightbox.on('loadError', (e) => {
			const content = e.content as unknown as { type?: string };
			if ('image' !== content.type) {
				return;
			}
			this.rateLimited = true;
			if (this.slideshowTimer !== null) {
				clearTimeout(this.slideshowTimer);
				this.slideshowTimer = null;
			}
		});
		lightbox.on('loadComplete', (e) => {
			// A real full-size image loaded — Google is serving us again.
			if (true !== e.isError) {
				this.rateLimited = false;
			}
		});
		// Replace PhotoSwipe's error placeholder with the cached grid thumbnail.
		lightbox.addFilter('contentErrorElement', (errorMsgEl, content) => {
			const data = (content as unknown as { data?: { element?: HTMLElement } }).data;
			const anchor = data?.element;
			const thumb = anchor instanceof HTMLElement ? anchor.querySelector('img') : null;
			const src = thumb instanceof HTMLImageElement ? (thumb.currentSrc || thumb.src) : '';
			if ('' === src) {
				return errorMsgEl;
			}
			const img = document.createElement('img');
			img.className = 'avpvh-pswp-fallback';
			img.src = src;
			// If even the thumbnail can't be shown, fail quietly (blank) rather
			// than a broken-image icon.
			img.addEventListener('error', () => { img.style.display = 'none'; });
			return img;
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
			// Check if this image needs horizontal flip correction
			// (happens when Google Drive serves preview with different EXIF handling than thumbnail)
			if (el instanceof HTMLAnchorElement) {
				const thumb = el.querySelector('img');
				if (thumb !== null && thumb.naturalWidth > 0 && thumb.naturalHeight > 0) {
					const thumbRatio = thumb.naturalWidth / thumb.naturalHeight;
					const pswpWidth = parseInt(el.getAttribute('data-pswp-width') ?? '1', 10);
					const pswpHeight = parseInt(el.getAttribute('data-pswp-height') ?? '1', 10);
					const pswpRatio = pswpWidth / pswpHeight;
					const ratioDiff = Math.abs(thumbRatio - pswpRatio);
					// If ratios differ, the preview might be mirrored (lower threshold for sensitivity)
					if (ratioDiff > 0.01) {
						itemData.needsHFlip = true;
					}
				}
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

				// Add progress bar to show video duration/progress + buffering
				const progressBar = document.createElement('div');
				progressBar.className = 'avpvh-video-progress';
				progressBar.style.cssText =
					'position:absolute;bottom:0;left:0;width:100%;height:3px;background:#555;opacity:0.6;' +
					'display:none;z-index:10;';
				wrap.appendChild(progressBar);

				let animationFrameId: number | null = null;
				const updateProgress = (): void => {
					if (videoEl.duration > 0 && !videoEl.paused) {
						const percent = (videoEl.currentTime / videoEl.duration) * 100;
						const progressFill = progressBar.querySelector('.avpvh-video-progress-fill') as HTMLElement;
						if (progressFill) {
							progressFill.style.width = percent + '%';
						}
						animationFrameId = requestAnimationFrame(updateProgress);
					}
				};

				const updateBuffered = (): void => {
					if (videoEl.duration > 0 && videoEl.buffered.length > 0) {
						const bufferedEnd = videoEl.buffered.end(videoEl.buffered.length - 1);
						const bufferedPercent = (bufferedEnd / videoEl.duration) * 100;
						const bufferedBar = progressBar.querySelector('.avpvh-video-buffered') as HTMLElement;
						if (bufferedBar) {
							bufferedBar.style.width = bufferedPercent + '%';
						}
					}
				};

				const onCanPlay = (): void => {
					// Show progress bar once video is ready
					if (videoEl.duration > 0) {
						progressBar.style.display = 'block';
						progressBar.innerHTML =
							'<div class="avpvh-video-buffered" style="position:absolute;left:0;top:0;height:100%;background:#888;width:0%;"></div>' +
							'<div class="avpvh-video-progress-fill" style="position:absolute;left:0;top:0;height:100%;background:#4CAF50;width:0%;"></div>';
						updateProgress();
						updateBuffered();
					}
				};

				const onPlay = (): void => {
					if (animationFrameId === null) {
						updateProgress();
					}
				};

				const onPause = (): void => {
					if (animationFrameId !== null) {
						cancelAnimationFrame(animationFrameId);
						animationFrameId = null;
					}
				};

				videoEl.addEventListener('canplay', onCanPlay, { once: true });
				videoEl.addEventListener('play', onPlay);
				videoEl.addEventListener('pause', onPause);
				videoEl.addEventListener('progress', updateBuffered); // Update buffered amount as video downloads
				videoEl.addEventListener('ended', () => {
					onPause();
					progressBar.style.display = 'none';
				});

				// Pause slideshow while video plays; resume when it finishes.
				// Slideshow timer stops so video plays uninterrupted.
				if (this.slideshowTimer !== null) {
					clearTimeout(this.slideshowTimer);
					this.slideshowTimer = null;
				}
				// When video ends, show the final frame briefly while preloading the next item,
				// then resume the slideshow or advance to boundary if at the end.
				const onVideoEnded = (): void => {
					videoEl.removeEventListener('ended', onVideoEnded);
					// Wait until currentTime actually reaches duration (sometimes there's a gap)
					// Then add brief pause to show final frame + preload next item
					const waitForComplete = (): void => {
						if (videoEl.currentTime >= videoEl.duration - 0.1) {
							// Video is truly complete, proceed after brief pause
							setTimeout(() => {
								const pswp = this.lightbox.pswp;
								if (pswp !== undefined) {
									// If this was the last item, handle boundary navigation
									if (pswp.currIndex === pswp.getNumItems() - 1) {
										this.nextBoundary(pswp);
									} else {
										// Otherwise, continue to next item normally
										pswp.next();
										this.startSlideshow(pswp);
									}
								}
							}, 500); // Brief pause to show final frame
						} else {
							// Not quite there yet, check again next frame
							requestAnimationFrame(waitForComplete);
						}
					};
					waitForComplete();
				};
				videoEl.addEventListener('ended', onVideoEnded);
			} else if ('image' === e.content.type) {
				// Apply horizontal flip to images that were served mirrored by Google Drive
				if ((e.content.data as Record<string, unknown>)?.['needsHFlip']) {
					if (e.content.element instanceof HTMLImageElement) {
						e.content.element.style.transform = 'scaleX(-1)';
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

			// Register EXIF info button for the lightbox (photos and videos)
			pswp.ui?.registerElement({
				name: 'avpvh-lightbox-info',
				order: 6,
				isButton: true,
				appendTo: 'root',
				onInit: (el, instance) => {
					el.classList.add('avpvh-lightbox-info-btn');
					el.innerHTML = 'ℹ';
					el.title = 'Informatie';
					el.setAttribute('aria-label', 'Toggle informatie');
					const exifOverlay = document.createElement('div');
					exifOverlay.className = 'avpvh-lightbox-exif-overlay';
					el.appendChild(exifOverlay);

					const update = (): void => {
						const slideEl = instance.currSlide?.data.element;
						if (slideEl instanceof HTMLElement) {
							const exifStr = slideEl.dataset['avpvhExif'] ?? '';
							exifOverlay.innerHTML = exifStr;
							el.style.display = exifStr ? 'block' : 'none';
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
			this.rateLimited = false;
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

		// ── Idle UI + slideshow coupling ──────────────────────────────
		// Rules:
		//  • Any interaction (move/click/tap/key) pauses the slideshow and shows
		//    the trowels, then arms a short idle countdown. Because every
		//    interaction re-arms it, rapid clicking can never collide with an
		//    auto-advance (the old "skipped photo" bug).
		//  • When the countdown elapses (user has stopped interacting) the
		//    trowels hide and the slideshow resumes — EXCEPT in windowed mode
		//    while the cursor is still resting on the photo, so hovering a photo
		//    keeps the show stopped.
		//  • In fullscreen the photo fills the screen, so there is no "off the
		//    photo" area; the only way to restart the show is to stop moving the
		//    mouse, hence we ignore the hover rule there.
		let lastOverPhoto = false;
		const isFullscreen = (): boolean => document.fullscreenElement !== null;
		const pauseShow = (): void => {
			this.slideshowPaused = true;
			if (this.slideshowTimer !== null) {
				clearTimeout(this.slideshowTimer);
				this.slideshowTimer = null;
			}
		};
		const resumeShow = (): void => {
			this.slideshowPaused = false;
			this.startSlideshow(pswp);
		};
		const goIdle = (): void => {
			el.classList.add('pswp--ui-idle');
			if (isFullscreen() || !lastOverPhoto) {
				resumeShow();
			}
		};
		const onActivity = (overPhoto: boolean): void => {
			lastOverPhoto = overPhoto;
			el.classList.remove('pswp--ui-idle');
			pauseShow();
			if (this.idleTimer !== null) {
				clearTimeout(this.idleTimer);
			}
			this.idleTimer = setTimeout(goIdle, this.IDLE_HIDE_MS);
		};
		const overPhotoTarget = (t: EventTarget | null): boolean =>
			t instanceof Element && null !== t.closest('.pswp__img');
		// Down events use the capture phase: PhotoSwipe's gesture handler calls
		// stopPropagation() on them for its drag logic, so a bubble-phase listener
		// would never see arrow/image clicks.
		el.addEventListener('mousemove', (e: MouseEvent) => { onActivity(overPhotoTarget(e.target)); });
		el.addEventListener('mousedown', (e: MouseEvent) => { onActivity(overPhotoTarget(e.target)); }, true);
		el.addEventListener('pointerdown', (e: Event) => { onActivity(overPhotoTarget(e.target)); }, true);
		el.addEventListener('touchstart', (e: Event) => { onActivity(overPhotoTarget(e.target)); });
		// Leaving the lightbox entirely (windowed only) resumes immediately.
		el.addEventListener('mouseleave', () => {
			lastOverPhoto = false;
			el.classList.add('pswp--ui-idle');
			if (!isFullscreen()) {
				resumeShow();
			}
		});
		// Start: trowels visible, slideshow running, idle countdown armed.
		resumeShow();
		this.idleTimer = setTimeout(goIdle, this.IDLE_HIDE_MS);
		// NOTE: the slideshow's own advance fires PhotoSwipe's 'change' event,
		// which is deliberately NOT treated as user activity — otherwise the
		// slideshow would pause itself after a single frame.

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
			if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
				// Keyboard navigation counts as interaction (not "over photo"),
				// so it pauses the slideshow and keeps the trowels visible.
				onActivity(false);
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
		if (this.slideshowPaused || this.rateLimited) {
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
		// Recursively search for next/prev folder, going up directory tree if needed
		this.findAdjacentFolder(currentPath, direction, pswp);
	}

	private findAdjacentFolder(currentPath: string, direction: 'next' | 'prev', pswp: PhotoSwipe): void {
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
				if (isError(data)) {
					this.folderNavigating = false;
					return;
				}
				const siblings = (data as GallerySuccessResponse).directories ?? [];
				const currentIndex = siblings.findIndex((d) => d.id === currentFolderId);
				if (currentIndex < 0) {
					this.folderNavigating = false;
					return;
				}
				const targetIndex = 'next' === direction ? currentIndex + 1 : currentIndex - 1;

				// Found adjacent folder at this level
				if (targetIndex >= 0 && targetIndex < siblings.length) {
					const targetDir = siblings[targetIndex];
					const newPath = ('' !== parentPath ? parentPath + '/' : '') + targetDir.id;
					pswp.close();
					this.pendingLightboxOpen = 'next' === direction ? 'first' : 'last';
					history.pushState({}, '', this.pathQueryParameter.add(newPath));
					this.path = newPath;
					this.folderNavigating = false;
					this.get();
					return;
				}

				// No adjacent folder at this level; recurse up if parent exists
				if ('' === parentPath) {
					// At root level, nowhere to go
					this.folderNavigating = false;
					return;
				}

				// Go up one level and search there
				this.findAdjacentFolder(parentPath, direction, pswp);
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

	private static formatVideoDuration(seconds: number): string {
		if (seconds < 60) {
			return Math.round(seconds) + 's';
		}
		const minutes = Math.floor(seconds / 60);
		const secs = Math.round(seconds % 60);
		return minutes + 'min ' + (secs > 0 ? secs + 's' : '');
	}

	private static formatFilesize(bytes: number): string {
		const units = ['B', 'KB', 'MB', 'GB'];
		let size = bytes;
		let unitIndex = 0;
		while (size >= 1024 && unitIndex < units.length - 1) {
			size /= 1024;
			unitIndex++;
		}
		return Math.round(size * 10) / 10 + ' ' + units[unitIndex];
	}

	private static formatResolution(width: number, height: number): string {
		return width + 'x' + height;
	}

	private static formatExifDate(time: string): string {
		// EXIF time format: "YYYY:MM:DD HH:MM:SS"
		const match = /^(\d{4}):(\d{2}):(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/.exec(time);
		if (match === null) {
			return '';
		}
		const date = match[1] + '-' + match[2] + '-' + match[3];
		if (match[4] === undefined || match[5] === undefined) {
			return date;
		}
		const seconds = match[6] !== undefined ? ':' + match[6] : '';
		return date + ' ' + match[4] + ':' + match[5] + seconds;
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
			const camera = [exif.make, exif.model].filter((x) => x !== undefined).join(' ').replace(/\s+/g, ' ').trim();
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

		// Format EXIF data for data attribute (used by lightbox)
		const exifParts: Array<string> = [];
		if (image.exif !== undefined) {
			if (image.exif.time !== undefined) {
				const d = Shortcode.formatExifDate(image.exif.time);
				if ('' !== d) {
					exifParts.push(d);
				}
			}
			const camera = [image.exif.make, image.exif.model].filter((x) => x !== undefined).join(' ').replace(/\s+/g, ' ').trim();
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

		// Format video metadata for overlay
		const metadataParts: Array<string> = [];
		metadataParts.push(Shortcode.formatResolution(width, height));
		// Note: duration and filesize would be added here if available from API
		const exifAttr = metadataParts.length > 0 ? ' data-avpvh-exif="' + metadataParts.join(' · ') + '"' : '';

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
			'" data-avpvh-fullpath="' +
			('' !== this.currentPathNames ? this.currentPathNames + ' / ' : '') + video.id +
			'"' +
			exifAttr +
			'>' +
			'<img class="avpvh-grid-img" src="' +
			video.thumbnail +
			'">' +
			Shortcode.renderExifOverlay(
				('' !== this.currentPathNames ? this.currentPathNames + ' / ' : '') + video.id,
				undefined
			) +
			'</a>'
		);
	}
}
