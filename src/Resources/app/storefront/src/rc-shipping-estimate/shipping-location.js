/**
 * Land und Postleitzahl, die der Kunde zuletzt für eine Versandauskunft eingegeben hat.
 *
 * Nur im Browser des Kunden (`localStorage`), nie auf dem Server: Die Produktseite liegt im Seiten-Zwischenspeicher,
 * eine Anschrift darin sähe der nächste Besucher. Privates Surfen oder gesperrter Speicher werfen; dann gibt es keine
 * Vorbelegung.
 */
const KEY = 'rcShippingLocation';

export function readShippingLocation() {
    try {
        const value = JSON.parse(window.localStorage.getItem(KEY) || 'null');
        if (value && typeof value.countryIso === 'string' && typeof value.zipCode === 'string' && value.zipCode !== '') {
            return value;
        }
    } catch (e) {
        // kein Speicher, keine Vorbelegung
        return null;
    }

    return null;
}

/**
 * @returns {boolean} ob der Browser die Angabe gespeichert hat
 */
export function rememberShippingLocation(countryIso, zipCode) {
    try {
        window.localStorage.setItem(KEY, JSON.stringify({ countryIso, zipCode }));
    } catch (e) {
        // kein Speicher, nichts zu merken
        return false;
    }

    return true;
}
