/*
 * BC Battery - purchase flow: shared helpers (cart, checkout, pay).
 * No dependencies. Exposes window.BC = { post, queue, toast, whenIdle }.
 * Settings come from window.bcFlow (printed by inc/flow-ui.php).
 */
(function () {
	'use strict';

	var cfg = window.bcFlow || {};
	var BC = (window.BC = window.BC || {});

	/* ---------- Requests ---------- */

	/** POST to the cart endpoint. Resolves with the parsed JSON answer (also for 4xx answers that carry JSON). */
	BC.post = function (act, data) {
		var body = new URLSearchParams();
		body.set('act', act);
		body.set('_bc', cfg.nonce || '');
		Object.keys(data || {}).forEach(function (k) {
			body.set(k, data[k]);
		});
		return fetch(cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' },
			body: body
		}).then(function (res) {
			return res.json();
		});
	};

	/* WooCommerce keeps the cart in the session; two requests that change it at the same time can overwrite each other.
	   So changes run one after another. */
	var chain = Promise.resolve();
	var running = 0;
	BC.queue = function (task) {
		running++;
		var run = chain.then(task, task);
		chain = run
			.catch(function () {})
			.then(function () {
				running--;
			});
		return run;
	};
	BC.whenIdle = function () {
		return chain.then(function () {
			return running ? BC.whenIdle() : undefined;
		});
	};
	BC.isBusy = function () {
		return running > 0;
	};

	/* ---------- Toast ---------- */

	var toastTimer;
	/** BC.toast('text', { type: 'err', action: { label: 'x', run: fn }, ms: 5000 }) */
	BC.toast = function (text, opts) {
		opts = opts || {};
		var box = document.getElementById('bc-toast');
		if (!box) {
			box = document.createElement('div');
			box.id = 'bc-toast';
			box.className = 'bc-toast';
			box.setAttribute('role', 'status');
			box.setAttribute('aria-live', 'polite');
			document.body.appendChild(box);
		}
		box.className = 'bc-toast' + (opts.type ? ' bc-toast--' + opts.type : '');
		box.textContent = '';
		var span = document.createElement('span');
		span.textContent = text;
		box.appendChild(span);
		if (opts.action) {
			var b = document.createElement('button');
			b.type = 'button';
			b.textContent = opts.action.label;
			b.addEventListener('click', function () {
				box.classList.remove('is-on');
				opts.action.run();
			});
			box.appendChild(b);
		}
		// Next frame so the transition runs also when the toast is already on screen.
		requestAnimationFrame(function () {
			box.classList.add('is-on');
		});
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () {
			box.classList.remove('is-on');
		}, opts.ms || (opts.action ? 6000 : 3500));
	};

	/* ---------- Bottom sheet (native <dialog>) ---------- */

	document.addEventListener('click', function (e) {
		var open = e.target.closest('[data-bc-open]');
		if (open) {
			var dlg = document.getElementById(open.getAttribute('data-bc-open'));
			if (dlg && !dlg.open) {
				if (typeof dlg.showModal === 'function') {
					dlg.showModal();
				} else {
					dlg.setAttribute('open', '');
				}
			}
			return;
		}
		var dialog = e.target.closest('dialog.bc-sheet');
		if (e.target.closest('[data-bc-close]') && dialog) {
			dialog.close ? dialog.close() : dialog.removeAttribute('open');
		} else if (e.target.tagName === 'DIALOG' && e.target.classList.contains('bc-sheet')) {
			e.target.close(); // click on the dimmed area
		}
	});

	/* ---------- Back arrow: go back when the visitor came from this site, otherwise follow the link ---------- */

	document.addEventListener('click', function (e) {
		var a = e.target.closest('[data-bc-back]');
		if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button) {
			return;
		}
		try {
			if (history.length > 1 && document.referrer && new URL(document.referrer).origin === location.origin) {
				e.preventDefault();
				history.back();
			}
		} catch (err) {
			/* follow the link */
		}
	});
})();
