/**
 * Photo tagging module using Annotorious for region annotations.
 */

export interface TagData {
	id: number;
	member_id: number;
	member_name: string;
	region_data: {
		x: number;
		y: number;
		width: number;
		height: number;
	} | null;
	comments: Array<{
		id: number;
		user_id: number;
		text: string;
		created_at: string;
	}>;
	reactions: Array<{
		emoji: string;
		count: number;
	}>;
}

interface Member {
	id: number;
	name: string;
	status: string;
}

interface TagListResponse {
	success: boolean;
	data?: {
		tags: Array<TagData>;
	};
}

export class PhotoTagger {
	private membersCache: Array<Member> = [];
	private currentImageId = '';
	private readonly annotationMap = new Map<string, TagData>();

	private static displayTags(tags: Array<TagData>): void {
		// Render tags as visual overlays on the image
		const pswpImg = document.querySelector<HTMLElement>('.pswp__img');

		if (!pswpImg) {
			return;
		}

		// Clear previous tags
		pswpImg.querySelectorAll('.avpvh-tag-overlay').forEach((el) => {
			el.remove();
		});

		tags.forEach((tag) => {
			if (tag.region_data) {
				// Draw region box with member name
				const box = document.createElement('div');
				box.className = 'avpvh-tag-overlay';
				box.style.position = 'absolute';
				box.style.left = `${String(tag.region_data.x)}px`;
				box.style.top = `${String(tag.region_data.y)}px`;
				box.style.width = `${String(tag.region_data.width)}px`;
				box.style.height = `${String(tag.region_data.height)}px`;
				box.style.border = '2px solid #ffeb3b';
				box.style.backgroundColor = 'rgba(255, 235, 59, 0.1)';
				box.style.zIndex = '50';

				const label = document.createElement('div');
				label.className = 'avpvh-tag-label';
				label.textContent = `👤 ${tag.member_name}`;
				label.style.position = 'absolute';
				label.style.bottom = '100%';
				label.style.left = '0';
				label.style.backgroundColor = 'rgba(0, 0, 0, 0.9)';
				label.style.color = 'white';
				label.style.padding = '4px 8px';
				label.style.borderRadius = '3px';
				label.style.fontSize = '11px';
				label.style.whiteSpace = 'nowrap';

				box.appendChild(label);
				pswpImg.parentElement?.appendChild(box);
			}
		});
	}

	public async init(lightboxContainer: HTMLElement): Promise<void> {
		await this.loadMembers();
		this.attachToPhotoSwipe(lightboxContainer);
	}

	public async addTag(
		imageId: string,
		memberId: number,
		region?: { x: number; y: number; width: number; height: number }
	): Promise<void> {
		try {
			const response = await fetch('/wp-admin/admin-ajax.php', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams({
					action: 'gallery_tag_add',
					image_id: imageId,
					member_id: String(memberId),
					region_data:
						undefined === region ? '' : JSON.stringify(region),
					_ajax_nonce: avpvhShortcodeLocalize.tag_nonce,
				}).toString(),
			});

			if (response.ok) {
				await this.loadAndRenderTags();
			}
		} catch {
			// Network error — the tag simply doesn't appear; nothing more to do here.
		}
	}

	public async deleteTag(tagId: number): Promise<void> {
		try {
			const response = await fetch('/wp-admin/admin-ajax.php', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams({
					action: 'gallery_tag_delete',
					tag_id: String(tagId),
					_ajax_nonce: avpvhShortcodeLocalize.tag_nonce,
				}).toString(),
			});

			if (response.ok) {
				await this.loadAndRenderTags();
			}
		} catch {
			// Network error — the tag simply stays; nothing more to do here.
		}
	}

	public async addComment(tagId: number, commentText: string): Promise<void> {
		try {
			const response = await fetch('/wp-admin/admin-ajax.php', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams({
					action: 'gallery_comment_add',
					tag_id: String(tagId),
					comment: commentText,
					_ajax_nonce: avpvhShortcodeLocalize.tag_nonce,
				}).toString(),
			});

			if (response.ok) {
				await this.loadAndRenderTags();
			}
		} catch {
			// Network error — the comment simply doesn't appear; nothing more to do here.
		}
	}

	public async addReaction(tagId: number, emoji: string): Promise<void> {
		try {
			const response = await fetch('/wp-admin/admin-ajax.php', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams({
					action: 'gallery_reaction_add',
					tag_id: String(tagId),
					emoji,
					_ajax_nonce: avpvhShortcodeLocalize.tag_nonce,
				}).toString(),
			});

			if (response.ok) {
				await this.loadAndRenderTags();
			}
		} catch {
			// Network error — the reaction simply doesn't appear; nothing more to do here.
		}
	}

	public getMembersForDropdown(): Array<Member> {
		return this.membersCache;
	}

	public showMemberSelector(
		x: number,
		y: number,
		callback: (memberId: number) => void
	): void {
		const overlay = document.createElement('div');
		overlay.className = 'avpvh-member-selector-overlay';
		overlay.style.left = `${String(x)}px`;
		overlay.style.top = `${String(y)}px`;

		const select = document.createElement('select');
		select.innerHTML = '<option value="">-- Select member --</option>';
		this.membersCache.forEach((m) => {
			const opt = document.createElement('option');
			opt.value = String(m.id);
			opt.textContent = `${m.name} (${'active' === m.status ? 'Active' : 'Ex'})`;
			select.appendChild(opt);
		});

		const btn = document.createElement('button');
		btn.textContent = 'Tag';
		btn.onclick = (): void => {
			const memberId = parseInt(select.value, 10);
			if (memberId > 0) {
				callback(memberId);
				overlay.remove();
			}
		};

		overlay.appendChild(select);
		overlay.appendChild(btn);

		const container = document.querySelector('.pswp__container');
		if (container) {
			container.appendChild(overlay);
		}
	}

	private async loadMembers(): Promise<void> {
		const nonce = avpvhShortcodeLocalize.rest_nonce;
		if ('' === nonce) {
			return;
		}
		try {
			const response = await fetch(
				`/wp-json/avpvh/v1/members/for-tagging`,
				{ headers: { 'X-WP-Nonce': nonce } }
			);
			if (!response.ok) {
				return;
			}
			const data = (await response.json()) as { data?: Array<Member> };
			this.membersCache = data.data ?? [];
		} catch {
			// Network error — tagging UI just stays empty
		}
	}

	private attachToPhotoSwipe(container: HTMLElement): void {
		// Hook into PhotoSwipe initialization to detect slide changes
		const photoSwipeInitObserver = new MutationObserver(() => {
			const firstPhotoLink = container.querySelector<HTMLElement>(
				'[data-avpvh-id][data-pswp-width]'
			);

			if (null !== firstPhotoLink) {
				// Watch for when the light box image changes
				const imageIdObserver = new MutationObserver(() => {
					void this.onSlideChange();
				});

				imageIdObserver.observe(firstPhotoLink, {
					attributes: true,
					attributeFilter: ['data-avpvh-id'],
				});
			}
		});

		photoSwipeInitObserver.observe(container, {
			subtree: true,
			childList: true,
		});
	}

	private async onSlideChange(): Promise<void> {
		// When slide changes in lightbox, reload tags
		const currentSlide = document.querySelector<HTMLElement>(
			'[data-pswp-width].pswp__img'
		);

		if (!currentSlide) {
			return;
		}

		const imageLink = currentSlide.closest<HTMLElement>('[data-avpvh-id]');

		if (!imageLink) {
			return;
		}

		const newImageId = imageLink.getAttribute('data-avpvh-id');

		if (null !== newImageId && newImageId !== this.currentImageId) {
			this.currentImageId = newImageId;
			await this.loadAndRenderTags();
		}
	}

	private async loadAndRenderTags(): Promise<void> {
		try {
			const response = await fetch(
				`/wp-admin/admin-ajax.php?action=gallery_tag_list&image_id=${this.currentImageId}`
			);
			const data = (await response.json()) as TagListResponse;

			if (data.success && undefined !== data.data) {
				const tags = data.data.tags;

				this.annotationMap.clear();

				// Store tag data for quick lookup
				tags.forEach((tag) => {
					this.annotationMap.set(`tag-${String(tag.id)}`, tag);
				});

				// Display tags on the image
				PhotoTagger.displayTags(tags);
			}
		} catch {
			// Network error — the tag overlay simply doesn't refresh; nothing more to do here.
		}
	}
}
