// Der Versandkostenrechner im Warenkorb: Eine Fehlerantwort (Begrenzung, Serverfehler) darf nicht als Seite im Kasten
// landen, und eine abgewiesene Postleitzahl wird nicht gemerkt.

import { beforeEach, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { element, fakeFetch, installDocument, loadPlugin } from './_support.mjs';
import fetchHtml from '../../src/Resources/app/storefront/src/rc-checkout-common/fetch-html.js';
import { requiredZip, showEstimateError } from '../../src/Resources/app/storefront/src/rc-shipping-estimate/estimate-form.js';

let remembered = null;
const RcShippingEstimatePlugin = loadPlugin('rc-shipping-estimate/rc-shipping-estimate.plugin.js', 'RcShippingEstimatePlugin', {
    fetchHtml,
    requiredZip,
    showEstimateError,
    readShippingLocation: () => null,
    rememberShippingLocation: (countryIso, zipCode) => { remembered = { countryIso, zipCode }; },
});

let http;

beforeEach(() => {
    installDocument();
    globalThis.window = { PluginManager: { initializePlugins() { globalThis.window.initialized = true; } } };
    http = fakeFetch();
    remembered = null;
});

function setup(zip = '44787') {
    const parts = {
        '[data-rc-shipping-estimate-country]': element({ value: 'DE', options: [{ value: 'DE' }] }),
        '[data-rc-shipping-estimate-zip]': element({ value: zip }),
        '[data-rc-shipping-estimate-submit]': element(),
        '[data-rc-shipping-estimate-output]': element({ querySelector: () => null }),
    };

    const plugin = Object.create(RcShippingEstimatePlugin.prototype);
    plugin.el = { querySelector: (selector) => parts[selector] };
    plugin.options = { url: '/rc-checkout/shipping-estimate', errorText: 'Die Versandkosten ließen sich gerade nicht berechnen.' };
    plugin.init();

    return { plugin, zip: parts['[data-rc-shipping-estimate-zip]'], output: parts['[data-rc-shipping-estimate-output]'], button: parts['[data-rc-shipping-estimate-submit]'] };
}

describe('Anfrage des Rechners', () => {
    test('POST mit Land, Postleitzahl und der Kopfzeile für AJAX', () => {
        const { plugin, button } = setup();
        plugin._onClick();

        assert.equal(http.calls.length, 1);
        assert.equal(http.calls[0].url, '/rc-checkout/shipping-estimate');
        assert.equal(http.calls[0].init.method, 'POST');
        assert.equal(http.calls[0].init.headers['X-Requested-With'], 'XMLHttpRequest');
        assert.deepEqual(http.calls[0].init.body.entries, { countryIso: 'DE', zipCode: '44787' });
        assert.equal(button.disabled, true, 'während der Anfrage gesperrt');
    });

    test('ohne Postleitzahl keine Anfrage, die Rückmeldung steht am Feld', () => {
        const { plugin, zip } = setup('  ');
        plugin._onClick();

        assert.equal(http.calls.length, 0);
        assert.equal(zip.attributes['aria-invalid'], 'true');
        assert.equal(zip.focused, true);
    });
});

describe('Antwort des Rechners', () => {
    test('eine gelungene Antwort ersetzt die Liste und merkt die Eingabe', async () => {
        const { plugin, output, button } = setup();
        plugin._onClick();
        http.respond('<ul><li>Paket 8,93 €</li></ul>');
        await http.settle();

        assert.equal(output.innerHTML, '<ul><li>Paket 8,93 €</li></ul>');
        assert.equal(button.disabled, false);
        assert.equal(globalThis.window.initialized, true);
        assert.deepEqual(remembered, { countryIso: 'DE', zipCode: '44787' });
    });

    test('eine abgewiesene Postleitzahl wird nicht gemerkt', async () => {
        const { plugin, output } = setup();
        output.querySelector = (selector) => (selector === '[data-rc-shipping-estimate-error]' ? {} : null);
        plugin._onClick();
        http.respond('<p data-rc-shipping-estimate-error>Ungültig</p>');
        await http.settle();

        assert.equal(remembered, null);
    });

    for (const [name, answer] of [['eine Begrenzung (429)', (h) => h.respond('<html>Too Many Requests</html>', 429)], ['ein Serverfehler (500)', (h) => h.respond('<html>Whoops</html>', 500)], ['ein Netzfehler', (h) => h.fail()]]) {
        test(`${name} zeigt den Fehlersatz statt der Seite`, async () => {
            const { plugin, output, button } = setup();
            plugin._onClick();
            answer(http);
            await http.settle();

            assert.equal(output.innerHTML, '');
            assert.equal(output.children.length, 1);
            assert.equal(output.children[0].textContent, 'Die Versandkosten ließen sich gerade nicht berechnen.');
            assert.equal(button.disabled, false, 'die Schaltfläche wird wieder frei');
        });
    }
});

describe('Abbau', () => {
    test('destroy() nimmt die Zuhörer ab', () => {
        const { plugin, zip, button } = setup();
        plugin.destroy();

        assert.equal(button.listeners.click.length, 0);
        assert.equal(zip.listeners.keydown.length, 0);
    });
});
