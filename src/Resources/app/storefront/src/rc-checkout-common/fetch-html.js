/**
 * Holt ein Stück Seite vom Server, wie es die Storefront-Routen dieses Plugins liefern.
 *
 * Eine Fehlerantwort oder ein Netzfehler ergibt `null`. Eine Fehlerseite gehört nicht in einen Kasten mitten auf der
 * Seite; was stattdessen erscheint, entscheidet der Aufrufer.
 *
 * @param {string} url
 * @param {{ method?: string, body?: FormData|null }} [options]
 * @returns {Promise<string|null>}
 */
export default function fetchHtml(url, { method = 'GET', body = null } = {}) {
    return fetch(url, {
        method,
        body,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
        .then((response) => (response.ok ? response.text() : null))
        .catch(() => null);
}
