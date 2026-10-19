// „Versand für dieses Produkt berechnen": rechnet mit gemerkter Anschrift beim Laden, nach einer Mengenänderung neu,
// und merkt nur, was der Server angenommen hat.

import { beforeEach, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { element, fakeFetch, installDocument, loadPlugin } from './_support.mjs';
import fetchHtml from '../../src/Resources/app/storefront/src/rc-checkout-common/fetch-html.js';
import { requiredZip, showEstimateError } from '../../src/Resources/app/storefront/src/rc-shipping-estimate/estimate-form.js';

let stored = null;
let remembered = null;
const RcProductShippingPlugin = loadPlugin('rc-product-shipping/rc-product-shipping.plugin.js', 'RcProductShippingPlugin', {
    fetchHtml,
    requiredZip,
    showEstimateError,
    readShippingLocation: () => stored,
    rememberShippingLocation: (countryIso, zipCode) => { remembered = { countryIso, zipCode }; },
});

let http;
let quantityField;

beforeEach(() => {
    const doc = installDocument();
    quantityField = { value: '3' };
    doc.querySelector = (selector) => (selector === '[name="lineItems[p1][quantity]"]' ? quantityField : null);
    http = fakeFetch();
    stored = null;
    remembered = null;
});

function setup() {
    const details = { open: true };
    const parts = {
        '[data-rc-product-shipping-country]': element({ value: 'DE', options: [{ value: 'DE', textContent: 'Deutschland' }, { value: 'AT', textContent: 'Österreich' }], selectedIndex: 0 }),
        '[data-rc-product-shipping-zip]': element({ value: '' }),
        '[data-rc-product-shipping-submit]': element(),
        '[data-rc-product-shipping-output]': element({ querySelector: () => null }),
        '[data-rc-product-shipping-for]': element({ hidden: true }),
        '[data-rc-product-shipping-details]': details,
    };
    const plugin = Object.create(RcProductShippingPlugin.prototype);
    plugin.el = element({ hidden: true, querySelector: (selector) => parts[selector] ?? null });
    plugin.options = { url: '/rc-checkout/product-shipping-estimate/p1', productId: 'p1', errorText: 'Fehler', forText: 'für %country%, %zip%, %quantity% Stück' };
    plugin.init();

    return { plugin, parts, details };
}

describe('Beim Laden', () => {
    test('ohne gemerkte Anschrift wird nicht gerechnet, der Kasten erscheint', () => {
        const { plugin } = setup();

        assert.equal(plugin.el.hidden, false);
        assert.equal(http.calls.length, 0);
    });

    test('mit gemerkter Anschrift wird sofort gerechnet, als Rechnung ohne Klick', () => {
        stored = { countryIso: 'AT', zipCode: '1010' };
        setup();

        assert.deepEqual(http.calls[0].init.body.entries, { countryIso: 'AT', zipCode: '1010', quantity: '3', auto: '1' });
    });

    test('ein gemerktes Land, das der Kanal nicht kennt, löst keine Rechnung aus', () => {
        stored = { countryIso: 'FR', zipCode: '75001' };
        setup();

        assert.equal(http.calls.length, 0);
    });
});

describe('Ergebnis', () => {
    test('gelungen: Ergebnis, Satz „für …", Eingabe gemerkt, Kasten zugeklappt', async () => {
        const { plugin, parts, details } = setup();
        parts['[data-rc-product-shipping-zip]'].value = '44787';
        plugin._onClick();
        http.respond('<ul><li>Paket 8,93 €</li></ul>');
        await http.settle();

        assert.equal(parts['[data-rc-product-shipping-output]'].innerHTML, '<ul><li>Paket 8,93 €</li></ul>');
        assert.equal(parts['[data-rc-product-shipping-for]'].textContent, 'für Deutschland, 44787, 3 Stück');
        assert.deepEqual(remembered, { countryIso: 'DE', zipCode: '44787' });
        assert.equal(details.open, false);
    });

    test('Fehlerantwort: Fehlersatz, nichts gemerkt', async () => {
        const { plugin, parts } = setup();
        parts['[data-rc-product-shipping-zip]'].value = '44787';
        plugin._onClick();
        http.respond('<html>Too Many Requests</html>', 429);
        await http.settle();

        assert.equal(parts['[data-rc-product-shipping-output]'].children[0].textContent, 'Fehler');
        assert.equal(remembered, null);
    });
});

describe('Mengenänderung', () => {
    test('rechnet nach einer Rechnung mit der neuen Menge neu, sonst nicht', async () => {
        const { plugin, parts } = setup();
        plugin._onQuantityChange({ target: quantityField });
        assert.equal(http.calls.length, 0, 'vor der ersten Rechnung nichts');

        parts['[data-rc-product-shipping-zip]'].value = '44787';
        plugin._onClick();
        http.respond('<ul></ul>');
        await http.settle();

        quantityField.value = '5';
        plugin._onQuantityChange({ target: quantityField });

        assert.equal(http.calls.length, 2);
        assert.equal(http.calls[1].init.body.entries.quantity, '5');
    });
});
