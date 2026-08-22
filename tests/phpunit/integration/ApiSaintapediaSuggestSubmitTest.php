<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Tests\Integration;

use ApiTestCase;
use ApiUsageException;
use MediaWiki\MediaWikiServices;

/**
 * Gate ordering and refusal behaviour of action=saintapediasuggest.
 *
 * These assertions are about the order the API says no in, which is the part
 * that carries the security properties: a malformed request must be refused
 * before it can burn a one-time captcha token, and the stored snapshot must
 * come from Cargo rather than from the request.
 *
 * Captcha is switched off for these tests ($wgSaintapediaSuggestRequireCaptcha
 * = false); CaptchaGateConfigTest covers the policy itself.
 *
 * @group Database
 * @group medium
 * @group SaintapediaSuggest
 * @covers \MediaWiki\Extension\SaintapediaSuggest\Api\ApiSaintapediaSuggestSubmit
 */
class ApiSaintapediaSuggestSubmitTest extends ApiTestCase {

	private const TABLE = 'SuggestTestTable';

	protected function setUp(): void {
		parent::setUp();
		$this->tablesUsed[] = 'sps_suggestion';
		$this->tablesUsed[] = 'sps_suggestion_log';
		$this->tablesUsed[] = 'page';

		$this->overrideConfigValues( [
			'SaintapediaSuggestRequireCaptcha' => false,
			'SaintapediaSuggestEnabled'        => true,
			'SaintapediaSuggestMode'           => 'public',
			'SaintapediaSuggestNamespaces'     => [ NS_MAIN ],
			'SaintapediaSuggestRateLimit'      => 50,
			'SaintapediaSuggestMaxValueLength' => 500,
			'SaintapediaSuggestTables'         => [ self::TABLE => [ 'Phone' ] ],
			'SaintapediaSuggestMergeDuplicates' => true,
		] );
	}

	private function existingPageId(): int {
		return $this->getExistingTestPage( 'SaintapediaSuggest test page' )->getId();
	}

	/**
	 * @param array<string,string|int> $params
	 */
	private function submit( array $params ): array {
		return $this->doApiRequestWithToken(
			$params + [ 'action' => 'saintapediasuggest' ]
		);
	}

	private function assertRefusedWith( string $code, array $params ): void {
		try {
			$this->submit( $params );
			$this->fail( "Expected the API to refuse with '$code'." );
		} catch ( ApiUsageException $e ) {
			$this->assertTrue(
				$e->getStatusValue()->hasMessage( $this->messageKeyFor( $code ) )
					|| $this->apiExceptionHasCode( $e, $code ),
				"Expected error code '$code', got: " . $e->getMessage()
			);
		}
	}

	private function messageKeyFor( string $code ): string {
		return 'saintapediasuggest-error-' . preg_replace( '/^sps-/', '', $code );
	}

	private function apiExceptionHasCode( ApiUsageException $e, string $code ): bool {
		foreach ( $e->getStatusValue()->getErrors() as $error ) {
			$message = $error['message'];
			$key = is_object( $message ) ? $message->getKey() : (string)$message;
			if ( strpos( $key, $code ) !== false ) {
				return true;
			}
		}
		return false;
	}

	public function testTokenIsRequired(): void {
		$this->expectException( ApiUsageException::class );
		$this->doApiRequest( [
			'action'         => 'saintapediasuggest',
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => 'Phone',
			'suggestedvalue' => '555-0100',
		] );
	}

	public function testNonexistentPageIsRefused(): void {
		$this->assertRefusedWith( 'invalidtitle', [
			'pageid'         => 999999999,
			'table'          => self::TABLE,
			'field'          => 'Phone',
			'suggestedvalue' => '555-0100',
		] );
	}

	public function testDisabledWikiRefusesBeforeAnythingElse(): void {
		$this->overrideConfigValue( 'SaintapediaSuggestEnabled', false );
		$this->assertRefusedWith( 'sps-disabled', [
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => 'Phone',
			'suggestedvalue' => '555-0100',
		] );
	}

	public function testNamespaceOutsideTheAllowedSetIsRefused(): void {
		$this->overrideConfigValue( 'SaintapediaSuggestNamespaces', [ NS_PROJECT ] );
		$this->assertRefusedWith( 'sps-namespace', [
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => 'Phone',
			'suggestedvalue' => '555-0100',
		] );
	}

	public function testReservedCargoFieldIsRefused(): void {
		$this->assertRefusedWith( 'sps-nofield', [
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => '_pageID',
			'suggestedvalue' => '1',
		] );
	}

	public function testFieldOutsideTheAllowListIsRefused(): void {
		$this->assertRefusedWith( 'sps-nofield', [
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => 'SomeOtherField',
			'suggestedvalue' => 'x',
		] );
	}

	public function testTableOutsideTheAllowListIsRefused(): void {
		$this->assertRefusedWith( 'sps-nofield', [
			'pageid'         => $this->existingPageId(),
			'table'          => 'NotAllowListedTable',
			'field'          => 'Phone',
			'suggestedvalue' => 'x',
		] );
	}

	/**
	 * The allow-list is checked before the captcha, so a request that could
	 * never succeed cannot consume a reader's one-time hCaptcha token.
	 */
	public function testAllowListIsCheckedBeforeTheCaptcha(): void {
		$this->overrideConfigValue( 'SaintapediaSuggestRequireCaptcha', true );
		$this->assertRefusedWith( 'sps-nofield', [
			'pageid'         => $this->existingPageId(),
			'table'          => 'NotAllowListedTable',
			'field'          => 'Phone',
			'suggestedvalue' => 'x',
		] );
	}

	public function testEmptySuggestedValueIsRefused(): void {
		$this->expectException( ApiUsageException::class );
		$this->submit( [
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => 'Phone',
			'suggestedvalue' => '',
		] );
	}

	public function testBlockedUserIsRefused(): void {
		$user = $this->getTestUser()->getUser();
		$this->getServiceContainer()->getDatabaseBlockStore()->insertBlock(
			$this->getServiceContainer()->getDatabaseBlockStoreFactory()
				->getDatabaseBlockStore()
				->newUnsaved( [
					'targetUser' => $user,
					'by'         => $this->getTestSysop()->getUser(),
					'reason'     => 'SaintapediaSuggest test',
					'expiry'     => 'infinity',
				] )
		);

		$this->expectException( ApiUsageException::class );
		$this->doApiRequestWithToken(
			[
				'action'         => 'saintapediasuggest',
				'pageid'         => $this->existingPageId(),
				'table'          => self::TABLE,
				'field'          => 'Phone',
				'suggestedvalue' => '555-0100',
			],
			null,
			$user
		);
	}

	/**
	 * The public API must never echo a stored contact email back.
	 */
	public function testResponseCarriesOnlyResultAndId(): void {
		// Requires a real Cargo row, so only assert the shape when the
		// allow-listed table genuinely resolves on this wiki.
		$registry = MediaWikiServices::getInstance()
			->getService( 'SaintapediaSuggest.CargoFieldRegistry' );
		if ( !$registry->isAllowed( self::TABLE, 'Phone' ) ) {
			$this->markTestSkipped(
				'No live Cargo table named ' . self::TABLE . '; submit-path shape is '
				. 'covered end-to-end against real Cargo data on the dev wiki.'
			);
		}

		[ $result ] = $this->submit( [
			'pageid'         => $this->existingPageId(),
			'table'          => self::TABLE,
			'field'          => 'Phone',
			'suggestedvalue' => '555-0100',
		] );

		$this->assertSame(
			[ 'result', 'id' ],
			array_keys( $result['saintapediasuggest'] )
		);
	}
}
