/* eslint-disable @typescript-eslint/member-ordering -- Disabled because PhotoSwipe v5 integration requires specific method declarations and helper methods */
import $ from 'jquery';
import { default as justifiedLayout } from 'justified-layout';
import PhotoSwipe from 'photoswipe';
import PhotoSwipeLightbox from 'photoswipe/lightbox';

import { isError } from '../../isError';
import {
	renderCorrectionOrientationChain,
	renderExifOrientationChain,
} from '../../orientationVisualization';
import { printError } from '../../printError';
import { PhotoTagger } from '../photo-tagger/PhotoTagger';
import { QueryParameter } from './QueryParameter';
import { ShortcodeRegistry } from './ShortcodeRegistry';

function escapeHtml(unsafe: string): string {
	return unsafe
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#039;');
}

interface FolderNode {
	path: string;
	pathNames: string;
	total: number; // imagecount + videocount from directory listing, or -1 if unknown
	items: Array<HTMLElement>;
	fullyLoaded: boolean;
	hasMore: boolean;
	lastPage: number;
	loadingMore: boolean;
	next: FolderNode | null;
	prev: FolderNode | null;
	nextSearched: boolean;
	loadingNext: boolean;
}

export class Shortcode {
	private static readonly cache = new Map<
		string,
		GalleryResponse | PageResponse
	>();
	private static readonly exifOrientationCache = new Map<
		string,
		Promise<number | null>
	>();

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
	private getEpoch = 0;
	private loading = false;
	private currentPathNames = '';
	private slideshowTimer: ReturnType<typeof setTimeout> | null = null;
	private idleTimer: ReturnType<typeof setTimeout> | null = null;
	private pendingLightboxOpen: 'first' | 'last' | null = null;
	private folderNavigating = false;
	private lightboxTouchStartX = 0;
	private lightboxTouchStartIndex = 0;
	private slideshowPaused = false;
	// When the active slide is a video the fixed-interval slideshow is suspended;
	// the video's own 'ended' event (or a stall fallback) advances instead.
	private currentSlideIsVideo = false;
	private videoFallbackTimer: ReturnType<typeof setTimeout> | null = null;
	private activeVideoCleanup: (() => void) | null = null;
	// Set when a full-size image fails to load (Google rate-limit or an expired
	// URL). While set, the auto-slideshow stays paused so we don't keep firing
	// doomed requests; it clears again as soon as a full-size image loads.
	private rateLimited = false;
	private screenWakeLock: WakeLockSentinel | null = null;
	private screenWakeLockRequest: Promise<void> | null = null;

	private slideNodes: Array<FolderNode> = [];
	private currentNode: FolderNode | null = null;
	private readonly knownTotals = new Map<string, number>();
	private readonly BOUNDARY_PRELOAD_THRESHOLD = 10;

	private readonly SLIDESHOW_DELAY_MS = 4000;
	private readonly IDLE_HIDE_MS = 3000;

	private readonly navigationIconUrl: string;

	public constructor(container: HTMLElement, hash: string) {
		this.container = $(container);
		this.hash = hash;
		this.shortHash = hash.substring(0, 8);
		this.navigationIconUrl = avpvhShortcodeLocalize.navigation_icon_url;
		this.container.toggleClass(
			'avpvh-gallery-branded',
			avpvhShortcodeLocalize.branded_assets === 'true'
		);
		this.pageQueryParameter = new QueryParameter(this.shortHash, 'page');
		this.pathQueryParameter = new QueryParameter(this.shortHash, 'path');
		this.path = this.pathQueryParameter.get();
		this.lightbox = this.createLightbox();
		this.lightbox.init();
		this.photoTagger = new PhotoTagger();
		void this.photoTagger.init(container);
		this.get();
		this.setupFolderSwipe();
		this.setupFolderKeyboard();
		window.addEventListener('message', (event: MessageEvent<unknown>) => {
			if (event.origin !== window.location.origin) {
				return;
			}
			this.handleInspectorSlideshowCommand(event.data);
		});
		window.addEventListener('storage', (event: StorageEvent) => {
			if (
				event.key !== 'avpvh_slideshow_command' ||
				event.newValue === null
			) {
				return;
			}
			try {
				this.handleInspectorSlideshowCommand(
					JSON.parse(event.newValue)
				);
			} catch {
				// Ignore malformed or unrelated localStorage values.
			}
		});
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
			loop: false,
			showAnimationDuration: parseInt(
				avpvhShortcodeLocalize.preview_speed,
				10
			),
			hideAnimationDuration: parseInt(
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
			arrowPrev: 'true' === avpvhShortcodeLocalize.preview_arrows,
			arrowNext: 'true' === avpvhShortcodeLocalize.preview_arrows,
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
				Shortcode.syncSlideNaturalDimensions(e.slide);
				const pswp = this.lightbox.pswp;
				if (pswp !== undefined && e.slide === pswp.currSlide) {
					this.startSlideshow(pswp);
				}
			}
		});
		// Replace PhotoSwipe's error placeholder with the cached grid thumbnail
		// plus an explicit notice attributing the problem to Google Drive.
		lightbox.addFilter('contentErrorElement', (_errorMsgEl, content) => {
			const data = (
				content as unknown as { data?: { element?: HTMLElement } }
			).data;
			const anchor = data?.element;
			const thumb =
				anchor instanceof HTMLElement
					? anchor.querySelector('img')
					: null;
			const src =
				thumb instanceof HTMLImageElement
					? thumb.currentSrc || thumb.src
					: '';

			const wrapper = document.createElement('div');
			wrapper.style.cssText =
				'position:absolute;top:0;right:0;bottom:0;left:0;display:flex;align-items:center;justify-content:center;';

			if ('' !== src) {
				const img = document.createElement('img');
				img.className = 'avpvh-pswp-fallback';
				img.src = src;
				img.addEventListener('error', () => {
					img.style.display = 'none';
				});
				wrapper.appendChild(img);
			}

			const notice = document.createElement('div');
			notice.className = 'avpvh-pswp-drive-error';
			const title = document.createElement('div');
			title.className = 'avpvh-pswp-drive-error-title';
			title.textContent = 'Google Drive kon de afbeelding niet laden';
			const detail = document.createElement('div');
			detail.className = 'avpvh-pswp-drive-error-detail';
			detail.textContent =
				'Dit is een tijdelijk probleem bij Google. ' +
				'Vernieuw de pagina om het opnieuw te proberen.';
			notice.appendChild(title);
			notice.appendChild(detail);
			wrapper.appendChild(notice);
			return wrapper;
		});

		lightbox.addFilter('itemData', (itemData) => {
			const el = itemData.element;
			if (
				el instanceof HTMLElement &&
				'video' === el.dataset['pswpType']
			) {
				const posterImg = el.querySelector('img');
				return {
					...itemData,
					type: 'video',
					videoSrc: el.dataset['avpvhVideoSrc'] ?? '',
					videoMime: el.dataset['avpvhVideoMime'] ?? '',
					// Grid thumbnail (already cached) doubles as the video poster so
					// the slide shows the picture before/while it loads.
					videoPoster:
						posterImg instanceof HTMLImageElement
							? posterImg.currentSrc || posterImg.src
							: '',
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
				videoEl.playsInline = true;
				videoEl.setAttribute('playsinline', '');
				// Don't fetch any video bytes until this slide becomes the active one
				// (see onSlideActivate). Preloaded neighbours therefore show only their
				// poster thumbnail instead of each spawning a downloading <video>.
				videoEl.preload = 'none';

				const data = e.content.data as Record<string, unknown>;
				const poster = data['videoPoster'];
				if (typeof poster === 'string' && '' !== poster) {
					videoEl.poster = poster;
				}
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

				// Thin progress bar — always visible at bottom
				const bar = document.createElement('div');
				bar.className = 'avpvh-video-bar';
				const bufEl = document.createElement('div');
				bufEl.className = 'avpvh-video-bar-buf';
				const playedEl = document.createElement('div');
				playedEl.className = 'avpvh-video-bar-played';
				bar.appendChild(bufEl);
				bar.appendChild(playedEl);

				// Hover controls: play/pause + time
				const ctrl = document.createElement('div');
				ctrl.className = 'avpvh-video-ctrl';
				const btnPlay = document.createElement('button');
				btnPlay.className = 'avpvh-video-btn-play';
				btnPlay.type = 'button';
				const timeEl = document.createElement('span');
				timeEl.className = 'avpvh-video-time';
				ctrl.appendChild(btnPlay);
				ctrl.appendChild(timeEl);
				// Show a proxy badge when the video is served through the server
				// proxy instead of directly from Drive. Remove it once streaming
				// starts so it doesn't look like an ongoing error.
				if (typeof src === 'string' && src.includes('video_proxy')) {
					const badge = document.createElement('span');
					badge.className = 'avpvh-video-proxy-badge';
					badge.title =
						'Drive kan dit bestand niet zelf streamen — wordt via de server gestreamd';
					badge.textContent = '⬇ proxy';
					ctrl.appendChild(badge);
					videoEl.addEventListener(
						'canplay',
						() => {
							badge.remove();
						},
						{ once: true }
					);
				}

				const inner = document.createElement('div');
				inner.className = 'avpvh-video-inner';
				inner.appendChild(videoEl);
				inner.appendChild(ctrl);
				inner.appendChild(bar);
				wrap.appendChild(inner);
				e.content.element = wrap;

				const fmt = (s: number): string => {
					const m = Math.floor(s / 60);
					const sec = Math.floor(s % 60);
					return String(m) + ':' + String(sec).padStart(2, '0');
				};
				const updateBar = (): void => {
					if (!isFinite(videoEl.duration) || 0 === videoEl.duration) {
						return;
					}
					playedEl.style.width =
						String(
							Math.round(
								(videoEl.currentTime / videoEl.duration) * 1000
							) / 10
						) + '%';
					if (0 < videoEl.buffered.length) {
						bufEl.style.width =
							String(
								Math.round(
									(videoEl.buffered.end(
										videoEl.buffered.length - 1
									) /
										videoEl.duration) *
										1000
								) / 10
							) + '%';
					}
					timeEl.textContent =
						fmt(videoEl.currentTime) +
						' / ' +
						fmt(videoEl.duration);
				};
				const updateBtn = (): void => {
					btnPlay.textContent = videoEl.paused ? '▶' : '⏸';
					btnPlay.setAttribute(
						'aria-label',
						videoEl.paused ? 'Afspelen' : 'Pauzeren'
					);
				};
				videoEl.addEventListener('timeupdate', updateBar);
				videoEl.addEventListener('progress', updateBar);
				videoEl.addEventListener('play', updateBtn);
				videoEl.addEventListener('pause', updateBtn);
				updateBtn();

				// Click on video = play/pause
				videoEl.addEventListener('click', (ev) => {
					ev.stopPropagation();
					if (videoEl.paused) {
						void videoEl.play();
					} else {
						videoEl.pause();
					}
				});
				btnPlay.addEventListener('click', (ev) => {
					ev.stopPropagation();
					if (videoEl.paused) {
						void videoEl.play();
					} else {
						videoEl.pause();
					}
				});
				// Seek by clicking on the progress bar
				bar.addEventListener('click', (ev) => {
					ev.stopPropagation();
					if (!isFinite(videoEl.duration) || 0 === videoEl.duration) {
						return;
					}
					const rect = bar.getBoundingClientRect();
					const pct = Math.max(
						0,
						Math.min(1, (ev.clientX - rect.left) / rect.width)
					);
					videoEl.currentTime = pct * videoEl.duration;
					updateBar();
				});
			}
		});

		// Drive video playback from slide activation rather than contentLoad: only
		// the slide that is actually on screen plays and downloads. Preloaded
		// neighbours keep preload='none' and simply show their poster thumbnail.
		lightbox.on('contentActivate', (e) => {
			this.onSlideActivate(
				e.content as {
					data?: Record<string, unknown>;
					element?: HTMLElement;
				}
			);
		});
		lightbox.on('contentDeactivate', (e) => {
			this.onSlideDeactivate(e.content as { element?: HTMLElement });
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
					let pendingExifLoad: (() => void) | null = null;
					let exifHoverTimer: ReturnType<typeof setTimeout> | null =
						null;
					const queueExifLoad = (): void => {
						if (pendingExifLoad === null) {
							return;
						}
						if (exifHoverTimer !== null) {
							clearTimeout(exifHoverTimer);
						}
						exifHoverTimer = setTimeout(() => {
							exifHoverTimer = null;
							pendingExifLoad?.();
						}, 300);
					};
					instance.element?.addEventListener(
						'pointermove',
						queueExifLoad,
						{
							passive: true,
						}
					);
					instance.on('close', () => {
						instance.element?.removeEventListener(
							'pointermove',
							queueExifLoad
						);
						if (exifHoverTimer !== null) {
							clearTimeout(exifHoverTimer);
						}
					});
					el.classList.add('avpvh-pswp-path');
					el.title = 'Klik om pad te kopiëren';
					const pathLine = document.createElement('div');
					pathLine.className = 'avpvh-pswp-path-name';
					const exifLine = document.createElement('div');
					exifLine.className = 'avpvh-pswp-path-exif';
					const exifText = document.createElement('span');
					const orientationIcon = document.createElement('span');
					orientationIcon.className = 'avpvh-pswp-orientation-icon';
					orientationIcon.style.setProperty(
						'--avpvh-orientation-filter',
						'invert(1)'
					);
					exifLine.appendChild(exifText);
					exifLine.appendChild(orientationIcon);
					// EXIF Inspector link — only visible to wp-admin users
					const exifInspectorLink = document.createElement('a');
					exifInspectorLink.className =
						'avpvh-pswp-exif-inspector-link';
					exifInspectorLink.title = 'Open in EXIF Inspector';
					exifInspectorLink.target = '_blank';
					exifInspectorLink.rel = 'opener';
					exifInspectorLink.innerHTML =
						'<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M11 2a9 9 0 1 0 6.32 15.32l4.18 4.18 1.42-1.42-4.18-4.18A9 9 0 0 0 11 2zm0 2a7 7 0 1 1 0 14A7 7 0 0 1 11 4zm-1 3v2H8v2h2v4h2v-4h2V9h-2V7h-2z"/></svg>';
					exifInspectorLink.addEventListener('click', (e) => {
						e.stopPropagation();
					});
					el.appendChild(pathLine);
					el.appendChild(exifLine);
					el.appendChild(exifInspectorLink);
					const update = (): void => {
						pendingExifLoad = null;
						const slideEl = instance.currSlide?.data.element;
						const fullPath =
							slideEl instanceof HTMLElement
								? (slideEl.dataset['avpvhFullpath'] ?? '')
								: '';
						let exifStr =
							slideEl instanceof HTMLElement
								? (slideEl.dataset['avpvhExif'] ?? '')
								: '';
						let displayedWidth = 0;
						let displayedHeight = 0;
						// Always append pixel dimensions when known and non-zero.
						if (slideEl instanceof HTMLElement) {
							const loadedWidth = parseInt(
								slideEl.dataset['avpvhLoadedWidth'] ?? '0',
								10
							);
							const loadedHeight = parseInt(
								slideEl.dataset['avpvhLoadedHeight'] ?? '0',
								10
							);
							const hasLoadedDimensions =
								loadedWidth > 0 && loadedHeight > 0;
							const w = hasLoadedDimensions
								? loadedWidth
								: parseInt(
										slideEl.dataset['pswpWidth'] ?? '0',
										10
									);
							const h = hasLoadedDimensions
								? loadedHeight
								: parseInt(
										slideEl.dataset['pswpHeight'] ?? '0',
										10
									);
							if (w > 0 && h > 0) {
								const orientation =
									Shortcode.readSlideOrientation(slideEl);
								const swapsAxes =
									hasLoadedDimensions &&
									(orientation.rotation === 90 ||
										orientation.rotation === 270);
								displayedWidth = swapsAxes ? h : w;
								displayedHeight = swapsAxes ? w : h;
								const dim =
									String(displayedWidth) +
									' × ' +
									String(displayedHeight) +
									' px';
								exifStr =
									exifStr !== ''
										? exifStr + ' · ' + dim
										: dim;
							}
						}
						pathLine.textContent = fullPath;
						exifText.textContent = exifStr;
						const { rotation, hFlip, vFlip } =
							Shortcode.readSlideOrientation(
								slideEl instanceof HTMLElement
									? slideEl
									: undefined
							);
						const hasCorrection =
							slideEl instanceof HTMLElement &&
							'1' === slideEl.dataset['avpvhHasCorrection'];
						orientationIcon.innerHTML = '';
						orientationIcon.style.display = 'none';
						if (hasCorrection) {
							orientationIcon.innerHTML =
								renderCorrectionOrientationChain(
									rotation,
									hFlip,
									vFlip,
									displayedHeight > displayedWidth,
									24
								);
							orientationIcon.title =
								'Handmatige oriëntatiecorrectie';
							orientationIcon.style.display = '';
						}
						exifLine.style.display = '';
						const fileId =
							slideEl instanceof HTMLElement
								? (slideEl.dataset['avpvhId'] ?? '')
								: '';
						if (!hasCorrection && fileId !== '') {
							const orientationSlideEl = slideEl;
							const portrait = displayedHeight > displayedWidth;
							pendingExifLoad = (): void => {
								pendingExifLoad = null;
								void Shortcode.loadExifOrientation(fileId).then(
									(exifOrientation) => {
										if (
											exifOrientation === null ||
											instance.currSlide?.data.element !==
												orientationSlideEl
										) {
											return;
										}
										orientationIcon.innerHTML =
											renderExifOrientationChain(
												exifOrientation,
												portrait,
												24
											);
										orientationIcon.title = `EXIF Orientation ${String(exifOrientation)}`;
										orientationIcon.style.display = '';
									}
								);
							};
						}
						if (
							fullPath !== '' &&
							avpvhShortcodeLocalize.is_admin === 'true'
						) {
							const adminBase =
								avpvhShortcodeLocalize.ajax_url.replace(
									'admin-ajax.php',
									'admin.php'
								);
							localStorage.setItem(
								'avpvh_exif_inspector_last_path',
								fullPath
							);
							exifInspectorLink.href =
								adminBase +
								'?page=avpvh_exif_inspector' +
								(fileId !== ''
									? '&avpvh_file_id=' + fileId
									: '');
							exifInspectorLink.style.display = '';
						} else {
							exifInspectorLink.style.display = 'none';
						}
					};
					instance.on('change', update);
					instance.on(
						'loadComplete',
						({ slide, isError: loadFailed }) => {
							if (
								true !== loadFailed &&
								slide === instance.currSlide
							) {
								update();
							}
						}
					);
					el.addEventListener('click', () => {
						const text = pathLine.textContent ?? '';
						void navigator.clipboard.writeText(text).then(() => {
							const original = pathLine.textContent;
							pathLine.textContent = 'Gekopieerd!';
							setTimeout(() => {
								pathLine.textContent = original;
							}, 1200);
						});
					});
					update();
				},
			});
		});

		lightbox.on('change', () => {
			const pswp = lightbox.pswp;
			if (pswp === undefined) {
				return;
			}
			this.onLightboxNodeChange(pswp);
			const slideEl = pswp.currSlide?.data.element;
			if (slideEl instanceof HTMLAnchorElement) {
				const id = slideEl.dataset['avpvhId'];
				if (id !== undefined && '' !== id) {
					history.replaceState(history.state, '', '#' + id);
				}
				if (slideEl.isConnected) {
					this.onLightboxNavigation($(slideEl));
				}
			}
		});

		// PhotoSwipe never emits 'open'; use 'afterInit' (fires at the end of
		// pswp.init(), after the DOM and data source are ready but before the
		// opening animation). The initial 'change' event fires during pswp.init()
		// before 'afterInit', so we call onLightboxNodeChange explicitly here to
		// trigger the preload check for the opening position.
		lightbox.on('afterInit', () => {
			const pswp = lightbox.pswp;
			if (pswp !== undefined) {
				void this.acquireScreenWakeLock();
				this.initLightboxNode(pswp, pswp.currIndex);
				this.onLightboxNodeChange(pswp);
				// Register our counter override AFTER afterInit — by this point all
				// UIElement onInit callbacks (including the built-in counter) have
				// already added their 'change' listeners. Ours runs last and wins,
				// showing the per-folder local index instead of the global position.
				pswp.on('change', () => {
					if (this.slideNodes.length === 0) {
						return;
					}
					const { node, localIndex } = this.getNodeForIndex(
						pswp.currIndex
					);
					Shortcode.updateNodeCounter(pswp, node, localIndex);
					if (pswp.currSlide !== undefined) {
						Shortcode.syncSlideNaturalDimensions(pswp.currSlide);
					}
					Shortcode.applySlideRotation(pswp);
				});
				pswp.on('loadComplete', ({ slide }) => {
					if (slide === pswp.currSlide) {
						Shortcode.applySlideRotation(pswp);
					}
				});
				// With loop:false PhotoSwipe rubber-bands at boundaries — no 'change'
				// event fires. Detect backward boundary swipes via touchstart/touchend.
				const onTouchStart = (e: TouchEvent): void => {
					if (e.touches.length === 1) {
						this.lightboxTouchStartX = e.touches[0].clientX;
						this.lightboxTouchStartIndex = pswp.currIndex;
					}
				};
				const onTouchEnd = (e: TouchEvent): void => {
					if (e.changedTouches.length !== 1 || !pswp.isOpen) {
						return;
					}
					const dx =
						e.changedTouches[0].clientX - this.lightboxTouchStartX;
					if (dx > 50 && this.lightboxTouchStartIndex === 0) {
						this.prevBoundary(pswp);
					}
				};
				document.addEventListener('touchstart', onTouchStart, {
					passive: true,
				});
				document.addEventListener('touchend', onTouchEnd, {
					passive: true,
				});
				let slideshowWasRunning = false;
				const onVisibilityChange = (): void => {
					if (document.hidden) {
						slideshowWasRunning = this.slideshowTimer !== null;
						if (this.slideshowTimer !== null) {
							clearTimeout(this.slideshowTimer);
							this.slideshowTimer = null;
						}
					} else {
						void this.acquireScreenWakeLock();
						if (slideshowWasRunning && !this.slideshowPaused) {
							slideshowWasRunning = false;
							this.startSlideshow(pswp);
						}
					}
				};
				document.addEventListener(
					'visibilitychange',
					onVisibilityChange
				);
				pswp.on('close', () => {
					document.removeEventListener('touchstart', onTouchStart);
					document.removeEventListener('touchend', onTouchEnd);
					document.removeEventListener(
						'visibilitychange',
						onVisibilityChange
					);
				});
			}
			const pswpEl = document.querySelector('.pswp');
			if (
				pswpEl instanceof HTMLElement &&
				document.fullscreenEnabled &&
				document.fullscreenElement === null &&
				!Shortcode.nativeFullscreenAddsOwnCloseButton()
			) {
				// PhotoSwipe already sized the slide for the pre-fullscreen viewport
				// (updateSize() runs synchronously during init(), before this handler
				// requests fullscreen). Its own resize listener isn't bound until the
				// opening animation ends, so the resize the browser fires when the
				// fullscreen transition completes can be missed, leaving the photo
				// stuck at the old, smaller viewport size. Force a recalculation once
				// the transition actually finishes.
				document.addEventListener(
					'fullscreenchange',
					() => {
						if (pswp?.isOpen === true) {
							pswp.updateSize(true);
						}
					},
					{ once: true }
				);
				void pswpEl
					.requestFullscreen({ navigationUI: 'hide' })
					.catch(() => {
						/* ignore */
					});
			}
			if (
				pswpEl instanceof HTMLElement &&
				avpvhShortcodeLocalize.is_admin === 'true'
			) {
				pswpEl.addEventListener('contextmenu', (e) => {
					const fileId =
						pswp?.currSlide?.data.element?.dataset['avpvhId'];
					if (fileId === undefined || fileId === '') {
						return;
					}
					e.preventDefault();
					const adminBase = avpvhShortcodeLocalize.ajax_url.replace(
						'admin-ajax.php',
						'admin.php'
					);
					Shortcode.showContextMenu(e.clientX, e.clientY, [
						{
							label: 'Open in EXIF Inspector',
							href:
								adminBase +
								'?page=avpvh_exif_inspector&avpvh_file_id=' +
								fileId,
						},
					]);
				});
				pswp?.on('close', () => {
					Shortcode.hideContextMenu();
				});
			}
		});

		lightbox.on('close', () => {
			void this.releaseScreenWakeLock();
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
			this.slideNodes = [];
			this.currentNode = null;
			if ('' !== window.location.hash) {
				history.replaceState(
					history.state,
					'',
					window.location.pathname + window.location.search
				);
			}
			if (document.fullscreenElement !== null) {
				void document.exitFullscreen().catch(() => {
					/* ignore */
				});
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

	private async acquireScreenWakeLock(): Promise<void> {
		if (
			document.hidden ||
			this.lightbox.pswp === undefined ||
			!this.lightbox.pswp.isOpen ||
			this.screenWakeLock?.released === false
		) {
			return Promise.resolve();
		}
		if (this.screenWakeLockRequest !== null) {
			return this.screenWakeLockRequest;
		}
		// The DOM lib types navigator.wakeLock as always-present, but older
		// browsers genuinely lack it at runtime — hence the `in` check rather
		// than a (statically "impossible") undefined comparison.
		if (!('wakeLock' in navigator)) {
			return Promise.resolve();
		}
		const wakeLock = navigator.wakeLock;

		this.screenWakeLockRequest = wakeLock
			.request('screen')
			.then(async (sentinel) => {
				if (
					this.lightbox.pswp === undefined ||
					!this.lightbox.pswp.isOpen ||
					document.hidden
				) {
					await sentinel.release();
					return;
				}
				this.screenWakeLock = sentinel;
				sentinel.addEventListener(
					'release',
					() => {
						if (this.screenWakeLock === sentinel) {
							this.screenWakeLock = null;
						}
					},
					{ once: true }
				);
			})
			.catch(() => {
				// Unsupported, denied, or unavailable due to battery policy.
			})
			.finally(() => {
				this.screenWakeLockRequest = null;
			});
		return this.screenWakeLockRequest;
	}

	private async releaseScreenWakeLock(): Promise<void> {
		const sentinel = this.screenWakeLock;
		this.screenWakeLock = null;
		if (sentinel !== null && !sentinel.released) {
			await sentinel.release().catch(() => {
				// The browser may already have released it on a visibility change.
			});
		}
	}

	private setupLightboxBehavior(pswp: PhotoSwipe): void {
		const el = pswp.element;
		if (el === undefined) {
			return;
		}

		// Replace arrow buttons with the bundled trowel SVG as a white silhouette.
		// The trowel originally points upper-right; CSS rotate(45deg) makes it
		// point right (next button); scaleX(-1) rotate(45deg) mirrors it to point left (prev).
		if ('' !== this.navigationIconUrl) {
			const navigationIconUrl = this.navigationIconUrl;
			setTimeout(() => {
				// transform tuple: [selector, transform, extraMargin]
				// Prev button sits flush with the viewport's left edge, so nudge
				// its trowel inward (to the right) for visual symmetry with next.
				// Each button gets its own unique filter ID. The prev button is
				// display:none on slide 1 (PhotoSwipe disables it with loop:false),
				// which means any <filter> defined inside it is in a hidden subtree.
				// Firefox refuses to resolve url(#id) references into display:none
				// elements, so a shared filter ID would leave the next button
				// rendering the unfiltered black SVG. Unique IDs make each button
				// self-contained.
				const uid = Math.random().toString(36).substring(2, 9);
				const arrowConfig: Array<[string, string, string, string]> = [
					[
						'.pswp__button--arrow--prev',
						'scaleX(-1) rotate(45deg)',
						'margin-left:12px;',
						'avpvh-wm-' + uid + '-p',
					],
					[
						'.pswp__button--arrow--next',
						'rotate(45deg)',
						'margin-right:12px;',
						'avpvh-wm-' + uid + '-n',
					],
				];

				const makeSvg = (fId: string): string =>
					'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="44" height="44">' +
					'<defs>' +
					'<filter id="' +
					fId +
					'" x="0" y="0" width="100%" height="100%">' +
					// Set RGB to pure white while retaining the SVG's transparent alpha.
					'<feColorMatrix type="matrix" values="0 0 0 0 1  0 0 0 0 1  0 0 0 0 1  0 0 0 1 0"/>' +
					'</filter>' +
					'</defs>' +
					'<image href="' +
					navigationIconUrl +
					'" x="0" y="0" width="24" height="24" preserveAspectRatio="xMidYMid meet" filter="url(#' +
					fId +
					')"/>' +
					'</svg>';

				arrowConfig.forEach(
					([selector, transform, extraMargin, filterId]) => {
						const btn = el.querySelector(selector);
						if (btn === null) {
							return;
						}
						// Remove PhotoSwipe's default arrow SVGs (hiding via style.display
						// is overridden by a CSS !important rule in some themes).
						btn.querySelectorAll('.pswp__icn').forEach((s) => {
							s.remove();
						});
						btn.querySelectorAll('.avpvh-trowel').forEach((n) => {
							n.remove();
						});

						const wrapper = document.createElement('div');
						wrapper.className = 'avpvh-trowel';
						// Let PhotoSwipe's own button layout center the wrapper; we only
						// size it and apply the orientation rotation.
						wrapper.style.cssText =
							'width:44px;height:44px;display:block;' +
							'pointer-events:none;' +
							extraMargin +
							'transform:' +
							transform +
							';';
						wrapper.innerHTML = makeSvg(filterId);
						btn.appendChild(wrapper);
					}
				);
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
			// In windowed mode, keep trowels visible while cursor rests on the photo
			// (slideshow stays paused; arrows only hide when the cursor leaves the photo)
			if (!isFullscreen() && lastOverPhoto) {
				return;
			}
			el.classList.add('pswp--ui-idle');
			resumeShow();
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
		el.addEventListener('mousemove', (e: MouseEvent) => {
			onActivity(overPhotoTarget(e.target));
		});
		el.addEventListener(
			'mousedown',
			(e: MouseEvent) => {
				onActivity(overPhotoTarget(e.target));
			},
			true
		);
		el.addEventListener(
			'pointerdown',
			(e: Event) => {
				onActivity(overPhotoTarget(e.target));
			},
			true
		);
		el.addEventListener('touchstart', (e: Event) => {
			onActivity(overPhotoTarget(e.target));
		});
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
		// Capture click on the prev arrow when at the start of the first node.
		// Forward navigation is handled seamlessly via preloaded items.
		el.addEventListener(
			'click',
			(e: MouseEvent) => {
				const target = e.target;
				if (!(target instanceof Element)) {
					return;
				}
				if (
					target.closest('.pswp__button--arrow--prev') !== null &&
					pswp.currIndex === 0
				) {
					e.stopImmediatePropagation();
					e.preventDefault();
					this.prevBoundary(pswp);
				}
			},
			true
		);

		// Capture keyboard before PhotoSwipe's document-level handler
		const handleKeydown = (e: KeyboardEvent): void => {
			if (!pswp.isOpen) {
				document.removeEventListener('keydown', handleKeydown, true);
				return;
			}
			if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
				onActivity(false);
			}
			if (e.key === 'ArrowLeft' && pswp.currIndex === 0) {
				e.stopImmediatePropagation();
				this.prevBoundary(pswp);
			}
		};
		document.addEventListener('keydown', handleKeydown, true);
		pswp.on('close', () => {
			document.removeEventListener('keydown', handleKeydown, true);
		});
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
			this.slideshowTimer = null;
		}
		if (this.currentSlideIsVideo) {
			// Videos are not on the fixed timer — they advance when they finish.
			return;
		}
		if (this.slideshowPaused || this.rateLimited) {
			this.slideshowTimer = null;
			return;
		}
		const currentContent = pswp.currSlide?.content;
		if ('image' === currentContent?.type) {
			const image = currentContent.element;
			// Never let the fixed timer skip a slide that is still black because
			// its image has not finished loading. loadComplete starts a fresh timer.
			if (
				!(image instanceof HTMLImageElement) ||
				!image.complete ||
				currentContent.isLoading()
			) {
				return;
			}
		}
		this.slideshowTimer = setTimeout(() => {
			this.slideshowTimer = null;
			if (this.lightbox.pswp !== pswp || this.loading) {
				return;
			}
			if (pswp.currIndex === pswp.getNumItems() - 1) {
				this.nextBoundary(pswp);
			} else {
				pswp.next();
				this.startSlideshow(pswp);
			}
		}, this.SLIDESHOW_DELAY_MS);
	}

	private nextBoundary(pswp: PhotoSwipe): void {
		// At the absolute end of all currently loaded items.
		// If next-folder preloading is in progress, do nothing — items will
		// be appended soon and appendItemsToDatasource restarts the timer.
		// If we've confirmed there is no next folder, close (if configured).
		const lastNode = this.slideNodes[this.slideNodes.length - 1];
		if (
			// eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- slideNodes can be empty, making this index read `undefined` at runtime despite the array element type
			lastNode !== undefined &&
			lastNode.nextSearched &&
			lastNode.next === null &&
			lastNode.fullyLoaded
		) {
			if ('true' === avpvhShortcodeLocalize.preview_quitOnEnd) {
				pswp.close();
			}
		}
	}

	private handleInspectorSlideshowCommand(data: unknown): void {
		if (
			typeof data !== 'object' ||
			data === null ||
			(data as Record<string, unknown>)['type'] !==
				'avpvh-resume-slideshow'
		) {
			return;
		}
		const pswp = this.lightbox.pswp;
		const fileId = (data as Record<string, unknown>)['fileId'];
		if (pswp?.isOpen !== true) {
			// The lightbox isn't open yet in this tab — open it directly on the
			// inspected photo instead of silently doing nothing.
			if (typeof fileId === 'string' && fileId !== '') {
				this.openLightboxForInspectorFile(fileId);
			}
			return;
		}
		// eslint-disable-next-line @typescript-eslint/init-declarations -- explicit `= undefined` is itself forbidden by no-undef-init; there is no other valid initial value for an optional HTMLElement
		let target: HTMLElement | undefined;
		if (typeof fileId === 'string' && fileId !== '') {
			const items =
				(pswp.options.dataSource as { items?: Array<HTMLElement> })
					.items ?? [];
			const index = items.findIndex(
				(item) => item.dataset['avpvhId'] === fileId
			);
			target = index >= 0 ? items[index] : undefined;
			if (target !== undefined) {
				Shortcode.applyInspectorCorrection(
					target,
					(data as Record<string, unknown>)['gridCorrection'],
					(data as Record<string, unknown>)['lightboxCorrection']
				);
			}
			if (index >= 0 && index !== pswp.currIndex) {
				pswp.goTo(index);
			}
		}
		if (target === pswp.currSlide?.data.element) {
			Shortcode.applySlideRotation(pswp);
			pswp.currSlide?.resize();
		}
		this.reflow();
		this.slideshowPaused = false;
		pswp.element?.classList.add('pswp--ui-idle');
		this.startSlideshow(pswp);
		window.focus();
	}

	private openLightboxForInspectorFile(fileId: string): void {
		const links = this.container
			.find('a.avpvh-grid-a[data-pswp-width]')
			.get();
		const index = links.findIndex(
			(el) => el.getAttribute('data-avpvh-id') === fileId
		);
		if (0 <= index) {
			this.lightbox.loadAndOpen(index);
			window.focus();
		}
	}

	// WebKit doesn't support the (Chromium-only) `navigationUI: 'hide'` fullscreen
	// hint, and Safari/iPadOS draws its own floating exit-fullscreen affordance on
	// top of whatever's fullscreened — duplicating PhotoSwipe's own close button in
	// the opposite corner. Skip requesting native fullscreen there and rely on
	// PhotoSwipe's own (already full-viewport) UI instead. iPadOS Safari reports
	// itself as desktop Safari, so the standard touch-points check is needed to
	// distinguish it from actual macOS.
	private static nativeFullscreenAddsOwnCloseButton(): boolean {
		const ua = navigator.userAgent;
		const isIOS = /iP(hone|ad|od)/.test(ua);
		const isIPadOS =
			// eslint-disable-next-line deprecation/deprecation -- no non-deprecated equivalent exists; userAgentData is unsupported in Safari
			navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
		return (
			(isIOS || isIPadOS) &&
			ua.includes('Safari') &&
			!ua.includes('Chrom')
		);
	}

	private static applyInspectorCorrection(
		target: HTMLElement,
		gridValue: unknown,
		lightboxValue: unknown
	): void {
		const read = (
			value: unknown
		): { r: number; h: boolean; v: boolean } | null => {
			if (typeof value !== 'object' || value === null) {
				return null;
			}
			const transform = value as Record<string, unknown>;
			return typeof transform['r'] === 'number' &&
				typeof transform['h'] === 'boolean' &&
				typeof transform['v'] === 'boolean'
				? { r: transform['r'], h: transform['h'], v: transform['v'] }
				: null;
		};
		const write = (
			prefix: 'avpvh' | 'avpvhThumb',
			value: { r: number; h: boolean; v: boolean }
		): void => {
			target.dataset[`${prefix}Rotation`] = String(value.r);
			if ('avpvhThumb' === prefix) {
				if (value.h) {
					target.dataset['avpvhThumbHflip'] = '1';
				} else {
					delete target.dataset['avpvhThumbHflip'];
				}
				if (value.v) {
					target.dataset['avpvhThumbVflip'] = '1';
				} else {
					delete target.dataset['avpvhThumbVflip'];
				}
			} else {
				if (value.h) {
					target.dataset['avpvhHflip'] = '1';
				} else {
					delete target.dataset['avpvhHflip'];
				}
				if (value.v) {
					target.dataset['avpvhVflip'] = '1';
				} else {
					delete target.dataset['avpvhVflip'];
				}
			}
		};
		const grid = read(gridValue);
		const lightbox = read(lightboxValue);
		if (grid !== null) {
			write('avpvhThumb', grid);
		}
		if (lightbox !== null) {
			write('avpvh', lightbox);
			if (lightbox.r !== 0 || lightbox.h || lightbox.v) {
				target.dataset['avpvhHasCorrection'] = '1';
			} else {
				delete target.dataset['avpvhHasCorrection'];
			}
		}
	}

	// ── Video playback driver ─────────────────────────────────────────────
	// The active slide's <video> plays; when it ends (or fails to start within
	// the fallback window) we move to the next item. Preloaded neighbours never
	// play or download (preload='none'), so only one video fetches at a time.
	private onSlideActivate(content: {
		data?: Record<string, unknown>;
		element?: HTMLElement;
	}): void {
		this.clearVideoFallback();
		if (this.activeVideoCleanup !== null) {
			this.activeVideoCleanup();
		}
		this.currentSlideIsVideo = content.data?.['type'] === 'video';
		const pswp = this.lightbox.pswp;
		if (pswp === undefined) {
			return;
		}
		if (!this.currentSlideIsVideo) {
			// Normal photo → hand control back to the timed slideshow.
			this.startSlideshow(pswp);
			return;
		}
		// Suspend the fixed-interval advance; the video drives the next step.
		if (this.slideshowTimer !== null) {
			clearTimeout(this.slideshowTimer);
			this.slideshowTimer = null;
		}
		const videoEl = content.element?.querySelector('video');
		if (!(videoEl instanceof HTMLVideoElement)) {
			this.armVideoFallback(pswp, null);
			return;
		}
		// Now that this slide is visible, allow it to fetch and play.
		videoEl.preload = 'auto';
		try {
			videoEl.currentTime = 0;
		} catch {
			// Not seekable yet — harmless.
		}
		const onEnded = (): void => {
			this.activeVideoCleanup?.();
			this.advanceFromVideo(pswp);
		};
		const onPlaying = (): void => {
			// Real playback began → cancel the "didn't start" fallback and just
			// wait for the video to finish.
			this.clearVideoFallback();
			// If the next slide is a proxy video (no Drive thumbnail), start
			// buffering it now so the black-screen delay is shorter when we get there.
			Shortcode.preloadNextProxyVideo(pswp);
		};
		const cleanup = (): void => {
			videoEl.removeEventListener('ended', onEnded);
			videoEl.removeEventListener('playing', onPlaying);
			this.activeVideoCleanup = null;
		};
		videoEl.addEventListener('ended', onEnded);
		videoEl.addEventListener('playing', onPlaying);
		this.activeVideoCleanup = cleanup;
		// If playback hasn't started within the slideshow delay (autoplay blocked,
		// file unreachable) advance anyway so the show never gets stuck.
		this.armVideoFallback(pswp, videoEl);
		const playPromise = videoEl.play();
		// eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- some older browsers return undefined instead of a Promise from play()
		if (playPromise !== undefined) {
			playPromise.catch(() => {
				// Autoplay blocked/aborted — the fallback timer or a manual click on
				// the native controls takes over.
			});
		}
	}

	private onSlideDeactivate(content: { element?: HTMLElement }): void {
		const videoEl = content.element?.querySelector('video');
		if (videoEl instanceof HTMLVideoElement) {
			videoEl.pause();
			try {
				videoEl.currentTime = 0;
			} catch {
				// ignore
			}
			// preload='none' alone does not abort an in-flight download; load()
			// cancels any active network request so the browser releases the connection.
			videoEl.preload = 'none';
			videoEl.load();
		}
		if (this.activeVideoCleanup !== null) {
			this.activeVideoCleanup();
		}
		this.clearVideoFallback();
	}

	private advanceFromVideo(pswp: PhotoSwipe): void {
		if (this.lightbox.pswp !== pswp) {
			return;
		}
		if (pswp.currIndex === pswp.getNumItems() - 1) {
			this.nextBoundary(pswp);
		} else {
			pswp.next();
		}
		// contentActivate for the new slide takes it from here.
	}

	private static preloadNextProxyVideo(pswp: PhotoSwipe): void {
		const nextIdx = pswp.currIndex + 1;
		if (nextIdx >= pswp.getNumItems()) {
			return;
		}
		// The next item's <a> tag is the gallery DOM element.
		const nextEl = pswp.getItemData(nextIdx).element;
		if (!(nextEl instanceof HTMLElement)) {
			return;
		}
		// .avpvh-grid-img-no-thumb is only on tiles with no Drive thumbnail — those
		// always go through the proxy and benefit from early buffering.
		if (nextEl.querySelector('.avpvh-grid-img-no-thumb') === null) {
			return;
		}
		// Find the already-rendered slide in PhotoSwipe's slide pool and start it fetching.
		// `.slides` is an undocumented but stable internal API, not part of PhotoSwipe's
		// public type definitions — hence the cast through a minimal local shape.
		const slides = (
			pswp as unknown as {
				slides?: Array<{
					index: number;
					content: { element: HTMLElement | undefined };
				}>;
			}
		).slides;
		const nextSlide = slides?.find((s) => s.index === nextIdx);
		const nextVideo = nextSlide?.content.element?.querySelector('video');
		if (
			nextVideo instanceof HTMLVideoElement &&
			nextVideo.preload === 'none'
		) {
			nextVideo.preload = 'auto';
		}
	}

	private armVideoFallback(
		pswp: PhotoSwipe,
		videoEl: HTMLVideoElement | null
	): void {
		this.clearVideoFallback();
		this.videoFallbackTimer = setTimeout(() => {
			this.videoFallbackTimer = null;
			// If the video actually started in the meantime, leave it playing.
			if (
				videoEl !== null &&
				(videoEl.currentTime > 0 || !videoEl.paused)
			) {
				return;
			}
			this.advanceFromVideo(pswp);
		}, this.SLIDESHOW_DELAY_MS);
	}

	private clearVideoFallback(): void {
		if (this.videoFallbackTimer !== null) {
			clearTimeout(this.videoFallbackTimer);
			this.videoFallbackTimer = null;
		}
	}

	// Detect ArrowLeft/ArrowRight on the grid and walk the folder tree.
	// Only fires when no lightbox is open, we are inside a subfolder, and no
	// text input is focused.
	private setupFolderKeyboard(): void {
		document.addEventListener('keydown', (e: KeyboardEvent) => {
			if (this.lightbox.pswp !== undefined) {
				return;
			}
			if ('' === this.path || this.folderNavigating) {
				return;
			}
			if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') {
				return;
			}
			const active = this.container[0].ownerDocument.activeElement;
			if (
				active instanceof HTMLInputElement ||
				active instanceof HTMLTextAreaElement ||
				active instanceof HTMLSelectElement
			) {
				return;
			}
			e.preventDefault();
			this.folderNavigating = true;
			this.findAdjacentFolder(
				this.path,
				e.key === 'ArrowLeft' ? 'prev' : 'next'
			);
		});
	}

	// Detect a horizontal swipe on the gallery grid and walk the folder tree.
	// Only fires when no lightbox is open and we are inside a subfolder.
	private setupFolderSwipe(): void {
		const el = this.container[0];
		let startX = 0;
		let startY = 0;
		// 'h' = horizontal dominant, 'v' = vertical dominant, null = undecided
		let axis: 'h' | 'v' | null = null;

		el.addEventListener(
			'touchstart',
			(e: TouchEvent) => {
				if (this.lightbox.pswp !== undefined) {
					return;
				}
				if (e.touches.length !== 1) {
					return;
				}
				startX = e.touches[0].clientX;
				startY = e.touches[0].clientY;
				axis = null;
			},
			{ passive: true }
		);

		el.addEventListener(
			'touchmove',
			(e: TouchEvent) => {
				if (this.lightbox.pswp !== undefined) {
					return;
				}
				if (e.touches.length !== 1) {
					return;
				}
				if (axis === 'v') {
					return;
				}

				const dx = e.touches[0].clientX - startX;
				const dy = e.touches[0].clientY - startY;

				if (axis === null && (Math.abs(dx) > 5 || Math.abs(dy) > 5)) {
					axis = Math.abs(dx) >= Math.abs(dy) ? 'h' : 'v';
				}
				if (axis === 'h') {
					// Prevent vertical scroll while swiping horizontally
					e.preventDefault();
				}
			},
			{ passive: false }
		);

		el.addEventListener(
			'touchend',
			(e: TouchEvent) => {
				if (axis !== 'h') {
					return;
				}
				if (e.changedTouches.length !== 1) {
					return;
				}
				const dx = e.changedTouches[0].clientX - startX;
				if (Math.abs(dx) < 50) {
					return;
				}
				if ('' === this.path || this.folderNavigating) {
					return;
				}

				this.folderNavigating = true;
				this.findAdjacentFolder(this.path, dx < 0 ? 'next' : 'prev');
			},
			{ passive: true }
		);
	}

	private navigateToAdjacentFolder(
		direction: 'next' | 'prev',
		pswp: PhotoSwipe
	): void {
		const currentPath = this.pathQueryParameter.get();
		if ('' === currentPath) {
			this.folderNavigating = false;
			return;
		}
		// Recursively search for next/prev folder, going up directory tree if needed
		this.findAdjacentFolder(currentPath, direction, pswp);
	}

	private findAdjacentFolder(
		currentPath: string,
		direction: 'next' | 'prev',
		pswp?: PhotoSwipe,
		searchPage = 1
	): void {
		const lastSlash = currentPath.lastIndexOf('/');
		const parentPath =
			lastSlash >= 0 ? currentPath.substring(0, lastSlash) : '';
		const currentFolderId =
			lastSlash >= 0 ? currentPath.substring(lastSlash + 1) : currentPath;

		void $.get(
			avpvhShortcodeLocalize.ajax_url,
			{
				action: 'gallery',
				hash: this.hash,
				path: parentPath,
				page: searchPage,
			},
			(data: GalleryResponse) => {
				if (isError(data)) {
					this.folderNavigating = false;
					return;
				}
				const siblings = data.directories ?? [];
				const currentIndex = siblings.findIndex(
					// Path segments can be Drive IDs OR human-readable folder names —
					// match both so navigation works regardless of how the URL was formed.
					(d) =>
						d.id === currentFolderId || d.name === currentFolderId
				);

				// Current folder not in this page — try the next page if there are more
				if (currentIndex < 0) {
					if (data.more === true) {
						this.findAdjacentFolder(
							currentPath,
							direction,
							pswp,
							searchPage + 1
						);
					} else {
						this.folderNavigating = false;
					}
					return;
				}

				const targetIndex =
					'next' === direction ? currentIndex + 1 : currentIndex - 1;

				// Found adjacent folder at this level
				if (targetIndex >= 0 && targetIndex < siblings.length) {
					const targetDir = siblings[targetIndex];
					// Canonicalise parent path to Drive IDs using the response's path
					// breadcrumb — prevents the URL staying in mixed name/ID format.
					const canonicalParent =
						data.path !== undefined && data.path.length > 0
							? data.path.map((p) => p.id).join('/')
							: parentPath;
					const newPath =
						('' !== canonicalParent ? canonicalParent + '/' : '') +
						targetDir.id;
					if (pswp !== undefined) {
						// Called from lightbox boundary: reopen in the new folder
						this.pendingLightboxOpen =
							'next' === direction ? 'first' : 'last';
					}
					history.pushState(
						{},
						'',
						this.pathQueryParameter.add(newPath)
					);
					this.path = newPath;
					this.folderNavigating = false;
					this.get();
					return;
				}

				let edgePage: number | null = null;
				if ('next' === direction) {
					edgePage = data.more === true ? searchPage + 1 : null;
				} else {
					edgePage = searchPage > 1 ? searchPage - 1 : null;
				}
				if (edgePage !== null) {
					this.findEdgeFolderPath(
						parentPath,
						edgePage,
						direction,
						(newPath) => {
							if (pswp !== undefined) {
								this.pendingLightboxOpen =
									'next' === direction ? 'first' : 'last';
							}
							history.pushState(
								{},
								'',
								this.pathQueryParameter.add(newPath)
							);
							this.path = newPath;
							this.folderNavigating = false;
							this.get();
						},
						() => {
							if (parentPath === '') {
								this.folderNavigating = false;
							} else {
								this.findAdjacentFolder(
									parentPath,
									direction,
									pswp
								);
							}
						}
					);
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
		).fail(() => {
			// Network or server error — release the lock so the user can retry
			this.folderNavigating = false;
		});
	}

	private findEdgeFolderPath(
		parentPath: string,
		page: number,
		direction: 'next' | 'prev',
		onFound: (path: string, total: number) => void,
		onNotFound: () => void
	): void {
		void $.get(
			avpvhShortcodeLocalize.ajax_url,
			{ action: 'gallery', hash: this.hash, path: parentPath, page },
			(data: GalleryResponse) => {
				if (isError(data)) {
					onNotFound();
					return;
				}
				const siblings = data.directories ?? [];
				if (siblings.length > 0) {
					this.storeKnownTotals(siblings);
					const target =
						'next' === direction
							? siblings[0]
							: siblings[siblings.length - 1];
					const canonicalParent =
						data.path !== undefined && data.path.length > 0
							? data.path.map((part) => part.id).join('/')
							: parentPath;
					onFound(
						(canonicalParent !== '' ? canonicalParent + '/' : '') +
							target.id,
						this.knownTotals.get(target.id) ?? -1
					);
					return;
				}
				if ('next' === direction && data.more === true) {
					this.findEdgeFolderPath(
						parentPath,
						page + 1,
						direction,
						onFound,
						onNotFound
					);
				} else if ('prev' === direction && page > 1) {
					this.findEdgeFolderPath(
						parentPath,
						page - 1,
						direction,
						onFound,
						onNotFound
					);
				} else {
					onNotFound();
				}
			}
		).fail(onNotFound);
	}

	private static renderMoreButton(): string {
		const label = avpvhShortcodeLocalize.load_more;
		if ('true' !== avpvhShortcodeLocalize.page_autoload) {
			return (
				'<button class="avpvh-more-button avpvh-more-button-text" type="button">' +
				label +
				'</button>'
			);
		}
		return (
			'<button class="avpvh-more-button" type="button" aria-label="' +
			label +
			'" title="' +
			label +
			'">' +
			'<span class="avpvh-loading"><span></span></span>' +
			'</button>'
		);
	}

	// ── Linked-list node management ───────────────────────────────────────────

	private storeKnownTotals(dirs: Array<Directory> | undefined): void {
		for (const dir of dirs ?? []) {
			if (dir.mediacount !== undefined) {
				this.knownTotals.set(dir.id, dir.mediacount);
			} else if (
				dir.imagecount !== undefined &&
				dir.videocount !== undefined
			) {
				this.knownTotals.set(dir.id, dir.imagecount + dir.videocount);
			}
		}
	}

	private initLightboxNode(pswp: PhotoSwipe, openIndex: number): void {
		this.slideNodes = [];
		const links = Array.from(
			this.container[0].querySelectorAll<HTMLAnchorElement>(
				'a.avpvh-grid-a[data-pswp-width]'
			)
		);
		const pathId = this.path.split('/').pop() ?? this.path;
		const knownTotal = this.knownTotals.get(pathId);
		const total = knownTotal ?? (this.hasMore ? -1 : links.length);
		const node: FolderNode = {
			path: this.path,
			pathNames: this.currentPathNames,
			total,
			items: [...links],
			fullyLoaded: !this.hasMore,
			hasMore: this.hasMore,
			lastPage: this.lastPage,
			loadingMore: false,
			next: null,
			prev: null,
			nextSearched: false,
			loadingNext: false,
		};
		this.slideNodes.push(node);
		this.currentNode = node;
		const ds = pswp.options.dataSource as Record<string, unknown>;
		ds['items'] = [...node.items];
		Shortcode.updateNodeCounter(pswp, node, openIndex);
	}

	private getNodeForIndex(index: number): {
		node: FolderNode;
		localIndex: number;
	} {
		let offset = 0;
		for (const node of this.slideNodes) {
			const end = offset + node.items.length;
			if (index < end) {
				return { node, localIndex: index - offset };
			}
			offset = end;
		}
		const last = this.slideNodes[this.slideNodes.length - 1];
		return {
			node: last,
			localIndex: index - (offset - last.items.length),
		};
	}

	private static readSlideOrientation(slideEl: HTMLElement | undefined): {
		rotation: 0 | 90 | 180 | 270;
		hFlip: boolean;
		vFlip: boolean;
	} {
		const rawRotation =
			slideEl instanceof HTMLElement
				? parseInt(slideEl.dataset['avpvhRotation'] ?? '0', 10)
				: 0;
		// Only act on clean quarter-turn values (90/180/270).
		const rotation: 0 | 90 | 180 | 270 =
			90 === rawRotation || 180 === rawRotation || 270 === rawRotation
				? rawRotation
				: 0;
		return {
			rotation,
			hFlip:
				slideEl instanceof HTMLElement &&
				'1' === slideEl.dataset['avpvhHflip'],
			vFlip:
				slideEl instanceof HTMLElement &&
				'1' === slideEl.dataset['avpvhVflip'],
		};
	}

	private static async loadExifOrientation(
		fileId: string
	): Promise<number | null> {
		const cached = Shortcode.exifOrientationCache.get(fileId);
		if (cached !== undefined) {
			return cached;
		}
		if (
			avpvhShortcodeLocalize.exif_orientation_url === '' ||
			avpvhShortcodeLocalize.rest_nonce === ''
		) {
			return Promise.resolve(null);
		}

		const request = fetch(avpvhShortcodeLocalize.exif_orientation_url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': avpvhShortcodeLocalize.rest_nonce,
			},
			credentials: 'include',
			body: JSON.stringify({ file_id: fileId }),
		})
			.then(async (response): Promise<number | null> => {
				if (!response.ok) {
					return null;
				}
				const data = (await response.json()) as {
					orientation?: number;
					found?: boolean;
				};
				if (
					data.found !== true ||
					data.orientation === undefined ||
					data.orientation < 1 ||
					data.orientation > 8
				) {
					return null;
				}
				return data.orientation;
			})
			.catch(() => null);

		Shortcode.exifOrientationCache.set(fileId, request);
		return request;
	}

	private static syncSlideNaturalDimensions(
		slide: NonNullable<PhotoSwipe['currSlide']>
	): void {
		const image = slide.content.element;
		if (
			!(image instanceof HTMLImageElement) ||
			image.naturalWidth <= 0 ||
			image.naturalHeight <= 0
		) {
			return;
		}

		const width = image.naturalWidth;
		const height = image.naturalHeight;
		const slideEl = slide.data.element;
		if (slideEl instanceof HTMLElement) {
			slideEl.dataset['avpvhLoadedWidth'] = String(width);
			slideEl.dataset['avpvhLoadedHeight'] = String(height);
		}

		if (
			slide.width === width &&
			slide.height === height &&
			slide.content.width === width &&
			slide.content.height === height
		) {
			return;
		}

		slide.data.w = width;
		slide.data.h = height;
		slide.data.width = width;
		slide.data.height = height;
		slide.content.width = width;
		slide.content.height = height;
		slide.width = width;
		slide.height = height;
		slide.resize();
	}

	// The Google 1920px derivative may already be physically rotated, while its
	// EXIF orientation is gone. Apply only the correction stored in WordPress.
	private static applySlideRotation(pswp: PhotoSwipe): void {
		const slideEl = pswp.currSlide?.data.element;
		const { rotation, hFlip, vFlip } = Shortcode.readSlideOrientation(
			slideEl instanceof HTMLElement ? slideEl : undefined
		);
		const imgEl = pswp.currSlide?.content.element;
		// Only act on the actual slide image — not on the error-fallback wrapper <div>
		// (which is set as content.element when the image fails to load). Rotating the
		// wrapper would tilt the error notice on its side.
		if (!(imgEl instanceof HTMLImageElement)) {
			return;
		}

		const flipPart =
			hFlip || vFlip
				? `scale(${hFlip ? '-1' : '1'}, ${vFlip ? '-1' : '1'}) `
				: '';

		if (rotation === 0) {
			imgEl.style.transform = flipPart ? flipPart.trimEnd() : '';
			return;
		}
		if (rotation === 180) {
			imgEl.style.transform = `${flipPart}rotate(180deg)`;
			return;
		}
		// A quarter turn swaps the natural image axes. Scale the rotated result to
		// the viewport independently from PhotoSwipe's unrotated fit calculation.
		const slotW = pswp.currSlide?.width ?? 1;
		const slotH = pswp.currSlide?.height ?? 1;
		const vw = window.innerWidth;
		const vh = window.innerHeight;
		const pswpScale = Math.min(vw / slotW, vh / slotH);
		const rotScale = Math.min(vw / slotH, vh / slotW);
		const adj = pswpScale > 0 ? rotScale / pswpScale : 1;
		imgEl.style.transform = `${flipPart}rotate(${String(rotation)}deg) scale(${String(adj)})`;
	}

	private static updateNodeCounter(
		pswp: PhotoSwipe,
		node: FolderNode,
		localIndex: number
	): void {
		const counterEl = pswp.element?.querySelector('.pswp__counter');
		if (!(counterEl instanceof HTMLElement)) {
			return;
		}
		const sep =
			(pswp.options.indexIndicatorSep as string | undefined) ?? ' / ';
		const total = node.total >= 0 ? String(node.total) : '?';
		counterEl.textContent = String(localIndex + 1) + sep + total;
	}

	private onLightboxNodeChange(pswp: PhotoSwipe): void {
		if (this.slideNodes.length === 0) {
			return;
		}
		const { node, localIndex } = this.getNodeForIndex(pswp.currIndex);
		if (node !== this.currentNode && this.currentNode !== null) {
			this.currentNode = node;
			history.pushState({}, '', this.pathQueryParameter.add(node.path));
			if ('' !== this.pageQueryParameter.get()) {
				history.replaceState({}, '', this.pageQueryParameter.remove());
			}
			this.get();
		} else {
			this.currentNode = node;
		}
		this.checkAndPreloadNext(pswp, node, localIndex);
	}

	private checkAndPreloadNext(
		pswp: PhotoSwipe,
		node: FolderNode,
		localIndex: number
	): void {
		const distFromEnd = node.items.length - 1 - localIndex;
		if (distFromEnd > this.BOUNDARY_PRELOAD_THRESHOLD) {
			return;
		}
		if (node.hasMore && !node.loadingMore) {
			this.loadMoreForNode(node, pswp);
		} else if (
			node.fullyLoaded &&
			!node.nextSearched &&
			!node.loadingNext
		) {
			node.loadingNext = true;
			this.findAndLoadNextNode(node, pswp);
		}
	}

	private findAndLoadNextNode(node: FolderNode, pswp: PhotoSwipe): void {
		this.findNextSiblingPath(
			node.path,
			(newPath, total) => {
				const newNode: FolderNode = {
					path: newPath,
					pathNames: '',
					total,
					items: [],
					fullyLoaded: false,
					hasMore: true,
					lastPage: 0,
					loadingMore: false,
					next: null,
					prev: node,
					nextSearched: false,
					loadingNext: false,
				};
				node.next = newNode;
				node.nextSearched = true;
				node.loadingNext = false;
				this.slideNodes.push(newNode);
				this.loadFirstNodePage(newNode, pswp);
			},
			() => {
				node.nextSearched = true;
				node.loadingNext = false;
			}
		);
	}

	private findNextSiblingPath(
		currentPath: string,
		onFound: (path: string, total: number) => void,
		onNotFound: () => void,
		searchPage = 1
	): void {
		const lastSlash = currentPath.lastIndexOf('/');
		const parentPath =
			lastSlash >= 0 ? currentPath.substring(0, lastSlash) : '';
		const currentId =
			lastSlash >= 0 ? currentPath.substring(lastSlash + 1) : currentPath;

		void $.get(
			avpvhShortcodeLocalize.ajax_url,
			{
				action: 'gallery',
				hash: this.hash,
				path: parentPath,
				page: searchPage,
			},
			(data: GalleryResponse) => {
				if (isError(data)) {
					onNotFound();
					return;
				}
				const siblings = data.directories ?? [];
				const idx = siblings.findIndex(
					(d) => d.id === currentId || d.name === currentId
				);
				if (idx < 0) {
					if (data.more === true) {
						this.findNextSiblingPath(
							currentPath,
							onFound,
							onNotFound,
							searchPage + 1
						);
					} else {
						onNotFound();
					}
					return;
				}
				if (idx + 1 < siblings.length) {
					this.storeKnownTotals(siblings);
					const nextDir = siblings[idx + 1];
					const canonicalParent =
						data.path !== undefined && data.path.length > 0
							? data.path.map((p) => p.id).join('/')
							: parentPath;
					const newPath =
						(canonicalParent !== '' ? canonicalParent + '/' : '') +
						nextDir.id;
					onFound(newPath, this.knownTotals.get(nextDir.id) ?? -1);
				} else if (data.more === true) {
					this.findEdgeFolderPath(
						parentPath,
						searchPage + 1,
						'next',
						onFound,
						() => {
							if (parentPath !== '') {
								this.findNextSiblingPath(
									parentPath,
									onFound,
									onNotFound
								);
							} else {
								onNotFound();
							}
						}
					);
				} else if (parentPath !== '') {
					this.findNextSiblingPath(parentPath, onFound, onNotFound);
				} else {
					onNotFound();
				}
			}
		).fail(onNotFound);
	}

	private loadFirstNodePage(node: FolderNode, pswp: PhotoSwipe): void {
		node.loadingMore = true;
		node.lastPage = 1;

		void $.get(
			avpvhShortcodeLocalize.ajax_url,
			{ action: 'gallery', hash: this.hash, path: node.path, page: 1 },
			(data: GalleryResponse) => {
				node.loadingMore = false;
				if (isError(data)) {
					return;
				}
				node.pathNames = (data.path ?? []).map((p) => p.name).join('/');
				const newItems = Shortcode.pageResponseToSlideItems(
					data,
					node.pathNames
				);
				// If the folder has no direct items but has subdirectories, dive
				// into the first subdirectory (mirrors subfolder-dive in
				// openLightboxIfPending).
				if (
					newItems.length === 0 &&
					data.directories !== undefined &&
					data.directories.length > 0
				) {
					// Store imagecount/videocount for the subdirs so the counter
					// can show the correct total once we dive in.
					this.storeKnownTotals(data.directories);
					const firstSub = data.directories[0];
					const canonicalParent =
						data.path !== undefined && data.path.length > 0
							? data.path.map((p) => p.id).join('/')
							: node.path;
					node.path =
						(canonicalParent !== '' ? canonicalParent + '/' : '') +
						firstSub.id;
					node.total = this.knownTotals.get(firstSub.id) ?? -1;
					node.lastPage = 0;
					this.loadFirstNodePage(node, pswp);
					return;
				}
				node.items.push(...newItems);
				node.hasMore = data.more ?? false;
				node.fullyLoaded = !node.hasMore;
				if (node.fullyLoaded && node.total < 0) {
					node.total = node.items.length;
				}
				this.appendItemsToDatasource(newItems, pswp);
			}
		).fail(() => {
			node.loadingMore = false;
		});
	}

	private loadMoreForNode(node: FolderNode, pswp: PhotoSwipe): void {
		if (node.loadingMore) {
			return;
		}
		node.loadingMore = true;
		node.lastPage++;

		void $.get(
			avpvhShortcodeLocalize.ajax_url,
			{
				action: 'page',
				hash: this.hash,
				path: node.path,
				page: node.lastPage,
			},
			(data: PageResponse) => {
				node.loadingMore = false;
				if (isError(data)) {
					return;
				}
				const newItems = Shortcode.pageResponseToSlideItems(
					data,
					node.pathNames
				);
				node.items.push(...newItems);
				node.hasMore = data.more ?? false;
				node.fullyLoaded = !node.hasMore;
				if (node.fullyLoaded && node.total < 0) {
					node.total = node.items.length;
				}
				this.appendItemsToDatasource(newItems, pswp);
			}
		).fail(() => {
			node.loadingMore = false;
		});
	}

	private appendItemsToDatasource(
		newItems: Array<HTMLElement>,
		pswp: PhotoSwipe
	): void {
		if (this.lightbox.pswp !== pswp || newItems.length === 0) {
			return;
		}
		const ds = pswp.options.dataSource as Record<string, unknown>;
		const existing = Array.isArray(ds['items'])
			? (ds['items'] as Array<HTMLElement>)
			: [];
		ds['items'] = [...existing, ...newItems];
		// PhotoSwipe only preloads neighbours when a slide changes. These items
		// were appended while the old last slide remained active, so explicitly
		// start loading the first new-folder item before restarting the slideshow.
		pswp.contentLoader.loadSlideByIndex(existing.length);
		// Refresh the counter so the total updates if a node just became fully
		// loaded (total was ? and now we know the exact count).
		const { node, localIndex } = this.getNodeForIndex(pswp.currIndex);
		Shortcode.updateNodeCounter(pswp, node, localIndex);
		// If the slideshow was stalled waiting at the last item, restart it.
		if (!this.slideshowPaused && this.slideshowTimer === null) {
			this.startSlideshow(pswp);
		}
	}

	private static pageResponseToSlideItems(
		data: PageSuccessResponse,
		pathNames: string
	): Array<HTMLElement> {
		const items: Array<HTMLElement> = [];
		const prefix = pathNames !== '' ? pathNames + '/' : '';
		for (const image of data.images ?? []) {
			const el = document.createElement('a');
			el.className = 'avpvh-grid-a';
			const previewDimensions = Shortcode.previewDimensions(
				image.width,
				image.height,
				image.image
			);
			el.dataset['pswpWidth'] = String(previewDimensions.width);
			el.dataset['pswpHeight'] = String(previewDimensions.height);
			el.dataset['avpvhId'] = image.id;
			el.dataset['avpvhCaption'] = image.description;
			el.dataset['avpvhFullpath'] = prefix + image.name;
			el.dataset['avpvhExif'] = Shortcode.formatExifString(image.exif);
			const driveRot = image.rotation ?? 0;
			// The derivative has no dependable EXIF orientation. Only the explicit
			// WordPress correction may transform its pixels in the lightbox.
			const lightRot = image.light_rotation ?? 0;
			el.dataset['avpvhRotation'] = String(lightRot);
			el.dataset['avpvhDriveRotation'] = String(driveRot);
			el.dataset['avpvhThumbRotation'] = String(
				image.thumb_rotation ?? 0
			);
			if (image.light_h_flip === true) {
				el.dataset['avpvhHflip'] = '1';
			}
			if (image.light_v_flip === true) {
				el.dataset['avpvhVflip'] = '1';
			}
			if (image.light_has_correction === true) {
				el.dataset['avpvhHasCorrection'] = '1';
			}
			if (image.thumb_h_flip === true) {
				el.dataset['avpvhThumbHflip'] = '1';
			}
			if (image.thumb_v_flip === true) {
				el.dataset['avpvhThumbVflip'] = '1';
			}
			el.href = image.image;
			if (avpvhShortcodeLocalize.is_admin === 'true') {
				const iconEl = document.createElement('button');
				iconEl.className = 'avpvh-exif-icon';
				iconEl.type = 'button';
				iconEl.title = 'Open in EXIF Inspector';
				iconEl.textContent = '⚙';
				const iconHref =
					avpvhShortcodeLocalize.exif_inspector_url +
					'&avpvh_file_id=' +
					image.id;
				iconEl.addEventListener('click', (e) => {
					e.stopPropagation();
					window.open(iconHref, '_blank', 'noopener');
				});
				el.appendChild(iconEl);
			}
			items.push(el);
		}
		for (const video of data.videos ?? []) {
			if (
				'' ===
				document.createElement('video').canPlayType(video.mimeType)
			) {
				continue;
			}
			const el = document.createElement('a');
			el.className = 'avpvh-grid-a';
			el.dataset['pswpWidth'] = String(
				video.width > 0 ? video.width : 1920
			);
			el.dataset['pswpHeight'] = String(
				video.height > 0 ? video.height : 1080
			);
			el.dataset['pswpType'] = 'video';
			el.dataset['avpvhId'] = video.id;
			el.dataset['avpvhVideoSrc'] = video.src;
			el.dataset['avpvhVideoMime'] = video.mimeType;
			el.dataset['avpvhFullpath'] = prefix + video.name;
			el.href = video.src;
			items.push(el);
		}
		return items;
	}

	private static previewDimensions(
		width: number,
		height: number,
		previewUrl: string
	): { width: number; height: number } {
		const sourceWidth = width > 0 ? width : 2000;
		const sourceHeight = height > 0 ? height : 1500;
		const sizeMatch = /=[shw](\d+)(?:-c)?$/.exec(previewUrl);
		if (sizeMatch === null) {
			return { width: sourceWidth, height: sourceHeight };
		}
		const maxPreviewSize = parseInt(sizeMatch[1], 10);
		const scale = Math.min(
			1,
			maxPreviewSize / Math.max(sourceWidth, sourceHeight)
		);
		return {
			width: Math.max(1, Math.round(sourceWidth * scale)),
			height: Math.max(1, Math.round(sourceHeight * scale)),
		};
	}

	// ─────────────────────────────────────────────────────────────────────────

	public onLightboxNavigation(e: JQuery): void {
		const page = $(e).data('avpvh-page') as string;
		history.replaceState(
			history.state,
			'',
			this.pageQueryParameter.add(page)
		);
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
				let ratio = NaN;
				const gridImage = child.querySelector('.avpvh-grid-img');
				const thumbRotation = parseInt(
					child.getAttribute('data-avpvh-thumb-rotation') ?? '0',
					10
				);
				const pswpWidth = $(child).attr('data-pswp-width');
				const pswpHeight = $(child).attr('data-pswp-height');
				if (
					gridImage instanceof HTMLImageElement &&
					gridImage.naturalWidth > 0 &&
					gridImage.naturalHeight > 0 &&
					child.hasAttribute('data-avpvh-thumb-rotation')
				) {
					ratio = gridImage.naturalWidth / gridImage.naturalHeight;
					if (thumbRotation === 90 || thumbRotation === 270) {
						ratio = 1 / ratio;
					}
				} else if (
					pswpWidth !== undefined &&
					pswpHeight !== undefined
				) {
					ratio = parseFloat(pswpWidth) / parseFloat(pswpHeight);
				} else {
					const image = child.firstChild as HTMLImageElement;
					ratio = image.naturalWidth / image.naturalHeight;
				}
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
				const gridImage = child.querySelector('.avpvh-grid-img');
				if (gridImage instanceof HTMLImageElement) {
					const thumbRotation = parseInt(
						child.getAttribute('data-avpvh-thumb-rotation') ?? '0',
						10
					);
					const quarterTurn =
						thumbRotation === 90 || thumbRotation === 270;
					gridImage.style.setProperty(
						'width',
						`${String(quarterTurn ? box.height : box.width)}px`,
						'important'
					);
					gridImage.style.setProperty(
						'height',
						`${String(quarterTurn ? box.width : box.height)}px`,
						'important'
					);
				}
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
		const epoch = ++this.getEpoch;
		this.path = this.pathQueryParameter.get();
		this.lastPage = parseInt(this.pageQueryParameter.get()) || 1;
		this.container
			.find('.avpvh-gallery')
			.replaceWith('<div class="avpvh-loading"><div></div></div>');
		this.container.find('.avpvh-more-button').remove();
		ShortcodeRegistry.reflowAll();

		const cacheKey = `gallery-${this.hash}-${this.path}-${this.lastPage.toString()}`;
		if (Shortcode.cache.has(cacheKey)) {
			const cachedData = Shortcode.cache.get(cacheKey) as GalleryResponse;
			if (isError(cachedData)) {
				this.container.html(
					printError(cachedData, avpvhShortcodeLocalize)
				);
			} else {
				this.getSuccess(cachedData);
			}
			return;
		}

		void $.get(
			avpvhShortcodeLocalize.ajax_url,
			{
				action: 'gallery',
				hash: this.hash,
				path: this.path,
				page: this.lastPage,
			},
			(data: GalleryResponse) => {
				if (epoch !== this.getEpoch) {
					return;
				}
				if (isError(data)) {
					this.container.html(
						printError(data, avpvhShortcodeLocalize)
					);
					return;
				}
				Shortcode.cache.set(cacheKey, data);
				this.getSuccess(data);
			}
		).fail(() => {
			if (epoch !== this.getEpoch) {
				return;
			}
			this.container.html(
				printError(
					{ error: avpvhShortcodeLocalize.server_error },
					avpvhShortcodeLocalize
				)
			);
		});
	}

	private getSuccess(data: GallerySuccessResponse): void {
		this.currentPathNames = (data.path ?? []).map((c) => c.name).join('/');
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
		this.storeKnownTotals(data.directories);
		this.postLoad();
		this.openLightboxIfPending();
	}

	private openLightboxIfPending(): void {
		if (this.pendingLightboxOpen !== null) {
			const action = this.pendingLightboxOpen;
			this.pendingLightboxOpen = null;
			const links = this.container
				.find('a.avpvh-grid-a[data-pswp-width]')
				.get();
			if (0 < links.length) {
				const index = 'first' === action ? 0 : links.length - 1;
				const openPswp = this.lightbox.pswp;
				if (openPswp !== undefined) {
					// Backward boundary or subfolder dive: close and instantly reopen
					// in the new folder. Zero animation durations make the transition
					// feel like a seamless cut.
					const origShow =
						this.lightbox.options.showAnimationDuration ?? 333;
					openPswp.options.hideAnimationDuration = 0;
					openPswp.on('destroy', () => {
						this.lightbox.options.showAnimationDuration = 0;
						this.lightbox.loadAndOpen(index);
						requestAnimationFrame(() => {
							this.lightbox.options.showAnimationDuration =
								origShow;
							const p = this.lightbox.pswp;
							if (p !== undefined) {
								p.options.showAnimationDuration = origShow;
							}
						});
					});
					openPswp.close();
				} else {
					this.lightbox.loadAndOpen(index);
				}
			} else {
				// No direct photos — folder contains only subfolders.
				// Dive into the first (or last) subfolder and try again.
				const subdirs = this.container
					.find('a.avpvh-grid-a[data-avpvh-path]')
					.get() as Array<HTMLAnchorElement>;
				if (subdirs.length > 0) {
					const target =
						'first' === action
							? subdirs[0]
							: subdirs[subdirs.length - 1];
					const subPath = $(target).data('avpvhPath') as string;
					if (subPath) {
						this.pendingLightboxOpen = action;
						history.replaceState(
							{},
							'',
							this.pathQueryParameter.add(subPath)
						);
						this.path = subPath;
						this.get();
					}
				}
			}
		} else if (this.lightbox.pswp === undefined) {
			// Only try to open from hash when the lightbox is not already open;
			// a get() triggered by onLightboxNodeChange must not spawn a second instance.
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

		const cacheKey = `page-${this.hash}-${this.pathQueryParameter.get()}-${this.lastPage.toString()}`;
		if (Shortcode.cache.has(cacheKey)) {
			const cachedData = Shortcode.cache.get(cacheKey) as PageResponse;
			if (isError(cachedData)) {
				this.container
					.find('.avpvh-loading')
					.replaceWith(
						printError(cachedData, avpvhShortcodeLocalize)
					);
				this.container.find('.avpvh-more-button').remove();
			} else {
				this.addSuccess(cachedData);
			}
			return;
		}

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
				Shortcode.cache.set(cacheKey, data);
				this.addSuccess(data);
			}
		).fail(() => {
			this.container
				.find('.avpvh-loading')
				.replaceWith(
					printError(
						{ error: avpvhShortcodeLocalize.server_error },
						avpvhShortcodeLocalize
					)
				);
			this.container.find('.avpvh-more-button').remove();
		});
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
			if (
				img === null ||
				img.naturalWidth === 0 ||
				img.naturalHeight === 0
			) {
				return;
			}
			// Extract the preview size from the href URL which ends with =s{size}
			const sizeMatch = /=s(\d+)$/.exec((el as HTMLAnchorElement).href);
			if (sizeMatch === null) {
				return;
			}
			const size = parseInt(sizeMatch[1], 10);
			// Use the thumbnail's natural dimensions to determine the true display aspect ratio.
			// Google Drive serves =h{n} thumbnails in display orientation (Drive's own EXIF
			// rotation applied), so naturalWidth/naturalHeight reflects the correct
			// portrait/landscape proportions FOR THAT ROTATION. But the lightbox's full-size
			// image is rotated by the *effective* rotation (a user correction, when set,
			// overrides Drive's own) — if that differs from Drive's rotation by a 90°
			// step, the thumbnail's orientation no longer matches what the lightbox will
			// actually show, so transpose the ratio to compensate.
			const driveRotation = parseInt(
				el.getAttribute('data-avpvh-drive-rotation') ?? '0',
				10
			);
			const effectiveRotation = parseInt(
				el.getAttribute('data-avpvh-rotation') ?? '0',
				10
			);
			const driveSwapped = [90, 270].includes(driveRotation);
			const effectiveSwapped = [90, 270].includes(effectiveRotation);
			let ratio = img.naturalWidth / img.naturalHeight;
			if (driveSwapped !== effectiveSwapped) {
				ratio = 1 / ratio;
			}
			let newW = 0;
			let newH = 0;
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
		this.container
			.find('.avpvh-breadcrumb-sibling')
			.off('click.avpvh')
			.on('click.avpvh', (e) => {
				if ('' === this.path || this.folderNavigating) {
					return false;
				}
				const dir = $(e.currentTarget).data('avpvhDir') as string;
				if (dir !== 'prev' && dir !== 'next') {
					return false;
				}
				this.folderNavigating = true;
				this.findAdjacentFolder(this.path, dir);
				return false;
			});
		this.container.find('.avpvh-more-button').on('click', () => {
			this.add();
			return false;
		});

		this.container.find('.avpvh-gallery').addClass('avpvh-gallery-loaded');
		ShortcodeRegistry.reflowAll();

		// Click on EXIF overlay: copy full path to clipboard, don't open lightbox
		this.container
			.find('.avpvh-exif-overlay')
			.off('click.avpvh-copy')
			.on('click.avpvh-copy', (e) => {
				e.preventDefault();
				e.stopPropagation();
				const anchor = e.currentTarget.closest('a');
				const fullPath = anchor?.dataset['avpvhFullpath'] ?? '';
				void navigator.clipboard.writeText(fullPath).then(() => {
					const el = e.currentTarget;
					const original = el.innerHTML;
					el.innerHTML =
						'<div class="avpvh-exif-filename">Gekopieerd!</div>';
					setTimeout(() => {
						el.innerHTML = original;
					}, 1200);
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
		this.prefetchNextPage();

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

	private prefetchNextPage(): void {
		if (!this.hasMore) {
			return;
		}
		const nextPage = this.lastPage + 1;
		const cacheKey = `page-${this.hash}-${this.pathQueryParameter.get()}-${String(nextPage)}`;
		if (Shortcode.cache.has(cacheKey)) {
			return;
		}
		void $.get(
			avpvhShortcodeLocalize.ajax_url,
			{
				action: 'page',
				hash: this.hash,
				path: this.pathQueryParameter.get(),
				page: nextPage,
			},
			(data: PageResponse) => {
				if (!isError(data)) {
					Shortcode.cache.set(cacheKey, data);
				}
			}
		);
	}

	private renderBreadcrumbs(path: Array<PartialDirectory>): string {
		const navigationIconUrl = this.navigationIconUrl;
		// Parent path = all but last segment, joined with /
		const parentPath = path
			.slice(0, -1)
			.map((c) => c.id)
			.join('/');
		const upIcon =
			'' !== navigationIconUrl
				? '<img src="' +
					navigationIconUrl +
					'" alt="Up" style="height:1.5em;width:1.5em;vertical-align:middle;border-radius:2px;object-fit:contain;transform:rotate(-45deg)">'
				: '&#8679;';
		const siblingIcon = (dir: 'next' | 'prev'): string => {
			if ('' === navigationIconUrl) {
				return dir === 'prev' ? '&#8678;' : '&#8680;';
			}
			const label = dir === 'prev' ? 'Previous' : 'Next';
			const rotate =
				dir === 'prev' ? 'scaleX(-1) rotate(45deg)' : 'rotate(45deg)';
			return (
				'<img src="' +
				navigationIconUrl +
				'" alt="' +
				label +
				' folder" style="height:1.5em;width:1.5em;vertical-align:middle;border-radius:2px;object-fit:contain;transform:' +
				rotate +
				'">'
			);
		};
		let html =
			'<div class="avpvh-breadcrumbs">' +
			'<a class="avpvh-breadcrumb-sibling avpvh-breadcrumb-prev" href="#" data-avpvh-dir="prev" aria-label="Previous folder">' +
			siblingIcon('prev') +
			'</a>' +
			'<a class="avpvh-breadcrumb-up" data-avpvh-path="' +
			parentPath +
			'" href="' +
			this.pathQueryParameter.add(parentPath) +
			'">' +
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
				escapeHtml(crumb.name) +
				'</a>';
			field += '/';
		});
		html +=
			'<a class="avpvh-breadcrumb-sibling avpvh-breadcrumb-next" href="#" data-avpvh-dir="next" aria-label="Next folder">' +
			siblingIcon('next') +
			'</a>' +
			'</div>';
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
					html +=
						'<div class="avpvh-dir-sublist-item' +
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
			escapeHtml(directory.name) +
			'</div>';
		const countParts: Array<string> = [];
		if (directory.dircount !== undefined && directory.dircount > 0) {
			countParts.push(
				'<span>' +
					Shortcode.SVG_FOLDER +
					' ' +
					directory.dircount.toString() +
					(1000 === directory.dircount ? '+' : '') +
					'</span>'
			);
		}
		if (directory.imagecount !== undefined && directory.imagecount > 0) {
			countParts.push(
				'<span>' +
					Shortcode.SVG_IMAGE +
					' ' +
					directory.imagecount.toString() +
					(1000 === directory.imagecount ? '+' : '') +
					'</span>'
			);
		}
		if (directory.videocount !== undefined && directory.videocount > 0) {
			countParts.push(
				'<span>' +
					Shortcode.SVG_VIDEO +
					' ' +
					directory.videocount.toString() +
					(1000 === directory.videocount ? '+' : '') +
					'</span>'
			);
		}
		if (0 < countParts.length) {
			html +=
				'<div class="avpvh-dir-counts">' +
				countParts.join('') +
				'</div>';
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

	private static formatResolution(width: number, height: number): string {
		return String(width) + 'x' + String(height);
	}

	// Combine make + model but drop the make when the model already starts with
	// it (e.g. "NIKON CORPORATION" + "NIKON D70" → "NIKON D70").
	private static formatCamera(
		make: string | undefined,
		model: string | undefined
	): string {
		if (model === undefined || model === '') {
			return (make ?? '').trim();
		}
		if (make === undefined || make === '') {
			return model.trim();
		}
		const brand = make.split(/\s+/)[0] ?? '';
		const redundant =
			brand !== '' && model.toUpperCase().startsWith(brand.toUpperCase());
		return (redundant ? model : make + ' ' + model)
			.replace(/\s+/g, ' ')
			.trim();
	}

	private static formatExifString(exif: ImageExif | undefined): string {
		if (exif === undefined) {
			return '';
		}
		const parts: Array<string> = [];
		if (exif.time !== undefined) {
			const d = Shortcode.formatExifDate(exif.time);
			if (d !== '') {
				parts.push(d);
			}
		}
		const camera = Shortcode.formatCamera(exif.make, exif.model);
		if (camera !== '') {
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
			parts.push('ISO ' + String(exif.iso));
		}
		if (exif.orientation !== undefined && exif.orientation > 0) {
			const orientationLabels: Partial<Record<number, string>> = {
				90: '90° rechtsom',
				180: '180° gedraaid',
				270: '90° linksom',
			};
			parts.push(
				orientationLabels[exif.orientation] ??
					String(exif.orientation) + '° gedraaid'
			);
		}
		return parts.join(' · ');
	}

	private static contextMenuEl: HTMLElement | null = null;

	private static showContextMenu(
		x: number,
		y: number,
		items: Array<{ label: string; href: string }>
	): void {
		let menu = this.contextMenuEl;
		if (menu === null) {
			menu = document.createElement('div');
			menu.className = 'avpvh-context-menu';
			document.body.appendChild(menu);
			document.addEventListener('click', () => {
				if (this.contextMenuEl !== null) {
					this.contextMenuEl.style.display = 'none';
				}
			});
			document.addEventListener('keydown', (e) => {
				if (e.key === 'Escape' && this.contextMenuEl !== null) {
					this.contextMenuEl.style.display = 'none';
				}
			});
			this.contextMenuEl = menu;
		}
		menu.innerHTML = '';
		for (const item of items) {
			const a = document.createElement('a');
			a.className = 'avpvh-context-menu-item';
			a.href = item.href;
			a.target = '_blank';
			a.rel = 'opener';
			a.textContent = item.label;
			a.addEventListener('click', () => {
				if (this.contextMenuEl !== null) {
					this.contextMenuEl.style.display = 'none';
				}
			});
			menu.appendChild(a);
		}
		menu.style.display = 'block';
		menu.style.left = String(x) + 'px';
		menu.style.top = String(y) + 'px';
		const rect = menu.getBoundingClientRect();
		if (rect.right > window.innerWidth) {
			menu.style.left = String(x - rect.width) + 'px';
		}
		if (rect.bottom > window.innerHeight) {
			menu.style.top = String(y - rect.height) + 'px';
		}
	}

	private static hideContextMenu(): void {
		if (this.contextMenuEl !== null) {
			this.contextMenuEl.style.display = 'none';
		}
	}

	private static formatExifDate(time: string): string {
		// EXIF time format: "YYYY:MM:DD HH:MM:SS"
		const match =
			/^(\d{4}):(\d{2}):(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/.exec(
				time
			);
		if (match === null) {
			return '';
		}
		const date = match[1] + '-' + match[2] + '-' + match[3];
		// eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- optional regex capture groups type as `string`, not `string | undefined`, without noUncheckedIndexedAccess, but are genuinely undefined here when the time-of-day part isn't present
		if (match[4] === undefined || match[5] === undefined) {
			return date;
		}
		// eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- see above
		const seconds = match[6] !== undefined ? ':' + match[6] : '';
		return date + ' ' + match[4] + ':' + match[5] + seconds;
	}

	private static renderExifOverlay(
		fullPath: string,
		exif: ImageExif | undefined
	): string {
		const parts: Array<string> = [];
		if (exif !== undefined) {
			if (exif.time !== undefined) {
				const d = Shortcode.formatExifDate(exif.time);
				if ('' !== d) {
					parts.push(d);
				}
			}
			const camera = [exif.make, exif.model]
				.filter((x) => x !== undefined)
				.join(' ')
				.replace(/\s+/g, ' ')
				.trim();
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

			if (exif.orientation !== undefined && exif.orientation > 0) {
				const orientationLabels: Partial<Record<number, string>> = {
					90: '90° rechtsom',
					180: '180° gedraaid',
					270: '90° linksom',
				};
				const label =
					orientationLabels[exif.orientation] ??
					String(exif.orientation) + '° gedraaid';
				parts.push(label);
			}
		}
		if ('' === fullPath && 0 === parts.length) {
			return '';
		}
		const nameHtml =
			'' !== fullPath
				? '<div class="avpvh-exif-filename">' + fullPath + '</div>'
				: '';
		const exifHtml =
			0 < parts.length
				? '<div class="avpvh-exif-data">' + parts.join(' · ') + '</div>'
				: '';
		return (
			'<div class="avpvh-exif-overlay">' + nameHtml + exifHtml + '</div>'
		);
	}

	private renderImage(page: number, image: Image): string {
		const { width, height } = Shortcode.previewDimensions(
			image.width,
			image.height,
			image.image
		);
		const thumbRotation = image.thumb_rotation ?? 0;
		// Never fall back to Drive's metadata rotation for the broken 1920px
		// derivative; its WordPress lightbox correction is authoritative.
		const lightRotation = image.light_rotation ?? 0;
		const lightHFlip =
			image.light_h_flip === true ? ' data-avpvh-hflip="1"' : '';
		const lightVFlip =
			image.light_v_flip === true ? ' data-avpvh-vflip="1"' : '';
		const thumbHFlip =
			image.thumb_h_flip === true ? ' data-avpvh-thumb-hflip="1"' : '';
		const thumbVFlip =
			image.thumb_v_flip === true ? ' data-avpvh-thumb-vflip="1"' : '';
		const hasCorrection =
			image.light_has_correction === true
				? ' data-avpvh-has-correction="1"'
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
			const camera = Shortcode.formatCamera(
				image.exif.make,
				image.exif.model
			);
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
		}
		const exifAttr =
			0 < exifParts.length
				? ' data-avpvh-exif="' + exifParts.join(' · ') + '"'
				: '';

		const exifIconHtml =
			avpvhShortcodeLocalize.is_admin === 'true'
				? '<button class="avpvh-exif-icon" type="button" ' +
					'data-exif-href="' +
					avpvhShortcodeLocalize.exif_inspector_url +
					'&amp;avpvh_file_id=' +
					image.id +
					'" title="Open in EXIF Inspector" ' +
					'onclick="window.open(this.dataset.exifHref,\'_blank\');event.stopPropagation()">&#9881;</button>'
				: '';

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
			escapeHtml(image.description) +
			'" ' +
			'data-avpvh-page="' +
			page.toString() +
			'" ' +
			'data-avpvh-rotation="' +
			String(lightRotation) +
			'" ' +
			'data-avpvh-drive-rotation="' +
			String(image.rotation ?? 0) +
			'" ' +
			'data-avpvh-thumb-rotation="' +
			String(thumbRotation) +
			'"' +
			lightHFlip +
			lightVFlip +
			thumbHFlip +
			thumbVFlip +
			hasCorrection +
			' href="' +
			image.image +
			'" data-avpvh-fullpath="' +
			('' !== this.currentPathNames ? this.currentPathNames + '/' : '') +
			image.name +
			'"' +
			exifAttr +
			'>' +
			'<img class="avpvh-grid-img" src="' +
			image.thumbnail +
			'">' +
			exifIconHtml +
			Shortcode.renderExifOverlay(
				('' !== this.currentPathNames
					? this.currentPathNames + '/'
					: '') + image.name,
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
		const exifAttr =
			metadataParts.length > 0
				? ' data-avpvh-exif="' + metadataParts.join(' · ') + '"'
				: '';

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
			('' !== this.currentPathNames ? this.currentPathNames + '/' : '') +
			video.name +
			'"' +
			exifAttr +
			'>' +
			(video.thumbnail !== null
				? '<img class="avpvh-grid-img" src="' + video.thumbnail + '">'
				: '<div class="avpvh-grid-img avpvh-grid-img-no-thumb"></div>') +
			'<div class="avpvh-video-play-btn">' +
			Shortcode.SVG_VIDEO +
			'</div>' +
			Shortcode.renderExifOverlay(
				('' !== this.currentPathNames
					? this.currentPathNames + '/'
					: '') + video.name,
				undefined
			) +
			'</a>'
		);
	}
}

/* eslint-enable @typescript-eslint/member-ordering -- Re-enable rules after class definition */
