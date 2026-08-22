<?php

namespace MediaWiki\Extension\SaintapediaSuggest;

/**
 * Decides when two reader submissions are "the same suggestion".
 *
 * Ten readers noticing one wrong phone number should become one queue item,
 * not ten. This class owns only the comparison rule; the storage side
 * (finding the canonical row, linking, counting) lives in SuggestionStore.
 *
 * Deliberately free of MediaWiki services so the rule is unit-testable.
 *
 * Two design choices worth stating, because both are easy to get wrong:
 *
 *  - Normalization is conservative. It folds case, trims, collapses runs of
 *    whitespace, and unifies the Unicode punctuation that phones and
 *    keyboards substitute silently (curly quotes, en/em dashes, non-breaking
 *    spaces). It does NOT strip punctuation or digits' separators: for a
 *    Cargo field, "555-0100" and "5550100" are different proposed values and
 *    a reviewer should see both.
 *
 *  - Only OPEN suggestions attract duplicates. If a suggestion was dismissed
 *    and a new reader proposes the same value again, that is evidence the
 *    dismissal may have been wrong — it deserves its own queue item rather
 *    than being folded silently into a closed one.
 */
class SuggestionMerger {

	/** Statuses that a new duplicate may be folded into. */
	public const OPEN_STATUSES = [ 'new', 'reviewed' ];

	/**
	 * Characters that different keyboards, phones and copy-paste sources
	 * produce for the same intent. Folding these stops "St. Mary's" typed on
	 * an iPhone from being treated as different from the same string typed
	 * on a laptop.
	 */
	private const PUNCTUATION_MAP = [
		"\u{2018}" => "'",   // left single quote
		"\u{2019}" => "'",   // right single quote / apostrophe
		"\u{201A}" => "'",
		"\u{201B}" => "'",
		"\u{201C}" => '"',   // left double quote
		"\u{201D}" => '"',   // right double quote
		"\u{201E}" => '"',
		"\u{2032}" => "'",   // prime
		"\u{2033}" => '"',   // double prime
		"\u{2010}" => '-',   // hyphen
		"\u{2011}" => '-',   // non-breaking hyphen
		"\u{2012}" => '-',   // figure dash
		"\u{2013}" => '-',   // en dash
		"\u{2014}" => '-',   // em dash
		"\u{2015}" => '-',   // horizontal bar
		"\u{2212}" => '-',   // minus sign
		"\u{00A0}" => ' ',   // non-breaking space
		"\u{202F}" => ' ',   // narrow no-break space
		"\u{2009}" => ' ',   // thin space
		"\u{200B}" => '',    // zero-width space
		"\u{FEFF}" => '',    // BOM / zero-width no-break space
		"\u{2026}" => '...', // ellipsis
	];

	/**
	 * Comparison form of a proposed value.
	 *
	 * Pure; unit-testable. Two submissions are duplicates when their
	 * normalized forms are identical strings.
	 */
	public static function normalizeValue( ?string $value ): string {
		if ( $value === null ) {
			return '';
		}

		$value = strtr( $value, self::PUNCTUATION_MAP );

		// Collapse every run of whitespace (including newlines and tabs
		// pasted out of a PDF) to a single space.
		$value = preg_replace( '/\s+/u', ' ', $value );
		$value = trim( (string)$value );

		if ( $value === '' ) {
			return '';
		}

		// mb_strtolower is Unicode-aware, so "MÜNCHEN" folds to "münchen";
		// strtolower would leave the umlaut alone and miss the match.
		return function_exists( 'mb_strtolower' )
			? mb_strtolower( $value, 'UTF-8' )
			: strtolower( $value );
	}

	/**
	 * Whether two proposed values should be treated as the same suggestion.
	 * Pure; unit-testable.
	 */
	public static function valuesMatch( ?string $a, ?string $b ): bool {
		$normalizedA = self::normalizeValue( $a );
		if ( $normalizedA === '' ) {
			// An empty proposal never matches anything, including another
			// empty one — the API rejects those before they reach storage.
			return false;
		}
		return $normalizedA === self::normalizeValue( $b );
	}

	/**
	 * Whether a suggestion in this status may absorb new duplicates.
	 * Pure; unit-testable.
	 */
	public static function statusAcceptsDuplicates( ?string $status ): bool {
		return in_array( (string)$status, self::OPEN_STATUSES, true );
	}

	/**
	 * Pick the canonical row for a new submission out of the candidate rows
	 * already stored for the same page/table/field.
	 *
	 * Candidates are filtered here rather than in SQL because the match is a
	 * normalized string comparison the database cannot express without
	 * storing a second, redundant column. The candidate set is bounded by
	 * (page, table, field), so it is small in practice.
	 *
	 * A candidate that is itself a duplicate is followed no further: rows
	 * are only ever one level deep, so folding into it would create a chain.
	 * The already-canonical row wins.
	 *
	 * @param object[] $candidates Rows with sg_id, sg_status,
	 *   sg_suggested_value, sg_duplicate_of
	 * @return int|null Canonical suggestion id, or null when this is new
	 */
	public static function pickCanonical( array $candidates, string $suggestedValue ): ?int {
		if ( self::normalizeValue( $suggestedValue ) === '' ) {
			return null;
		}

		$best = null;
		foreach ( $candidates as $row ) {
			if ( !is_object( $row ) ) {
				continue;
			}
			// Never fold into a row that is already someone else's duplicate.
			if ( ( $row->sg_duplicate_of ?? null ) !== null ) {
				continue;
			}
			if ( !self::statusAcceptsDuplicates( $row->sg_status ?? null ) ) {
				continue;
			}
			if ( !self::valuesMatch( $suggestedValue, $row->sg_suggested_value ?? null ) ) {
				continue;
			}
			$id = (int)( $row->sg_id ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			// Oldest canonical row wins, so the queue item keeps the
			// timestamp of the first person who reported the problem.
			if ( $best === null || $id < $best ) {
				$best = $id;
			}
		}
		return $best;
	}
}
