import './bootstrap.js';
import './styles/app.css';
import { setProgressBarDelay } from '@hotwired/turbo';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');

// Liste des scripts à conserver (ceux gérés par Webpack/AssetMapper)
const PRESERVED_SCRIPTS = [
    'runtime.js',
    'vendors-node_modules_',
    'app.js',
    'controllers/'
];

// Configuration globale de Turbo
function configureTurbo() {
    setProgressBarDelay(999999);
    
    document.addEventListener('turbo:load', () => {
        const turboProgress = document.querySelector('.turbo-progress');
        if (turboProgress) turboProgress.style.display = 'none';
    });
}

// Marque et désactive les scripts indésirables
function markScriptsForRemoval() {
    document.querySelectorAll('script[src]').forEach(script => {
        const shouldRemove = !PRESERVED_SCRIPTS.some(term => script.src.includes(term));
        if (shouldRemove) {
            // Marquage pour suppression et désactivation afin de ne pas interférer avec Turbo
            script.setAttribute('data-should-remove', 'true');
            script.type = 'text/plain'; // Désactive l'exécution lors du rechargement
        }
    });
}

// Supprime définitivement les scripts marqués
function removeMarkedScripts() {
    document.querySelectorAll('script[data-should-remove]').forEach(script => {
        script.remove();
    });
}

// Gestion du spinner personnalisé
function setupCustomSpinner() {
    let isFirstVisit = true;

    document.addEventListener('turbo:before-visit', () => {
        // Marquer les scripts indésirables juste avant la navigation
        markScriptsForRemoval();
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
        // Une fois la nouvelle page chargée, on supprime définitivement les scripts marqués
        removeMarkedScripts();
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

// Réinitialise les composants JS nécessaires
function reinitializeComponents() {
    // Exemple pour recharger un plugin
    if (typeof PerfectScrollbar !== 'undefined') {
        document.querySelectorAll('[data-perfect-scrollbar]').forEach(el => {
            new PerfectScrollbar(el);
        });
    }
    
    // Réinitialiser d'autres librairies si nécessaire
}

// Initialisation de Turbo et des fonctionnalités à chaque chargement
document.addEventListener('turbo:load', () => {
    configureTurbo();
    setupCustomSpinner();
    reinitializeComponents();
    // Note : la redéclaration manuelle de l'événement turbo:load a été retirée
});

// Pour les rechargements manuels
window.reloadWithSpinner = () => {
    markScriptsForRemoval();
    const spinner = document.getElementById('custom-spinner');
    if (spinner) spinner.classList.remove('hidden');
    window.location.reload();
};
