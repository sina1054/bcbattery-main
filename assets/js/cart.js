/*
 * BC Battery - cart page behaviour.
 * Every change is sent to /?wc-ajax=bc_cart (see inc/cart.php). The answer carries HTML blocks
 * ("fragments"); each one replaces the element with the same data-bc="name". Needs flow.js.
 */
(function () {
	'use strict';

	var BC = window.BC;
	var cfg = window.bcFlow || {};
	if (!BC || !cfg.ajax) {
		return;
	}
	var t = cfg.i18n || {};
	var QTY_DELAY = 350; // ms to wait for more +/- taps before sending the new quantity
	var pending = {}; // line key -> { qty, timer }

	/* ---------- Helpers ---------- */

	function $(sel, ctx) {
		return (ctx || document).querySelector(sel);
	}
	function $$(sel, ctx) {
		return Array.prototype.slice.call((ctx || document).querySelectorAll(sel));
	}
	function root() {
		return $('[data-bc-cart]');
	}
	function isEmptyView() {
		var r = root();
		return !!r && r.classList.contains('bc-cart--empty');
	}
	function toNode(html) {
		var tpl = document.createElement('template');
		tpl.innerHTML = html.trim();
		return tpl.content.firstElementChild;
	}
	function setBusy(on) {
		var r = root();
		if (r) {
			r.classList.toggle('is-busy', on);
			r.setAttribute('aria-busy', on ? 'true' : 'false');
		}
		document.body.classList.toggle('bc-busy', on);
	}

	/* ---------- Apply an answer from the server ---------- */

	function apply(res) {
		var f = res.fragments || {};
		var focus = rememberFocus();
		var scroll = {};

		if (f.root) {
			var node = toNode(f.root);
			var cur = root();
			if (cur && node) {
				cur.replaceWith(node);
			}
			$$('[data-bc="bar"]').forEach(function (el) {
				el.remove();
			});
			if (f.bar) {
				document.body.appendChild(toNode(f.bar));
			}
		} else {
			var oldTrack = $('.bc-comp__track');
			scroll.comp = oldTrack ? oldTrack.scrollLeft : 0;
			Object.keys(f).forEach(function (name) {
				var el = $('[data-bc="' + name + '"]');
				var next = toNode(f[name]);
				if (el && next) {
					el.replaceWith(next);
				}
			});
			var newTrack = $('.bc-comp__track');
			if (newTrack && scroll.comp) {
				newTrack.scrollLeft = scroll.comp;
			}
		}

		$$('[data-bc="count"]').forEach(function (el) {
			el.textContent = res.countText || el.textContent;
		});
		initComp();
		restoreFocus(focus);

		// Header mini-cart (WooCommerce cart fragments).
		if (window.jQuery) {
			window.jQuery(document.body).trigger('wc_fragment_refresh');
		}
	}

	/* The buttons are replaced with the HTML; give keyboard focus back to the same kind of button on the same line. */
	function rememberFocus() {
		var a = document.activeElement;
		var line = a && a.closest ? a.closest('.bc-line') : null;
		if (!line || !a.closest('.bc-qty')) {
			return null;
		}
		return { key: line.getAttribute('data-key') };
	}
	function restoreFocus(f) {
		if (!f) {
			return;
		}
		var btn = $('.bc-line[data-key="' + f.key + '"] .bc-qty__btn:last-child');
		if (btn) {
			btn.focus({ preventScroll: true });
		}
	}

	/* ---------- Send a change ---------- */

	/**
	 * Run one change through the queue, then show the result.
	 * opts.after(res) is called with the answer (toast, undo, ...).
	 */
	function send(act, data, opts) {
		opts = opts || {};
		setBusy(true);
		return BC.queue(function () {
			return BC.post(act, Object.assign({ view: isEmptyView() ? 'empty' : 'cart' }, data));
		})
			.then(function (res) {
				if (res.reload) {
					BC.toast(res.msg || '', { type: 'err' });
					setTimeout(function () {
						location.reload();
					}, 1200);
					return res;
				}
				apply(res);
				if (opts.after) {
					opts.after(res);
				} else if (res.msg) {
					BC.toast(res.msg, { type: res.ok ? '' : 'err' });
				}
				return res;
			})
			.catch(function () {
				BC.toast(t.error || 'Error', { type: 'err' });
				// Show the real state of the cart again.
				return BC.post('refresh').then(apply, function () {});
			})
			.then(function (res) {
				if (!BC.isBusy()) {
					setBusy(false);
				}
				return res;
			});
	}

	/* ---------- Quantity ---------- */

	function changeQty(line, target) {
		var key = line.getAttribute('data-key');
		var entry = pending[key] || (pending[key] = { qty: parseInt(line.getAttribute('data-qty'), 10) || 1, timer: 0 });
		entry.qty = Math.max(0, target(entry.qty));
		clearTimeout(entry.timer);

		var n = $('.bc-qty__n', line);
		if (n && entry.qty > 0) {
			n.textContent = entry.qty.toLocaleString('en-US'); // the page font shows Persian digits
		}
		line.classList.add('is-pending');

		var flush = function () {
			var qty = entry.qty;
			delete pending[key];
			send('qty', { key: key, qty: qty }, { after: afterRemove });
		};
		if (entry.qty === 0) {
			flush(); // removing: no reason to wait
		} else {
			entry.timer = setTimeout(flush, QTY_DELAY);
		}
	}

	function afterRemove(res) {
		if (res.undo) {
			BC.toast(t.removed || '', {
				action: {
					label: t.undo || '',
					run: function () {
						send('undo', { key: res.undo });
					}
				}
			});
		} else if (res.msg) {
			BC.toast(res.msg, { type: res.ok ? '' : 'err' });
		}
	}

	/* ---------- Complementary carousel ---------- */

	function initComp() {
		var sec = $('.bc-comp');
		var track = sec && $('.bc-comp__track', sec);
		if (!track || !track.children.length) {
			return;
		}
		var nav = $('.bc-comp__nav', sec);
		var dots = $('.bc-comp__dots', sec);
		var prev = $('[data-bc-act="comp_prev"]', sec);
		var next = $('[data-bc-act="comp_next"]', sec);
		var ticking = false;

		function update() {
			ticking = false;
			var max = track.scrollWidth - track.clientWidth;
			var pos = Math.abs(track.scrollLeft);
			var scrollable = max > 4;
			nav.hidden = !scrollable;
			dots.hidden = !scrollable;
			if (!scrollable) {
				return;
			}
			var perView = Math.max(1, Math.round(track.clientWidth / track.children[0].getBoundingClientRect().width));
			var pages = Math.ceil(track.children.length / perView);
			if (dots.children.length !== pages) {
				dots.innerHTML = '';
				for (var i = 0; i < pages; i++) {
					dots.appendChild(document.createElement('span'));
				}
			}
			var active = Math.round((pos / max) * (pages - 1));
			$$('span', dots).forEach(function (d, i) {
				d.classList.toggle('is-on', i === active);
			});
			prev.disabled = pos < 4;
			next.disabled = pos > max - 4;
		}
		track.addEventListener(
			'scroll',
			function () {
				if (!ticking) {
					ticking = true;
					requestAnimationFrame(update);
				}
			},
			{ passive: true }
		);
		update();
	}

	function scrollComp(dir) {
		var track = $('.bc-comp__track');
		if (!track) {
			return;
		}
		var rtl = getComputedStyle(track).direction === 'rtl' ? -1 : 1;
		track.scrollBy({ left: dir * rtl * track.clientWidth * 0.9, behavior: 'smooth' });
	}

	function pickVariant(chip) {
		var card = chip.closest('.bc-comp__card');
		$$('.bc-chip', card).forEach(function (c) {
			c.setAttribute('aria-pressed', c === chip ? 'true' : 'false');
		});
		card.setAttribute('data-vid', chip.getAttribute('data-vid'));
		var price = $('.bc-comp__price .bc-money__n', card);
		if (price) {
			price.textContent = chip.getAttribute('data-price');
		}
		var name = $('[data-bc-varname]', card);
		if (name) {
			name.textContent = chip.textContent;
		}
		// In the cart already: the stepper (on that variant's cart line) instead of the add button.
		var btn = $('.bc-comp__add', card);
		var step = $('[data-bc-line]', card);
		var key = chip.getAttribute('data-key') || '';
		var qty = parseInt(chip.getAttribute('data-qty'), 10) || 1;
		var max = parseInt(chip.getAttribute('data-max'), 10);
		if (btn) {
			btn.hidden = !!key;
		}
		if (step) {
			step.hidden = !key;
			step.setAttribute('data-key', key);
			step.setAttribute('data-qty', String(qty));
			step.classList.toggle('is-one', qty <= 1);
			$('.bc-qty__n', step).textContent = qty.toLocaleString('en-US');
			$('[data-bc-act="inc"]', step).disabled = max > -1 && qty >= max;
		}
	}

	function openPicker(card) {
		var vs;
		try {
			vs = JSON.parse(card.getAttribute('data-variants'));
		} catch (e) {
			vs = null;
		}
		if (!vs) {
			return;
		}
		var img = $('img', card);
		var src = img ? img.getAttribute('data-src') || img.currentSrc || img.src : '';
		var name = $('.bc-comp__name', card);
		window.BCVP.open({
			name: name ? name.textContent : '',
			img: src && src.indexOf('data:') !== 0 ? src : '',
			label: card.getAttribute('data-vlabel'),
			variants: vs,
			selected: card.getAttribute('data-vid'),
			onConfirm: function (vid) {
				return send(
					'add',
					{ product_id: card.getAttribute('data-pid'), variation_id: vid },
					{
						after: function (res) {
							BC.toast(res.ok ? 'به سبد خرید اضافه شد' : res.msg, { type: res.ok ? '' : 'err' });
						}
					}
				).then(function (res) {
					if (!res || !res.ok) {
						throw new Error('add');
					}
				});
			}
		});
	}

	/* ---------- Coupon ---------- */

	function toggleCoupon(open) {
		var box = $('[data-bc="coupon"]');
		var form = box && $('.bc-coupon__form', box);
		var toggle = box && $('.bc-coupon__toggle', box);
		if (!form) {
			return;
		}
		var show = typeof open === 'boolean' ? open : form.hidden;
		form.hidden = !show;
		toggle.hidden = show;
		toggle.setAttribute('aria-expanded', show ? 'true' : 'false');
		if (show) {
			$('input', form).focus();
		} else {
			toggle.focus();
		}
	}

	function submitCoupon(form) {
		var input = $('input[name="code"]', form);
		var msg = $('.bc-coupon__msg', form);
		var btn = $('button[type="submit"]', form);
		msg.hidden = true;
		input.removeAttribute('aria-invalid');
		if (!input.value.trim()) {
			msg.textContent = 'کد تخفیف را وارد کنید.';
			msg.hidden = false;
			input.setAttribute('aria-invalid', 'true');
			input.focus();
			return;
		}
		btn.disabled = true;
		send('coupon_add', { code: input.value.trim() }, {
			after: function (res) {
				if (res.ok) {
					BC.toast('کد تخفیف اعمال شد');
					return;
				}
				// The form was not replaced: it is still on screen, show the reason next to the field.
				var f = $('.bc-coupon__form');
				var m = $('.bc-coupon__msg', f);
				m.textContent = res.msg;
				m.hidden = false;
				$('input', f).setAttribute('aria-invalid', 'true');
				$('input', f).focus();
			}
		}).then(function () {
			btn.disabled = false;
		});
	}

	/* ---------- Events ---------- */

	document.addEventListener('click', function (e) {
		var el = e.target.closest('[data-bc-act]');
		if (!el || el.disabled || el.getAttribute('aria-disabled') === 'true') {
			return;
		}
		var line = el.closest('.bc-line, [data-bc-line]'); // [data-bc-line]: the small stepper on a complementary card
		var card = el.closest('.bc-comp__card');

		switch (el.getAttribute('data-bc-act')) {
			case 'inc':
				changeQty(line, function (q) {
					return q + 1;
				});
				break;
			case 'dec':
				changeQty(line, function (q) {
					return q - 1;
				});
				break;
			case 'remove':
				changeQty(line, function () {
					return 0;
				});
				break;
			case 'set':
				changeQty(line, function () {
					return parseInt(el.getAttribute('data-qty'), 10) || 1;
				});
				break;
			case 'ack_price':
				send('ack_price');
				break;
			case 'comp_prev':
				scrollComp(-1);
				break;
			case 'comp_next':
				scrollComp(1);
				break;
			case 'comp_var':
				pickVariant(el);
				break;
			case 'comp_add':
				if (card && card.hasAttribute('data-variants') && window.BCVP) {
					openPicker(card); // options are chosen in the "انتخاب تنوع" popup (variant-picker.js)
					break;
				}
				el.classList.add('is-loading');
				el.disabled = true;
				send('add', { product_id: card.getAttribute('data-pid'), variation_id: card.getAttribute('data-vid') || 0 }, {
					after: function (res) {
						BC.toast(res.ok ? 'به سبد خرید اضافه شد' : res.msg, { type: res.ok ? '' : 'err' });
						if (!res.ok) {
							el.classList.remove('is-loading');
							el.disabled = false;
						}
					}
				});
				break;
			case 'coupon_toggle':
				toggleCoupon();
				break;
			case 'coupon_remove':
				send('coupon_remove', { key: el.getAttribute('data-code') });
				break;
		}
	});

	document.addEventListener('submit', function (e) {
		var form = e.target.closest('[data-bc-form="coupon_add"]');
		if (form) {
			e.preventDefault();
			submitCoupon(form);
		}
	});

	/* Do not leave for the checkout while a change is still on its way: the totals would be out of date. */
	document.addEventListener('click', function (e) {
		var cta = e.target.closest('a.bc-bar__cta, a.bc-summary__cta');
		if (cta && (BC.isBusy() || Object.keys(pending).length)) {
			e.preventDefault();
			var href = cta.href;
			var wait = function () {
				if (Object.keys(pending).length) {
					return setTimeout(wait, 100);
				}
				BC.whenIdle().then(function () {
					location.href = href;
				});
			};
			wait();
		}
	});

	/* Back from the checkout (browser cache): show the real cart. */
	window.addEventListener('pageshow', function (e) {
		if (e.persisted) {
			send('refresh');
		}
	});

	initComp();
})();
