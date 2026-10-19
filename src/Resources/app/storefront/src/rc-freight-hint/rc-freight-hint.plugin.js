import Plugin from 'src/plugin-system/plugin.class';
import fetchHtml from '../rc-checkout-common/fetch-html';
import { requiredZip, showEstimateError } from '../rc-shipping-estimate/estimate-form';

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

        this._onClick = this._onClick.bind(this);
        this._onKeydown = this._onKeydown.bind(this);

        this.el.addEventListener('click', this._onClick);
        this.el.addEventListener('keydown', this._onKeydown);

        // Bei einer Fehlerantwort bleibt der Platz leer: Der Hinweis ist eine Zugabe, eine Fehlerseite
        // im Kaufbereich wäre schlimmer als gar keiner.
        fetchHtml(this.options.url).then((response) => {
            if (response === null || response.trim() === '') {
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

        const zipCode = requiredZip(zip);
        if (zipCode === null) {
            return;
        }

        button.disabled = true;
        button.setAttribute('aria-busy', 'true');

        const data = new FormData();
        data.append('zipCode', zipCode);

        fetchHtml(this.options.estimateUrl, { method: 'POST', body: data }).then((response) => {
            button.disabled = false;
            button.setAttribute('aria-busy', 'false');

            if (response === null) {
                showEstimateError(output, this.options.errorText);

                return;
            }

            output.innerHTML = response;
        });
    }
}
