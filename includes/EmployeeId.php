<?php

namespace MediaWiki\Extension\KpiId;

/**
 * An EMPLOYEE_ID as an administrator types it.
 */
class EmployeeId {

	/**
	 * @param string $value as typed
	 * @return string|null the value without surrounding whitespace, or null when it is empty, has
	 *   whitespace inside or is longer than the user_properties column takes
	 */
	public static function normalize( string $value ): ?string {
		$value = trim( $value );
		if ( $value === '' || strlen( $value ) > 255 || preg_match( '/[\s\p{Z}]/u', $value ) ) {
			return null;
		}
		return $value;
	}
}
