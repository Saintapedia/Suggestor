<?php

namespace MediaWiki\Extension\SaintapediaSuggest;

/**
 * Compares the value a reader saw when they reported a problem against what
 * Cargo holds now.
 *
 * This extension never writes to Cargo, so a suggestion can sit in the queue
 * while the underlying data moves on beneath it. Two cases matter and neither
 * is visible without checking:
 *
 *  - someone already made the change, by hand or as part of other work, and the
 *    queue item is now busywork
 *  - the value changed to something else entirely, so the reader's report is
 *    about a state that no longer exists and the reviewer would otherwise be
 *    comparing against a stale snapshot
 *
 * A third case is the reason the whole thing is worth having: an item marked
 * ACTIONED whose live value still equals the original snapshot means a reviewer
 * marked it done without the edit actually landing.
 *
 * Deliberately free of MediaWiki services so the rule is unit-testable.
 */
class SuggestionFreshness {

	/** Cargo no longer has that row or field; nothing can be said. */
	public const UNKNOWN = 'unknown';

	/** The live value still matches what the reader saw. */
	public const CURRENT = 'current';

	/** The live value already equals what the reader proposed. */
	public const APPLIED = 'applied';

	/** The live value is neither the snapshot nor the proposal. */
	public const CHANGED = 'changed';

	/**
	 * Classify one suggestion against the live value.
	 *
	 * Comparison reuses SuggestionMerger's normalization, so a value that
	 * differs only by case, whitespace or curly punctuation counts as the
	 * same — otherwise a reviewer would see "already applied" flip to
	 * "changed" over a typographic apostrophe.
	 *
	 * Pure; unit-testable.
	 *
	 * @param string|null $snapshot Value stored at submit time
	 * @param string|null $live Value in Cargo now, or null when it is gone
	 * @param string $suggested What the reader proposed
	 */
	public static function classify( ?string $snapshot, ?string $live, string $suggested ): string {
		if ( $live === null ) {
			return self::UNKNOWN;
		}

		$liveNorm = SuggestionMerger::normalizeValue( $live );

		// Checked before CURRENT: when a reader proposes the value that is
		// already stored the API rejects it, so these cannot both be true for
		// a live suggestion — but a legacy row could, and "already applied"
		// is the more useful reading of it.
		if ( $liveNorm !== '' && $liveNorm === SuggestionMerger::normalizeValue( $suggested ) ) {
			return self::APPLIED;
		}

		if ( $liveNorm === SuggestionMerger::normalizeValue( (string)$snapshot ) ) {
			return self::CURRENT;
		}

		return self::CHANGED;
	}

	/**
	 * Whether this state is worth drawing a reviewer's attention to.
	 * CURRENT is the ordinary case and gets no badge.
	 */
	public static function isNoteworthy( string $state ): bool {
		return $state === self::APPLIED || $state === self::CHANGED;
	}

	/**
	 * Whether an ACTIONED item looks like it was never actually applied.
	 *
	 * Marking something actioned asserts the change was made elsewhere. If the
	 * live value still equals the snapshot the reader reported, that assertion
	 * did not hold — the commonest way this queue silently goes wrong.
	 */
	public static function isActionedButUnchanged( string $status, string $state ): bool {
		return $status === 'actioned' && $state === self::CURRENT;
	}
}
