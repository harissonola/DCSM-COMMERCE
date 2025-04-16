import './bootstrap.js';
import './styles/app.css';
import { setProgressBarDelay } from '@hotwired/turbo';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');

// Configuration globale de Turbo
function configureTurbo() {
    setProgressBarDelay(999999);

    document.addEventListener('turbo:load', () => {
        const turboProgress = document.querySelector('.turbo-progress');
        if (turboProgress) turboProgress.style.display = 'none';
    });
}

// Gestion du spinner personnalisé sans suppression de scripts
function setupCustomSpinner() {
    let isFirstVisit = true;

    // À la navigation, on ne touche plus aux scripts afin de préserver Turbo
    document.addEventListener('turbo:before-visit', () => {
        // Si besoin, on peut ajouter ici d'autres opérations avant visite
    });

    document.addEventListener('turbo:visit', () => {
        if (isFirstVisit) {
            isFirstVisit = false;
            return;
        }

        const spinner = document.getElementById('custom-spinner');
        if (spinner) {
            spinner.classList.remove('hidden');
            spinner.timeout = setTimeout(() => {
                spinner.classList.add('hidden');
            }, 10000);
        }
    });

    document.addEventListener('turbo:load', () => {
        const spinner = document.getElementById('custom-spinner');
        if (spinner) {
            clearTimeout(spinner.timeout);
            spinner.classList.add('hidden');
        }
    });

    document.addEventListener('turbo:before-fetch-error', () => {
        const spinner = document.getElementById('custom-spinner');
        if (spinner) {
            clearTimeout(spinner.timeout);
            spinner.classList.add('hidden');
        }
    });
}

// Réinitialise (ou recrée) les composants JS nécessaires
function reinitializeComponents() {
    // Pour chaque plugin ou librairie qui nécessite une initialisation, vérifie si elle n'a pas déjà été initialisée.
    // Par exemple, pour PerfectScrollbar :
    if (typeof PerfectScrollbar !== 'undefined') {
        document.querySelectorAll('[data-perfect-scrollbar]').forEach(el => {
            // Si l'instance n'est pas encore associée, alors on la crée.
            if (!el._perfectScrollbarInitialized) {
                new PerfectScrollbar(el);
                el._perfectScrollbarInitialized = true;
            }
        });
    }

    // Ajoute ici d'autres initialisations ou recréations de plugins au besoin.
}

// Initialisation des fonctionnalités à chaque chargement Turbo
document.addEventListener('turbo:load', () => {
    configureTurbo();
    setupCustomSpinner();
    reinitializeComponents();
});

// Pour les rechargements manuels (si nécessaire)
// Ce rechargement complet est possible via window.location.reload()
// Cela ne fait pas intervenir la suppression de scripts, Turbo pourra ainsi fonctionner normalement
window.reloadWithSpinner = () => {
    const spinner = document.getElementById('custom-spinner');
    if (spinner) spinner.classList.remove('hidden');
    window.location.reload();
};