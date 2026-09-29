// Fuzzy person-name search for the tagging panel.
//
// Follows the same rules as avpvh-members' AVPVH_Name_Matcher: names are
// stored with their tussenvoegsel in different places ("Berg, van den",
// "van den Berg", "Berg v/d", "Tom van"), so tussenvoegsels are ignored
// when matching rather than guessed at. On top of that, the search is
// forgiving for typing: word order doesn't matter, words may be typed
// partially ("mass jef"), and small typos are tolerated ("maschelein").

export interface SearchablePerson {
	id: number;
	name: string;
}

const TUSSENVOEGSELS = new Set([
	'van',
	'von',
	'de',
	'den',
	'der',
	'des',
	'het',
	't',
	'ter',
	'ten',
	'te',
	'in',
	'op',
	'la',
	'le',
	'du',
	'v',
	'd',
	'vd',
	'vdn',
]);

export function normalizeName(name: string): Array<string> {
	return name
		.normalize('NFD')
		.replace(/[̀-ͯ]/g, '')
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, ' ')
		.trim()
		.split(' ')
		.filter((token) => token !== '');
}

function significant(tokens: Array<string>): Array<string> {
	const kept = tokens.filter((token) => !TUSSENVOEGSELS.has(token));
	return kept.length > 0 ? kept : tokens;
}

// Edit distance, capped: returns max + 1 as soon as it can't be <= max.
function editDistance(a: string, b: string, max: number): number {
	if (Math.abs(a.length - b.length) > max) {
		return max + 1;
	}
	let previous = Array.from({ length: b.length + 1 }, (_, i) => i);
	for (let i = 1; i <= a.length; i++) {
		const current = [i];
		let rowMin = i;
		for (let j = 1; j <= b.length; j++) {
			const cost = a[i - 1] === b[j - 1] ? 0 : 1;
			const value = Math.min(
				(previous[j] ?? 0) + 1,
				(current[j - 1] ?? 0) + 1,
				(previous[j - 1] ?? 0) + cost
			);
			current.push(value);
			rowMin = Math.min(rowMin, value);
		}
		if (rowMin > max) {
			return max + 1;
		}
		previous = current;
	}
	return previous[b.length] ?? max + 1;
}

function allowedTypos(token: string): number {
	if (token.length >= 7) {
		return 2;
	}
	return token.length >= 4 ? 1 : 0;
}

// Cost of matching one typed word against a name's words: 0 for a prefix
// match, the typo count for a near miss, or null when nothing fits.
function tokenCost(query: string, nameTokens: Array<string>): number | null {
	let best: number | null = null;
	for (const token of nameTokens) {
		if (token.startsWith(query)) {
			return 0;
		}
		const max = allowedTypos(query);
		if (max === 0) {
			continue;
		}
		const distance = Math.min(
			editDistance(query, token, max),
			editDistance(query, token.slice(0, query.length), max)
		);
		if (distance <= max && (best === null || distance < best)) {
			best = distance;
		}
	}
	return best;
}

// Two capitals ("GB") search by initials: the first letter of the first
// name and of the surname, tussenvoegsels skipped — so "GB" matches both
// "Garmt Boekholt" and "Germie van den Berg" (or "Berg, van den"). Typed
// in lowercase, the same letters are an ordinary search.
function matchesInitials(query: string, name: string): boolean {
	if (!/^[A-Z]{2}$/.test(query)) {
		return false;
	}
	const tokens = significant(normalizeName(name));
	const first = tokens[0] ?? '';
	// The surname: in "Anna Berg, van den" it's the word before the comma.
	const surname = name.includes(',')
		? (significant(normalizeName(name.split(',')[0] ?? '')).pop() ?? '')
		: (tokens[tokens.length - 1] ?? '');
	return (
		tokens.length >= 2 &&
		first.startsWith(query.charAt(0).toLowerCase()) &&
		surname.startsWith(query.charAt(1).toLowerCase())
	);
}

// Lower is better; null means no match.
export function matchScore(query: string, name: string): number | null {
	if (matchesInitials(query.trim(), name)) {
		return 0;
	}
	const queryTokens = significant(normalizeName(query));
	if (queryTokens.length === 0) {
		return 0;
	}
	const nameTokens = normalizeName(name);
	let score = 0;
	for (const token of queryTokens) {
		const cost = tokenCost(token, nameTokens);
		if (cost === null) {
			return null;
		}
		score += cost;
	}
	return score;
}

// Matching people, best match first; ties keep the pool's order (so
// suggested people — e.g. the activity's participants — stay on top).
export function searchPeople<T extends SearchablePerson>(
	query: string,
	pool: Array<T>
): Array<T> {
	return pool
		.map((person, index) => ({
			index,
			person,
			score: matchScore(query, person.name),
		}))
		.filter(
			(entry): entry is { index: number; person: T; score: number } =>
				entry.score !== null
		)
		.sort((a, b) => a.score - b.score || a.index - b.index)
		.map((entry) => entry.person);
}
