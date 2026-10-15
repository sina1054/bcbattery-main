/*
 * BC Battery - checkout page behaviour (steps 2 and 3 of the purchase flow).
 * The page is ONE WooCommerce checkout form with two views ("details" and "pay"). WooCommerce's own script keeps doing the
 * totals update and the order submit; this file only
 *   - switches the view and keeps the browser's back button working,
 *   - checks the fields before the visitor moves on and shows the messages next to them,
 *   - maps errors that come back from the server onto the fields,
 *   - keeps the main button (bottom bar and summary card) in step with the view.
 * Needs flow.js (BC), jQuery and WooCommerce's wc-checkout. Settings: window.bcCheckout (printed by inc/checkout.php).
 */
(function ($) {
	'use strict';

	var BC = window.BC || {};
	var cfg = window.bcCheckout || {};
	var rules = cfg.rules || {};
	var t = cfg.i18n || {};
	var root = document.querySelector('[data-bc-checkout]');
	var form = document.querySelector('form.checkout');
	if (!root || !form) {
		return;
	}

	var ALERT_ICON =
		'<svg class="bc-i" aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17.5v.5"/></svg>';
	var view = 'details';
	var updating = false; // an update_order_review request is on its way

	/* ---------- Small helpers ---------- */

	function $$(sel, ctx) {
		return Array.prototype.slice.call((ctx || document).querySelectorAll(sel));
	}
	function fmt(text, value) {
		return String(text || '').replace('%s', value);
	}
	/* Persian/Arabic digits <-> Latin. */
	function enDigits(s) {
		return String(s).replace(/[۰-۹٠-٩]/g, function (c) {
			var n = c.charCodeAt(0);
			return n >= 0x06f0 ? n - 0x06f0 : n - 0x0660;
		});
	}
	function faDigits(s) {
		return String(s).replace(/[0-9]/g, function (d) {
			return String.fromCharCode(0x06f0 + Number(d));
		});
	}
	function isVisible(el) {
		return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
	}
	function setBusy(on) {
		document.body.classList.toggle('bc-busy', on);
	}
	function inputOf(row) {
		return row.querySelector('input:not([type="hidden"]), select, textarea');
	}
	function labelOf(row) {
		var label = row.querySelector('label');
		if (!label) {
			return '';
		}
		var copy = label.cloneNode(true);
		$$('.required, .optional', copy).forEach(function (n) {
			n.remove();
		});
		return copy.textContent.trim();
	}

	/* ---------- Messages next to the fields ---------- */

	function clearError(row) {
		row.classList.remove('bc-invalid');
		var msg = row.querySelector(':scope > .bc-err');
		if (msg) {
			msg.remove();
		}
		var el = inputOf(row);
		if (el) {
			el.removeAttribute('aria-invalid');
			if (el.dataset.bcDesc !== undefined) {
				el.dataset.bcDesc ? el.setAttribute('aria-describedby', el.dataset.bcDesc) : el.removeAttribute('aria-describedby');
			}
		}
	}

	function setError(row, text) {
		clearError(row);
		row.classList.add('bc-invalid');
		var el = inputOf(row);
		var msg = document.createElement('span');
		msg.className = 'bc-err';
		msg.setAttribute('role', 'alert');
		msg.id = (el && el.id ? el.id : 'bc-field') + '-err';
		msg.insertAdjacentHTML('afterbegin', ALERT_ICON);
		msg.appendChild(document.createTextNode(text));
		row.appendChild(msg);
		if (el) {
			el.setAttribute('aria-invalid', 'true');
			if (el.dataset.bcDesc === undefined) {
				el.dataset.bcDesc = el.getAttribute('aria-describedby') || '';
			}
			el.setAttribute('aria-describedby', (el.dataset.bcDesc + ' ' + msg.id).trim());
		}
	}

	/* The box at the top: "3 fields need fixing" (or one general message). */
	function showSummary(title, text) {
		var box = document.querySelector('[data-bc-errors]');
		if (!box) {
			return;
		}
		box.querySelector('[data-bc-errors-title]').textContent = title;
		box.querySelector('[data-bc-errors-text]').textContent = text || '';
		box.hidden = false;
	}
	function hideSummary() {
		var box = document.querySelector('[data-bc-errors]');
		if (box) {
			box.hidden = true;
		}
	}
	function summarize(count) {
		if (count > 0) {
			showSummary(count === 1 ? t.fixOne : fmt(t.fix, faDigits(count)), t.hint);
		} else {
			hideSummary();
		}
	}
	function countInvalid() {
		return $$('.bc-invalid', form).length;
	}

	function reveal(el, focusable) {
		if (!el) {
			return;
		}
		el.scrollIntoView({ behavior: 'smooth', block: 'center' });
		if (focusable) {
			try {
				focusable.focus({ preventScroll: true });
			} catch (e) {
				/* not focusable */
			}
		}
	}

	/* ---------- Checks (the server repeats them) ---------- */

	/* '' when the field is fine, otherwise the message. Fields that are not on screen are skipped. */
	function check(row) {
		var el = inputOf(row);
		if (!el || !isVisible(row)) {
			return '';
		}
		var rule = rules[el.id] || {};
		var value = String(el.value || '').trim();
		if (value === '') {
			if (row.classList.contains('validate-required')) {
				return fmt(el.tagName === 'SELECT' ? t.select : t.enter, labelOf(row));
			}
			return '';
		}
		if (rule.digits) {
			value = enDigits(value);
		}
		if (rule.words && value.replace(/\s+/g, ' ').split(' ').length < rule.words) {
			return rule.msg;
		}
		if (rule.min && value.length < rule.min) {
			return rule.msg;
		}
		if (rule.pattern && !new RegExp(rule.pattern).test(value)) {
			return rule.msg;
		}
		return '';
	}

	function deliveryBox() {
		return form.querySelector('[data-bc="delivery"]');
	}
	function clearDeliveryError() {
		var box = deliveryBox();
		var msg = box && box.querySelector(':scope > .bc-err');
		if (msg) {
			msg.remove();
		}
	}

	/* Step 2 -> 3: true when everything is fine; otherwise the problems are shown and the first one is revealed. */
	function validateDetails() {
		var first = null;
		var count = 0;
		$$('.bc-stack.bc-view-details .form-row', form).forEach(function (row) {
			var text = check(row);
			if (text) {
				setError(row, text);
				count++;
				first = first || row;
			} else {
				clearError(row);
			}
		});
		var box = deliveryBox();
		clearDeliveryError();
		if (box && isVisible(box) && !box.querySelector('input.shipping_method:checked')) {
			var msg = document.createElement('span');
			msg.className = 'bc-err';
			msg.setAttribute('role', 'alert');
			msg.insertAdjacentHTML('afterbegin', ALERT_ICON);
			msg.appendChild(document.createTextNode(box.querySelector('input.shipping_method') ? t.ship : t.shipNone));
			box.appendChild(msg);
			count++;
			first = first || box;
		}
		summarize(count);
		if (first) {
			reveal(first, first.matches('.form-row') ? inputOf(first) : null);
		}
		return count === 0;
	}

	/* Fix a field -> its message goes away as soon as it is right. */
	function recheck(e) {
		var row = e.target.closest && e.target.closest('.form-row.bc-invalid');
		if (!row) {
			return;
		}
		if (!check(row)) {
			clearError(row);
			summarize(countInvalid());
		}
	}
	form.addEventListener('input', recheck);
	form.addEventListener('change', recheck);
	/* Leaving a field of step 2: tell right away if it is wrong. The top box is updated only when it is already on screen. */
	form.addEventListener('focusout', function (e) {
		var row = e.target.closest && e.target.closest('.bc-stack.bc-view-details .form-row');
		if (!row || (e.relatedTarget && row.contains(e.relatedTarget))) {
			return;
		}
		var text = check(row);
		if (text) {
			setError(row, text);
		} else {
			clearError(row);
		}
		var box = document.querySelector('[data-bc-errors]');
		if (box && !box.hidden) {
			summarize(countInvalid());
		}
	});
	form.addEventListener('change', function (e) {
		if (e.target.matches && e.target.matches('input.shipping_method')) {
			clearDeliveryError();
		}
		if (e.target.id === 'bc_terms') {
			var box = e.target.closest('.bc-terms');
			if (box && e.target.checked) {
				clearError(box);
				summarize(countInvalid());
			}
		}
	});

	/* Latin digits as the visitor types in the number fields. */
	form.addEventListener('input', function (e) {
		var el = e.target;
		if (el && (el.name === 'billing_phone' || el.name === 'billing_postcode')) {
			var v = enDigits(el.value);
			if (v !== el.value) {
				el.value = v;
			}
		}
	});

	/* ---------- Views ---------- */

	function payLabel() {
		var pay = document.getElementById('payment');
		return (pay && pay.getAttribute('data-bc-cta')) || t.pay;
	}

	/* The main button (bottom bar on phones, summary card on desktop). */
	function syncCta() {
		var label = view === 'pay' ? payLabel() : t.next;
		$$('[data-bc-next]').forEach(function (b) {
			if (b.textContent !== label) {
				b.textContent = label;
			}
		});
	}

	function setView(next, push) {
		if (next === view) {
			return;
		}
		view = next;
		root.setAttribute('data-view', next);
		syncCta();
		if (push) {
			try {
				history.pushState({ bcv: next }, '', next === 'pay' ? '#pay' : location.pathname + location.search);
			} catch (e) {
				/* history not available: the arrows inside the page still work */
			}
		}
		window.scrollTo(0, 0);
		var title = root.querySelector('.bc-view-' + next + ' .bc-head__title');
		if (title) {
			title.setAttribute('tabindex', '-1');
			title.focus({ preventScroll: true });
		}
	}

	function goDetails() {
		if (history.state && history.state.bcv === 'pay') {
			history.back(); // popstate switches the view
		} else {
			setView('details', false);
		}
	}

	window.addEventListener('popstate', function () {
		setView(location.hash === '#pay' ? 'pay' : 'details', false);
	});
	if (location.hash === '#pay') {
		// A reload on the payment step starts again at step 2 (the fields are checked on the way).
		try {
			history.replaceState(null, '', location.pathname + location.search);
		} catch (e) {
			/* ignore */
		}
	}

	/* "Back to address and details": the step indicator and the header arrow of the payment step. */
	document.addEventListener(
		'click',
		function (e) {
			var link = e.target.closest('a[href="#details"]');
			if (link) {
				e.preventDefault();
				e.stopImmediatePropagation();
				goDetails();
			}
		},
		true
	);

	/* ---------- The main button ---------- */

	/* Run cb once the totals are up to date (the visitor may tap right after leaving a field). */
	function whenSettled(cb) {
		if (!updating) {
			cb();
			return;
		}
		var done = false;
		var go = function () {
			if (!done) {
				done = true;
				cb();
			}
		};
		$(document.body).one('updated_checkout', go);
		setTimeout(go, 8000);
	}

	function pay() {
		var terms = root.querySelector('[data-bc-terms]');
		var box = terms && terms.querySelector('input[type="checkbox"]');
		if (cfg.terms && box && !box.checked) {
			setError(terms, t.terms);
			reveal(terms, box);
			return;
		}
		var place = document.getElementById('place_order');
		if (!place) {
			if (BC.toast) {
				BC.toast(t.pay, { type: 'err' });
			}
			return;
		}
		hideSummary();
		setBusy(true);
		place.click(); // WooCommerce's own submit: validation on the server, then the payment gateway
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-bc-next]');
		if (!btn) {
			return;
		}
		e.preventDefault();
		if (btn.disabled) {
			return;
		}
		if (view === 'details') {
			whenSettled(function () {
				if (validateDetails()) {
					setView('pay', true);
				}
			});
		} else {
			whenSettled(pay);
		}
	});

	/* The Enter key must not place the order from step 2. */
	document.addEventListener(
		'submit',
		function (e) {
			if (e.target === form && view === 'details') {
				e.preventDefault();
				e.stopImmediatePropagation();
				whenSettled(function () {
					if (validateDetails()) {
						setView('pay', true);
					}
				});
			}
		},
		true
	);

	/* ---------- Errors from the server ---------- */

	/* WooCommerce error ids are field names; our name field stands for first + last name. */
	function rowFor(id) {
		if (id === 'billing_first_name' || id === 'billing_last_name') {
			id = 'bc_full_name';
		}
		var el = id ? document.getElementById(id) : null;
		return el ? el.closest('.form-row, .bc-terms') : null;
	}

	function showServerErrors(items) {
		var fields = 0;
		var general = [];
		var first = null;
		var needDetails = false;
		items.forEach(function (it) {
			var row = rowFor(it.id);
			if (row && it.text) {
				setError(row, it.text);
				fields++;
				first = first || row;
				if (row.closest('.bc-view-details')) {
					needDetails = true;
				}
			} else if (it.text) {
				general.push(it.text);
			}
		});
		if (needDetails && view !== 'details') {
			setView('details', false);
			try {
				history.replaceState(null, '', location.pathname + location.search);
			} catch (e) {
				/* ignore */
			}
		}
		if (fields) {
			summarize(countInvalid());
			reveal(first, first.matches('.form-row') ? inputOf(first) : null);
		} else if (general.length) {
			showSummary(general[0], general.slice(1).join(' · '));
			reveal(document.querySelector('[data-bc-errors]'), document.querySelector('[data-bc-errors]'));
		}
	}

	/* WooCommerce prints its error list at the top of the form and fires checkout_error with the HTML. */
	$(document.body).on('checkout_error', function (e, html) {
		var group = form.querySelector('.woocommerce-NoticeGroup-checkout');
		var tmp = document.createElement('div');
		tmp.innerHTML = html || (group ? group.innerHTML : '');
		var items = $$('li', tmp).map(function (li) {
			return { id: li.getAttribute('data-id') || '', text: li.textContent.replace(/\s+/g, ' ').trim() };
		});
		if (!items.length && tmp.textContent.trim()) {
			items.push({ id: '', text: tmp.textContent.replace(/\s+/g, ' ').trim() });
		}
		if (group) {
			group.remove();
		}
		$('html, body').stop(true); // WooCommerce started to scroll to its own list
		setBusy(false);
		showServerErrors(items);
	});

	/* ---------- WooCommerce updates (shipping, totals, gateways) ---------- */

	$(document.body).on('update_checkout', function () {
		updating = true;
		setBusy(true);
		$$('[data-bc="delivery"]').forEach(function (n) {
			n.classList.add('is-loading');
		});
	});
	$(document.body).on('updated_checkout', function () {
		updating = false;
		setBusy(false);
		$$('[data-bc="delivery"]').forEach(function (n) {
			n.classList.remove('is-loading');
		});
		syncCta();
	});

	/* "Price changed" notice: acknowledge once, so it does not come back. */
	document.addEventListener('click', function (e) {
		var ack = e.target.closest('[data-bc-ack]');
		if (!ack) {
			return;
		}
		var alert = ack.closest('[data-bc-price-alert]');
		if (BC.post) {
			BC.post('ack_price', {}).catch(function () {});
		}
		if (alert) {
			alert.remove();
		}
	});

	syncCta();
})(window.jQuery);