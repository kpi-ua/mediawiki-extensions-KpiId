<?php

namespace MediaWiki\Extension\KpiId;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Permissions\Authority;
use MediaWiki\Title\Title;
use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserRigorOptions;
use StatusValue;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IResultWrapper;

/**
 * Accounts and their EMPLOYEE_IDs, for Special:KpiIdAccounts and the assign script. Every change
 * goes to the kpiid log, which only holders of the kpiid-manage right can read.
 */
class Accounts {

	public const RIGHT = 'kpiid-manage';
	public const LOG = 'kpiid';

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly UserFactory $userFactory,
		private readonly UserOptionsManager $userOptions,
		private readonly AuthManager $authManager,
		private readonly Matcher $matcher
	) {
	}

	public static function fromServices(): self {
		$services = MediaWikiServices::getInstance();
		return new self(
			$services->getConnectionProvider(),
			$services->getUserFactory(),
			$services->getUserOptionsManager(),
			$services->getAuthManager(),
			Matcher::fromServices()
		);
	}

	/**
	 * @return IResultWrapper rows with user_id, user_name, user_real_name, user_email and the
	 *   EMPLOYEE_ID as up_value, by account name
	 */
	public function linked(): IResultWrapper {
		return $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( [ 'user_id', 'user_name', 'user_real_name', 'user_email', 'up_value' ] )
			->from( 'user_properties' )
			->join( 'user', null, 'user_id = up_user' )
			->where( [ 'up_property' => Matcher::PROPERTY ] )
			->orderBy( 'user_name' )
			->caller( __METHOD__ )
			->fetchResultSet();
	}

	/**
	 * Creates an account without a password, its owner signs in through KPI ID, and links it.
	 *
	 * @param string $employeeId as typed
	 * @param string $name account name as typed
	 * @param string $realName optional
	 * @param string $email optional; taken as confirmed, since an administrator typed it
	 * @param Authority $performer
	 * @return StatusValue good with the new User as value
	 */
	public function create(
		string $employeeId, string $name, string $realName, string $email, Authority $performer
	): StatusValue {
		$employeeId = EmployeeId::normalize( $employeeId );
		if ( $employeeId === null ) {
			return StatusValue::newFatal( 'kpiidaccounts-error-employeeid' );
		}
		$user = $this->userFactory->newFromName( $name, UserRigorOptions::RIGOR_CREATABLE );
		if ( !$user ) {
			return StatusValue::newFatal( 'kpiidaccounts-error-username', $name );
		}
		if ( $user->isRegistered() ) {
			return StatusValue::newFatal( 'kpiidaccounts-error-username-exists', $user->getName() );
		}
		$email = trim( $email );
		if ( $email !== '' && !Sanitizer::validateEmail( $email ) ) {
			return StatusValue::newFatal( 'kpiidaccounts-error-email', $email );
		}
		$taken = $this->checkFree( $employeeId, null );
		if ( !$taken->isOK() ) {
			return $taken;
		}

		$status = $this->authManager->autoCreateUser(
			$user, AuthManager::AUTOCREATE_SOURCE_MAINT, false, true, $performer
		);
		if ( !$status->isGood() ) {
			return $status;
		}
		$realName = trim( $realName );
		if ( $realName !== '' || $email !== '' ) {
			$user->setRealName( $realName );
			if ( $email !== '' ) {
				$user->setEmail( $email );
				$user->setEmailAuthenticationTimestamp( wfTimestampNow() );
			}
			$user->saveSettings();
		}
		$this->writeProperty( $user, $employeeId );
		$this->log( 'create', $user, $employeeId, $performer );
		return StatusValue::newGood( $user );
	}

	/**
	 * Links an existing account that has no EMPLOYEE_ID yet.
	 *
	 * @param User $user
	 * @param string $employeeId as typed
	 * @param Authority $performer
	 * @return StatusValue
	 */
	public function link( User $user, string $employeeId, Authority $performer ): StatusValue {
		$employeeId = EmployeeId::normalize( $employeeId );
		if ( $employeeId === null ) {
			return StatusValue::newFatal( 'kpiidaccounts-error-employeeid' );
		}
		$current = $this->userOptions->getOption( $user, Matcher::PROPERTY );
		if ( $current !== null && $current !== '' && $current !== $employeeId ) {
			return StatusValue::newFatal( 'kpiidaccounts-error-linked', $user->getName(), $current );
		}
		$taken = $this->checkFree( $employeeId, $user->getId() );
		if ( !$taken->isOK() ) {
			return $taken;
		}
		$this->writeProperty( $user, $employeeId );
		$this->log( 'link', $user, $employeeId, $performer );
		return StatusValue::newGood();
	}

	/**
	 * Takes the EMPLOYEE_ID away: the account can no longer sign in through KPI ID.
	 *
	 * @param User $user
	 * @param Authority $performer
	 * @return StatusValue
	 */
	public function unlink( User $user, Authority $performer ): StatusValue {
		$current = $this->userOptions->getOption( $user, Matcher::PROPERTY );
		if ( $current === null || $current === '' ) {
			return StatusValue::newFatal( 'kpiidaccounts-error-not-linked', $user->getName() );
		}
		$this->writeProperty( $user, null );
		$this->log( 'unlink', $user, (string)$current, $performer );
		return StatusValue::newGood();
	}

	private function checkFree( string $employeeId, ?int $userId ): StatusValue {
		$holders = array_diff( $this->matcher->userIdsFor( [ $employeeId ] ), [ $userId ] );
		if ( !$holders ) {
			return StatusValue::newGood();
		}
		$holder = $this->userFactory->newFromId( reset( $holders ) );
		return StatusValue::newFatal( 'kpiidaccounts-error-employeeid-taken', $employeeId, $holder->getName() );
	}

	private function writeProperty( User $user, ?string $employeeId ): void {
		Hooks::$allowPropertyWrite = true;
		try {
			$this->userOptions->setOption( $user, Matcher::PROPERTY, $employeeId );
			$this->userOptions->saveOptions( $user );
		} finally {
			Hooks::$allowPropertyWrite = false;
		}
	}

	private function log( string $subtype, User $user, string $employeeId, Authority $performer ): void {
		$entry = new ManualLogEntry( self::LOG, $subtype );
		$entry->setPerformer( $performer->getUser() );
		$entry->setTarget( Title::makeTitle( NS_USER, $user->getName() ) );
		$entry->setParameters( [ '4::employeeid' => $employeeId ] );
		$entry->insert();
	}
}
