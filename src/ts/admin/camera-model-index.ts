declare const avpvhCameraModelIndex: {
	rest_url: string;
	nonce: string;
};

interface IndexStatus {
	running: boolean;
	folders_done: number;
	folders_queued: number;
	images_done: number;
	updated_at: number | null;
	folder_count: number;
	image_count: number;
}

const statusElement = document.getElementById('avpvh-camera-model-status');
const startButton = document.getElementById(
	'avpvh-camera-model-start'
) as HTMLButtonElement | null;
let indexStatus: IndexStatus | null = null;
let working = false;

async function request(path: string, method = 'GET'): Promise<IndexStatus> {
	const response = await fetch(avpvhCameraModelIndex.rest_url + path, {
		method,
		headers: { 'X-WP-Nonce': avpvhCameraModelIndex.nonce },
		credentials: 'include',
	});
	if (!response.ok) {
		const data = (await response.json().catch(() => null)) as {
			message?: string;
		} | null;
		throw new Error(data?.message ?? `HTTP ${String(response.status)}`);
	}
	return response.json() as Promise<IndexStatus>;
}

function getButtonLabel(next: IndexStatus): string {
	if (next.running) {
		return 'Indexeren hervatten';
	}
	return next.updated_at === null
		? 'Cameramodellen indexeren'
		: 'Index bijwerken';
}

function render(next: IndexStatus, error = ''): void {
	indexStatus = next;
	if (statusElement) {
		if (error !== '') {
			statusElement.textContent = `Indexeren gestopt: ${error}. De scan kan worden hervat.`;
			statusElement.style.color = '#b32d2e';
		} else if (next.running) {
			statusElement.textContent =
				`Bezig: ${String(next.folders_done)} mappen en ${String(next.images_done)} afbeeldingen verwerkt; ` +
				`${String(next.folders_queued)} mappen in de wachtrij.`;
			statusElement.style.color = '#50575e';
		} else if (next.updated_at !== null) {
			const date = new Date(next.updated_at * 1000).toLocaleString(
				'nl-NL'
			);
			statusElement.textContent = `Laatst bijgewerkt: ${date}. ${String(next.folder_count)} mappen en ${String(next.image_count)} afbeeldingen geïndexeerd.`;
			statusElement.style.color = '#2271b1';
		} else {
			statusElement.textContent =
				'Er is nog geen cameramodel-index gemaakt.';
			statusElement.style.color = '#50575e';
		}
	}
	if (startButton) {
		startButton.disabled = working;
		startButton.textContent = getButtonLabel(next);
	}
}

async function continueScan(): Promise<void> {
	if (indexStatus?.running !== true || working) {
		return;
	}
	working = true;
	if (startButton) {
		startButton.disabled = true;
	}
	let failure = '';
	try {
		while (indexStatus.running) {
			indexStatus = await request('step', 'POST');
			render(indexStatus);
			await new Promise((resolve) => setTimeout(resolve, 50));
		}
	} catch (error) {
		failure = error instanceof Error ? error.message : 'onbekende fout';
	} finally {
		working = false;
		render(indexStatus, failure);
	}
}

startButton?.addEventListener('click', () => {
	void (async (): Promise<void> => {
		if (working) {
			return;
		}
		try {
			if (indexStatus?.running !== true) {
				indexStatus = await request('start', 'POST');
				render(indexStatus);
			}
			await continueScan();
		} catch (error) {
			if (indexStatus) {
				render(
					indexStatus,
					error instanceof Error ? error.message : 'onbekende fout'
				);
			}
		}
	})();
});

void request('status')
	.then(render)
	.catch((error: unknown) => {
		if (statusElement) {
			statusElement.textContent = `Status laden mislukt: ${error instanceof Error ? error.message : 'onbekende fout'}`;
		}
	});
