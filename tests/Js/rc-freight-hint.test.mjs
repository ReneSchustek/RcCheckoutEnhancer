// Der Speditionshinweis auf der Produktseite lädt sich beim Aufbau nach. Eine Fehlerantwort lässt den Platz leer: Der
// Hinweis ist eine Zugabe, eine Fehlerseite im Kaufbereich wäre schlimmer.

import { beforeEach, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { element, fakeFetch, installDocument, loadPlugin } from './_support.mjs';
import fetchHtml from '../../src/Resources/app/storefront/src/rc-checkout-common/fetch-html.js';
import { requiredZip, showEstimateError } from '../../src/Resources/app/storefront/src/rc-shipping-estimate/estimate-form.js';

const RcFreightHintPlugin = loadPlugin('rc-freight-hint/rc-freight-hint.plugin.js', 'RcFreightHintPlugin', { fetchHtml, requiredZip, showEstimateError });

let http;

beforeEach(() => {
    installDocument();
    http = fakeFetch();
});

function plugin(parts = {}) {
    const instance = Object.create(RcFreightHintPlugin.prototype);
    instance.el = element({ hidden: true, querySelector: (selector) => parts[selector] ?? null });
    instance.options = { url: '/rc-checkout/freight-hint/1', estimateUrl: '/rc-checkout/freight-hint/1/estimate', errorText: 'Fehler' };
    instance.init();

    return instance;
}

describe('Nachladen des Speditionshinweises', () => {
    test('ein Hinweis erscheint', async () => {
        const instance = plugin();
        http.respond('<div>Lieferung per Spedition</div>');
        await http.settle();

        assert.equal(http.calls[0].init.method, 'GET');
        assert.equal(instance.el.innerHTML, '<div>Lieferung per Spedition</div>');
        assert.equal(instance.el.hidden, false);
    });

    test('eine leere Antwort (204) lässt den Platz leer', async () => {
        const instance = plugin();
        http.respond('', 204);
        await http.settle();

        assert.equal(instance.el.hidden, true);
    });

    for (const [name, answer] of [['eine Begrenzung (429)', (h) => h.respond('<html>Too Many Requests</html>', 429)], ['ein Netzfehler', (h) => h.fail()]]) {
        test(`${name} lässt den Platz leer`, async () => {
            const instance = plugin();
            answer(http);
            await http.settle();

            assert.equal(instance.el.innerHTML, '');
            assert.equal(instance.el.hidden, true);
        });
    }
});

describe('Rechner im Hinweis', () => {
    function withForm(zip) {
        const parts = {
            '[data-rc-freight-hint-zip]': element({ value: zip }),
            '[data-rc-freight-hint-submit]': element(),
            '[data-rc-freight-hint-output]': element(),
        };
        const instance = plugin(parts);
        http.respond('');

        return { instance, parts };
    }

    test('rechnet mit der Postleitzahl und zeigt bei einem Fehler den Satz', async () => {
        const { instance, parts } = withForm(' 96215 ');
        await http.settle();
        instance._estimate();

        assert.deepEqual(http.calls[1].init.body.entries, { zipCode: '96215' });
        http.respond('<html>Whoops</html>', 500);
        await http.settle();

        assert.equal(parts['[data-rc-freight-hint-output]'].children[0].textContent, 'Fehler');
        assert.equal(parts['[data-rc-freight-hint-submit]'].disabled, false);
    });

    test('ohne Postleitzahl keine Anfrage', async () => {
        const { instance } = withForm('');
        await http.settle();
        instance._estimate();

        assert.equal(http.calls.length, 1, 'nur das Nachladen des Hinweises');
    });
});
