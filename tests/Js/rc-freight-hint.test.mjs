// Der Speditionshinweis auf der Produktseite lädt sich beim Aufbau nach. Eine Fehlerantwort lässt
// den Platz leer: Der Hinweis ist eine Zugabe, eine Fehlerseite im Kaufbereich wäre schlimmer.

import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(
    join(__dirname, '..', '..', 'src', 'Resources', 'app', 'storefront', 'src', 'rc-freight-hint', 'rc-freight-hint.plugin.js'),
    'utf8',
)
    .replace(/^import [^\n]*\n/gm, '')
    .replace(/^export default /m, '');

class HttpClientStub {
    get(url, callback) {
        HttpClientStub.lastCallback = callback;
    }
}

const RcFreightHintPlugin = new Function('Plugin', 'HttpClient', `${source}\nreturn RcFreightHintPlugin;`)(
    class { init() {} destroy() {} },
    HttpClientStub,
);

function plugin() {
    const instance = Object.create(RcFreightHintPlugin.prototype);
    instance.el = { innerHTML: '', hidden: true, addEventListener() {} };
    instance.options = { url: '/rc-checkout/freight-hint/1', estimateUrl: '/rc-checkout/freight-hint/1/estimate', errorText: 'Fehler' };
    instance.init();

    return instance;
}

describe('Nachladen des Speditionshinweises', () => {
    test('ein Hinweis erscheint', () => {
        const instance = plugin();

        HttpClientStub.lastCallback('<div>Lieferung per Spedition</div>', { status: 200 });

        assert.equal(instance.el.innerHTML, '<div>Lieferung per Spedition</div>');
        assert.equal(instance.el.hidden, false);
    });

    test('eine leere Antwort lässt den Platz leer', () => {
        const instance = plugin();

        HttpClientStub.lastCallback('   ', { status: 200 });

        assert.equal(instance.el.hidden, true);
    });

    test('eine Begrenzung (429) lässt den Platz leer', () => {
        const instance = plugin();

        HttpClientStub.lastCallback('<html>Too Many Requests</html>', { status: 429 });

        assert.equal(instance.el.innerHTML, '');
        assert.equal(instance.el.hidden, true);
    });
});
