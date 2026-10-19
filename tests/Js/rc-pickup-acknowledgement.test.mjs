// Der Abhol-Dialog: Bestätigen meldet die Zusage an den Server. Kommt sie nicht an, bleibt der Dialog offen, sonst
// glaubte der Kunde, er habe bestätigt, und liefe in die Sperre.

import { beforeEach, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { element, fakeFetch, installDocument, loadPlugin } from './_support.mjs';
import fetchHtml from '../../src/Resources/app/storefront/src/rc-checkout-common/fetch-html.js';

const RcPickupAcknowledgementPlugin = loadPlugin('rc-pickup-acknowledgement/rc-pickup-acknowledgement.plugin.js', 'RcPickupAcknowledgementPlugin', { fetchHtml });

let http;
let reloaded;

beforeEach(() => {
    installDocument();
    http = fakeFetch();
    reloaded = false;
    globalThis.window = { location: { reload() { reloaded = true; } } };
});

function setup() {
    const error = element({ hidden: true });
    const parts = {
        '[data-rc-pickup-dialog]': element({ hidden: true, querySelector: (s) => (s === '[data-rc-pickup-error]' ? error : null), querySelectorAll: () => [] }),
        '[data-rc-pickup-confirm]': element(),
        '[data-rc-pickup-box]': element(),
        '[data-rc-pickup-close]': element(),
        '[data-rc-pickup-reopen]': element(),
    };
    const plugin = Object.create(RcPickupAcknowledgementPlugin.prototype);
    plugin.el = { querySelector: (selector) => parts[selector] ?? null };
    plugin.options = { ...RcPickupAcknowledgementPlugin.options, acknowledgeUrl: '/rc-checkout/pickup-acknowledgement' };
    plugin.init();

    return { plugin, parts, error };
}

describe('Dialog', () => {
    test('öffnet sich beim Aufbau, der Kasten tritt zurück, der Fokus liegt auf „Bestätigen"', () => {
        const { parts } = setup();

        assert.equal(parts['[data-rc-pickup-dialog]'].hidden, false);
        assert.equal(parts['[data-rc-pickup-box]'].hidden, true);
        assert.equal(parts['[data-rc-pickup-confirm]'].focused, true);
        assert.equal(document.listeners.keydown.length, 1);
    });

    test('Schließen stellt den Kasten wieder her und gibt den Fokus an „Erneut öffnen"', () => {
        const { parts } = setup();
        parts['[data-rc-pickup-close]'].listeners.click[0]();

        assert.equal(parts['[data-rc-pickup-dialog]'].hidden, true);
        assert.equal(parts['[data-rc-pickup-box]'].hidden, false);
        assert.equal(parts['[data-rc-pickup-reopen]'].focused, true);
        assert.equal(document.listeners.keydown.length, 0, 'Escape hört nur bei offenem Dialog zu');
    });
});

describe('Bestätigen', () => {
    test('meldet die Zusage per POST und lädt nach Erfolg neu', async () => {
        const { plugin } = setup();
        plugin._onConfirm();

        assert.equal(http.calls[0].init.method, 'POST');
        assert.equal(http.calls[0].url, '/rc-checkout/pickup-acknowledgement');
        http.respond('{"acknowledged":true}');
        await http.settle();

        assert.equal(reloaded, true);
    });

    for (const [name, answer] of [['eine Ablehnung (409)', (h) => h.respond('{"acknowledged":false}', 409)], ['ein Netzfehler', (h) => h.fail()]]) {
        test(`${name} lässt den Dialog offen und zeigt den Fehler`, async () => {
            const { plugin, parts, error } = setup();
            plugin._onConfirm();
            answer(http);
            await http.settle();

            assert.equal(reloaded, false);
            assert.equal(parts['[data-rc-pickup-confirm]'].disabled, false);
            assert.equal('hidden' in error.attributes, false);
            assert.equal(parts['[data-rc-pickup-dialog]'].hidden, false);
        });
    }
});

describe('Abbau', () => {
    test('destroy() nimmt alle Zuhörer ab', () => {
        const { plugin, parts } = setup();
        plugin.destroy();

        for (const selector of ['[data-rc-pickup-confirm]', '[data-rc-pickup-close]', '[data-rc-pickup-reopen]']) {
            assert.equal(parts[selector].listeners.click.length, 0, selector);
        }
        assert.equal(document.listeners.keydown.length, 0);
    });
});
