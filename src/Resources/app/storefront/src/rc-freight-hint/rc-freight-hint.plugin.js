import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

/**
 * Lädt den Speditionshinweis der Produktseite nach und bedient den Rechner darin.
 *
 * Der Platz steht leer und versteckt im Kaufbereich. Geht der Artikel per Paket, antwortet der
 * Server ohne Inhalt, und der Platz bleibt, wie er ist — kein Flackern, kein leerer Kasten.
 *
 * Die Ereignisse hängen am Platz selbst, nicht an den Feldern darin: Die Felder kommen erst mit
 * der Antwort, und so gibt es genau eine Stelle, an der Behandler an- und abgemeldet werden.
 */
export default class RcFreightHintPlugin extends Plugin {
    static options = {
        url: '',
        estimateUrl: '',
        errorText: '',
    };

    init() {
        if (!this.options.url) {
            return;
        }

        this._client = new HttpClient();
        this._onClick = this._onClick.bind(this);
        this._onKeydown = this._onKeydown.bind(this);

        this.el.addEventListener('click', this._onClick);
        this.el.addEventListener('keydown', this._onKeydown);

        // Bei einer Fehlerantwort bleibt der Platz leer: Der Hinweis ist eine Zugabe, eine Fehlerseite
        // im Kaufbereich wäre schlimmer als gar keiner.
        this._client.get(this.options.url, (response, request) => {
            if (!request || request.status >= 400 || !response || response.trim() === '') {
                return;
            }

            this.el.innerHTML = response;
            this.el.hidden = false;
        });
    }

    destroy() {
        this.el.removeEventListener('click', this._onClick);
        this.el.removeEventListener('keydown', this._onKeydown);
    }

    _onClick(event) {
        if (!event.target.closest('[data-rc-freight-hint-submit]')) {
            return;
        }

        this._estimate();
    }

    /**
     * Die Eingabetaste im Feld rechnet — und schickt nicht das Kaufformular ab, in dessen
     * Nähe der Kasten steht.
     */
    _onKeydown(event) {
        if (event.key !== 'Enter' || !event.target.matches('[data-rc-freight-hint-zip]')) {
            return;
        }

        event.preventDefault();
        this._estimate();
    }

    _estimate() {
        const zip = this.el.querySelector('[data-rc-freight-hint-zip]');
        const button = this.el.querySelector('[data-rc-freight-hint-submit]');
        const output = this.el.querySelector('[data-rc-freight-hint-output]');
        if (!zip || !button || !output) {
            return;
        }

        if (zip.value.trim() === '') {
            zip.setAttribute('aria-invalid', 'true');
            zip.focus();
            zip.reportValidity();

            return;
        }

        zip.removeAttribute('aria-invalid');
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');

        const data = new FormData();
        data.append('zipCode', zip.value.trim());

        this._client.post(this.options.estimateUrl, data, (response, request) => {
            button.disabled = false;
            button.setAttribute('aria-busy', 'false');

            if (!request || request.status >= 400 || request.status === 0) {
                const message = document.createElement('p');
                message.className = 'rc-shipping-estimate-failed alert alert-warning mb-0';
                message.textContent = this.options.errorText;
                output.replaceChildren(message);

                return;
            }

            output.innerHTML = response;
        });
    }
}
