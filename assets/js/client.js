/* Reloom for Human Design — admin JS. */
(function ($) {
	'use strict';

	function nonce($scope) {
		return ($scope.closest('[data-nonce]').data('nonce')) || (window.rbhdc && rbhdc.nonce) || '';
	}

	/* ---- Settings ---- */
	function initSettings() {
		var $wrap = $('.rbhdc-settings');
		if (!$wrap.length) { return; }
		var $status = $wrap.find('.rbhdc-settings-status');
		var $result = $wrap.find('.rbhdc-settings-result');

		function payload(extra) {
			return $.extend({
				nonce: nonce($wrap),
				host: $('#rbhdc-host').val(),
				base: $('#rbhdc-base').val(),
				token: $('#rbhdc-token').val(),
				sync: $('#rbhdc-sync').is(':checked') ? '1' : '0'
			}, extra || {});
		}
		$wrap.find('.rbhdc-save-settings').on('click', function (e) {
			e.preventDefault();
			$status.text('Saving…');
			$.post(rbhdc.ajaxUrl, payload({ action: 'rbhdc_save_settings' }))
				.done(function () { $status.text('Saved.'); }).fail(function () { $status.text('Save failed.'); });
		});
		$wrap.find('.rbhdc-test').on('click', function (e) {
			e.preventDefault();
			$status.text('Testing…'); $result.empty();
			$.post(rbhdc.ajaxUrl, payload({ action: 'rbhdc_test' }))
				.done(function (resp) {
					if (!resp || !resp.success) { $status.text((resp && resp.data && resp.data.message) || 'Failed.'); return; }
					$status.text('Connected.');
					var d = resp.data;
					var $box = $('<div class="rbhd-card" style="padding:12px 18px;margin-top:10px;max-width:680px;"/>');
					$box.append($('<p/>').html('<strong>Account:</strong> ' + $('<i/>').text(d.profile || '(unnamed)').html()));
					var $ul = $('<ul style="margin:0 0 0 18px;"/>');
					(d.scopes || []).forEach(function (s) {
						$ul.append($('<li/>').html($('<i/>').text(s.label).html() + ': ' +
							(s.enabled ? '<span style="color:#1a7f37;">shared</span>' : '<span style="color:#b32d2e;">off</span>')));
					});
					$box.append('<p style="margin:6px 0 2px;"><strong>Shared content:</strong></p>').append($ul);
					$result.append($box);
				}).fail(function () { $status.text('Request failed.'); });
		});
	}

	/* ---- Profiles list ---- */
	function initList() {
		var $wrap = $('.rbhdc-profiles');
		if (!$wrap.length) { return; }

		$wrap.find('.rbhdc-add-toggle').on('click', function (e) { e.preventDefault(); $wrap.find('.rbhdc-add-form').slideToggle(120); });
		$wrap.find('.rbhdc-import-toggle').on('click', function (e) { e.preventDefault(); $wrap.find('.rbhdc-import-form').slideToggle(120); });

		$wrap.on('click', '.rbhdc-delete', function (e) {
			e.preventDefault();
			if (!window.confirm('Delete this profile + its cached chart/readings from this site? (Your Reloom account is not affected.)')) { return; }
			var id = $(this).data('id'), $row = $(this).closest('tr');
			$.post(rbhdc.ajaxUrl, { action: 'rbhdc_delete_profile', nonce: nonce($wrap), id: id })
				.done(function () { $row.fadeOut(120, function () { $(this).remove(); applyFilters(); }); });
		});

		/* Export */
		$wrap.find('.rbhdc-export').on('click', function (e) {
			e.preventDefault();
			$.post(rbhdc.ajaxUrl, { action: 'rbhdc_export', nonce: nonce($wrap) }).done(function (resp) {
				if (!resp || !resp.success) { return; }
				var blob = new Blob([resp.data.json], { type: 'application/json' });
				var a = document.createElement('a');
				a.href = URL.createObjectURL(blob); a.download = resp.data.filename;
				document.body.appendChild(a); a.click(); document.body.removeChild(a);
			});
		});
		/* Import */
		$wrap.find('.rbhdc-import-go').on('click', function (e) {
			e.preventDefault();
			var $st = $wrap.find('.rbhdc-import-status');
			var file = $wrap.find('.rbhdc-import-file')[0].files[0];
			if (!file) { $st.text('Pick a file first.'); return; }
			var reader = new FileReader();
			reader.onload = function () {
				$st.text('Importing…');
				$.post(rbhdc.ajaxUrl, { action: 'rbhdc_import', nonce: nonce($wrap), json: reader.result })
					.done(function (resp) {
						if (resp && resp.success) { $st.text(resp.data.added + ' imported.'); setTimeout(function () { window.location.reload(); }, 700); }
						else { $st.text((resp && resp.data && resp.data.message) || 'Import failed.'); }
					}).fail(function () { $st.text('Import failed.'); });
			};
			reader.readAsText(file);
		});

		/* Filters (client-side) */
		var $search = $wrap.find('.rbhdc-search');
		var $gender = $wrap.find('.rbhdc-filter-gender');
		var $sort = $wrap.find('.rbhdc-sort');
		var $tbody = $wrap.find('.rbhdc-table tbody');
		var $count = $wrap.find('.rbhdc-filter-count');

		function applyFilters() {
			var q = ($search.val() || '').toLowerCase().trim();
			var g = $gender.val();
			var shown = 0, total = 0;
			$tbody.find('tr[data-id]').each(function () {
				total++;
				var $tr = $(this);
				var ok = (!q || ($tr.data('name') || '').toString().indexOf(q) !== -1) && (!g || $tr.data('gender') === g);
				$tr.toggle(ok);
				if (ok) { shown++; }
			});
			$count.text(total ? (shown === total ? (total + ' profiles') : (shown + ' / ' + total)) : '');
			// Sort.
			var key = $sort.val();
			var rows = $tbody.find('tr[data-id]').get();
			rows.sort(function (a, b) {
				if (key === 'name-asc') { return ($(a).data('name') + '').localeCompare($(b).data('name') + ''); }
				var ca = parseInt($(a).data('created'), 10) || 0, cb = parseInt($(b).data('created'), 10) || 0;
				return key === 'created-asc' ? ca - cb : cb - ca;
			});
			$.each(rows, function (i, r) { $tbody.append(r); });
		}
		$search.on('input', applyFilters);
		$gender.on('change', applyFilters);
		$sort.on('change', applyFilters);
		if ($tbody.length) { applyFilters(); }
		window._rbhdcApplyFilters = applyFilters;
	}

	/* ---- Birth Data form save (add + edit), works on any page ---- */
	function initForms() {
		function collect($form) {
			var p = {};
			$form.find('[name]').each(function () { p[this.name] = $(this).val(); });
			var id = $form.data('id');
			if (id) { p.id = id; }
			return p;
		}
		function save($form, then) {
			var $status = $form.find('.rbhdc-form-status');
			$status.text('Verifying place & saving…').css('color', '');
			$.post(rbhdc.ajaxUrl, { action: 'rbhdc_save_profile', nonce: nonce($form), profile: collect($form) })
				.done(function (resp) {
					if (!resp || !resp.success) {
						$status.text((resp && resp.data && resp.data.message) || 'Save failed.').css('color', '#b32d2e');
						return;
					}
					// The profile is saved locally either way — but if the sync to
					// Reloom was refused (e.g. plan profile limit reached), say so
					// instead of silently pretending it landed there.
					var sync = resp.data && resp.data.sync;
					if (sync && sync.ok === false) {
						$status
							.text('Saved here, but NOT synced to Reloom: ' + (sync.message || 'sync failed.'))
							.css('color', '#b32d2e');
						setTimeout(function () { then(resp.data); }, 4000);
						return;
					}
					then(resp.data);
				})
				.fail(function () { $status.text('Save failed.').css('color', '#b32d2e'); });
		}
		$(document).on('click', '.rbhdc-generate', function (e) {
			e.preventDefault();
			save($(this).closest('form'), function (d) { window.location = d.url; });
		});
		$(document).on('click', '.rbhdc-save', function (e) {
			e.preventDefault();
			var $form = $(this).closest('form');
			save($form, function (d) {
				if ($form.data('context') === 'edit') { window.location.reload(); }
				else { window.location = rbhdc.profilesUrl; }
			});
		});
		// Detail: toggle / cancel the edit form.
		$(document).on('click', '.rbhdc-edit-toggle', function (e) { e.preventDefault(); $('.rbhdc-edit-form').slideToggle(120); });
		$(document).on('click', '.rbhdc-edit-cancel', function (e) { e.preventDefault(); $('.rbhdc-edit-form').slideUp(120); });
	}

	/* ---- Detail: tabs, stored content, refresh ---- */
	function initDetail() {
		var $wrap = $('.rbhdc-detail');
		if (!$wrap.length) { return; }
		var id = $wrap.data('id');

		function setBusy($btn, busy) {
			if (busy) {
				$btn.prop('disabled', true).addClass('rbhdc-busy')
					.html('<span class="spinner is-active" style="float:none;margin:0 4px 0 0;vertical-align:middle;"></span> ' + 'Working…');
			} else {
				$btn.prop('disabled', false).removeClass('rbhdc-busy')
					.html('<span class="dashicons dashicons-update" style="vertical-align:text-bottom;"></span> Refresh');
			}
		}

		function fetch($panel, force) {
			var $c = $panel.find('.rbhdc-content');
			if (!force && $c.attr('data-loaded') === '1') { return; }
			var scope = $panel.data('scope');
			$c.attr('data-loaded', '1');
			var $btn = $panel.find('.rbhdc-refresh');
			setBusy($btn, true);
			// Always show a clear loading state — readings call the AI and can
			// take 10–30 seconds.
			var what = (scope === 'chart') ? 'Fetching the Bodygraph chart' : 'Generating this reading with AI';
			$c.html(
				'<div class="rbhdc-loading-box" style="display:flex;align-items:center;gap:10px;padding:14px 4px;">' +
				'<span class="spinner is-active" style="float:none;margin:0;"></span>' +
				'<span><strong>' + what + '…</strong><br>' +
				'<span class="description">This runs on the server and can take <strong>10–30 seconds</strong>. Please wait — no need to click again.</span></span>' +
				'</div>'
			);
			$.post(rbhdc.ajaxUrl, { action: 'rbhdc_content', nonce: nonce($wrap), id: id, scope: scope })
				.done(function (resp) {
					setBusy($btn, false);
					if (resp && resp.success && resp.data && resp.data.html) {
						$c.html(resp.data.html);
						$panel.find('.rbhdc-stored').text(resp.data.stored ? ('Stored ' + resp.data.stored) : '');
						$wrap.find('.rbhd-tab[data-scope="' + scope + '"]').find('.rbhd-tab-dot').remove();
						$wrap.find('.rbhd-tab[data-scope="' + scope + '"] .rbhd-tab-label').after('<span class="rbhd-tab-dot" aria-hidden="true"></span>');
					} else {
						$c.html('<div class="notice notice-error inline"><p>' + $('<i/>').text((resp && resp.data && resp.data.message) || 'Failed to load.').html() + '</p></div>');
						$c.attr('data-loaded', '0');
					}
				})
				.fail(function (jqxhr) {
					setBusy($btn, false);
					var msg = (jqxhr && jqxhr.status === 0) ? 'The request timed out or was interrupted. The reading may still finish on the server — try Refresh in a moment.' : 'Request failed.';
					$c.html('<div class="notice notice-error inline"><p>' + msg + '</p></div>');
					$c.attr('data-loaded', '0');
				});
		}

		// Auto-pull EVERYTHING at once on open: the chart (left) and every reading
		// (right). Each fills in as it returns; cached ones are instant.
		$wrap.find('.rbhd-tab-panel').each(function () { fetch($(this), false); });

		// Bodygraph zoom (default smaller). The level is stored as a CSS variable
		// on the chart panel and persisted in localStorage.
		(function () {
			var $panel = $wrap.find('.rbhd-tab-panel[data-scope="chart"]');
			if (!$panel.length) { return; }
			var levels = [35, 45, 55, 70, 85, 100, 120, 140, 170, 200];
			var saved;
			try { saved = parseInt(window.localStorage.getItem('rbhdcChartZoom'), 10); } catch (e) {}
			var idx = levels.indexOf(saved);
			if (idx < 0) { idx = levels.indexOf(55); }
			function apply() {
				var z = levels[idx];
				if ($panel[0].style.setProperty) { $panel[0].style.setProperty('--rbhdc-chart-zoom', z + '%'); }
				$panel.find('.rbhdc-zoom-val').text(z + '%');
				try { window.localStorage.setItem('rbhdcChartZoom', z); } catch (e) {}
			}
			apply();
			$wrap.on('click', '.rbhdc-zoom-in', function (e) { e.preventDefault(); if (idx < levels.length - 1) { idx++; apply(); } });
			$wrap.on('click', '.rbhdc-zoom-out', function (e) { e.preventDefault(); if (idx > 0) { idx--; apply(); } });
		})();

		// Tabs switch only the RIGHT-hand reading panels; the chart on the left
		// (and its static "Chart" tab) stays visible at all times.
		$wrap.on('click', '.rbhdc-split-right .rbhd-tab', function (e) {
			e.preventDefault();
			var scope = $(this).data('scope');
			var $right = $(this).closest('.rbhdc-split-right');
			$right.find('.rbhd-tab').removeClass('nav-tab-active');
			$(this).addClass('nav-tab-active');
			$right.find('.rbhd-tab-panels .rbhd-tab-panel').attr('hidden', true).removeClass('is-active');
			$right.find('.rbhd-tab-panels .rbhd-tab-panel[data-scope="' + scope + '"]').removeAttr('hidden').addClass('is-active');
		});

		$wrap.on('click', '.rbhdc-refresh', function (e) {
			e.preventDefault();
			fetch($(this).closest('.rbhd-tab-panel'), true);
		});

		// Rasterise an on-screen SVG to a PNG data URL via canvas. The browser
		// renders the bodygraph perfectly, so this gives the PDF a crisp chart
		// without relying on the server's (limited) SVG support. cb('') on any
		// failure so export can proceed without the chart.
		function svgToPng(svgEl, scale, cb) {
			var called = false;
			var timer = null;
			function finish(v) {
				if (called) { return; }
				called = true;
				if (timer) { clearTimeout(timer); }
				cb(v);
			}
			// Watchdog: never let a stalled SVG-load freeze the export button.
			timer = setTimeout(function () {
				if (window.console) { console.warn('[rbhdc] chart rasterise timed out; exporting without chart'); }
				finish('');
			}, 6000);
			try {
				var clone = svgEl.cloneNode(true);
				clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
				var vb = svgEl.viewBox && svgEl.viewBox.baseVal;
				var w = (vb && vb.width) ? vb.width : (svgEl.getBBox ? svgEl.getBBox().width : 0) || svgEl.clientWidth || 620;
				var h = (vb && vb.height) ? vb.height : (svgEl.getBBox ? svgEl.getBBox().height : 0) || svgEl.clientHeight || 900;
				clone.setAttribute('width', w);
				clone.setAttribute('height', h);
				var xml = new XMLSerializer().serializeToString(clone);
				var src = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(xml)));
				var img = new Image();
				img.onload = function () {
					try {
						var canvas = document.createElement('canvas');
						canvas.width = Math.round(w * scale);
						canvas.height = Math.round(h * scale);
						var ctx = canvas.getContext('2d');
						ctx.fillStyle = '#ffffff';
						ctx.fillRect(0, 0, canvas.width, canvas.height);
						ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
						finish(canvas.toDataURL('image/png'));
					} catch (err) {
						if (window.console) { console.warn('[rbhdc] chart rasterise failed:', err); }
						finish('');
					}
				};
				img.onerror = function () {
					if (window.console) { console.warn('[rbhdc] chart image failed to load'); }
					finish('');
				};
				img.src = src;
			} catch (err) {
				if (window.console) { console.warn('[rbhdc] chart rasterise error:', err); }
				finish('');
			}
		}

		// Export to PDF: rasterise the chart, POST everything, and download the
		// real .pdf the server generates (Dompdf). No print dialog, no chrome.
		$wrap.on('click', '.rbhdc-pdf', function (e) {
			e.preventDefault();
			var $btn = $(this);
			if ($btn.prop('disabled')) { return; }
			var orig = $btn.html();
			$btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 4px 0 0;vertical-align:middle;"></span> ' + 'Preparing…');
			function done() { $btn.prop('disabled', false).html(orig); }

			function send(pngDataUrl) {
				var fd = new FormData();
				fd.append('action', 'rbhdc_pdf');
				fd.append('nonce', nonce($wrap));
				fd.append('id', id);
				if (pngDataUrl) { fd.append('chart_png', pngDataUrl); }
				if (window.console) { console.log('[rbhdc] requesting PDF (chart ' + (pngDataUrl ? 'included, ' + Math.round(pngDataUrl.length / 1024) + 'KB' : 'none') + ')'); }

				// Abort the request if the server doesn't respond, so the button
				// can never spin forever.
				var ctrl = window.AbortController ? new AbortController() : null;
				var killer = setTimeout(function () { if (ctrl) { ctrl.abort(); } }, 45000);
				var opts = { method: 'POST', credentials: 'same-origin', body: fd };
				if (ctrl) { opts.signal = ctrl.signal; }

				// NOTE: use window.fetch explicitly — initDetail has a local
				// function named fetch() (the content loader) that would shadow
				// the browser API and throw "$panel.find is not a function".
				window.fetch(rbhdc.ajaxUrl, opts)
					.then(function (r) {
						if (window.console) { console.log('[rbhdc] PDF response', r.status); }
						if (!r.ok) { throw new Error('server returned ' + r.status); }
						return r.blob();
					})
					.then(function (blob) {
						clearTimeout(killer);
						if (!blob || blob.size === 0) { throw new Error('empty file'); }
						var name = ($wrap.find('h1').first().text() || 'Human Design').trim() + ' - Human Design.pdf';
						var url = URL.createObjectURL(blob);
						var a = document.createElement('a');
						a.href = url; a.download = name;
						document.body.appendChild(a); a.click(); a.remove();
						setTimeout(function () { URL.revokeObjectURL(url); }, 5000);
						done();
					})
					.catch(function (err) {
						clearTimeout(killer);
						var msg = (err && err.name === 'AbortError') ? 'the server took too long to respond' : (err && err.message ? err.message : 'unknown error');
						if (window.console) { console.error('[rbhdc] PDF request failed:', err); }
						window.alert('Could not generate the PDF — ' + msg + '. (PDF export v1.11.4)');
						done();
					});
			}

			var svgEl = $wrap.find('.rbhd-svg-wrap svg, .rbhd-tab-panel[data-scope="chart"] svg').get(0);
			if (svgEl) { svgToPng(svgEl, 2, send); } else { send(''); }
		});
	}

	/* ---- Place-of-birth verification (proxied to the Bodygraph /locations) ---- */
	function initPlaceSearch() {
		var timer = null;

		function close() { $('.rbhdc-place-results').attr('hidden', true).empty(); }

		function setStatus($wrap, state, msg) {
			var $s = $wrap.closest('label').find('.rbhdc-place-status');
			var map = {
				verified:   ['✓ verified', '#1a7f37'],
				checking:   ['checking…', '#646970'],
				unverified: ['not verified — pick a city', '#b32d2e'],
				nomatch:    ['no match in the location database — check spelling', '#b32d2e'],
				error:      [msg || 'could not reach the location service', '#b32d2e'],
				none:       ['', '']
			};
			var v = map[state] || map.none;
			$s.text(v[0]).css('color', v[1]);
		}

		function isVerified($wrap) {
			return $wrap.closest('label').find('.rbhdc-place-status').text().indexOf('verified') !== -1;
		}

		// Query the proxy. cb(results|null, errorMessage).
		function lookup($input, cb) {
			var q = ($input.val() || '').trim();
			if (q.length < 2) { cb([], null); return; }
			$.post(rbhdc.ajaxUrl, { action: 'rbhdc_locations', nonce: nonce($input), query: q })
				.done(function (resp) {
					if (resp && resp.success && resp.data && resp.data.results) { cb(resp.data.results, null); }
					else { cb(null, (resp && resp.data && resp.data.message) || 'lookup failed'); }
				})
				.fail(function (jqxhr) {
					var m = (jqxhr && jqxhr.responseJSON && jqxhr.responseJSON.data && jqxhr.responseJSON.data.message) || 'connection error';
					cb(null, m);
				});
		}

		// Pin the dropdown to the input via FIXED viewport coordinates — works
		// regardless of ancestor positioning/overflow (the same trick the Suite
		// uses, since some admin themes break position:relative on wrappers).
		function positionResults($wrap) {
			var el = $wrap.find('.rbhdc-place')[0];
			if (!el || !el.getBoundingClientRect) { return; }
			var rect = el.getBoundingClientRect();
			$wrap.find('.rbhdc-place-results').css({
				position: 'fixed',
				left: rect.left + 'px',
				right: 'auto',
				top: (rect.bottom + 2) + 'px',
				width: rect.width + 'px',
				zIndex: 100000
			});
		}

		// Always open the popup: with suggestions, a "no matches" row, or a loud
		// error row — so typing never looks like "nothing happens".
		function renderItems($wrap, results, message, isError) {
			var $results = $wrap.find('.rbhdc-place-results').empty();
			if (message) {
				$results.append($('<li class="rbhdc-msg"/>').text(message).css({ cursor: 'default', color: isError ? '#b32d2e' : '#646970' }));
				$results.removeAttr('hidden');
				positionResults($wrap);
				return;
			}
			if (!results || !results.length) {
				$results.append($('<li class="rbhdc-msg"/>').text('No matching place found — check the spelling.').css({ cursor: 'default', color: '#646970' }));
				$results.removeAttr('hidden');
				positionResults($wrap);
				return;
			}
			results.forEach(function (r) {
				$results.append($('<li/>', { 'data-tz': r.timezone, 'data-label': r.label })
					.text(r.label)
					.append($('<span class="rbhd-tz"/>').text(' (' + r.timezone + ')')));
			});
			$results.removeAttr('hidden');
			positionResults($wrap);
		}
		function renderResults($wrap, results) { renderItems($wrap, results); }

		// Keep the open dropdown pinned to the input on scroll/resize.
		$(window).on('scroll resize', function () {
			$('.rbhdc-place-results').not('[hidden]').each(function () {
				positionResults($(this).closest('.rbhdc-place-wrap'));
			});
		});

		function applyPick($wrap, label, tz) {
			$wrap.find('.rbhdc-place').val(label);
			$wrap.closest('form').find('.rbhdc-timezone').val(tz);
			$wrap.find('.rbhdc-place-results').attr('hidden', true).empty();
			setStatus($wrap, 'verified');
		}

		// Live typeahead — opens the suggestion window as you type.
		function search($input, invalidateTz) {
			var $wrap = $input.closest('.rbhdc-place-wrap');
			if (invalidateTz) { $wrap.closest('form').find('.rbhdc-timezone').val(''); }
			var q = ($input.val() || '').trim();
			clearTimeout(timer);
			if (q.length < 2) { $wrap.find('.rbhdc-place-results').attr('hidden', true).empty(); setStatus($wrap, q ? 'unverified' : 'none'); return; }
			setStatus($wrap, 'checking');
			timer = setTimeout(function () {
				lookup($input, function (results, err) {
					if (err) { renderItems($wrap, null, 'Cannot reach the location service: ' + err, true); setStatus($wrap, 'error', err); return; }
					renderItems($wrap, results);
					setStatus($wrap, results.length ? 'unverified' : 'nomatch');
				});
			}, 200);
		}

		$(document).on('input', '.rbhdc-place', function () { search($(this), true); });
		// Re-open the suggestions when focusing a field that already has text.
		$(document).on('focus', '.rbhdc-place', function () {
			var $input = $(this);
			if (($input.val() || '').trim().length >= 2 && !isVerified($input.closest('.rbhdc-place-wrap'))) {
				search($input, false);
			}
		});

		// Pick a real suggestion (not the message rows). mousedown fires before
		// blur; preventDefault keeps focus.
		$(document).on('mousedown', '.rbhdc-place-results li[data-tz]', function (e) {
			e.preventDefault();
			var $li = $(this);
			applyPick($li.closest('.rbhdc-place-wrap'), $li.data('label'), $li.data('tz'));
		});

		// Auto-verify on blur: if the user typed a place but didn't pick, look it
		// up and snap to the best match — so verification happens even without a click.
		$(document).on('blur', '.rbhdc-place', function () {
			var $input = $(this);
			var $wrap = $input.closest('.rbhdc-place-wrap');
			if (isVerified($wrap)) { return; }
			var q = ($input.val() || '').trim();
			if (q.length < 2) { return; }
			setStatus($wrap, 'checking');
			setTimeout(function () { // let a pending click on a suggestion win first
				if (isVerified($wrap)) { return; }
				lookup($input, function (results, err) {
					if (err) { setStatus($wrap, 'error', err); return; }
					if (results && results.length) { applyPick($wrap, results[0].label, results[0].timezone); }
					else { setStatus($wrap, 'nomatch'); }
				});
			}, 200);
		});

		// On load, an existing profile with place + timezone is already verified.
		$('.rbhdc-place').each(function () {
			var $wrap = $(this).closest('.rbhdc-place-wrap');
			if (($(this).val() || '').trim() && $wrap.closest('form').find('.rbhdc-timezone').val()) {
				setStatus($wrap, 'verified');
			}
		});

		$(document).on('click', function (e) {
			if (!$(e.target).closest('.rbhdc-place-wrap').length) { close(); }
		});
	}

	// Run each initialiser in isolation so one failing module can't stop the
	// others (e.g. a list-filter error must not prevent the place typeahead).
	$(function () {
		// NOTE: initPlaceSearch is intentionally omitted — the place typeahead is
		// now bound by the standalone block below for maximum robustness.
		[initSettings, initForms, initList, initDetail].forEach(function (fn) {
			try { fn(); } catch (e) { if (window.console && console.error) { console.error('[rbhdc] init failed:', fn.name, e); } }
		});
	});

	// Bulletproof, standalone binding for the place typeahead — bound directly
	// here (outside the init chain) so it always attaches even if an init throws.
	$(function () {
		// The suggestion popup lives directly on <body> so NO ancestor (cards,
		// transforms, overflow:hidden, builders) can clip or mis-anchor it.
		var $pop = null, $active = null;
		function pop() {
			if (!$pop) { $pop = $('<ul class="rbhdc-place-results rbhdc-place-pop" hidden></ul>').appendTo(document.body); }
			return $pop;
		}
		function place() {
			if (!$active || !$active.length) { return; }
			var el = $active[0]; if (!el || !el.getBoundingClientRect) { return; }
			var r = el.getBoundingClientRect();
			pop().css({ position: 'fixed', left: r.left + 'px', top: (r.bottom + 2) + 'px', width: r.width + 'px', zIndex: 2147483000 });
		}
		function hide() { if ($pop) { $pop.attr('hidden', true).empty(); } }
		function status($input, text, color) { $input.closest('label').find('.rbhdc-place-status').text(text || '').css('color', color || ''); }

		$(document).on('input.rbhdcPlace', '.rbhdc-place', function () {
			var $input = $(this); $active = $input;
			$input.closest('form').find('.rbhdc-timezone').val('');
			var q = ($input.val() || '').trim();
			if (q.length < 2) { hide(); status($input, ''); return; }
			status($input, 'checking…', '#646970');
			clearTimeout($input.data('t'));
			$input.data('t', setTimeout(function () {
				$.post(rbhdc.ajaxUrl, { action: 'rbhdc_locations', nonce: (window.rbhdc && rbhdc.nonce) || '', query: q })
					.done(function (resp) {
						var $p = pop().empty();
						var results = resp && resp.data && resp.data.results;
						if (results && results.length) {
							results.forEach(function (it) {
								$('<li/>', { 'data-tz': it.timezone, 'data-label': it.label })
									.text(it.label).append($('<span class="rbhd-tz"/>').text(' (' + it.timezone + ')')).appendTo($p);
							});
						} else {
							var m = (resp && resp.data && resp.data.message) ? ('Error: ' + resp.data.message) : 'No matching place found.';
							$('<li class="rbhdc-msg"/>').text(m).appendTo($p);
						}
						$p.removeAttr('hidden'); place(); status($input, '');
					})
					.fail(function (x) {
						pop().empty().append($('<li class="rbhdc-msg"/>').text('Lookup failed (HTTP ' + (x && x.status) + ').')).removeAttr('hidden');
						place(); status($input, '');
					});
			}, 200));
		});

		$(document).on('mousedown.rbhdcPlace', '.rbhdc-place-pop li[data-tz]', function (e) {
			e.preventDefault();
			if (!$active || !$active.length) { return; }
			var $li = $(this);
			$active.val($li.data('label'));
			$active.closest('form').find('.rbhdc-timezone').val($li.data('tz'));
			status($active, '✓ verified', '#1a7f37');
			hide();
		});
		$(document).on('click.rbhdcPlace', function (e) {
			if (!$(e.target).closest('.rbhdc-place, .rbhdc-place-pop').length) { hide(); }
		});
		$(window).on('scroll.rbhdcPlace resize.rbhdcPlace', place);
	});
})(jQuery);
