<?php
/**
 * Give an account the EMPLOYEE_ID that lets its owner sign in through KPI ID, or take it away:
 *   php maintenance/run.php KpiId:assign --user 'Name' --employee-id 12345
 *   php maintenance/run.php KpiId:assign --user 'Name' --remove
 *   php maintenance/run.php KpiId:assign --list
 */

namespace MediaWiki\Extension\KpiId\Maintenance;

use MediaWiki\Extension\KpiId\Accounts;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\User\User;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

class Assign extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'KpiId' );
		$this->addDescription( 'Give an account the EMPLOYEE_ID that lets its owner sign in through KPI ID' );
		$this->addOption( 'user', 'Account name', false, true );
		$this->addOption( 'employee-id', 'EMPLOYEE_ID of the account owner in KPI ID', false, true );
		$this->addOption( 'remove', 'Take the EMPLOYEE_ID away: the account can no longer sign in through KPI ID' );
		$this->addOption( 'list', 'List every account with an EMPLOYEE_ID' );
	}

	/** @inheritDoc */
	public function execute() {
		$accounts = Accounts::fromServices();
		if ( $this->hasOption( 'list' ) ) {
			foreach ( $accounts->linked() as $row ) {
				$this->output( "{$row->up_value}\t{$row->user_name}\n" );
			}
			return true;
		}

		$user = $this->getServiceContainer()->getUserFactory()
			->newFromName( (string)$this->getOption( 'user', '' ) );
		if ( !$user || !$user->isRegistered() ) {
			$this->fatalError( 'No such account: ' . $this->getOption( 'user' ) );
		}
		$performer = new UltimateAuthority( User::newSystemUser( User::MAINTENANCE_SCRIPT_USER, [ 'steal' => true ] ) );

		if ( $this->hasOption( 'remove' ) ) {
			$status = $accounts->unlink( $user, $performer );
			if ( !$status->isGood() ) {
				$this->fatalError( $status );
			}
			$this->output( "Removed the EMPLOYEE_ID from {$user->getName()}\n" );
			return true;
		}

		$employeeId = (string)$this->getOption( 'employee-id', '' );
		if ( trim( $employeeId ) === '' ) {
			$this->fatalError( 'Pass --employee-id, --remove or --list' );
		}
		$status = $accounts->link( $user, $employeeId, $performer );
		if ( !$status->isGood() ) {
			$this->fatalError( $status );
		}
		$this->output( "{$user->getName()} now signs in with EMPLOYEE_ID " . trim( $employeeId ) . "\n" );
		return true;
	}
}

// @codeCoverageIgnoreStart
$maintClass = Assign::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
