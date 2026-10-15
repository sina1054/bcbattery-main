/*
 * BC Battery - payment pages: copy buttons of the result screen and the pay button of an existing order.
 * Needs flow.js (BC.toast). Texts come from window.bcFlow.i18n.
 */
(function () {
	'use strict';

	var BC = window.BC || {};
	var t = (window.bcFlow && window.bcFlow.i18n) || {};
	var body = document.body;

	/* ---------- Copy buttons (order number, bank tracking code) ---------- */

	/* Clipboard API first; when the browser refuses it (no permission, embedded view) the old way. */
	function copy(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text).catch(function () {
				return copyOld(text);
			});
		}
		return copyOld(text);
	}
	function copyOld(text) {
		return new Promise(function (resolve, reject) {
			var box = document.createElement('textarea');
			box.value = text;
			box.setAttribute('readonly', '');
			box.style.cssText = 'position:fixed;top:0;opacity:0';
			document.body.appendChild(box);
			box.select();
			var ok = false;
			try {
				ok = document.execCommand('copy');
			} catch (e) {
				/* fall through */
			}
			box.remove();
			ok ? resolve() : reject(new Error('copy'));
		});
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-bc-copy]');
		if (!btn) {
			return;
		}
		var text = btn.getAttribute('data-bc-copy');
		copy(text).then(
			function () {
				if (BC.toast) {
					BC.toast(t.copied || 'Copied');
				}
			},
			function () {
				// Copy is not allowed here: select the text so the visitor can copy it by hand.
				var range = document.createRange();
				range.selectNodeContents(btn.querySelector('[dir]') || btn);
				var sel = window.getSelection();
				sel.removeAllRanges();
				sel.addRange(range);
			}
		);
	});

	/* ---------- Pay for an existing order (form-pay.php) ---------- */

	var form = document.getElementById('order_review');

	/* The visible buttons press WooCommerce's own (hidden) button: pay_action() and the gateways see the usual form post. */
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-bc-pay]');
		if (!btn || !form) {
			return;
		}
		e.preventDefault();
		if (body.classList.contains('bc-busy')) {
			return;
		}
		var terms = form.querySelector('[data-bc-terms] input[type="checkbox"]');
		if (terms && !terms.checked) {
			terms.closest('[data-bc-terms]').classList.add('bc-invalid');
			terms.focus();
			if (BC.toast) {
				BC.toast(t.terms || 'Please accept the terms', { type: 'err' });
			}
			return;
		}
		var place = document.getElementById('place_order');
		if (!place) {
			return;
		}
		body.classList.add('bc-busy');
		place.click();
	});

	if (form) {
		form.addEventListener('change', function (e) {
			if (e.target.name === 'terms') {
				e.target.closest('[data-bc-terms]').classList.remove('bc-invalid');
			}
		});
	}

	/* Back from the bank with the browser's back button: the page comes from memory, so it must not stay "busy". */
	window.addEventListener('pageshow', function (e) {
		if (e.persisted) {
			body.classList.remove('bc-busy');
		}
	});
})();