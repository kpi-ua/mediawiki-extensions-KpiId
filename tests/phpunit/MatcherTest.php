<?php

namespace MediaWiki\Extension\KpiId\Tests;

use MediaWiki\Extension\KpiId\Matcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\KpiId\Matcher
 */
class MatcherTest extends TestCase {

	public static function provideEmployeeIds(): iterable {
		yield 'array of numbers' => [ [ 'employee_ids' => [ 1053408 ] ], [ '1053408' ] ];
		yield 'several, duplicated' => [ [ 'employee_ids' => [ 12, '12', 34 ] ], [ '12', '34' ] ];
		yield 'single number' => [ [ 'employee_ids' => 77 ], [ '77' ] ];
		yield 'single string, trimmed' => [ [ 'employee_ids' => ' 77 ' ], [ '77' ] ];
		yield 'JSON-encoded array' => [ [ 'employee_ids' => '[5, 6]' ], [ '5', '6' ] ];
		yield 'empty values dropped' => [ [ 'employee_ids' => [ '', ' ', null, 8 ] ], [ '8' ] ];
		yield 'nested and other types dropped' => [ [ 'employee_ids' => [ [ 1 ], 1.5, true ] ], [] ];
		yield 'empty array' => [ [ 'employee_ids' => [] ], [] ];
		yield 'no claim' => [ [ 'sub' => 'abc' ], [] ];
	}

	#[DataProvider( 'provideEmployeeIds' )]
	public function testEmployeeIds( array $attributes, array $expected ): void {
		$this->assertSame( $expected, Matcher::employeeIds( $attributes ) );
	}

	public function testEmployeeIdsAreAList(): void {
		// A list with string values, never string keys that PHP would turn into ints
		$ids = Matcher::employeeIds( [ 'employee_ids' => [ 3, 3, 9 ] ] );
		$this->assertTrue( array_is_list( $ids ) );
		$this->assertContainsOnlyString( $ids );
	}

	public function testPropertyIsNotUserSettable(): void {
		$this->assertStringStartsNotWith( 'userjs-', Matcher::PROPERTY );
	}

	public function testKeepPropertyRestoresStoredValue(): void {
		$this->assertSame(
			[ 'gender' => 'male', Matcher::PROPERTY => '12' ],
			Matcher::keepProperty(
				[ 'gender' => 'male', Matcher::PROPERTY => null ],
				[ Matcher::PROPERTY => '12' ]
			)
		);
	}

	public function testKeepPropertyRefusesNewValue(): void {
		$this->assertSame(
			[ 'gender' => 'male' ],
			Matcher::keepProperty( [ 'gender' => 'male', Matcher::PROPERTY => '12' ], [] )
		);
	}

	public function testKeepPropertyLeavesOtherSavesAlone(): void {
		$this->assertSame(
			[ 'gender' => 'female' ],
			Matcher::keepProperty( [ 'gender' => 'female' ], [ Matcher::PROPERTY => '12' ] )
		);
	}
}
