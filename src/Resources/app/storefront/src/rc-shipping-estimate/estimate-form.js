/**
 * Was die drei Versandauskünfte (Warenkorb, Produktseite, Speditionshinweis) gemeinsam haben.
 */

/**
 * Die Postleitzahl ist Pflicht. Fehlt sie, steht die Rückmeldung am Feld, nicht in der Ergebnisliste; dort sucht sie
 * niemand.
 *
 * @param {HTMLInputElement} input
 * @returns {string|null} die Postleitzahl oder `null`, wenn sie fehlt
 */
export function requiredZip(input) {
    const zip = input.value.trim();

    if (zip === '') {
        input.setAttribute('aria-invalid', 'true');
        input.focus();
        input.reportValidity();

        return null;
    }

    input.removeAttribute('aria-invalid');

    return zip;
}

/**
 * Der Satz, der statt einer gescheiterten Auskunft im Ergebnis steht.
 *
 * @param {HTMLElement} output
 * @param {string} text
 */
export function showEstimateError(output, text) {
    const message = document.createElement('p');
    message.className = 'rc-shipping-estimate-failed alert alert-warning mb-0';
    message.textContent = text;
    output.replaceChildren(message);
}
