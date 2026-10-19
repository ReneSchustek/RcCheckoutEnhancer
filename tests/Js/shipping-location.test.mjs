// Land und Postleitzahl der letzten Versandauskunft, nur im Browser des Kunden. Privates Surfen oder gesperrter
// Speicher werfen; dann gibt es keine Vorbelegung, aber auch keinen Fehler.

import { beforeEach, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readShippingLocation, rememberShippingLocation } from '../../src/Resources/app/storefront/src/rc-shipping-estimate/shipping-location.js';

function storage(values = {}) {
    return {
        values,
        getItem(key) { return this.values[key] ?? null; },
        setItem(key, value) { this.values[key] = value; },
    };
}

beforeEach(() => {
    globalThis.window = { localStorage: storage() };
});

describe('Gemerkte Anschrift', () => {
    test('was gemerkt wurde, kommt zurück', () => {
        assert.equal(rememberShippingLocation('DE', '44787'), true);
        assert.deepEqual(readShippingLocation(), { countryIso: 'DE', zipCode: '44787' });
    });

    test('nichts, Unsinn oder eine leere Postleitzahl ergeben keine Vorbelegung', () => {
        assert.equal(readShippingLocation(), null);

        window.localStorage.setItem('rcShippingLocation', '{kaputt');
        assert.equal(readShippingLocation(), null);

        window.localStorage.setItem('rcShippingLocation', JSON.stringify({ countryIso: 'DE', zipCode: '' }));
        assert.equal(readShippingLocation(), null);
    });

    test('ein gesperrter Speicher wirft nicht', () => {
        globalThis.window = { get localStorage() { throw new Error('SecurityError'); } };

        assert.equal(readShippingLocation(), null);
        assert.equal(rememberShippingLocation('DE', '44787'), false);
    });
});
