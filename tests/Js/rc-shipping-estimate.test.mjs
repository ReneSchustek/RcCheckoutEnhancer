// Der Versandkostenrechner im Warenkorb: Eine Fehlerantwort (Begrenzung, Serverfehler) darf nicht
// als Seite im Kasten landen. Der Quelltext wird gelesen und mit Attrappen für Plugin, HttpClient
// und DOM ausgewertet, damit das Browser-Plugin unverändert gebaut werden kann.

import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(
    join(__dirname, '..', '..', 'src', 'Resources', 'app', 'storefront', 'src', 'rc-shipping-estimate', 'rc-shipping-estimate.plugin.js'),
    'utf8',
)
    .replace(/^import [^\n]*\n/gm, '')
    .replace(/^export default /m, '');

/** Eine Anfrage, deren Rückruf der Test selbst auslöst. */
class HttpClientStub {
    post(url, data, callback) {
        HttpClientStub.lastCallback = callback;
    }
}

const RcShippingEstimatePlugin = new Function('Plugin', 'HttpClient', `${source}\nreturn RcShippingEstimatePlugin;`)(
    class { init() {} destroy() {} },
    HttpClientStub,
);

function element(extra = {}) {
    return {
        attributes: {},
        innerHTML: '',
        children: [],
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
        addEventListener() {},
        replaceChildren(...children) { this.children = children; this.innerHTML = ''; },
        ...extra,
    };
}

function setup() {
    globalThis.FormData = class { append() {} };
    globalThis.document = { createElement: () => ({ className: '', textContent: '' }) };
    globalThis.window = { PluginManager: { initializePlugins() { globalThis.window.initialized = true; } } };

    const parts = {
        '[data-rc-shipping-estimate-country]': element({ value: 'DE' }),
        '[data-rc-shipping-estimate-zip]': element({ value: '44787' }),
        '[data-rc-shipping-estimate-submit]': element({ disabled: false }),
        '[data-rc-shipping-estimate-output]': element(),
    };

    const plugin = Object.create(RcShippingEstimatePlugin.prototype);
    plugin.el = { querySelector: (selector) => parts[selector] };
    plugin.options = { url: '/rc-checkout/shipping-estimate', errorText: 'Die Versandkosten ließen sich gerade nicht berechnen.' };
    plugin.init();
    plugin._onClick();

    return { plugin, output: parts['[data-rc-shipping-estimate-output]'], button: parts['[data-rc-shipping-estimate-submit]'] };
}

describe('Antwort des Rechners', () => {
    test('eine gelungene Antwort ersetzt die Liste', () => {
        const { output, button } = setup();

        HttpClientStub.lastCallback('<ul><li>Paket 8,93 €</li></ul>', { status: 200 });

        assert.equal(output.innerHTML, '<ul><li>Paket 8,93 €</li></ul>');
        assert.equal(button.disabled, false);
        assert.equal(globalThis.window.initialized, true);
    });

    test('eine Begrenzung (429) zeigt den Fehlersatz statt der Seite', () => {
        const { output, button } = setup();

        HttpClientStub.lastCallback('<html>Too Many Requests</html>', { status: 429 });

        assert.equal(output.innerHTML, '');
        assert.equal(output.children.length, 1);
        assert.equal(output.children[0].textContent, 'Die Versandkosten ließen sich gerade nicht berechnen.');
        assert.equal(button.disabled, false, 'die Schaltfläche wird wieder frei');
    });

    test('ein Serverfehler (500) ebenso', () => {
        const { output } = setup();

        HttpClientStub.lastCallback('<html>Whoops</html>', { status: 500 });

        assert.equal(output.children[0].textContent, 'Die Versandkosten ließen sich gerade nicht berechnen.');
    });

    test('ein abgebrochener Aufruf (Status 0) ebenso', () => {
        const { output } = setup();

        HttpClientStub.lastCallback('', { status: 0 });

        assert.equal(output.children.length, 1);
    });
});
