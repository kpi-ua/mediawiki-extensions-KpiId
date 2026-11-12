<?php

namespace MediaWiki\Extension\KpiId;

use MediaWiki\Extension\PluggableAuth\Hook\PluggableAuthUserAuthorization;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\GetFullURLHook;
use MediaWiki\Hook\TestCanonicalRedirectHook;
use MediaWiki\Language\Hook\MessageCacheFetchOverridesHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\Options\Hook\SaveUserOptionsHook;
use MediaWiki\User\UserIdentity;

class Hooks implements
	PluggableAuthUserAuthorization,
	SaveUserOptionsHook,
	GetFullURLHook,
	TestCanonicalRedirectHook,
	MessageCacheFetchOverridesHook,
	BeforePageDisplayHook
{

	public const CONFIG_ID = 'KPI ID';
	public const DEFAULT_PROVIDER_URL = 'https://auth.kpi.ua';

	/** Set while Accounts writes the EMPLOYEE_ID; every other save keeps the stored one. */
	public static bool $allowPropertyWrite = false;

	/**
	 * Adds KPI ID to PluggableAuth once the application id and secret are known, so a wiki
	 * needs no settings file for it. OpenIDConnect finds the account for a first sign-in by the
	 * preferred username; ours is the account that holds the EMPLOYEE_ID, never one derived from
	 * the name or the email.
	 */
	public static function onRegistration(): void {
		$clientId = $GLOBALS['wgKpiIdClientId'] ?? self::env( 'KPIID_CLIENT_ID' );
		$clientSecret = $GLOBALS['wgKpiIdClientSecret'] ?? self::env( 'KPIID_CLIENT_SECRET' );
		if ( $clientId === null || $clientSecret === null ) {
			return;
		}
		$GLOBALS['wgPluggableAuth_Config'][self::CONFIG_ID] = [
			'plugin' => 'OpenIDConnect',
			'buttonLabelMessage' => 'kpiid-login-button',
			'data' => [
				'providerURL' => $GLOBALS['wgKpiIdProviderUrl']
					?? self::env( 'KPIID_PROVIDER_URL' )
					?? self::DEFAULT_PROVIDER_URL,
				'clientID' => $clientId,
				'clientsecret' => $clientSecret,
				'scope' => [ 'openid', 'profile', Matcher::CLAIM ],
				'codeChallengeMethod' => 'S256',
			],
		];
		$GLOBALS['wgOpenIDConnect_MigrateUsersByUserName'] = true;
		$GLOBALS['wgOpenIDConnect_MigrateUsersByEmail'] = false;
		$GLOBALS['wgOpenIDConnect_PreferredUsernameProcessor'] = [ self::class, 'preferredUsername' ];
	}

	private static function env( string $name ): ?string {
		$value = getenv( $name );
		return is_string( $value ) && $value !== '' ? $value : null;
	}

	/**
	 * @param string|null $preferredUsername what OpenIDConnect derived from the claims (ignored)
	 * @param array $attributes ID token claims
	 * @return string|null name of the matching account; null lets no account be picked
	 */
	public static function preferredUsername( ?string $preferredUsername, array $attributes ): ?string {
		$id = Matcher::fromServices()->findUserId( $attributes );
		if ( $id === null ) {
			return null;
		}
		return MediaWikiServices::getInstance()->getUserIdentityLookup()
			->getUserIdentityByUserId( $id )?->getName();
	}

	/**
	 * Every sign-in, not only the first: the account must be the one that holds the EMPLOYEE_ID
	 * now, so removing it from an account takes the access away. A sign-in that would create a
	 * new account (id 0) is refused: accounts are created by an administrator.
	 *
	 * @inheritDoc
	 */
	public function onPluggableAuthUserAuthorization( UserIdentity $user, bool &$authorized ): bool {
		if ( !$authorized ) {
			return true;
		}
		$plugin = MediaWikiServices::getInstance()->get( 'PluggableAuthFactory' )->getInstance();
		$attributes = $plugin ? $plugin->getAttributes( $user ) : [];
		$matchedId = Matcher::fromServices()->findUserId( $attributes );
		$authorized = $matchedId !== null && $user->getId() === $matchedId;
		if ( !$authorized && $matchedId !== null ) {
			LoggerFactory::getInstance( 'KpiId' )->warning(
				'Sign-in as {user} refused: the EMPLOYEE_ID belongs to account #{matched}',
				[ 'user' => $user->getName(), 'matched' => $matchedId ]
			);
		}
		return true;
	}

	/**
	 * PluggableAuth's refusal says only that the user is not authorised; ours says what to do. The
	 * "login required" page gets the KPI ID button, while KPI ID is configured.
	 *
	 * @inheritDoc
	 */
	public function onMessageCacheFetchOverrides( array &$keys ): void {
		$keys['pluggableauth-not-authorized'] = 'kpiid-not-authorized';
		if ( self::providerUrl() !== null ) {
			$keys['loginreqpagetext'] = 'kpiid-loginreqpagetext';
		}
	}

	/**
	 * Loads the button script for readers who are not signed in; it acts only on the page that
	 * carries the button's element (kpiid-loginreqpagetext).
	 *
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$providerUrl = self::providerUrl();
		if ( $providerUrl === null || $out->getUser()->isRegistered() ) {
			return;
		}
		$out->addJsConfigVars( SignInButton::SCRIPT_VAR, SignInButton::scriptUrl( $providerUrl ) );
		$out->addModules( SignInButton::MODULE );
	}

	/** @return string|null the KPI ID issuer, or null while KPI ID is not configured */
	private static function providerUrl(): ?string {
		return $GLOBALS['wgPluggableAuth_Config'][self::CONFIG_ID]['data']['providerURL'] ?? null;
	}

	/**
	 * Only Accounts (the special page, the assign script) changes the EMPLOYEE_ID. The options API
	 * already refuses unknown keys, but "Restore all default settings" in the preferences would
	 * delete it and lock the person out; this keeps it whatever path the save comes from.
	 *
	 * @inheritDoc
	 */
	public function onSaveUserOptions( UserIdentity $user, array &$modifiedOptions, array $originalOptions ) {
		if ( !self::$allowPropertyWrite ) {
			$modifiedOptions = Matcher::keepProperty( $modifiedOptions, $originalOptions );
		}
		return true;
	}

	/**
	 * OpenIDConnect sends Special:PluggableAuthLogin's full URL as the redirect URI, and on a wiki
	 * in another language that URL carries the localised namespace (Спеціальна:…), which KPI ID
	 * refuses in any encoding. Send the canonical English form, which MediaWiki serves just the same.
	 *
	 * @inheritDoc
	 */
	public function onGetFullURL( $title, &$url, $query ) {
		if ( !$title->isSpecial( 'PluggableAuthLogin' ) ) {
			return true;
		}
		$services = MediaWikiServices::getInstance();
		$local = str_replace( '$1', 'Special:PluggableAuthLogin', $services->getMainConfig()->get( 'ArticlePath' ) );
		$url = $services->getUrlUtils()->expand( wfAppendQuery( $local, $query ), PROTO_CANONICAL ) ?? $url;
		return true;
	}

	/**
	 * MediaWiki redirects Special:X to its localised name, which onGetFullURL turns back into
	 * Special:X: a loop. Serve the English name as is for this page.
	 *
	 * @inheritDoc
	 */
	public function onTestCanonicalRedirect( $request, $title, $output ) {
		return !$title->isSpecial( 'PluggableAuthLogin' );
	}
}
