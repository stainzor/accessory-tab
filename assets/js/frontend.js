/**
 * Accessory Tab — "Visa alla" toggle + qty selector + add-to-cart sync.
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
		var sections = document.querySelectorAll('.sijab-accessories-section');
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

	// ── Quantity +/- buttons ──
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.sijab-qty-minus, .sijab-qty-plus');
		if (!btn) return;

		var qtyWrap = btn.closest('.sijab-acc-card__qty');
		if (!qtyWrap) return;

		var input = qtyWrap.querySelector('.sijab-qty-input');
		if (!input) return;

		var val = parseInt(input.value, 10) || 1;

		if (btn.classList.contains('sijab-qty-minus')) {
			val = Math.max(1, val - 1);
		} else {
			val = val + 1;
		}

		input.value = val;

		// Sync quantity to the add-to-cart button
		var row = btn.closest('.sijab-acc-card__qty-row');
		var atcBtn = row ? row.querySelector('.sijab-acc-atc') : null;
		if (atcBtn) {
			atcBtn.setAttribute('data-quantity', val);
		}
	});

	// Sync on manual input change.
	document.addEventListener('change', function (e) {
		if (!e.target.classList.contains('sijab-qty-input')) return;

		var val = Math.max(1, parseInt(e.target.value, 10) || 1);
		e.target.value = val;

		var row = e.target.closest('.sijab-acc-card__qty-row');
		var atcBtn = row ? row.querySelector('.sijab-acc-atc') : null;
		if (atcBtn) {
			atcBtn.setAttribute('data-quantity', val);
		}
	});

	// Init mobile toggle when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initMobileToggle);
	} else {
		initMobileToggle();
	}
})();
