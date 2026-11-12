<?php

namespace MediaWiki\Extension\KpiId\Tests;

use MediaWiki\Extension\KpiId\EmployeeId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\KpiId\EmployeeId
 */
class EmployeeIdTest extends TestCase {

	public static function provideNormalize(): iterable {
		yield 'digits' => [ '12345', '12345' ];
		yield 'surrounding whitespace' => [ " 12345\n", '12345' ];
		yield 'letters kept as typed' => [ 'A-17', 'A-17' ];
		yield 'empty' => [ '', null ];
		yield 'only whitespace' => [ "  \t", null ];
		yield 'whitespace inside' => [ '123 45', null ];
		yield 'non-breaking space inside' => [ "123\u{00A0}45", null ];
		yield 'longest the column takes' => [ str_repeat( '1', 255 ), str_repeat( '1', 255 ) ];
		yield 'too long' => [ str_repeat( '1', 256 ), null ];
	}

	#[DataProvider( 'provideNormalize' )]
	public function testNormalize( string $value, ?string $expected ): void {
		$this->assertSame( $expected, EmployeeId::normalize( $value ) );
	}
}
