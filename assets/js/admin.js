/* global rcData */
( function () {
	'use strict';

	// -----------------------------------------------------------------------
	// Estado global del último reporte renderizado (para CSV export)
	// -----------------------------------------------------------------------
	let lastReport = null;

	// -----------------------------------------------------------------------
	// Init
	// -----------------------------------------------------------------------
	document.addEventListener( 'DOMContentLoaded', function () {
		const btnGenerar     = document.getElementById( 'rc-btn-generar' );
		const btnExport      = document.getElementById( 'rc-btn-export' );
		const btnClearCache  = document.getElementById( 'rc-btn-clear-cache' );

		if ( btnGenerar )    btnGenerar.addEventListener( 'click', fetchReport );
		if ( btnExport )     btnExport.addEventListener( 'click', exportCSV );
		if ( btnClearCache ) btnClearCache.addEventListener( 'click', clearCache );
	} );

	// -----------------------------------------------------------------------
	// Limpiar cache Conekta del mes
	// -----------------------------------------------------------------------
	function clearCache() {
		const month = document.getElementById( 'rc-month' ).value;
		const year  = document.getElementById( 'rc-year' ).value;
		const btn   = document.getElementById( 'rc-btn-clear-cache' );

		btn.disabled    = true;
		btn.textContent = 'Limpiando…';

		const body = new URLSearchParams( {
			action: 'rc_clear_month_cache',
			nonce:  rcData.nonce,
			month,
			year,
		} );

		fetch( rcData.ajaxUrl, { method: 'POST', body } )
			.then( r => r.json() )
			.then( data => {
				btn.disabled    = false;
				btn.innerHTML   = '<span class="dashicons dashicons-update" style="vertical-align:middle;"></span> Limpiar cache Conekta';
				const msg = data.success ? data.data.message : ( data.data?.message || 'Error.' );
				alert( msg + '\n\nAhora haz clic en "Generar Reporte" para recargar.' );
			} )
			.catch( () => {
				btn.disabled  = false;
				btn.innerHTML = '<span class="dashicons dashicons-update" style="vertical-align:middle;"></span> Limpiar cache Conekta';
			} );
	}

	// -----------------------------------------------------------------------
	// Fetch reporte via AJAX
	// -----------------------------------------------------------------------
	function fetchReport() {
		const month = document.getElementById( 'rc-month' ).value;
		const year  = document.getElementById( 'rc-year' ).value;

		setLoading( true );
		hideError();
		document.getElementById( 'rc-report' ).style.display = 'none';
		document.getElementById( 'rc-btn-export' ).style.display = 'none';

		const url = rcData.ajaxUrl
			+ '?action=rc_get_report&nonce=' + encodeURIComponent( rcData.nonce )
			+ '&month=' + encodeURIComponent( month )
			+ '&year='  + encodeURIComponent( year );

		fetch( url )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				setLoading( false );
				if ( ! data.success ) {
					showError( data.data && data.data.message ? data.data.message : 'Error desconocido.' );
					return;
				}
				lastReport = data.data;
				renderReport( data.data );
				document.getElementById( 'rc-btn-export' ).style.display = '';
			} )
			.catch( function ( err ) {
				setLoading( false );
				showError( 'Error de conexión: ' + err.message );
			} );
	}

	// -----------------------------------------------------------------------
	// Renderizar reporte
	// -----------------------------------------------------------------------
	function renderReport( data ) {
		const wrap = document.getElementById( 'rc-report' );
		let html = '';

		// Aviso si no hay API key configurada
		if ( data.no_api_key ) {
			html += '<div class="rc-notice-no-apikey">'
				+ '<strong>Aviso:</strong> No hay API Key de Conekta configurada. '
				+ 'Los pagos con tarjeta se clasificarán como <em>crédito</em> por defecto. '
				+ '<a href="admin.php?page=resumen-cobros-settings">Configurar ahora</a>.'
				+ '</div>';
		}

		// Secciones
		const sectionConfig = [
			{ key: 'credito',  label: 'Tarjeta Crédito', code: '04 CRÉDITO',  cls: 'rc-section--credito' },
			{ key: 'debito',   label: 'Tarjeta Débito',  code: '07 DÉBITO',   cls: 'rc-section--debito' },
			{ key: 'efectivo', label: 'Efectivo',         code: '07 EFECTIVO', cls: 'rc-section--efectivo' },
		];

		if ( data.sections.otros && data.sections.otros.length > 0 ) {
			sectionConfig.push( { key: 'otros', label: 'Otros métodos', code: '', cls: 'rc-section--otros' } );
		}

		for ( const cfg of sectionConfig ) {
			html += renderSection( cfg, data.sections[ cfg.key ] || [], data );
		}

		// Tabla resumen al fondo
		html += renderSummary( data );

		wrap.innerHTML = html;
		wrap.style.display = '';
	}

	// -----------------------------------------------------------------------
	// Renderizar una sección (crédito / débito / efectivo)
	// -----------------------------------------------------------------------
	function renderSection( cfg, rows, data ) {
		const isCard     = cfg.key === 'credito' || cfg.key === 'debito';
		const totals     = data.summary[ cfg.key ] || { count: 0, base: 0, iva: 0, total: 0, conekta_fee: 0, bbva_net: 0 };
		const noRows     = rows.length === 0;

		let html = '<div class="rc-section ' + escHtml( cfg.cls ) + '">';

		// Encabezado
		html += '<div class="rc-section-header">'
			+ '<h2>' + escHtml( cfg.label ) + ( cfg.code ? ' — ' + escHtml( cfg.code ) : '' ) + '</h2>'
			+ '<span class="rc-section-period">' + escHtml( data.month_label ) + '</span>'
			+ '</div>';

		if ( noRows ) {
			html += '<div class="rc-empty-section">Sin registros.</div>';
			html += '</div>';
			return html;
		}

		html += '<div class="rc-table-wrap"><table class="rc-table"><thead><tr>';

		// Cabeceras
		html += '<th>#</th>'
			+ '<th>Recibo</th>'
			+ '<th>Fecha</th>'
			+ '<th>Cliente</th>'
			+ '<th>Descripción</th>'
			+ '<th class="rc-num">Valor (sin IVA)</th>'
			+ '<th class="rc-num">IVA (16%)</th>'
			+ '<th class="rc-num">Total</th>';

		if ( isCard ) {
			html += '<th>Tipo</th>'
				+ '<th>Tarjeta</th>'
				+ '<th class="rc-num">Comis. Conekta</th>'
				+ '<th class="rc-num">Neto BBVA</th>';
		} else {
			html += '<th>Estado</th>';
		}

		html += '</tr></thead><tbody>';

		// Filas
		rows.forEach( function ( row, i ) {
			html += '<tr>';
			html += '<td>' + ( i + 1 ) + '</td>';
			html += '<td><a href="post.php?post=' + row.id + '&action=edit" target="_blank">'
				+ escHtml( '\'' + row.number ) + '</a></td>';
			html += '<td>' + escHtml( row.date ) + '</td>';
			html += '<td>' + escHtml( row.customer ) + '</td>';
			html += '<td>' + escHtml( row.description ) + '</td>';
			html += '<td class="rc-num">' + fmt( row.base ) + '</td>';
			html += '<td class="rc-num">' + fmt( row.iva ) + '</td>';
			html += '<td class="rc-num">' + fmt( row.total ) + '</td>';

			if ( isCard ) {
				const badgeCls = row.card_type === 'debito' ? 'rc-badge--debito' : ( row.card_type === 'credito' ? 'rc-badge--credito' : 'rc-badge--unknown' );
				html += '<td><span class="rc-badge ' + badgeCls + '">' + escHtml( row.card_type || '?' ) + '</span></td>';
				html += '<td>' + cardInfo( row ) + '</td>';
				const feeLabel  = row.conekta_fee !== null ? fmt( row.conekta_fee ) : '—';
			const bbvaLabel = row.bbva_net    !== null ? fmt( row.bbva_net )    : '—';
			html += '<td class="rc-num">' + feeLabel  + '</td>';
			html += '<td class="rc-num">' + bbvaLabel + '</td>';
			} else {
				html += '<td><span class="rc-badge rc-badge--efectivo">Efectivo</span></td>';
			}

			html += '</tr>';
		} );

		// Fila subtotal
		html += '<tr class="rc-row-subtotal">';
		html += '<td colspan="5"><strong>TOTAL ' + escHtml( cfg.label.toUpperCase() ) + '</strong></td>';
		html += '<td class="rc-num"><strong>' + fmt( totals.base ) + '</strong></td>';
		html += '<td class="rc-num"><strong>' + fmt( totals.iva ) + '</strong></td>';
		html += '<td class="rc-num"><strong>' + fmt( totals.total ) + '</strong></td>';

		if ( isCard ) {
			html += '<td colspan="2"></td>';
			html += '<td class="rc-num"><strong>' + fmt( totals.conekta_fee ) + '</strong></td>';
			html += '<td class="rc-num"><strong>' + fmt( totals.bbva_net ) + '</strong></td>';
		} else {
			html += '<td></td>';
		}

		html += '</tr>';
		html += '</tbody></table></div>';
		html += '</div>';

		return html;
	}

	// -----------------------------------------------------------------------
	// Tabla resumen
	// -----------------------------------------------------------------------
	function renderSummary( data ) {
		const s   = data.summary;
		const eff = s.efectivo || { total: 0 };
		const deb = s.debito   || { total: 0, bbva_net: 0 };
		const cre = s.credito  || { total: 0, bbva_net: 0 };
		const g   = s.grand    || { base: 0, iva: 0, total: 0 };

		// Comparación: total - (efectivo + débito_neto + crédito_neto)
		// En la práctica debería ser 0 si todo coincide
		const totalIngresos = eff.total + deb.total + cre.total;
		const comparacion   = round2( totalIngresos - g.total );

		let html = '<div class="rc-summary">';
		html += '<div class="rc-summary-title">Resumen del Mes — ' + escHtml( data.month_label ) + '</div>';
		html += '<table>';

		html += summaryRow(
			'<span class="rc-letter rc-letter--a">A</span> Efectivo depositado',
			fmt( eff.total ),
			''
		);
		html += summaryRow(
			'<span class="rc-letter rc-letter--b">B</span> Tarjeta de débito',
			fmt( deb.total ),
			''
		);
		html += summaryRow(
			'<span class="rc-letter rc-letter--c">C</span> Tarjeta de crédito',
			fmt( cre.total ),
			''
		);

		html += '<tr class="rc-summary-row--total">'
			+ '<td class="rc-summary-label"><strong>Total de ingresos (A+B+C)</strong></td>'
			+ '<td><strong>' + fmt( totalIngresos ) + '</strong></td>'
			+ '</tr>';

		html += '<tr class="rc-summary-row--sin-iva">'
			+ '<td class="rc-summary-label">Ingreso sin IVA</td>'
			+ '<td>' + fmt( g.base ) + '</td>'
			+ '</tr>';

		html += '<tr class="rc-summary-row--iva">'
			+ '<td class="rc-summary-label">IVA (' + ( ( data.iva_rate || 0.16 ) * 100 ).toFixed(0) + '%)</td>'
			+ '<td>' + fmt( g.iva ) + '</td>'
			+ '</tr>';

		html += '<tr class="rc-summary-row--con-iva">'
			+ '<td class="rc-summary-label"><strong>Total con IVA</strong></td>'
			+ '<td><strong>' + fmt( g.total ) + '</strong></td>'
			+ '</tr>';

		html += '<tr class="rc-summary-row--comparacion">'
			+ '<td class="rc-summary-label">Comparación</td>'
			+ '<td>' + fmt( comparacion ) + '</td>'
			+ '</tr>';

		html += '</table></div>';

		return html;
	}

	function summaryRow( label, value ) {
		return '<tr><td class="rc-summary-label">' + label + '</td><td>' + value + '</td></tr>';
	}

	// -----------------------------------------------------------------------
	// Exportar CSV
	// -----------------------------------------------------------------------
	function exportCSV() {
		if ( ! lastReport ) return;

		const data = lastReport;
		const rows = [];

		// Cabecera
		rows.push( [ 'Sección', 'Pedido', 'Fecha', 'Cliente', 'Descripción',
			'Base (sin IVA)', 'IVA', 'Total', 'Tipo tarjeta', 'Marca', 'Últimos 4',
			'Comisión Conekta', 'Neto BBVA' ] );

		const sections = [
			{ key: 'credito',  label: 'Tarjeta Crédito' },
			{ key: 'debito',   label: 'Tarjeta Débito' },
			{ key: 'efectivo', label: 'Efectivo' },
			{ key: 'otros',    label: 'Otros' },
		];

		for ( const sec of sections ) {
			const sRows = data.sections[ sec.key ] || [];
			for ( const row of sRows ) {
				rows.push( [
					sec.label,
					'\'' + row.number,
					row.date,
					row.customer,
					row.description,
					row.base,
					row.iva,
					row.total,
					row.card_type || '',
					row.brand     || '',
					row.last4     || '',
					row.conekta_fee || '',
					row.bbva_net    || '',
				] );
			}

			// Subtotal de sección
			const t = data.summary[ sec.key ];
			if ( t && t.count > 0 ) {
				rows.push( [
					'TOTAL ' + sec.label.toUpperCase(), '', '', '', '',
					t.base, t.iva, t.total, '', '', '',
					t.conekta_fee || '', t.bbva_net || '',
				] );
				rows.push( [] );
			}
		}

		// Resumen
		const g   = data.summary.grand   || {};
		const eff = data.summary.efectivo || { total: 0 };
		const deb = data.summary.debito   || { total: 0 };
		const cre = data.summary.credito  || { total: 0 };

		rows.push( [] );
		rows.push( [ 'A - Efectivo depositado', '', '', '', '', '', '', eff.total ] );
		rows.push( [ 'B - Tarjeta de débito',   '', '', '', '', '', '', deb.total ] );
		rows.push( [ 'C - Tarjeta de crédito',  '', '', '', '', '', '', cre.total ] );
		rows.push( [ 'Total de ingresos',        '', '', '', '', '', '', eff.total + deb.total + cre.total ] );
		rows.push( [ 'Ingreso sin IVA',          '', '', '', '', '', '', g.base ] );
		rows.push( [ 'IVA',                      '', '', '', '', '', '', g.iva ] );
		rows.push( [ 'Total con IVA',            '', '', '', '', '', '', g.total ] );
		rows.push( [ 'Comparación',              '', '', '', '', '', '', 0 ] );

		const csvContent = rows.map( function ( r ) {
			return r.map( function ( cell ) {
				const s = String( cell === null || cell === undefined ? '' : cell );
				return s.includes( ',' ) || s.includes( '"' ) || s.includes( '\n' )
					? '"' + s.replace( /"/g, '""' ) + '"'
					: s;
			} ).join( ',' );
		} ).join( '\r\n' );

		const filename = 'resumen-cobros-' + data.month_label.replace( /\s/g, '-' ).toLowerCase() + '.csv';
		const blob = new Blob( [ '\uFEFF' + csvContent ], { type: 'text/csv;charset=utf-8;' } );
		const url  = URL.createObjectURL( blob );
		const a    = document.createElement( 'a' );
		a.href     = url;
		a.download = filename;
		a.click();
		URL.revokeObjectURL( url );
	}

	// -----------------------------------------------------------------------
	// Utilidades
	// -----------------------------------------------------------------------

	function setLoading( show ) {
		document.getElementById( 'rc-loading' ).style.display = show ? 'flex' : 'none';
	}

	function showError( msg ) {
		const el = document.getElementById( 'rc-error' );
		el.textContent = msg;
		el.style.display = '';
	}

	function hideError() {
		document.getElementById( 'rc-error' ).style.display = 'none';
	}

	function escHtml( str ) {
		return String( str )
			.replace( /&/g,  '&amp;' )
			.replace( /</g,  '&lt;' )
			.replace( />/g,  '&gt;' )
			.replace( /"/g,  '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function fmt( value ) {
		const n   = parseFloat( value ) || 0;
		const sym = ( rcData.currencySymbol || '$' );
		return sym + n.toLocaleString( 'es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	}

	function round2( n ) {
		return Math.round( ( parseFloat( n ) || 0 ) * 100 ) / 100;
	}

	function cardInfo( row ) {
		if ( ! row.brand ) return '—';
		const iconMap = {
			visa:               'visa-24px.svg',
			mastercard:         'master-card-24px.svg',
			amex:               'amex-24px.svg',
			'american express': 'amex-24px.svg',
			american_express:   'amex-24px.svg',
		};
		const brand = row.brand.toLowerCase();
		const icon  = iconMap[ brand ];
		if ( icon ) {
			return '<img src="' + rcData.pluginUrl + 'assets/img/' + icon + '" alt="' + escHtml( row.brand ) + '" style="height:24px;vertical-align:middle;">';
		}
		// Fallback texto para marcas sin icono (carnet, etc.)
		return '<span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;background:#f3f4f6;color:#555;">' + escHtml( row.brand.toUpperCase() ) + '</span>';
	}

	// -----------------------------------------------------------------------
	// Diagnóstico de pedido (solo en página de configuración)
	// -----------------------------------------------------------------------
	document.addEventListener( 'DOMContentLoaded', function () {
		const btn    = document.getElementById( 'rc-diag-btn' );
		const input  = document.getElementById( 'rc-diag-order' );
		const result = document.getElementById( 'rc-diag-result' );
		if ( ! btn ) return;

		btn.addEventListener( 'click', function () {
			const orderId = input.value.trim();
			if ( ! orderId ) return;

			btn.disabled    = true;
			btn.textContent = 'Consultando…';
			result.style.display = 'none';

			const body = new URLSearchParams( {
				action:   'rc_diagnose_order',
				nonce:    rcData.nonce,
				order_id: orderId,
			} );

			fetch( rcData.ajaxUrl, { method: 'POST', body } )
				.then( r => r.json() )
				.then( data => {
					btn.disabled    = false;
					btn.textContent = 'Diagnosticar';
					result.textContent   = JSON.stringify( data.success ? data.data : data, null, 2 );
					result.style.display = '';
				} )
				.catch( err => {
					btn.disabled    = false;
					btn.textContent = 'Diagnosticar';
					result.textContent   = 'Error: ' + err.message;
					result.style.display = '';
				} );
		} );

		// Enter key
		input.addEventListener( 'keydown', e => { if ( e.key === 'Enter' ) btn.click(); } );
	} );

} )();
