<?php

namespace MediaWiki\Extension\KpiId;

/**
 * The KPI ID button on the "login required" page: KPI ID's own button script, the one its button
 * wizard hands out, drawn over the sign-in link MediaWiki puts on that page.
 */
class SignInButton {

	public const MODULE = 'ext.kpiId.signInButton';

	/** mw.config variable with the URL of KPI ID's button script */
	public const SCRIPT_VAR = 'wgKpiIdSignInButtonScript';

	/**
	 * @param string $providerUrl KPI ID issuer
	 * @return string URL of the button script KPI ID serves next to its issuer
	 */
	public static function scriptUrl( string $providerUrl ): string {
		return rtrim( $providerUrl, '/' ) . '/kpi-id-signin.js';
	}
}
