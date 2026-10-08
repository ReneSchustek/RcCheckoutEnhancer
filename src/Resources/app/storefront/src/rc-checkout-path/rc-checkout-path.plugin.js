import Plugin from 'src/plugin-system/plugin.class';

/**
 * Die drei Wege oben im einseitigen Checkout: Gastbestellung, Bereits Kunde, Neues Kundenkonto.
 *
 * Die Knöpfe wählen nur, was darunter zu sehen ist, und setzen das Häkchen „Kundenkonto anlegen"
 * des Kerns. Welche Felder dann Pflicht sind, regelt weiter das Formular des Kerns; es hört auf
 * das Ändern des Häkchens und blendet die Kennwortfelder selbst ein und aus.
 *
 * „Weiter" steht unter Versandart, Zahlart und Summen und schickt das Adressformular darüber ab;
 * dessen eigener Knopf wird dafür ausgeblendet. Stünde er oben im Formular, klickte der Kunde ihn
 * nach der Adresse und sähe die Auswahl darunter nie.
 *
 * Ohne Skript bleiben die Knöpfe verborgen, Anmeldung und Formular stehen untereinander, das
 * Häkchen ist zu sehen, und das Formular schickt mit seinem eigenen Knopf ab.
 */
export default class RcCheckoutPathPlugin extends Plugin {
    static options = {
        initial: 'guest',
        choiceSelector: '[data-rc-checkout-path-choice]',
        choicesSelector: '[data-rc-checkout-path-choices]',
        loginSelector: '[data-rc-checkout-path-section="login"]',
        formSelector: '[data-rc-checkout-path-section="form"]',
        accountToggleSelector: '[data-rc-checkout-path-account-toggle]',
        accountCheckboxSelector: 'input[name="createCustomerAccount"]',
        continueSelector: '[data-rc-checkout-path-continue]',
        formSubmitSelector: '[data-rc-checkout-path-form-submit]',
    };

    init() {
        this._choices = Array.from(this.el.querySelectorAll(this.options.choiceSelector));
        this._login = this.el.querySelector(this.options.loginSelector);
        this._form = this.el.querySelector(this.options.formSelector);
        const choices = this.el.querySelector(this.options.choicesSelector);

        if (!choices || !this._login || !this._form || this._choices.length === 0) {
            return;
        }

        const accountToggle = this.el.querySelector(this.options.accountToggleSelector);
        this._accountCheckbox = accountToggle?.querySelector(this.options.accountCheckboxSelector) ?? null;

        // Die Knöpfe übernehmen die Aufgabe des Häkchens. Zwei Bedienelemente für dieselbe Wahl
        // könnten sich widersprechen.
        if (accountToggle) {
            accountToggle.hidden = true;
        }
        choices.hidden = false;

        this._continue = this.el.querySelector(this.options.continueSelector);
        const registerForm = this._form.querySelector('form');
        const formSubmit = this._form.querySelector(this.options.formSubmitSelector);
        if (this._continue && registerForm && formSubmit) {
            formSubmit.hidden = true;
            // `requestSubmit` statt `submit`: Nur so prüft der Browser die Pflichtfelder und die
            // Formularprüfung des Kerns sieht das Absenden.
            this._continue.querySelector('button')?.addEventListener('click', () => registerForm.requestSubmit());
        } else {
            this._continue = null;
        }

        this._choices.forEach((button) => {
            button.addEventListener('click', () => this._select(button.dataset.rcCheckoutPathChoice));
        });

        this._select(this.options.initial);
    }

    /**
     * @param {string} path `guest`, `login` oder `register`
     */
    _select(path) {
        this._choices.forEach((button) => {
            const isActive = button.dataset.rcCheckoutPathChoice === path;
            button.setAttribute('aria-pressed', String(isActive));
            button.classList.toggle('is-active', isActive);
        });

        this._login.hidden = path !== 'login';
        this._form.hidden = path === 'login';
        if (this._continue) {
            this._continue.hidden = path === 'login';
        }

        if (!this._accountCheckbox) {
            return;
        }

        const wantsAccount = path === 'register';
        if (this._accountCheckbox.checked !== wantsAccount) {
            this._accountCheckbox.checked = wantsAccount;
            this._accountCheckbox.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
}
