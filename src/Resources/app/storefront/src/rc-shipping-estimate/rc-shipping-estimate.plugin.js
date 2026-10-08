import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

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

        this._client.post(this.options.url, data, (response, request) => {
            this._setLoading(false);

            // Eine Fehlerseite (Begrenzung, Serverfehler) gehört nicht in den Kasten; dort stünde
            // sonst eine ganze Seite mitten im Warenkorb. Stattdessen der Satz, den der Rechner
            // auch bei einer gescheiterten Berechnung zeigt.
            if (!request || request.status >= 400 || request.status === 0) {
                this._showError();

                return;
            }

            this._output.innerHTML = response;
            window.PluginManager.initializePlugins();
        });
    }

    _showError() {
        const message = document.createElement('p');
        message.className = 'rc-shipping-estimate-failed alert alert-warning mb-0';
        message.textContent = this.options.errorText;
        this._output.replaceChildren(message);
    }

    _setLoading(loading) {
        this._button.disabled = loading;
        this._button.setAttribute('aria-busy', loading ? 'true' : 'false');
    }
}
