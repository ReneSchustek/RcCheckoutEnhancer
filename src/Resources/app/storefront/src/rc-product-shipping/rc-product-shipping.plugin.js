import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import { readShippingLocation, rememberShippingLocation } from '../rc-shipping-estimate/shipping-location';

/**
 * „Versand für dieses Produkt berechnen": rechnet für Land, Postleitzahl und die Menge im Kaufbereich.
 *
 * Hat der Browser Land und Postleitzahl schon gemerkt, sind die Felder vorbelegt, und es wird beim Laden gerechnet;
 * ändert der Kunde die Menge, wird neu gerechnet. Das Ergebnis steht unter der Zeile zum Aufklappen.
 */
export default class RcProductShippingPlugin extends Plugin {
    static options = {
        url: '',
        productId: '',
        errorText: '',
        forText: '',
    };

    init() {
        this._client = new HttpClient();
        this._country = this.el.querySelector('[data-rc-product-shipping-country]');
        this._zip = this.el.querySelector('[data-rc-product-shipping-zip]');
        this._button = this.el.querySelector('[data-rc-product-shipping-submit]');
        this._output = this.el.querySelector('[data-rc-product-shipping-output]');
        this._for = this.el.querySelector('[data-rc-product-shipping-for]');

        if (!this.options.url || !this._country || !this._zip || !this._button || !this._output) {
            return;
        }

        this.el.hidden = false;

        this._onClick = this._onClick.bind(this);
        this._onKeydown = this._onKeydown.bind(this);
        this._onQuantityChange = this._onQuantityChange.bind(this);

        this._button.addEventListener('click', this._onClick);
        this._zip.addEventListener('keydown', this._onKeydown);
        // In der Einfangphase: Die Mengenwahl und andere Erweiterungen im Kaufbereich halten `change` teils auf.
        document.addEventListener('change', this._onQuantityChange, true);

        // Das gemerkte Land kann aus einem anderen Verkaufskanal stammen; steht es nicht in der Liste, wird nicht
        // gerechnet.
        const location = readShippingLocation();
        if (location && this._hasCountry(location.countryIso)) {
            this._country.value = location.countryIso;
            this._zip.value = location.zipCode;
            this._estimate(true);
        }
    }

    destroy() {
        if (this._button) {
            this._button.removeEventListener('click', this._onClick);
            this._zip.removeEventListener('keydown', this._onKeydown);
            document.removeEventListener('change', this._onQuantityChange, true);
        }
    }

    _onClick() {
        this._estimate(false);
    }

    _onKeydown(event) {
        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();
        this._estimate(false);
    }

    /** Neue Menge, neuer Preis, aber nur, wenn schon einmal gerechnet wurde. */
    _onQuantityChange(event) {
        if (this._lastQuantity === undefined || event.target !== this._quantityField()) {
            return;
        }

        if (this._quantity() !== this._lastQuantity) {
            this._estimate(true);
        }
    }

    _quantityField() {
        return document.querySelector(`[name="lineItems[${this.options.productId}][quantity]"]`);
    }

    /** 0, wenn die Seite kein Mengenfeld hat; der Server rechnet dann mit der Mindestabnahme. */
    _quantity() {
        const value = parseInt(this._quantityField()?.value ?? '', 10);

        return Number.isNaN(value) ? 0 : value;
    }

    _hasCountry(iso) {
        return [...this._country.options].some((option) => option.value === iso);
    }

    _estimate(auto) {
        const zip = this._zip.value.trim();

        if (zip === '') {
            this._zip.setAttribute('aria-invalid', 'true');
            this._zip.focus();
            this._zip.reportValidity();

            return;
        }

        this._zip.removeAttribute('aria-invalid');
        this._button.disabled = true;
        this._button.setAttribute('aria-busy', 'true');

        const quantity = this._quantity();
        const data = new FormData();
        data.append('countryIso', this._country.value);
        data.append('zipCode', zip);
        data.append('quantity', String(quantity));
        // Rechnungen ohne Klick laufen auf jeder Produktseite mit; der Server zählt sie in einer großzügigeren Staffel.
        if (auto) {
            data.append('auto', '1');
        }

        this._client.post(this.options.url, data, (response, request) => {
            this._button.disabled = false;
            this._button.setAttribute('aria-busy', 'false');

            if (!request || request.status >= 400 || request.status === 0) {
                const message = document.createElement('p');
                message.className = 'rc-shipping-estimate-failed alert alert-warning mb-0';
                message.textContent = this.options.errorText;
                this._output.replaceChildren(message);

                return;
            }

            this._output.innerHTML = response;
            this._lastQuantity = quantity;

            // Gemerkt wird nur, was der Server angenommen hat; eine abgewiesene Postleitzahl bliebe sonst stehen.
            if (!this._output.querySelector('[data-rc-shipping-estimate-error]')) {
                rememberShippingLocation(this._country.value, zip);
                this._showFor(zip, quantity);
                // Das Ergebnis steht außerhalb des Kastens und bleibt nach dem Zuklappen sichtbar.
                this.el.querySelector('[data-rc-product-shipping-details]').open = false;
            }
        });
    }

    _showFor(zip, quantity) {
        if (!this._for || !this.options.forText) {
            return;
        }

        const country = this._country.options[this._country.selectedIndex]?.textContent.trim() ?? '';
        this._for.textContent = this.options.forText
            .replace('%country%', country)
            .replace('%zip%', zip)
            .replace('%quantity%', String(quantity || 1));
        this._for.hidden = false;
    }
}
