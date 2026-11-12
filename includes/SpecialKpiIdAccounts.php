<?php

namespace MediaWiki\Extension\KpiId;

use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use OOUI\ButtonInputWidget;
use StatusValue;

/**
 * Special:KpiIdAccounts: the accounts that sign in through KPI ID, a form that creates an account
 * or links an existing one, and removing a link. Needs the kpiid-manage right.
 */
class SpecialKpiIdAccounts extends SpecialPage {

	private const UNLINK_SALT = 'kpiid-unlink';

	public function __construct( private readonly UserFactory $userFactory ) {
		parent::__construct( 'KpiIdAccounts', Accounts::RIGHT );
	}

	/** @inheritDoc */
	public function doesWrites() {
		return true;
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'users';
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->checkPermissions();
		$this->outputHeader();
		$out = $this->getOutput();
		$out->enableOOUI();
		$request = $this->getRequest();
		$accounts = Accounts::fromServices();

		if ( $request->wasPosted() && $request->getVal( 'kpiid-do' ) === 'unlink' ) {
			$this->checkReadOnly();
			$status = $this->unlink( $accounts );
			if ( $status->isGood() ) {
				$out->redirect( $this->getPageTitle()->getFullURL( [ 'unlinked' => $status->getValue() ] ) );
				return;
			}
			$out->addHTML( Html::errorBox( $this->msg( $status->getMessages()[0] )->parse() ) );
		}

		foreach ( [ 'created', 'linked', 'unlinked' ] as $done ) {
			$name = $request->getVal( $done );
			if ( $name !== null && $name !== '' ) {
				$out->addHTML( Html::successBox( $this->msg( "kpiidaccounts-$done", $name )->parse() ) );
			}
		}

		$form = HTMLForm::factory( 'ooui', $this->formFields(), $this->getContext() );
		$form->setWrapperLegendMsg( 'kpiidaccounts-legend' );
		$form->setSubmitTextMsg( 'kpiidaccounts-submit' );
		$form->setSubmitCallback( fn ( array $data ) => $this->submit( $accounts, $data ) );
		$form->show();

		$this->showList( $accounts );
	}

	private function formFields(): array {
		return [
			'employeeid' => [
				'type' => 'text',
				'label-message' => 'kpiidaccounts-employeeid',
				'required' => true,
			],
			'username' => [
				'type' => 'text',
				'label-message' => 'kpiidaccounts-username',
				'help-message' => 'kpiidaccounts-username-help',
				'required' => true,
			],
			'realname' => [
				'type' => 'text',
				'label-message' => 'kpiidaccounts-realname',
			],
			'email' => [
				'type' => 'email',
				'label-message' => 'kpiidaccounts-email',
			],
			'existing' => [
				'type' => 'check',
				'label-message' => 'kpiidaccounts-existing',
				'help-message' => 'kpiidaccounts-existing-help',
			],
		];
	}

	/**
	 * @param Accounts $accounts
	 * @param array $data the form's values
	 * @return StatusValue|bool true after a redirect to the result
	 */
	private function submit( Accounts $accounts, array $data ) {
		$this->checkReadOnly();
		if ( $data['existing'] ) {
			$user = $this->userFactory->newFromName( $data['username'] );
			if ( !$user || !$user->isRegistered() ) {
				return StatusValue::newFatal( 'kpiidaccounts-error-no-such-user', $data['username'] );
			}
			$status = $accounts->link( $user, $data['employeeid'], $this->getAuthority() );
			$done = 'linked';
		} else {
			$status = $accounts->create(
				$data['employeeid'],
				$data['username'],
				(string)$data['realname'],
				(string)$data['email'],
				$this->getAuthority()
			);
			$user = $status->getValue();
			$done = 'created';
		}
		if ( !$status->isGood() ) {
			return $status;
		}
		$this->getOutput()->redirect( $this->getPageTitle()->getFullURL( [ $done => $user->getName() ] ) );
		return true;
	}

	/**
	 * @param Accounts $accounts
	 * @return StatusValue good with the account name as value
	 */
	private function unlink( Accounts $accounts ): StatusValue {
		$tokens = $this->getContext()->getCsrfTokenSet();
		if ( !$tokens->matchTokenField( 'wpEditToken', self::UNLINK_SALT ) ) {
			return StatusValue::newFatal( 'sessionfailure' );
		}
		$user = $this->userFactory->newFromId( $this->getRequest()->getInt( 'user' ) );
		if ( !$user->isRegistered() ) {
			return StatusValue::newFatal(
				'kpiidaccounts-error-no-such-user', '#' . $this->getRequest()->getInt( 'user' )
			);
		}
		if ( $user->getId() === $this->getUser()->getId() ) {
			return StatusValue::newFatal( 'kpiidaccounts-error-own-account' );
		}
		$status = $accounts->unlink( $user, $this->getAuthority() );
		return $status->isGood() ? StatusValue::newGood( $user->getName() ) : $status;
	}

	private function showList( Accounts $accounts ): void {
		$out = $this->getOutput();
		$rows = $accounts->linked();
		$out->addHTML( Html::element( 'h2', [], $this->msg( 'kpiidaccounts-list' )->text() ) );
		if ( !$rows->numRows() ) {
			$out->addWikiMsg( 'kpiidaccounts-list-empty' );
			return;
		}
		$out->addWikiMsg( 'kpiidaccounts-list-count', $this->getLanguage()->formatNum( $rows->numRows() ) );
		$out->addModules( 'jquery.tablesorter' );
		$out->addModuleStyles( 'jquery.tablesorter.styles' );

		$header = '';
		foreach ( [ 'username', 'employeeid', 'realname', 'email' ] as $column ) {
			$header .= Html::element( 'th', [], $this->msg( "kpiidaccounts-$column" )->text() );
		}
		$header .= Html::element( 'th', [ 'class' => 'unsortable' ] );

		$linkRenderer = $this->getLinkRenderer();
		$body = '';
		foreach ( $rows as $row ) {
			$user = User::newFromRow( $row );
			$cells = Html::rawElement( 'td', [], $linkRenderer->makeLink( $user->getUserPage(), $user->getName() ) )
				. Html::element( 'td', [], $row->up_value )
				. Html::element( 'td', [], $row->user_real_name )
				. Html::element( 'td', [], $row->user_email )
				. Html::rawElement( 'td', [], $this->unlinkButton( (int)$row->user_id ) );
			$body .= Html::rawElement( 'tr', [], $cells );
		}
		$out->addHTML( Html::rawElement( 'table', [ 'class' => 'wikitable sortable' ],
			Html::rawElement( 'thead', [], Html::rawElement( 'tr', [], $header ) )
			. Html::rawElement( 'tbody', [], $body )
		) );
	}

	private function unlinkButton( int $userId ): string {
		if ( $userId === $this->getUser()->getId() ) {
			return '';
		}
		$tokens = $this->getContext()->getCsrfTokenSet();
		return Html::rawElement( 'form',
			[ 'method' => 'post', 'action' => $this->getPageTitle()->getLocalURL() ],
			Html::hidden( 'kpiid-do', 'unlink' )
			. Html::hidden( 'user', (string)$userId )
			. Html::hidden( 'wpEditToken', $tokens->getToken( self::UNLINK_SALT )->toString() )
			. new ButtonInputWidget( [
				'type' => 'submit',
				'label' => $this->msg( 'kpiidaccounts-unlink' )->text(),
				'flags' => [ 'destructive' ],
				'framed' => false,
			] )
		);
	}
}
