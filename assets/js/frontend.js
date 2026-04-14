/**
 * Accessory Tab — "Visa alla" toggle + qty selector + add-to-cart sync + stats tracking + checklist total.
 * v2.30.6
 */
(function () {
	'use strict';

	// ── "Visa alla tillbehör" toggle (desktop) ──
	document.addEventListener('click', function (e) {
		var link = e.target.closest('.sijab-show-all-link');
		if (!link) return;

		e.preventDefault();
		var section = link.closest('.sijab-accessories-section');
		if (!section) return;

		var isExpanded = section.classList.toggle('sijab-show-all');

		if (isExpanded) {
			link.textContent = link.getAttribute('data-hide');
		} else {
			link.textContent = link.getAttribute('data-show');
		}
	});

	// ── Mobile: inject "Visa fler" link if more than 1 accessory ──
	function initMobileToggle() {
		var sections = document.querySelectorAll('.sijab-accessories-section:not(.sijab-bundle-section)');
		sections.forEach(function (section) {
			var items = section.querySelectorAll('.sijab-acc-item');
			if (items.length <= 1) return;
			// Don't add twice
			if (section.querySelector('.sijab-mobile-toggle')) return;

			var total = items.length;
			var wrapper = document.createElement('div');
			wrapper.className = 'sijab-mobile-toggle';
			var link = document.createElement('a');
			link.href = '#';
			link.textContent = 'Visa fler tillbehör (' + total + ')';
			link.setAttribute('data-show', 'Visa fler tillbehör (' + total + ')');
			link.setAttribute('data-hide', 'Visa färre');
			wrapper.appendChild(link);

			var list = section.querySelector('.sijab-accessories-section__list');
			if (list) {
				list.after(wrapper);
			}
		});
	}

	// ── Mobile toggle click ──
	document.addEventListener('click', function (e) {
		var link = e.target.closest('.sijab-mobile-toggle a');
		if (!link) return;

		e.preventDefault();
		var section = link.closest('.sijab-accessories-section');
		if (!section) return;

		var isExpanded = section.classList.toggle('sijab-show-all-mobile');

		if (isExpanded) {
			link.textContent = link.getAttribute('data-hide');
		} else {
			link.textContent = link.getAttribute('data-show');
		}
	});

	// ── Quantity +/- buttons (accessories + bundles) ──
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.sijab-qty-minus, .sijab-qty-plus');
		if (!btn) return;

		var qtyWrap = btn.closest('.sijab-acc-card__qty');
		if (!qtyWrap) return;

		var input = qtyWrap.querySelector('.sijab-qty-input');
		if (!input) return;

		var val = parseInt(input.value, 10) || 1;
		var minVal = parseInt(input.getAttribute('min'), 10) || 1;
		var maxVal = parseInt(input.getAttribute('max'), 10) || 0; // 0 = no limit

		if (btn.classList.contains('sijab-qty-minus')) {
			val = Math.max(minVal, val - 1);
		} else {
			val = val + 1;
			if (maxVal > 0) val = Math.min(maxVal, val);
		}

		input.value = val;

		// Sync quantity to the add-to-cart button (accessories)
		var row = btn.closest('.sijab-acc-card__qty-row');
		var atcBtn = row ? row.querySelector('.sijab-acc-atc') : null;
		if (atcBtn) {
			atcBtn.setAttribute('data-quantity', val);
		}
	});

	// Sync on manual input change.
	document.addEventListener('change', function (e) {
		if (!e.target.classList.contains('sijab-qty-input')) return;

		var minVal = parseInt(e.target.getAttribute('min'), 10) || 1;
		var maxVal = parseInt(e.target.getAttribute('max'), 10) || 0;
		var val = Math.max(minVal, parseInt(e.target.value, 10) || minVal);
		if (maxVal > 0) val = Math.min(maxVal, val);
		e.target.value = val;

		var row = e.target.closest('.sijab-acc-card__qty-row');
		var atcBtn = row ? row.querySelector('.sijab-acc-atc') : null;
		if (atcBtn) {
			atcBtn.setAttribute('data-quantity', val);
		}
	});

	// ── Variable product: update price + stock on variant select ──
	document.addEventListener('change', function (e) {
		var select = e.target.closest('.sijab-var-select');
		if (!select) return;

		var card     = select.closest('.sijab-acc-card');
		var selected = select.options[select.selectedIndex];
		var varId    = select.value;

		// Price
		var priceHtml = selected.getAttribute('data-price-html');
		var priceEl   = card.querySelector('.sijab-acc-card__price');
		if (priceEl && priceHtml) priceEl.innerHTML = priceHtml;

		// Stock badge
		var stockStatus = selected.getAttribute('data-stock') || '';
		var stockLabel  = selected.getAttribute('data-stock-label') || '';
		var stockEl     = card.querySelector('.sijab-acc-card__stock');
		if (stockEl) {
			stockEl.className = 'sijab-acc-card__stock' + (stockStatus ? ' sijab-acc-card__stock--' + stockStatus : '');
			stockEl.textContent = stockLabel;
		}

		// SKU
		var skuVal = selected.getAttribute('data-sku') || '';
		var skuEl  = card.querySelector('.sijab-acc-card__sku');
		if (skuEl) {
			skuEl.textContent = skuVal ? 'Art.nr: ' + skuVal : '';
		}

		// Enable/disable add-to-cart button
		var btn        = card.querySelector('.sijab-var-atc-btn');
		var purchasable = varId && selected.getAttribute('data-purchasable') === '1';
		if (btn) btn.disabled = !purchasable;
	});

	// ── Variable product: AJAX add to cart (uses custom handler) ──
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.sijab-var-atc-btn');
		if (!btn || btn.disabled) return;
		e.preventDefault();

		var card     = btn.closest('.sijab-acc-card');
		var select   = card ? card.querySelector('.sijab-var-select') : null;
		if (!select || !select.value) return;

		var varId    = select.value;
		var parentId = btn.getAttribute('data-parent-id');
		var selected = select.options[select.selectedIndex];
		var attrs    = {};
		try { attrs = JSON.parse(selected.getAttribute('data-attributes') || '{}'); } catch (err) {}

		// Get quantity from the qty input (if present)
		var qtyInput = card.querySelector('.sijab-var-qty-input');
		var qty = qtyInput ? Math.max(1, parseInt(qtyInput.value, 10) || 1) : 1;

		btn.disabled    = true;
		var origText    = btn.textContent;
		btn.textContent = '…';

		// Use our custom AJAX handler for reliable variable product add-to-cart.
		var ajaxUrl = (typeof sijabAccStats !== 'undefined') ? sijabAccStats.ajax_url : '/wp-admin/admin-ajax.php';

		var body = new FormData();
		body.append('action',       'sijab_add_to_cart');
		body.append('product_id',   parentId);
		body.append('variation_id', varId);
		body.append('quantity',     qty);
		Object.keys(attrs).forEach(function (key) { body.append(key, attrs[key]); });

		// Tag for order tracking.
		if (typeof sijabAccStats !== 'undefined' && sijabAccStats.parent_id) {
			body.append('sijab_acc_parent', sijabAccStats.parent_id);
		}

		fetch(ajaxUrl, { method: 'POST', body: body })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				btn.disabled    = false;
				btn.textContent = origText;
				if (res && res.success) {
					// Trigger WooCommerce cart update.
					if (res.data && res.data.fragments && typeof jQuery !== 'undefined') {
						jQuery(document.body).trigger('added_to_cart', [res.data.fragments, res.data.cart_hash, jQuery(btn)]);
					} else if (typeof jQuery !== 'undefined') {
						jQuery(document.body).trigger('wc_fragment_refresh');
					}
					// Visual feedback
					btn.textContent = '✓';
					setTimeout(function() { btn.textContent = origText; }, 1500);
				} else {
					// Show error message briefly
					var msg = (res && res.data && res.data.message) ? res.data.message : 'Fel';
					btn.textContent = msg;
					btn.style.fontSize = '11px';
					setTimeout(function() {
						btn.textContent = origText;
						btn.style.fontSize = '';
					}, 3000);
				}
			})
			.catch(function () {
				btn.disabled    = false;
				btn.textContent = origText;
			});
	});

	// ── Statistics tracking ──
	function getAccessoryId(el) {
		var card = el.closest('.sijab-acc-card');
		return card ? card.getAttribute('data-accessory-id') : null;
	}

	function trackEvent(accessoryId, eventType) {
		if (typeof sijabAccStats === 'undefined' || !accessoryId) return;
		var data = 'action=sijab_acc_track'
			+ '&parent_id=' + encodeURIComponent(sijabAccStats.parent_id)
			+ '&accessory_id=' + encodeURIComponent(accessoryId)
			+ '&event_type=' + encodeURIComponent(eventType);
		if (navigator.sendBeacon) {
			navigator.sendBeacon(
				sijabAccStats.ajax_url,
				new Blob([data], { type: 'application/x-www-form-urlencoded' })
			);
		}
	}

	// Track: "Lägg till" (simple product add to cart).
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.sijab-acc-atc');
		if (!btn) return;
		trackEvent(getAccessoryId(btn), 'add_to_cart');
	});

	// Track: "Lägg till" (variable product add to cart).
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.sijab-var-atc-btn');
		if (!btn || btn.disabled) return;
		trackEvent(getAccessoryId(btn), 'add_to_cart');
	});

	// Track: "Visa produkt" button click.
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.sijab-acc-atc-btn:not(.sijab-acc-atc):not(.sijab-var-atc-btn)');
		if (!btn) return;
		trackEvent(getAccessoryId(btn), 'view_product');
	});

	// Track: product name or image click.
	document.addEventListener('click', function (e) {
		var link = e.target.closest('.sijab-acc-card__name, .sijab-acc-card__image');
		if (!link) return;
		trackEvent(getAccessoryId(link), 'product_click');
	});

	// ── Checklist: add checked accessories when main add-to-cart is submitted ──
	// Returns a Promise that resolves when all checked accessories have been added.
	function addCheckedAccessories() {
		var checkboxes = document.querySelectorAll('.sijab-checklist__input:checked');
		if (!checkboxes.length) return Promise.resolve();

		var ajaxUrl = (typeof sijabAccStats !== 'undefined') ? sijabAccStats.ajax_url : '/wp-admin/admin-ajax.php';
		var parentId = (typeof sijabAccStats !== 'undefined') ? sijabAccStats.parent_id : '';

		var promises = [];
		checkboxes.forEach(function (cb) {
			var productId = cb.getAttribute('data-product_id');
			var body = new FormData();
			body.append('action', 'sijab_add_to_cart');
			body.append('product_id', productId);
			body.append('quantity', '1');
			if (parentId) body.append('sijab_acc_parent', parentId);

			promises.push(fetch(ajaxUrl, { method: 'POST', body: body }));
			trackEvent(productId, 'add_to_cart');
		});

		return Promise.all(promises);
	}

	// Hook into the WooCommerce add-to-cart form submit.
	// Prevent default, add accessories first, then re-submit the form.
	var sijabSubmitting = false;
	document.addEventListener('submit', function (e) {
		var form = e.target.closest('form.cart');
		if (!form) return;
		if (sijabSubmitting) return; // Already re-submitting after accessories added.

		var checked = document.querySelectorAll('.sijab-checklist__input:checked');
		if (!checked.length) return; // No accessories checked, let form submit normally.

		e.preventDefault();

		// Disable button to prevent double-clicks.
		var btn = form.querySelector('[type="submit"]');
		if (btn) {
			btn.disabled = true;
			btn.style.opacity = '0.6';
		}

		addCheckedAccessories().then(function () {
			sijabSubmitting = true;
			form.submit();
		}).catch(function () {
			// If accessories fail, still submit the main product.
			sijabSubmitting = true;
			form.submit();
		});
	});

	// ── Checklist total: live-updated sum of main product (× qty) + checked accessories ──
	function formatPrice(amount, totalBox) {
		var decimals = parseInt(totalBox.getAttribute('data-decimals'), 10);
		if (isNaN(decimals)) decimals = 2;
		var decSep  = totalBox.getAttribute('data-dec-sep') || ',';
		var thouSep = totalBox.getAttribute('data-thou-sep') || ' ';
		var currency = totalBox.getAttribute('data-currency') || 'kr';

		var fixed = (Math.round(amount * Math.pow(10, decimals)) / Math.pow(10, decimals)).toFixed(decimals);
		var parts = fixed.split('.');
		// Insert thousands separator.
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thouSep);
		var numStr = parts.length === 2 ? parts[0] + decSep + parts[1] : parts[0];
		// Match WooCommerce wc_price() output: <span class="woocommerce-Price-amount amount"><bdi>NN,NN&nbsp;<span class="woocommerce-Price-currencySymbol">kr</span></bdi></span>
		return '<span class="woocommerce-Price-amount amount"><bdi>' + numStr + '&nbsp;<span class="woocommerce-Price-currencySymbol">' + currency + '</span></bdi></span>';
	}

	function getMainQty() {
		var qtyInput = document.querySelector('form.cart input.qty, form.cart input[name="quantity"]');
		if (!qtyInput) return 1;
		var val = parseInt(qtyInput.value, 10);
		return (isNaN(val) || val < 1) ? 1 : val;
	}

	// Detect current tax mode by comparing visible main product price against excl/incl values.
	function detectTaxMode(totalBox) {
		var excl = parseFloat(totalBox.getAttribute('data-main-price-excl')) || 0;
		var incl = parseFloat(totalBox.getAttribute('data-main-price-incl')) || 0;
		if (excl === incl) return totalBox.getAttribute('data-tax-display') || 'excl';

		// Read the visible main product price on the single-product page.
		var priceEl = document.querySelector('.product .summary .price .woocommerce-Price-amount, .product .summary p.price .woocommerce-Price-amount, .product-info .price .woocommerce-Price-amount');
		if (!priceEl) return totalBox.getAttribute('data-tax-display') || 'excl';

		var txt = priceEl.textContent.replace(/[^\d,.\-]/g, '').replace(/\s/g, '');
		// Handle Swedish format: "50 000,00" — remove thousand seps then normalise decimal comma to dot.
		var num = parseFloat(txt.replace(/\./g, '').replace(',', '.'));
		if (isNaN(num)) return totalBox.getAttribute('data-tax-display') || 'excl';

		// Pick whichever is closer.
		return Math.abs(num - incl) < Math.abs(num - excl) ? 'incl' : 'excl';
	}

	function updateChecklistTotal() {
		var totalBox = document.querySelector('.sijab-checklist__total');
		if (!totalBox) return;

		var mode      = detectTaxMode(totalBox);
		var mainAttr  = mode === 'incl' ? 'data-main-price-incl' : 'data-main-price-excl';
		var mainPrice = parseFloat(totalBox.getAttribute(mainAttr)) || 0;

		var qty = getMainQty();
		var sum = mainPrice * qty;

		var priceAttr = mode === 'incl' ? 'data-price-incl' : 'data-price-excl';
		var checked = document.querySelectorAll('.sijab-checklist__input:checked');
		checked.forEach(function (cb) {
			var p = parseFloat(cb.getAttribute(priceAttr));
			// Fallback to legacy single attribute.
			if (isNaN(p)) p = parseFloat(cb.getAttribute('data-price')) || 0;
			sum += p;
		});

		var valueEl  = totalBox.querySelector('.sijab-checklist__total-value');
		var suffixEl = totalBox.querySelector('.sijab-checklist__total-suffix');
		if (valueEl) valueEl.innerHTML = formatPrice(sum, totalBox);
		if (suffixEl) {
			var lbl = mode === 'incl' ? totalBox.getAttribute('data-label-incl') : totalBox.getAttribute('data-label-excl');
			suffixEl.textContent = lbl || '';
		}
	}

	// Watch the main product price for changes (tax toggle flip) and recalculate.
	function observeMainPrice() {
		var priceWrap = document.querySelector('.product .summary .price, .product .summary p.price, .product-info .price');
		if (!priceWrap || typeof MutationObserver === 'undefined') return;
		var obs = new MutationObserver(function () { updateChecklistTotal(); });
		obs.observe(priceWrap, { childList: true, subtree: true, characterData: true });
	}

	// Update on checkbox change.
	document.addEventListener('change', function (e) {
		if (e.target && e.target.classList && e.target.classList.contains('sijab-checklist__input')) {
			updateChecklistTotal();
		}
	});

	// Update on main product qty change.
	document.addEventListener('input', function (e) {
		if (e.target && e.target.matches && e.target.matches('form.cart input.qty, form.cart input[name="quantity"]')) {
			updateChecklistTotal();
		}
	});
	document.addEventListener('change', function (e) {
		if (e.target && e.target.matches && e.target.matches('form.cart input.qty, form.cart input[name="quantity"]')) {
			updateChecklistTotal();
		}
	});

	// Init mobile toggle when DOM is ready
	function initAll() {
		initMobileToggle();
		updateChecklistTotal();
		observeMainPrice();
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}
})();
