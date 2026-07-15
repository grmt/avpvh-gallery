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

export class PhotoTagger {
	private membersCache: Array<{ id: number; name: string; status: string }> =
		[];
	private currentImageId = '';
	private readonly annotationMap = new Map<string, TagData>();
	private lightboxContainer: HTMLElement | null = null;

	async init(lightboxContainer: HTMLElement) {
		this.lightboxContainer = lightboxContainer;
		await this.loadMembers();
		this.attachToPhotoSwipe(lightboxContainer);
	}

	private async loadMembers() {
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
			const data = await response.json() as { data?: unknown[] };
			this.membersCache = (data.data ?? []) as typeof this.membersCache;
		} catch {
			// Network error — tagging UI just stays empty
		}
	}

	private attachToPhotoSwipe(container: HTMLElement) {
		// Hook into PhotoSwipe initialization to detect slide changes
		const photoSwipeInitObserver = new MutationObserver(() => {
			const photoLinks = container.querySelectorAll(
				'[data-avpvh-id][data-pswp-width]'
			);
			const firstPhotoLink = photoLinks[0] as HTMLElement;

			if (firstPhotoLink) {
				// Watch for when the light box image changes
				const imageIdObserver = new MutationObserver(() => {
					this.onSlideChange();
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

	private async onSlideChange() {
		// When slide changes in lightbox, reload tags
		const currentSlide = document.querySelector(
			'[data-pswp-width].pswp__img'
		)!;

		if (currentSlide) {
			const imageLink = currentSlide.closest('[data-avpvh-id]')!;
			if (imageLink) {
				const newImageId = imageLink.getAttribute('data-avpvh-id');
				if (newImageId && newImageId !== this.currentImageId) {
					this.currentImageId = newImageId;
					await this.loadAndRenderTags();
				}
			}
		}
	}

	private async loadAndRenderTags() {
		try {
			const response = await fetch(
				`/wp-admin/admin-ajax.php?action=gallery_tag_list&image_id=${this.currentImageId}`
			);
			const data = await response.json();

			if (data.success) {
				this.annotationMap.clear();

				// Store tag data for quick lookup
				data.data.tags.forEach((tag: TagData) => {
					this.annotationMap.set(`tag-${tag.id}`, tag);
				});

				// Display tags on the image
				this.displayTags(data.data.tags);
			}
		} catch (error) {
			console.error('Failed to load tags:', error);
		}
	}

	private displayTags(tags: Array<TagData>) {
		// Render tags as visual overlays on the image
		const pswpImg = document.querySelector('.pswp__img')!;
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
				box.style.left = tag.region_data.x + 'px';
				box.style.top = tag.region_data.y + 'px';
				box.style.width = tag.region_data.width + 'px';
				box.style.height = tag.region_data.height + 'px';
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

	async addTag(
		imageId: string,
		memberId: number,
		region?: { x: number; y: number; width: number; height: number }
	) {
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
					region_data: region ? JSON.stringify(region) : '',
					_ajax_nonce:
						(window as any).avpvhShortcodeLocalize?.tag_nonce || '',
				}).toString(),
			});

			if (response.ok) {
				await this.loadAndRenderTags();
			}
		} catch (error) {
			console.error('Failed to add tag:', error);
		}
	}

	async deleteTag(tagId: number) {
		try {
			const response = await fetch('/wp-admin/admin-ajax.php', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams({
					action: 'gallery_tag_delete',
					tag_id: String(tagId),
					_ajax_nonce:
						(window as any).avpvhShortcodeLocalize?.tag_nonce || '',
				}).toString(),
			});

			if (response.ok) {
				await this.loadAndRenderTags();
			}
		} catch (error) {
			console.error('Failed to delete tag:', error);
		}
	}

	async addComment(tagId: number, commentText: string) {
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
					_ajax_nonce:
						(window as any).avpvhShortcodeLocalize?.tag_nonce || '',
				}).toString(),
			});

			if (response.ok) {
				await this.loadAndRenderTags();
			}
		} catch (error) {
			console.error('Failed to add comment:', error);
		}
	}

	async addReaction(tagId: number, emoji: string) {
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
					_ajax_nonce:
						(window as any).avpvhShortcodeLocalize?.tag_nonce || '',
				}).toString(),
			});

			if (response.ok) {
				await this.loadAndRenderTags();
			}
		} catch (error) {
			console.error('Failed to add reaction:', error);
		}
	}

	getMembersForDropdown() {
		return this.membersCache;
	}

	showMemberSelector(
		x: number,
		y: number,
		callback: (memberId: number) => void
	) {
		const overlay = document.createElement('div');
		overlay.className = 'avpvh-member-selector-overlay';
		overlay.style.left = x + 'px';
		overlay.style.top = y + 'px';

		const select = document.createElement('select');
		select.innerHTML = '<option value="">-- Select member --</option>';
		this.membersCache.forEach((m) => {
			const opt = document.createElement('option');
			opt.value = String(m.id);
			opt.textContent = `${m.name} (${m.status === 'active' ? 'Active' : 'Ex'})`;
			select.appendChild(opt);
		});

		const btn = document.createElement('button');
		btn.textContent = 'Tag';
		btn.onclick = () => {
			const memberId = parseInt(select.value);
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
}
