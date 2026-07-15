import landscapeIconUrl from '../../assets/icon.svg?url';
import portraitIconUrl from '../../assets/icon-portrait.svg?url';

export function getOrientationIconUrl(portrait: boolean): string {
	return portrait ? portraitIconUrl : landscapeIconUrl;
}

type Matrix = readonly [number, number, number, number];

interface Move {
	label: string;
	matrix: Matrix;
}

const IDENTITY: Matrix = [1, 0, 0, 1];
const MIRROR: Matrix = [-1, 0, 0, 1];
const FLIP: Matrix = [1, 0, 0, -1];
const ROTATE_90: Matrix = [0, 1, -1, 0];
const ROTATE_MINUS_90: Matrix = [0, -1, 1, 0];
const ROTATE_180: Matrix = [-1, 0, 0, -1];

function multiply(left: Matrix, right: Matrix): Matrix {
	return [
		left[0] * right[0] + left[2] * right[1],
		left[1] * right[0] + left[3] * right[1],
		left[0] * right[2] + left[2] * right[3],
		left[1] * right[2] + left[3] * right[3],
	];
}

function inverse(matrix: Matrix): Matrix {
	const determinant = matrix[0] * matrix[3] - matrix[1] * matrix[2];
	return [
		matrix[3] / determinant,
		-matrix[1] / determinant,
		-matrix[2] / determinant,
		matrix[0] / determinant,
	];
}

function matrixCss(matrix: Matrix): string {
	const clean = matrix.map((value) => (Object.is(value, -0) ? 0 : value));
	return `matrix(${clean.join(',')},0,0)`;
}

function renderState(iconUrl: string, matrix: Matrix, size: number): string {
	return (
		`<span class="avpvh-orientation-state" style="display:inline-flex;align-items:center;justify-content:center;vertical-align:middle;width:${String(size)}px;height:${String(size)}px">` +
		`<img src="${iconUrl}" alt="" width="${String(size)}" height="${String(size)}" ` +
		`style="display:block;filter:var(--avpvh-orientation-filter,none);transform-origin:center;transform:${matrixCss(matrix)}">` +
		'</span>'
	);
}

function renderChain(
	moves: ReadonlyArray<Move>,
	portrait: boolean,
	size: number
): string {
	const states = new Array<Matrix>(moves.length + 1);
	states[moves.length] = IDENTITY;
	for (let index = moves.length - 1; index >= 0; --index) {
		states[index] = multiply(
			inverse(moves[index].matrix),
			states[index + 1]
		);
	}

	const iconUrl = getOrientationIconUrl(portrait);
	let html = renderState(iconUrl, states[0], size);
	for (let index = 0; index < moves.length; ++index) {
		html +=
			`<span class="avpvh-orientation-move" style="display:inline-flex;align-items:center;gap:2px;white-space:nowrap">→ <strong>${moves[index].label}</strong> →</span>` +
			renderState(iconUrl, states[index + 1], size);
	}
	return `<span class="avpvh-orientation-chain" style="display:inline-flex;align-items:center;gap:3px;vertical-align:middle">${html}</span>`;
}

function rotationMove(rotation: number): Move | null {
	if (rotation === 90) {
		return { label: '+90°', matrix: ROTATE_90 };
	}
	if (rotation === 180) {
		return { label: '180°', matrix: ROTATE_180 };
	}
	if (rotation === 270 || rotation === -90) {
		return { label: '−90°', matrix: ROTATE_MINUS_90 };
	}
	return null;
}

export function renderCorrectionOrientationChain(
	rotation: number,
	hFlip: boolean,
	vFlip: boolean,
	portrait: boolean,
	size = 20
): string {
	const moves: Array<Move> = [];
	const rotationOperation = rotationMove(rotation);
	if (rotationOperation !== null) {
		moves.push(rotationOperation);
	}
	if (hFlip) {
		moves.push({ label: 'mirror', matrix: MIRROR });
	}
	if (vFlip) {
		moves.push({ label: 'flip', matrix: FLIP });
	}
	return renderChain(moves, portrait, size);
}

export function renderExifOrientationChain(
	orientation: number,
	portrait: boolean,
	size = 20
): string {
	const moves: Partial<Record<number, ReadonlyArray<Move>>> = {
		1: [],
		2: [{ label: 'mirror', matrix: MIRROR }],
		3: [{ label: '180°', matrix: ROTATE_180 }],
		4: [{ label: 'flip', matrix: FLIP }],
		// These two mappings intentionally follow the approved UI convention.
		5: [
			{ label: '+90°', matrix: ROTATE_90 },
			{ label: 'flip', matrix: FLIP },
		],
		6: [{ label: '+90°', matrix: ROTATE_90 }],
		7: [
			{ label: '+90°', matrix: ROTATE_90 },
			{ label: 'mirror', matrix: MIRROR },
		],
		8: [{ label: '−90°', matrix: ROTATE_MINUS_90 }],
	};
	return renderChain(moves[orientation] ?? [], portrait, size);
}
