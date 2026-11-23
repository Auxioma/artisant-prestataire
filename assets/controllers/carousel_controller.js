/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * Carrousel des maquettes V2 (catégories, mieux notés, professionnels) : défilement natif (scroll-snap, clavier,
 * tactile) ; les flèches défilent d'une « page » et les points indiquent la page courante. Sans JavaScript, la
 * liste reste défilable. Flèches et points masqués quand tout tient à l'écran ; mouvement réduit respecté.
 */
export default class extends Controller {
    static targets = ['track', 'prev', 'next', 'dots'];

    connect() {
        this.update = this.update.bind(this);
        this.trackTarget.addEventListener('scroll', this.update, { passive: true });
        this.observer = new ResizeObserver(() => this.render());
        this.observer.observe(this.trackTarget);
        this.render();
    }

    disconnect() {
        this.trackTarget.removeEventListener('scroll', this.update);
        this.observer?.disconnect();
    }

    previous() {
        this.go(-1);
    }

    next() {
        this.go(1);
    }

    go(direction) {
        const track = this.trackTarget;
        const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        track.scrollBy({ left: direction * this.step(), behavior: smooth ? 'smooth' : 'auto' });
    }

    /** Largeur visible arrondie au nombre entier de cartes, pour ne jamais couper une carte en arrivant. */
    step() {
        const track = this.trackTarget;
        const item = track.firstElementChild;
        if (!item) {
            return track.clientWidth;
        }
        const gap = parseFloat(getComputedStyle(track).columnGap) || 0;
        const size = item.getBoundingClientRect().width + gap;

        return Math.max(1, Math.floor((track.clientWidth + gap) / size)) * size;
    }

    pages() {
        const track = this.trackTarget;

        return track.scrollWidth <= track.clientWidth + 1 ? 1 : Math.ceil((track.scrollWidth - track.clientWidth) / this.step()) + 1;
    }

    render() {
        const pages = this.pages();
        this.element.classList.toggle('is-static', pages <= 1);
        if (this.hasDotsTarget) {
            this.dotsTarget.replaceChildren(...Array.from({ length: pages > 1 ? pages : 0 }, (_, index) => {
                const dot = document.createElement('button');
                dot.type = 'button';
                dot.className = 'tm-carousel__dot';
                dot.setAttribute('aria-label', `Page ${index + 1} sur ${pages}`);
                dot.addEventListener('click', () => {
                    const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                    this.trackTarget.scrollTo({ left: index * this.step(), behavior: smooth ? 'smooth' : 'auto' });
                });

                return dot;
            }));
        }
        this.update();
    }

    update() {
        const track = this.trackTarget;
        const max = track.scrollWidth - track.clientWidth;
        if (this.hasPrevTarget) {
            this.prevTarget.disabled = track.scrollLeft <= 1;
        }
        if (this.hasNextTarget) {
            this.nextTarget.disabled = track.scrollLeft >= max - 1;
        }
        if (this.hasDotsTarget) {
            const current = track.scrollLeft >= max - 1 ? this.dotsTarget.children.length - 1 : Math.round(track.scrollLeft / this.step());
            Array.from(this.dotsTarget.children).forEach((dot, index) => dot.setAttribute('aria-current', index === current ? 'true' : 'false'));
        }
    }
}
