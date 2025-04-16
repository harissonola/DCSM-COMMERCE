import './bootstrap.js';
import './styles/app.css';
import { setProgressBarDelay } from '@hotwired/turbo';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');

// Configuration globale
let isFirstLoad = true;
const spinner = document.getElementById('custom-spinner');

// Fonction pour initialiser Turbo
function initTurbo() {
    // Désactive la barre de progression native
    setProgressBarDelay(999999);
    
    // Cache la barre de progression si elle apparaît
    const hideTurboProgress = () => {
        const progressBar = document.querySelector('.turbo-progress');
        if (progressBar) progressBar.style.display = 'none';
    };

    // Gestion du spinner
    const handleSpinner = () => {
        if (!spinner) return;

        // Affiche le spinner sauf pour le premier chargement
        document.addEventListener('turbo:visit', () => {
            if (!isFirstLoad) {
                spinner.classList.remove('hidden');
                spinner.timeout = setTimeout(() => {
                    spinner.classList.add('hidden');
                }, 10000); // Timeout de sécurité
            }
            isFirstLoad = false;
        });

        // Cache le spinner quand la page est chargée
        document.addEventListener('turbo:load', () => {
            clearTimeout(spinner.timeout);
            spinner.classList.add('hidden');
            hideTurboProgress();
        });

        // Cache le spinner en cas d'erreur
        document.addEventListener('turbo:before-fetch-error', () => {
            clearTimeout(spinner.timeout);
            spinner.classList.add('hidden');
        });
    };

    // Réinitialisation des composants après navigation
    const resetComponents = () => {
        document.addEventListener('turbo:load', () => {
            // Réinitialise les plugins nécessaires ici
            if (typeof PerfectScrollbar !== 'undefined') {
                document.querySelectorAll('[data-perfect-scrollbar]').forEach(el => {
                    new PerfectScrollbar(el);
                });
            }
            
            // Réinitialise le menu Sneat si nécessaire
            if (typeof Menu !== 'undefined' && document.getElementById('layout-menu')) {
                new Menu(document.getElementById('layout-menu'));
            }
        });
    };

    handleSpinner();
    resetComponents();
}

// Initialisation
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        initTurbo();
        isFirstLoad = false;
    });
} else {
    initTurbo();
    isFirstLoad = false;
}

// Pour les rechargements manuels
window.reloadWithSpinner = () => {
    if (spinner) spinner.classList.remove('hidden');
};