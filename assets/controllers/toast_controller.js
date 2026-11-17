import { Controller } from '@hotwired/stimulus';

/*
 * TM / Toast : fermeture automatique (5 s par défaut, jamais pour les erreurs — CDC §3.3),
 * mise en pause au survol et au focus (WCAG 2.2.1), fermeture manuelle.
 */
export default class extends Controller {
    static values = { delay: Number };

    connect() {
        this.remaining = this.delayValue;
        this.resume();
    }

    disconnect() {
        this.pause();
    }

    pause() {
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
            this.remaining -= Date.now() - this.startedAt;
        }
    }

    resume() {
        if (this.remaining > 0 && !this.timer) {
            this.startedAt = Date.now();
            this.timer = setTimeout(() => this.close(), this.remaining);
        }
    }

    close() {
        this.pause();
        this.element.hidden = true;
        this.element.remove();
    }
}
