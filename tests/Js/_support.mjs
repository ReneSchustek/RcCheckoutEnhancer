// Gemeinsame Hilfen der Skript-Tests: ein Storefront-Plugin aus seinem Quelltext laden und `fetch` so nachbilden, dass
// der Test die Antwort selbst bestimmt. Die Plugins importieren die Basisklasse über einen Webpack-Pfad des Kerns
// (`src/plugin-system/…`), den Node nicht kennt; die eigenen Hilfsmodule dagegen lassen sich direkt einbinden.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'src', 'Resources', 'app', 'storefront', 'src');

export const sourcePath = (relative) => join(root, relative);

/** Die Basisklasse der Storefront-Plugins, so weit die Plugins sie brauchen. */
export class Plugin {
    init() {}
    destroy() {}
}

/**
 * Lädt ein Plugin und gibt ihm seine Importe als Namen mit.
 *
 * @param {string} relative Pfad unter `storefront/src`
 * @param {string} className Name der Klasse im Quelltext
 * @param {Record<string, unknown>} imports Name im Quelltext => Wert
 */
export function loadPlugin(relative, className, imports = {}) {
    const source = readFileSync(sourcePath(relative), 'utf8')
        .replace(/^import [^\n]*\n/gm, '')
        .replace(/^export default /m, '');
    const names = ['Plugin', ...Object.keys(imports)];

    return new Function(...names, `${source}\nreturn ${className};`)(Plugin, ...Object.values(imports));
}

/**
 * Ein `fetch`, dessen Antwort der Test bestimmt. `respond()` und `fail()` gelten für die zuletzt gestellte Anfrage;
 * `settle()` wartet, bis die Verarbeitung der Antwort durch ist.
 */
export function fakeFetch() {
    const calls = [];
    globalThis.fetch = (url, init = {}) => new Promise((resolve, reject) => {
        calls.push({ url, init, resolve, reject });
    });

    return {
        calls,
        respond(body, status = 200) {
            calls.at(-1).resolve({ ok: status >= 200 && status < 300, status, text: async () => body });
        },
        fail() {
            calls.at(-1).reject(new TypeError('Failed to fetch'));
        },
        settle: () => new Promise((resolve) => setTimeout(resolve, 0)),
    };
}

/** Ein DOM-Element, so weit die Plugins es anfassen. */
export function element(extra = {}) {
    return {
        attributes: {},
        innerHTML: '',
        children: [],
        hidden: false,
        disabled: false,
        listeners: {},
        setAttribute(name, value) { this.attributes[name] = value; },
        getAttribute(name) { return this.attributes[name] ?? null; },
        removeAttribute(name) { delete this.attributes[name]; },
        addEventListener(type, listener) { (this.listeners[type] ??= []).push(listener); },
        removeEventListener(type, listener) { this.listeners[type] = (this.listeners[type] ?? []).filter((l) => l !== listener); },
        querySelector() { return null; },
        replaceChildren(...children) { this.children = children; this.innerHTML = ''; },
        focus() { this.focused = true; },
        reportValidity() { this.reported = true; },
        ...extra,
    };
}

/** Das globale `document`, so weit die Plugins und Hilfen es anfassen. */
export function installDocument() {
    const listeners = {};
    globalThis.document = {
        listeners,
        body: { classList: { added: new Set(), add(c) { this.added.add(c); }, remove(c) { this.added.delete(c); } } },
        activeElement: null,
        createElement: () => element({ className: '', textContent: '' }),
        addEventListener(type, listener) { (listeners[type] ??= []).push(listener); },
        removeEventListener(type, listener) { listeners[type] = (listeners[type] ?? []).filter((l) => l !== listener); },
    };
    globalThis.FormData = class {
        constructor() { this.entries = {}; }
        append(name, value) { this.entries[name] = value; }
    };

    return globalThis.document;
}
