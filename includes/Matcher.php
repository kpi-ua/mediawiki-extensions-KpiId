<?php

namespace MediaWiki\Extension\KpiId;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Finds the one wiki account that holds the EMPLOYEE_ID a KPI ID sign-in carries.
 */
class Matcher {

	/** ID token claim with the person's EMPLOYEE_IDs, granted by the employee_ids scope */
	public const CLAIM = 'employee_ids';

	/**
	 * user_properties key with the EMPLOYEE_ID of each allowed account. It must not start with
	 * userjs-: people can set such options themselves and would pick whose account they get.
	 */
	public const PROPERTY = 'kpiid-employee-id';

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly LoggerInterface $logger
	) {
	}

	public static function fromServices(): self {
		return new self(
			MediaWikiServices::getInstance()->getConnectionProvider(),
			LoggerFactory::getInstance( 'KpiId' )
		);
	}

	/**
	 * @param array $attributes ID token claims
	 * @return string[] the EMPLOYEE_IDs, trimmed, non-empty, unique
	 */
	public static function employeeIds( array $attributes ): array {
		$value = $attributes[self::CLAIM] ?? null;
		if ( is_string( $value ) && str_starts_with( ltrim( $value ), '[' ) ) {
			$value = json_decode( $value, true );
		}
		$ids = [];
		foreach ( is_array( $value ) ? $value : [ $value ] as $item ) {
			if ( is_string( $item ) || is_int( $item ) ) {
				$item = trim( (string)$item );
				if ( $item !== '' ) {
					$ids[] = $item;
				}
			}
		}
		// Not array keys: PHP turns a numeric string key back into an int,
		// and PostgreSQL refuses to compare the text column with an integer.
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Keeps the stored EMPLOYEE_ID in an options save that would change or delete it.
	 *
	 * @param array $modified options about to be saved
	 * @param array $original options as stored
	 * @return array $modified with the property as stored
	 */
	public static function keepProperty( array $modified, array $original ): array {
		if ( array_key_exists( self::PROPERTY, $modified ) ) {
			if ( array_key_exists( self::PROPERTY, $original ) ) {
				$modified[self::PROPERTY] = $original[self::PROPERTY];
			} else {
				unset( $modified[self::PROPERTY] );
			}
		}
		return $modified;
	}

	/**
	 * @param array $attributes ID token claims
	 * @return int|null the id of the only account that holds one of the EMPLOYEE_IDs
	 */
	public function findUserId( array $attributes ): ?int {
		$employeeIds = self::employeeIds( $attributes );
		if ( !$employeeIds ) {
			$this->logger->warning( 'Sign-in without the {claim} claim', [ 'claim' => self::CLAIM ] );
			return null;
		}
		$userIds = $this->userIdsFor( $employeeIds );
		if ( count( $userIds ) !== 1 ) {
			$this->logger->warning(
				'EMPLOYEE_ID {employeeIds} matches {count} accounts; sign-in refused',
				[ 'employeeIds' => implode( ', ', $employeeIds ), 'count' => count( $userIds ) ]
			);
			return null;
		}
		return $userIds[0];
	}

	/**
	 * @param string[] $employeeIds
	 * @return int[] distinct ids of the accounts holding any of them
	 */
	public function userIdsFor( array $employeeIds ): array {
		$ids = $this->dbProvider->getPrimaryDatabase()->newSelectQueryBuilder()
			->select( 'up_user' )
			->distinct()
			->from( 'user_properties' )
			->where( [ 'up_property' => self::PROPERTY, 'up_value' => $employeeIds ] )
			->caller( __METHOD__ )
			->fetchFieldValues();
		return array_map( 'intval', $ids );
	}
}
