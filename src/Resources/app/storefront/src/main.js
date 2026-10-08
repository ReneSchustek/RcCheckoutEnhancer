import RcPickupAcknowledgementPlugin from './rc-pickup-acknowledgement/rc-pickup-acknowledgement.plugin';
import RcShippingEstimatePlugin from './rc-shipping-estimate/rc-shipping-estimate.plugin';
import RcFreightHintPlugin from './rc-freight-hint/rc-freight-hint.plugin';
import RcCheckoutPathPlugin from './rc-checkout-path/rc-checkout-path.plugin';

// Der Warenkorb wird nach Mengenänderungen per AJAX ersetzt. Shopware bindet
// registrierte Plugins beim Neuaufbau erneut an; die Registrierung hier genügt
// deshalb, ein eigener Aufruf von initializePlugins() ist nur nach dem Austausch
// der Ergebnisliste nötig (siehe Plugin).
window.PluginManager.register(
    'RcShippingEstimate',
    RcShippingEstimatePlugin,
    '[data-rc-shipping-estimate]',
);

// Der Abhol-Dialog. Der Server entscheidet, ob das Markup da ist; ist es nicht da, bindet der
// PluginManager hier nichts an.
window.PluginManager.register(
    'RcPickupAcknowledgement',
    RcPickupAcknowledgementPlugin,
    '[data-rc-pickup-acknowledgement]',
);

// Der Speditionshinweis der Produktseite. Der Platz steht nur da, wenn der Hinweis
// eingeschaltet ist; sonst bindet der PluginManager hier nichts an.
window.PluginManager.register(
    'RcFreightHint',
    RcFreightHintPlugin,
    '[data-rc-freight-hint]',
);

// Die drei Wege oben im einseitigen Checkout. Das Markup steht nur in dieser Darstellung.
window.PluginManager.register(
    'RcCheckoutPath',
    RcCheckoutPathPlugin,
    '[data-rc-checkout-path]',
);
