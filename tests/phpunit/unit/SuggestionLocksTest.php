<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Tests\Unit;

use MediaWiki\Extension\SaintapediaSuggest\SuggestionLocks;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SaintapediaSuggest\SuggestionLocks
 */
class SuggestionLocksTest extends TestCase {

	public function testRateLimitLockIsKeyedOnTheIpHashNotTheTarget(): void {
		$a = SuggestionLocks::rateLimitLockName( str_repeat( 'a', 64 ) );
		$b = SuggestionLocks::rateLimitLockName( str_repeat( 'b', 64 ) );
		$this->assertNotSame( $a, $b, 'Two IPs must not share a rate-limit lock' );
		$this->assertStringStartsWith( 'sps-rl-', $a );
		$this->assertLessThanOrEqual( 64, strlen( $a ), 'MySQL GET_LOCK names are 64 chars' );
	}

	public function testDuplicateLockIsIndependentOfTheSubmitter(): void {
		$lock = SuggestionLocks::duplicateLockName( 42, 'Parishes', 'Phone', 7 );
		$this->assertSame(
			$lock,
			SuggestionLocks::duplicateLockName( 42, 'Parishes', 'Phone', 7 ),
			'Same target always hashes to the same lock, whoever is submitting'
		);
		$this->assertStringStartsWith( 'sps-dupe-', $lock );
		$this->assertLessThanOrEqual( 64, strlen( $lock ) );
	}

	public function testDuplicateLockDiffersAcrossPageTableFieldAndRow(): void {
		$base = SuggestionLocks::duplicateLockName( 42, 'Parishes', 'Phone', 1 );
		$this->assertNotSame(
			$base,
			SuggestionLocks::duplicateLockName( 43, 'Parishes', 'Phone', 1 ),
			'different page'
		);
		$this->assertNotSame(
			$base,
			SuggestionLocks::duplicateLockName( 42, 'Dioceses', 'Phone', 1 ),
			'different table'
		);
		$this->assertNotSame(
			$base,
			SuggestionLocks::duplicateLockName( 42, 'Parishes', 'Address', 1 ),
			'different field'
		);
		$this->assertNotSame(
			$base,
			SuggestionLocks::duplicateLockName( 42, 'Parishes', 'Phone', 2 ),
			'different row'
		);
		$this->assertNotSame(
			$base,
			SuggestionLocks::duplicateLockName( 42, 'Parishes', 'Phone', null ),
			'legacy null row is its own key'
		);
	}

	public function testRateLimitAndDuplicateLocksNeverCollide(): void {
		$ip = SuggestionLocks::rateLimitLockName( str_repeat( 'f', 64 ) );
		$dupe = SuggestionLocks::duplicateLockName( 1, 'Parishes', 'Phone', 1 );
		$this->assertNotSame( $ip, $dupe );
	}
}
