/*
 * BC Battery - "انتخاب تنوع" popup for product cards (complementary / related / cart suggestions).
 * Cards no longer carry option chips: the add button opens this popup, the visitor picks an option here and adds.
 * No dependencies. Used by assets/js/pdp.js and assets/js/cart.js through window.BCVP.open().
 *
 * BCVP.open({
 *   name: 'product name', img: 'image url', label: 'رنگ',
 *   variants: [{ id: 123, label: 'مشکی', price: '۷٬۸۰۹٬۰۰۰' }], selected: 123,
 *   onConfirm: function (variationId) { return promise }   // resolve: close, reject: stay open
 * })
 */
(function () {
	'use strict';

	var dlg = null;
	var state = null;

	function h(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) {
			n.className = cls;
		}
		if (text != null) {
			n.textContent = text;
		}
		return n;
	}

	var ICON_CLOSE = '<svg aria-hidden="true" focusable="false" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
	var ICON_CHECK = '<svg aria-hidden="true" focusable="false" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5 9-10"/></svg>';

	function build() {
		dlg = h('dialog', 'bcvp');
		dlg.setAttribute('dir', 'rtl');
		dlg.setAttribute('aria-labelledby', 'bcvp-title');
		dlg.innerHTML =
			'<div class="bcvp__box">' +
			'<header class="bcvp__head"><b id="bcvp-title">انتخاب تنوع</b>' +
			'<button type="button" class="bcvp__x" data-bcvp-close aria-label="بستن">' + ICON_CLOSE + '</button></header>' +
			'<div class="bcvp__body">' +
			'<p class="bcvp__hint">اول گزینه‌ی مورد نظرت را انتخاب کن.</p>' +
			'<div class="bcvp__label"><span data-bcvp="label"></span>: <b data-bcvp="chosen"></b></div>' +
			'<div class="bcvp__opts" role="group" data-bcvp="opts"></div>' +
			'<div class="bcvp__prod"><span class="bcvp__img" data-bcvp="img"></span>' +
			'<span class="bcvp__info"><span class="bcvp__name" data-bcvp="name"></span>' +
			'<b class="bcvp__price"><span data-bcvp="price"></span> <small>تومان</small></b></span></div>' +
			'</div>' +
			'<footer class="bcvp__foot"><button type="button" class="bcvp__add" data-bcvp="add">افزودن به سبد خرید</button></footer>' +
			'</div>';
		document.body.appendChild(dlg);

		dlg.addEventListener('click', function (e) {
			if (e.target === dlg || e.target.closest('[data-bcvp-close]')) {
				close();
				return;
			}
			var opt = e.target.closest('.bcvp__opt');
			if (opt) {
				pick(parseInt(opt.getAttribute('data-vid'), 10));
				return;
			}
			if (e.target.closest('[data-bcvp="add"]')) {
				confirm();
			}
		});
		dlg.addEventListener('close', function () {
			document.documentElement.classList.remove('bcvp-open');
			state = null;
		});
	}

	function q(name) {
		return dlg.querySelector('[data-bcvp="' + name + '"]');
	}

	function pick(vid) {
		if (!state || state.busy) {
			return;
		}
		var v = null;
		state.variants.forEach(function (x) {
			if (x.id === vid) {
				v = x;
			}
		});
		if (!v) {
			return;
		}
		state.selected = vid;
		Array.prototype.forEach.call(dlg.querySelectorAll('.bcvp__opt'), function (b) {
			var on = parseInt(b.getAttribute('data-vid'), 10) === vid;
			b.classList.toggle('is-on', on);
			b.setAttribute('aria-pressed', on ? 'true' : 'false');
		});
		q('chosen').textContent = v.label;
		q('price').textContent = v.price;
		q('add').disabled = false;
	}

	function confirm() {
		if (!state || state.busy || !state.selected) {
			return;
		}
		var btn = q('add');
		state.busy = true;
		btn.classList.add('is-loading');
		btn.disabled = true;
		var done = function () {
			if (state) {
				state.busy = false;
			}
			btn.classList.remove('is-loading');
			btn.disabled = false;
		};
		Promise.resolve()
			.then(function () {
				return state.onConfirm(state.selected);
			})
			.then(function () {
				done();
				close();
			}, done);
	}

	function close() {
		if (dlg && dlg.open) {
			dlg.close();
		}
	}

	function open(opts) {
		if (!dlg) {
			build();
		}
		var variants = (opts.variants || []).map(function (v) {
			return { id: parseInt(v.id, 10), label: String(v.label), price: String(v.price) };
		});
		state = { variants: variants, selected: 0, busy: false, onConfirm: opts.onConfirm || function () {} };

		q('label').textContent = opts.label || 'گزینه';
		q('opts').setAttribute('aria-label', opts.label || 'گزینه');
		q('name').textContent = opts.name || '';
		var img = q('img');
		img.innerHTML = '';
		if (opts.img) {
			var i = h('img');
			i.src = opts.img;
			i.alt = '';
			i.decoding = 'async';
			img.appendChild(i);
		}
		var box = q('opts');
		box.innerHTML = '';
		variants.forEach(function (v) {
			var b = h('button', 'bcvp__opt');
			b.type = 'button';
			b.setAttribute('data-vid', String(v.id));
			b.setAttribute('aria-pressed', 'false');
			b.innerHTML = '<span class="bcvp__tick">' + ICON_CHECK + '</span>';
			b.appendChild(h('span', 'bcvp__opt-t', v.label));
			box.appendChild(b);
		});
		q('chosen').textContent = '';
		q('price').textContent = variants.length ? variants[0].price : '';
		q('add').disabled = true;

		document.documentElement.classList.add('bcvp-open');
		dlg.showModal();
		var pre = parseInt(opts.selected, 10);
		if (pre) {
			pick(pre);
		} else if (1 === variants.length) {
			pick(variants[0].id);
		}
		var first = dlg.querySelector('.bcvp__opt.is-on') || dlg.querySelector('.bcvp__opt');
		if (first) {
			first.focus({ preventScroll: true });
		}
	}

	window.BCVP = { open: open, close: close };
})();

/*
 * Product lists (shop, categories, archives, home page carousels): a variable product's button reads
 * "افزودن به سبد خرید" and opens the popup; the chosen option goes into the cart through WooCommerce's
 * own AJAX add, and the usual "added_to_cart" event lets Woodmart refresh and open its mini cart.
 */
(function () {
	'use strict';

	function ajaxUrl() {
		var p = window.wc_add_to_cart_params;
		return p && p.wc_ajax_url ? p.wc_ajax_url.toString().replace('%%endpoint%%', 'add_to_cart') : '/?wc-ajax=add_to_cart';
	}

	function addVariation(vid, btn) {
		var body = new URLSearchParams();
		body.append('product_id', String(vid));
		body.append('quantity', '1');
		return fetch(ajaxUrl(), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		})
			.then(function (r) {
				return r.json();
			})
			.then(function (res) {
				if (!res || res.error) {
					var url = (res && res.product_url) || btn.getAttribute('href');
					if (url) {
						location.href = url;
					}
					throw new Error('add');
				}
				if (window.jQuery) {
					window.jQuery(document.body).trigger('added_to_cart', [res.fragments, res.cart_hash, window.jQuery(btn)]);
				}
				return res;
			});
	}

	document.addEventListener(
		'click',
		function (e) {
			var btn = e.target.closest ? e.target.closest('a.bc-vp-loop[data-variants]') : null;
			if (!btn || !window.BCVP || e.ctrlKey || e.metaKey || e.shiftKey || 1 < e.button) {
				return;
			}
			var vs;
			try {
				vs = JSON.parse(btn.getAttribute('data-variants'));
			} catch (err) {
				vs = null;
			}
			if (!vs || !vs.length) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			e.stopImmediatePropagation();
			var card = btn.closest('.wd-product, .product-grid-item, .product') || btn.parentNode;
			var img = card.querySelector('.product-image-link img, img');
			var src = img ? img.getAttribute('data-src') || img.currentSrc || img.src : '';
			var name = card.querySelector('.wd-entities-title, .woocommerce-loop-product__title');
			window.BCVP.open({
				name: name ? name.textContent.trim() : '',
				img: src && src.indexOf('data:') !== 0 ? src : '',
				label: btn.getAttribute('data-vlabel'),
				variants: vs,
				selected: btn.getAttribute('data-vid'),
				onConfirm: function (vid) {
					return addVariation(vid, btn);
				}
			});
		},
		true
	);
})();
