<?php
/**
 * Give an account the EMPLOYEE_ID that lets its owner sign in through KPI ID, or take it away:
 *   php maintenance/run.php KpiId:assign --user 'Name' --employee-id 12345
 *   php maintenance/run.php KpiId:assign --user 'Name' --remove
 *   php maintenance/run.php KpiId:assign --list
 */

namespace MediaWiki\Extension\KpiId\Maintenance;

use MediaWiki\Extension\KpiId\Hooks;
use MediaWiki\Extension\KpiId\Matcher;
use MediaWiki\Maintenance\Maintenance;

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
		if ( $this->hasOption( 'list' ) ) {
			$rows = $this->getPrimaryDB()->newSelectQueryBuilder()
				->select( [ 'user_name', 'up_value' ] )
				->from( 'user_properties' )
				->join( 'user', null, 'user_id = up_user' )
				->where( [ 'up_property' => Matcher::PROPERTY ] )
				->orderBy( 'user_name' )
				->caller( __METHOD__ )->fetchResultSet();
			foreach ( $rows as $row ) {
				$this->output( "{$row->up_value}\t{$row->user_name}\n" );
			}
			return true;
		}

		$services = $this->getServiceContainer();
		$user = $services->getUserFactory()->newFromName( (string)$this->getOption( 'user', '' ) );
		if ( !$user || !$user->isRegistered() ) {
			$this->fatalError( 'No such account: ' . $this->getOption( 'user' ) );
		}
		$options = $services->getUserOptionsManager();
		Hooks::$allowPropertyWrite = true;

		if ( $this->hasOption( 'remove' ) ) {
			$options->setOption( $user, Matcher::PROPERTY, null );
			$options->saveOptions( $user );
			$this->output( "Removed the EMPLOYEE_ID from {$user->getName()}\n" );
			return true;
		}

		$employeeId = trim( (string)$this->getOption( 'employee-id', '' ) );
		if ( $employeeId === '' ) {
			$this->fatalError( 'Pass --employee-id, --remove or --list' );
		}
		$holders = array_diff(
			Matcher::fromServices()->userIdsFor( [ $employeeId ] ),
			[ $user->getId() ]
		);
		if ( $holders ) {
			$this->fatalError( "EMPLOYEE_ID $employeeId already belongs to account #" . implode( ', #', $holders ) );
		}
		$options->setOption( $user, Matcher::PROPERTY, $employeeId );
		$options->saveOptions( $user );
		$this->output( "{$user->getName()} now signs in with EMPLOYEE_ID $employeeId\n" );
		return true;
	}
}

// @codeCoverageIgnoreStart
$maintClass = Assign::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
