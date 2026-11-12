<?php

namespace MediaWiki\Extension\KpiId\Tests;

use MediaWiki\Extension\KpiId\SignInButton;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\KpiId\SignInButton
 */
class SignInButtonTest extends TestCase {

	public static function provideScriptUrl(): iterable {
		yield 'production' => [ 'https://auth.kpi.ua', 'https://auth.kpi.ua/kpi-id-signin.js' ];
		yield 'trailing slash' => [ 'https://auth.cloud.kpi.ua/', 'https://auth.cloud.kpi.ua/kpi-id-signin.js' ];
	}

	#[DataProvider( 'provideScriptUrl' )]
	public function testScriptUrl( string $providerUrl, string $expected ): void {
		$this->assertSame( $expected, SignInButton::scriptUrl( $providerUrl ) );
	}
}
