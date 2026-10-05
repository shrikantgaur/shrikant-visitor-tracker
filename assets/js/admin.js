/**
 * SK Visitor Tracker — Admin Dashboard JS
 *
 * Responsibilities:
 *  1. Poll the /sk-vt/v1/online REST endpoint every 30 s and update
 *     the "currently online" counter without a page reload.
 *  2. Render doughnut charts for Devices, Browsers, Traffic Sources.
 *  3. Render the hourly bar chart for today's distribution.
 *
 * Dependencies: Chart.js 4.x (enqueued by SK_VT_Admin), WP REST API.
 * No jQuery needed.
 */
/* global skVtAdmin, Chart */
( function () {
	'use strict';

	// Data passed from PHP via wp_localize_script().
	const cfg = window.skVtAdmin || {};

	// ── Online counter polling ──────────────────────────────────────────────
	function updateOnlineCount() {
		if ( ! cfg.restUrl || ! cfg.nonce ) {
			return;
		}
		fetch( cfg.restUrl + 'online', {
			headers: {
				'X-WP-Nonce': cfg.nonce,
				'Accept'    : 'application/json',
			},
			credentials: 'same-origin',
		} )
		.then( function ( r ) { return r.ok ? r.json() : null; } )
		.then( function ( data ) {
			if ( ! data ) {
				return;
			}
			const el = document.querySelector( '.sk-vt-online-num' );
			if ( el ) {
				el.textContent = data.count;
			}
		} )
		.catch( function () { /* Silently ignore — polling will retry */ } );
	}

	// Poll every 30 seconds.
	if ( cfg.restUrl ) {
		setInterval( updateOnlineCount, 30000 );
	}

	// ── Chart helpers ───────────────────────────────────────────────────────
	const PALETTE = [
		'#2271b1', '#d63638', '#00a32a', '#dba617',
		'#3582c4', '#e65054', '#1d8348', '#f0c14b',
		'#5b9bd5', '#e88fa1', '#52be80', '#f7dc6f',
	];

	/**
	 * Replace an empty chart with a sentence. An empty frame reads as a
	 * broken plugin; "nothing recorded in this window" does not.
	 */
	function blank( el, message ) {
		const wrap = el.parentNode;
		if ( ! wrap ) {
			return;
		}
		const note = document.createElement( 'div' );
		note.className = 'sk-vt-chart-empty';
		note.textContent = message;
		wrap.replaceChild( note, el );
	}

	const NOTHING = ( cfg.labels && cfg.labels.empty ) || 'Nothing recorded in this window.';

	function doughnut( canvasId, labels, data ) {
		const el = document.getElementById( canvasId );
		if ( ! el ) {
			return;
		}
		if ( ! labels.length || ! data.some( function ( v ) { return Number( v ) > 0; } ) ) {
			blank( el, NOTHING );
			return;
		}
		new Chart( el, {
			type: 'doughnut',
			data: {
				labels: labels,
				datasets: [ {
					data: data,
					backgroundColor: PALETTE.slice( 0, data.length ),
					borderWidth: 2,
				} ],
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				cutout: '62%',
				plugins: {
					legend: {
						position: 'bottom',
						labels: { boxWidth: 12, padding: 10, font: { size: 12 } },
					},
				},
			},
		} );
	}

	function barChart( canvasId, labels, data, label ) {
		const el = document.getElementById( canvasId );
		if ( ! el ) {
			return;
		}
		if ( ! labels.length || ! data.some( function ( v ) { return Number( v ) > 0; } ) ) {
			blank( el, ( cfg.labels && cfg.labels.emptyToday ) || NOTHING );
			return;
		}
		new Chart( el, {
			type: 'bar',
			data: {
				labels: labels,
				datasets: [ {
					label: label || '',
					data: data,
					backgroundColor: 'rgba(34,113,177,0.7)',
					borderRadius: 3,
				} ],
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: { legend: { display: false } },
				scales: {
					y: { beginAtZero: true, ticks: { precision: 0 } },
				},
			},
		} );
	}

	/**
	 * The 'traffic over time' line.
	 *
	 * This lives here rather than in a <script> beside the canvas because
	 * Chart.js is enqueued in the footer: a script printed with the markup
	 * runs before Chart.js exists, so the chart was never drawn at all.
	 */
	function trendChart() {
		const el = document.getElementById( 'sk-vt-trend-chart' );
		if ( ! el || ! cfg.trend ) {
			return;
		}
		if ( ! cfg.trend.labels || ! cfg.trend.labels.length ) {
			blank( el, NOTHING );
			return;
		}

		new Chart( el, {
			type: 'line',
			data: {
				labels: cfg.trend.labels,
				datasets: [
					{
						label: cfg.labels.pageviews,
						data: cfg.trend.pageviews,
						borderColor: '#2271b1',
						backgroundColor: 'rgba(34,113,177,0.10)',
						borderWidth: 2, fill: true, tension: 0.35,
						pointRadius: 0, pointHoverRadius: 4,
					},
					{
						label: cfg.labels.unique,
						data: cfg.trend.unique,
						borderColor: '#d63638',
						backgroundColor: 'rgba(214,54,56,0.07)',
						borderWidth: 2, fill: true, tension: 0.35,
						pointRadius: 0, pointHoverRadius: 4,
					},
				],
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
				plugins: {
					legend: { position: 'bottom', labels: { boxWidth: 12, padding: 14, usePointStyle: true } },
				},
				scales: {
					y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f0f0f1' }, border: { display: false } },
					x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 24 } },
				},
			},
		} );
	}

	// ── Render charts from inline data ──────────────────────────────────────
	document.addEventListener( 'DOMContentLoaded', function () {

		trendChart();

		// Device doughnut.
		if ( cfg.devices ) {
			doughnut(
				'sk-vt-devices-chart',
				cfg.devices.map( function ( d ) { return d.device; } ),
				cfg.devices.map( function ( d ) { return d.count; } )
			);
		}

		// Browser doughnut.
		if ( cfg.browsers ) {
			const bLabels = Object.keys( cfg.browsers );
			const bData   = Object.values( cfg.browsers );
			doughnut( 'sk-vt-browsers-chart', bLabels, bData );
		}

		// OS doughnut.
		if ( cfg.osData ) {
			const osLabels = Object.keys( cfg.osData );
			const osVals   = Object.values( cfg.osData );
			doughnut( 'sk-vt-os-chart', osLabels, osVals );
		}

		// Traffic sources doughnut.
		if ( cfg.sources ) {
			doughnut(
				'sk-vt-sources-chart',
				cfg.sources.map( function ( s ) { return s.type; } ),
				cfg.sources.map( function ( s ) { return s.count; } )
			);
		}

		// Hourly bar chart.
		if ( cfg.hourly ) {
			// Fill all 24 hours (hours not in data default to 0).
			const hours    = Array.from( { length: 24 }, function ( _, i ) { return i; } );
			const hourMap  = {};
			cfg.hourly.forEach( function ( h ) { hourMap[ h.hour ] = h.pageviews; } );
			const hourData = hours.map( function ( h ) { return hourMap[ h ] || 0; } );

			barChart(
				'sk-vt-hourly-chart',
				hours.map( function ( h ) { return h + ':00'; } ),
				hourData,
				cfg.labels && cfg.labels.pageviews ? cfg.labels.pageviews : 'Pageviews'
			);
		}
	} );
} )();
