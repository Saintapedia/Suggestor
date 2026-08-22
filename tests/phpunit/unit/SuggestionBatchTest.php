<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Tests\Unit;

use MediaWiki\Extension\SaintapediaSuggest\SuggestionBatch;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SaintapediaSuggest\SuggestionBatch
 */
class SuggestionBatchTest extends TestCase {

	private function row( array $overrides = [] ): object {
		return (object)( $overrides + [
			'sg_id'              => 7,
			'sg_page_id'         => 79,
			'sg_page_title'      => 'St._Barnabas',
			'sg_page_namespace'  => 0,
			'sg_cargo_table'     => 'Parishes',
			'sg_cargo_field'     => 'Phone',
			'sg_current_value'   => '555-0100',
			'sg_suggested_value' => '555-0199',
			'sg_comment'         => 'Number changed.',
			'sg_status'          => 'new',
			'sg_mode'            => 'public',
			'sg_duplicate_count' => 2,
			'sg_timestamp'       => '20260822000000',
			// Present on real rows; must not reach the payload.
			'sg_contact_email'   => 'reader@example.org',
			'sg_ip_hash'         => str_repeat( 'a', 64 ),
			'sg_work_note'       => 'Internal triage note.',
		] );
	}

	public function testPayloadShape(): void {
		$payload = SuggestionBatch::buildPayload( [ $this->row() ] );

		$this->assertSame( 1, $payload['count'] );
		$this->assertCount( 1, $payload['items'] );

		$item = $payload['items'][0];
		$this->assertSame( 7, $item['id'] );
		$this->assertSame( 'Parishes', $item['cargoTable'] );
		$this->assertSame( 'Phone', $item['cargoField'] );
		$this->assertSame( '555-0100', $item['currentValue'] );
		$this->assertSame( '555-0199', $item['suggestedValue'] );
		$this->assertSame( 2, $item['duplicateCount'] );
	}

	/**
	 * This payload crosses a network boundary to a third party, so it must
	 * carry the suggestion and not the submitter.
	 */
	public function testPayloadNeverCarriesSubmitterOrInternalData(): void {
		$payload = SuggestionBatch::buildPayload( [ $this->row() ] );
		$item = $payload['items'][0];

		$this->assertArrayNotHasKey( 'contactEmail', $item );
		$this->assertArrayNotHasKey( 'sg_contact_email', $item );
		$this->assertArrayNotHasKey( 'ipHash', $item );
		$this->assertArrayNotHasKey( 'sg_ip_hash', $item );
		$this->assertArrayNotHasKey( 'workNote', $item );
		$this->assertArrayNotHasKey( 'sg_work_note', $item );

		$encoded = json_encode( $payload );
		$this->assertStringNotContainsString( 'reader@example.org', $encoded );
		$this->assertStringNotContainsString( 'Internal triage note', $encoded );
		$this->assertStringNotContainsString( str_repeat( 'a', 64 ), $encoded );
	}

	public function testEmptyCommentBecomesNull(): void {
		$payload = SuggestionBatch::buildPayload( [ $this->row( [ 'sg_comment' => '' ] ) ] );
		$this->assertNull( $payload['items'][0]['comment'] );
	}

	public function testMalformedRowsAreSkipped(): void {
		$payload = SuggestionBatch::buildPayload( [ 'garbage', null, $this->row() ] );
		$this->assertSame( 1, $payload['count'] );
	}

	public function testEmptyBatch(): void {
		$payload = SuggestionBatch::buildPayload( [] );
		$this->assertSame( 0, $payload['count'] );
		$this->assertSame( [], $payload['items'] );
	}

	/** @dataProvider provideWebhooks */
	public function testIsValidWebhook( ?string $url, bool $expected ): void {
		$this->assertSame( $expected, SuggestionBatch::isValidWebhook( $url ) );
	}

	public static function provideWebhooks(): array {
		return [
			'https'          => [ 'https://example.org/hook', true ],
			'https with port' => [ 'https://example.org:8443/hook', true ],
			// Plaintext would expose reader content and the bearer token.
			'http refused'   => [ 'http://example.org/hook', false ],
			'no scheme'      => [ 'example.org/hook', false ],
			'ftp'            => [ 'ftp://example.org/hook', false ],
			'empty'          => [ '', false ],
			'whitespace'     => [ '   ', false ],
			'null'           => [ null, false ],
			'garbage'        => [ 'not a url at all', false ],
		];
	}

	public function testClampBatchSize(): void {
		$this->assertSame( 100, SuggestionBatch::clampBatchSize( 100 ) );
		$this->assertSame( 1, SuggestionBatch::clampBatchSize( 1 ) );
		$this->assertSame(
			SuggestionBatch::MAX_BATCH_SIZE,
			SuggestionBatch::clampBatchSize( 100000 )
		);
		// Zero and negatives mean "unset", not "post nothing".
		$this->assertSame( SuggestionBatch::DEFAULT_BATCH_SIZE, SuggestionBatch::clampBatchSize( 0 ) );
		$this->assertSame( SuggestionBatch::DEFAULT_BATCH_SIZE, SuggestionBatch::clampBatchSize( -5 ) );
	}

	/**
	 * Operators put tokens in query strings even when told not to; the log
	 * line must not copy one into terminal scrollback.
	 */
	public function testRedactUrlDropsQueryAndCredentials(): void {
		$this->assertSame(
			'https://example.org/hook?<redacted>',
			SuggestionBatch::redactUrl( 'https://example.org/hook?token=supersecret' )
		);
		$this->assertStringNotContainsString(
			'supersecret',
			SuggestionBatch::redactUrl( 'https://user:supersecret@example.org/hook' )
		);
		$this->assertSame(
			'https://example.org/hook',
			SuggestionBatch::redactUrl( 'https://example.org/hook' )
		);
		$this->assertSame( '(none)', SuggestionBatch::redactUrl( '' ) );
		$this->assertSame( '(none)', SuggestionBatch::redactUrl( null ) );
		$this->assertSame( '(invalid URL)', SuggestionBatch::redactUrl( 'nonsense' ) );
	}
}
