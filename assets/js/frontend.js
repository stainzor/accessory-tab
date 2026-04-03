/**
 * Accessory Tab — "Visa alla" toggle + qty selector + add-to-cart sync + stats tracking.
 * v2.22.0
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

	// Init mobile toggle when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initMobileToggle);
	} else {
		initMobileToggle();
	}
})();
