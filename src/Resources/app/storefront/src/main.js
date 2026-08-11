import RcShippingEstimatePlugin from './rc-shipping-estimate/rc-shipping-estimate.plugin';

// Der Warenkorb wird nach Mengenänderungen per AJAX ersetzt. Shopware bindet
// registrierte Plugins beim Neuaufbau erneut an; die Registrierung hier genügt
// deshalb, ein eigener Aufruf von initializePlugins() ist nur nach dem Austausch
// der Ergebnisliste nötig (siehe Plugin).
window.PluginManager.register(
    'RcShippingEstimate',
    RcShippingEstimatePlugin,
    '[data-rc-shipping-estimate]',
);
