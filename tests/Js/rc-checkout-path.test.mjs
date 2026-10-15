// Der Weiter-Knopf im einseitigen Checkout trägt den Text des gewählten Wegs. Ein Gast, der „Registrieren" liest,
// hält die Gastbestellung für einen Irrtum.

import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(
    join(__dirname, '..', '..', 'src', 'Resources', 'app', 'storefront', 'src', 'rc-checkout-path', 'rc-checkout-path.plugin.js'),
    'utf8',
)
    .replace(/^import [^\n]*\n/gm, '')
    .replace(/^export default /m, '');

const RcCheckoutPathPlugin = new Function(`class Plugin { init() {} }\n${source}\nreturn RcCheckoutPathPlugin;`)();

const button = () => ({
    textContent: 'Weiter',
    dataset: {
        rcCheckoutPathLabelGuest: 'Weiter zur Übersicht',
        rcCheckoutPathLabelRegister: 'Konto anlegen und weiter',
    },
});

describe('RcCheckoutPathPlugin.applyLabel', () => {
    test('der Gast geht weiter zur Übersicht', () => {
        const knopf = button();
        RcCheckoutPathPlugin.applyLabel(knopf, 'guest');
        assert.equal(knopf.textContent, 'Weiter zur Übersicht');
    });

    test('ein neues Konto wird angelegt', () => {
        const knopf = button();
        RcCheckoutPathPlugin.applyLabel(knopf, 'register');
        assert.equal(knopf.textContent, 'Konto anlegen und weiter');
    });

    test('ohne Knopf oder ohne Text passiert nichts', () => {
        assert.doesNotThrow(() => RcCheckoutPathPlugin.applyLabel(null, 'guest'));
        const knopf = { textContent: 'Weiter', dataset: {} };
        RcCheckoutPathPlugin.applyLabel(knopf, 'guest');
        assert.equal(knopf.textContent, 'Weiter');
    });
});
