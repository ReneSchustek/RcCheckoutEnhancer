import Plugin from 'src/plugin-system/plugin.class';
import fetchHtml from '../rc-checkout-common/fetch-html';
import { requiredZip, showEstimateError } from './estimate-form';
import { readShippingLocation, rememberShippingLocation } from './shipping-location';

/**
 * Schickt Land und Postleitzahl an den Rechner und tauscht die Ergebnisliste aus.
 *
 * Lädt Shopware den Warenkorb per AJAX neu, entsteht neues Markup mit einer neuen
 * Instanz; ein erneutes `initializePlugins()` auf demselben Element ruft nur
 * `update()` und bindet nichts doppelt. `destroy()` nimmt die Zuhörer ab, wenn
 * eine Instanz ausdrücklich abgebaut wird.
 */
export default class RcShippingEstimatePlugin extends Plugin {
    static options = {
        url: '',
        errorText: '',
    };

    init() {
        this._country = this.el.querySelector('[data-rc-shipping-estimate-country]');
        this._zip = this.el.querySelector('[data-rc-shipping-estimate-zip]');
        this._button = this.el.querySelector('[data-rc-shipping-estimate-submit]');
        this._output = this.el.querySelector('[data-rc-shipping-estimate-output]');

        if (!this._country || !this._zip || !this._button || !this._output) {
            return;
        }

        this._onClick = this._onClick.bind(this);
        this._onKeydown = this._onKeydown.bind(this);

        this._button.addEventListener('click', this._onClick);
        this._zip.addEventListener('keydown', this._onKeydown);

        // Vorbelegung aus der Produktseite, aber nie über die Anschrift des Kontos hinweg.
        const location = readShippingLocation();
        if (location && this._zip.value.trim() === '' && [...this._country.options].some((option) => option.value === location.countryIso)) {
            this._country.value = location.countryIso;
            this._zip.value = location.zipCode;
        }
    }

    destroy() {
        if (this._button) {
            this._button.removeEventListener('click', this._onClick);
        }
        if (this._zip) {
            this._zip.removeEventListener('keydown', this._onKeydown);
        }
    }

    /**
     * Die Eingabetaste im Postleitzahl-Feld löst dieselbe Abfrage aus wie die
     * Schaltfläche. Ohne das müsste, wer mit der Tastatur arbeitet, erst
     * weitertabben, für eine Eingabe aus zwei Feldern ein unnötiger Umweg.
     */
    _onKeydown(event) {
        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();
        this._onClick();
    }

    _onClick() {
        const zip = requiredZip(this._zip);
        if (zip === null) {
            return;
        }

        this._setLoading(true);

        const data = new FormData();
        data.append('countryIso', this._country.value);
        data.append('zipCode', zip);

        fetchHtml(this.options.url, { method: 'POST', body: data }).then((response) => {
            this._setLoading(false);

            // Bei einer Fehlerantwort (Begrenzung, Serverfehler) der Satz, den der Rechner auch bei einer gescheiterten
            // Berechnung zeigt.
            if (response === null) {
                showEstimateError(this._output, this.options.errorText);

                return;
            }

            this._output.innerHTML = response;
            // Eine abgewiesene Postleitzahl wird nicht gemerkt, sonst stünde sie auf jeder Produktseite wieder da.
            if (!this._output.querySelector('[data-rc-shipping-estimate-error]')) {
                rememberShippingLocation(this._country.value, zip);
            }
            window.PluginManager.initializePlugins();
        });
    }

    _setLoading(loading) {
        this._button.disabled = loading;
        this._button.setAttribute('aria-busy', loading ? 'true' : 'false');
    }
}
