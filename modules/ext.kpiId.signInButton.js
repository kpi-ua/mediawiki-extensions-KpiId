// kpiid-loginreqpagetext wraps MediaWiki's sign-in link in <div id="kpi_id_signin">; KPI ID's
// script replaces that element with its button. Where the script does not load, the link stays.
$( () => {
	if ( !document.getElementById( 'kpi_id_signin' ) ) {
		return;
	}
	mw.loader.getScript( mw.config.get( 'wgKpiIdSignInButtonScript' ) ).then( () => {
		if ( window.KPIID ) {
			window.KPIID.init();
		}
	} );
} );
