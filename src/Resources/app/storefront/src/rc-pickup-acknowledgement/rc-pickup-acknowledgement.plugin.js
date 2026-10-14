import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

/**
 * Zeigt den Abhol-Hinweis als Dialog und meldet die Bestätigung an den Server.
 *
 * Ob der Hinweis fällig ist, hat der Server entschieden, bevor die Seite gerendert wurde. Ist
 * das Markup da, ist er fällig; ist es nicht da, passiert hier gar nichts. Eine zweite Wahrheit
 * im Browser wiche irgendwann von der ersten ab.
 *
 * Ohne JavaScript bleibt der Hinweis als Kasten stehen und die Bestellung gesperrt: Die Sperre
 * hängt am Server, nicht an dieser Datei.
 */
export default class RcPickupAcknowledgementPlugin extends Plugin {
    static options = {
        confirmSelector: '[data-rc-pickup-confirm]',
        dialogSelector: '[data-rc-pickup-dialog]',
        boxSelector: '[data-rc-pickup-box]',
        reopenSelector: '[data-rc-pickup-reopen]',
        revertFormSelector: '[data-rc-pickup-revert-form]',
        closeSelector: '[data-rc-pickup-close]',
        acknowledgeUrl: '',
    };

    init() {
        this._dialog = this.el.querySelector(this.options.dialogSelector);
        this._confirmButton = this.el.querySelector(this.options.confirmSelector);
        this._box = this.el.querySelector(this.options.boxSelector);
        this._closeButton = this.el.querySelector(this.options.closeSelector);
        this._reopenButton = this.el.querySelector(this.options.reopenSelector);
        this._revertForm = this.el.querySelector(this.options.revertFormSelector);

        if (!this._dialog || !this._confirmButton || !this.options.acknowledgeUrl) {
            return;
        }

        this._client = new HttpClient();

        this._onConfirm = this._onConfirm.bind(this);
        this._onKeydown = this._onKeydown.bind(this);

        this._confirmButton.addEventListener('click', this._onConfirm);
        this._closeButton?.addEventListener('click', () => this._close());
        this._reopenButton?.addEventListener('click', () => this._open());

        this._open();
    }

    _open() {
        // Am Dokument und nur dort, solange der Dialog offen ist. Ein Klick auf die abgedunkelte
        // Fläche schiebt den Fokus auf den Seitenkörper, ein Zuhörer am Dialog bekäme Escape dann
        // nicht mehr. Tastendrücke im Dialog steigen ohnehin bis hierher auf; ein zweiter Zuhörer
        // am Dialog verarbeitete sie doppelt und schickte das Rückstell-Formular zweimal ab.
        document.addEventListener('keydown', this._onKeydown);

        // Der Kasten sagt dasselbe wie der Dialog. Solange der Dialog steht, wäre er ein
        // Doppel; ohne JavaScript ist er das Einzige, was den Kunden erreicht.
        if (this._box) {
            this._box.hidden = true;
        }

        this._dialog.hidden = false;
        this._dialog.setAttribute('aria-modal', 'true');
        document.body.classList.add('rc-pickup-dialog-open');

        // Der Fokus muss in den Dialog, sonst tabbt sich eine Tastaturbedienung hinter ihm
        // durch die Seite und bedient etwas, das gerade verdeckt ist.
        this._confirmButton.focus();
    }

    _close() {
        document.removeEventListener('keydown', this._onKeydown);

        this._dialog.hidden = true;
        this._dialog.removeAttribute('aria-modal');

        // Zurück auf den Kasten: Wer schließt, ohne zu bestätigen, steht weiter vor einer
        // gesperrten Bestellung und braucht den Satz weiterhin vor Augen.
        if (this._box) {
            this._box.hidden = false;
        }

        document.body.classList.remove('rc-pickup-dialog-open');

        // Der Fokus muss aus dem Dialog heraus. Er ist jetzt `hidden`, und ein Fokus darin ist
        // für Tastatur und Vorlesewerkzeug eine Sackgasse: unsichtbar, nicht angesagt, und der
        // nächste Tab springt unvorhersehbar.
        //
        // Zielpunkt ist die Schaltfläche im Kasten, mit der sich der Dialog wieder öffnen lässt,
        // sonst der Kasten selbst. Das Element von vorher taugt nicht: Der Dialog geht beim
        // Seitenaufbau von selbst auf, „vorher" war also der Seitenkörper, und `body.focus()`
        // bewirkt nichts.
        if (this._reopenButton) {
            this._reopenButton.focus();
        } else if (this._box) {
            this._box.focus();
        }
    }

    /**
     * Fokus im Dialog halten und Escape zulassen.
     *
     * Escape bestätigt nicht. Mit Rückstell-Formular stellt es die Versandart zurück wie
     * „Abbrechen", ohne schließt es nur, und der Kasten steht weiter auf der Seite.
     */
    _onKeydown(event) {
        if (event.key === 'Escape') {
            // Escape tut dasselbe wie „Abbrechen": die Versandart zurückstellen. Ein bloßes
            // Zuklappen ließe den Kunden mit einer gesperrten Bestellung zurück; so ist der
            // Warenkorb danach wieder gültig. Escape ist damit keine Zustimmung, die Abholung ist
            // danach schlicht nicht mehr gewählt.
            if (this._revertForm) {
                this._revertForm.submit();
                return;
            }

            this._close();
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusable = this._dialog.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
        );
        if (focusable.length === 0) {
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    _onConfirm() {
        this._confirmButton.disabled = true;

        this._client.post(this.options.acknowledgeUrl, null, (response, request) => {
            if (request.status !== 200) {
                // Die Bestätigung ist nicht angekommen. Ein geschlossener Dialog ließe den Kunden
                // glauben, er habe bestätigt, und er liefe in die Sperre, ohne zu verstehen warum.
                this._confirmButton.disabled = false;
                this._dialog.querySelector('[data-rc-pickup-error]')?.removeAttribute('hidden');
                return;
            }

            // Neu laden statt die Schaltfläche im Browser freizuschalten: Die Sperre liegt als
            // Warenkorb-Fehler auf dem Server, und erst der nächste Seitenaufbau zeigt den
            // Warenkorb ohne sie. Alles andere wäre eine Anzeige, die der Wirklichkeit vorauseilt.
            window.location.reload();
        });
    }
}
