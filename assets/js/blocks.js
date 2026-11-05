/* Registers each crypto payment option in the WooCommerce block checkout. No build step needed. */
(function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settingsApi = window.wc && window.wc.wcSettings;
	var el = window.wp && window.wp.element && window.wp.element.createElement;
	var decode = (window.wp && window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities) || function (s) { return s; };
	if (!registry || !settingsApi || !el) { return; }

	(window.cdcMethods || []).forEach(function (name) {
		var data = settingsApi.getSetting(name + '_data', null);
		if (!data || !data.title) { return; } // Not active on this store.

		var title = decode(data.title);
		var description = decode(data.description || '');
		var Content = function () {
			return description ? el('p', null, description) : null;
		};

		registry.registerPaymentMethod({
			name: name,
			label: el('span', null, title),
			ariaLabel: title,
			content: el(Content, null),
			edit: el(Content, null),
			canMakePayment: function () { return true; },
			supports: { features: data.supports || ['products'] }
		});
	});
})();
