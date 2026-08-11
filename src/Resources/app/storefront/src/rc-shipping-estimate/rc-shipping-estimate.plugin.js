import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

/**
 * Schickt Land und Postleitzahl an den Rechner und tauscht die Ergebnisliste aus.
 *
 * Der Warenkorb wird nach jeder Mengenänderung per AJAX neu geladen. Shopware
 * bindet JS-Plugins dabei erneut an — ohne `destroy()` sammeln sich die
 * Ereignis-Behandler auf, und ein Klick löst nach dem dritten Nachladen vier
 * Anfragen aus.
 */
export default class RcShippingEstimatePlugin extends Plugin {
    static options = {
        url: '',
    };

    init() {
        this._client = new HttpClient();
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
     * weitertabben — für eine Eingabe aus zwei Feldern ein unnötiger Umweg.
     */
    _onKeydown(event) {
        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();
        this._onClick();
    }

    _onClick() {
        const zip = this._zip.value.trim();

        // Pflichtfeld: ohne Postleitzahl wird nicht gerechnet. Die Rückmeldung
        // steht am Feld, nicht in der Ergebnisliste — dort sucht sie niemand.
        if (zip === '') {
            this._zip.setAttribute('aria-invalid', 'true');
            this._zip.focus();
            this._zip.reportValidity();

            return;
        }

        this._zip.removeAttribute('aria-invalid');
        this._setLoading(true);

        const data = new FormData();
        data.append('countryIso', this._country.value);
        data.append('zipCode', zip);

        this._client.post(this.options.url, data, (response) => {
            this._output.innerHTML = response;
            this._setLoading(false);
            window.PluginManager.initializePlugins();
        });
    }

    _setLoading(loading) {
        this._button.disabled = loading;
        this._button.setAttribute('aria-busy', loading ? 'true' : 'false');
    }
}
