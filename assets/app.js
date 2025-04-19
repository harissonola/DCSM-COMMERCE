import './bootstrap.js';
import './styles/app.css';

// Configuration globale de Turbo
Turbo.setProgressBarDelay(0); // Désactive complètement la barre de progression native

console.log('Application Turbo chargée avec succès !');

// Gestion du spinner personnalisé
const spinner = document.getElementById('custom-spinner');

// Cache le spinner initialement
if (spinner) {
    spinner.classList.add('hidden');
}

// Liste des scripts essentiels à ne pas supprimer
const ESSENTIAL_SCRIPTS = [
    'bootstrap.js',
    'styles/app.css',
    'turbo.js' // ou le nom de votre fichier Turbo principal
];

// Gestion des événements Turbo
document.addEventListener('turbo:before-visit', () => {
    // Nettoie les scripts avant même de quitter la page actuelle
    cleanNonEssentialScripts();
    
    if (spinner) spinner.classList.remove('hidden');
});

document.addEventListener('turbo:before-render', (event) => {
    if (spinner) spinner.classList.add('hidden');
    
    // Nettoie les scripts dupliqués avant le rendu
    cleanDuplicateScripts();
    
    // Alternative: supprimer tous les scripts non essentiels
    // cleanNonEssentialScripts();
});

document.addEventListener('turbo:render', () => {
    // Réinitialise les librairies après le rendu
    if (typeof AOS !== 'undefined') {
        AOS.init();
    }
});

document.addEventListener('turbo:load', () => {
    console.log('Turbo:load - Page chargée');
});

// Fonction pour nettoyer les scripts non essentiels
function cleanNonEssentialScripts() {
    const scripts = document.querySelectorAll('script[src]');
    
    scripts.forEach(script => {
        const src = script.getAttribute('src');
        const isEssential = ESSENTIAL_SCRIPTS.some(essential => src.includes(essential));
        
        if (!isEssential) {
            script.remove();
            console.log(`Script supprimé: ${src}`);
        }
    });
}

// Fonction pour nettoyer les scripts dupliqués
function cleanDuplicateScripts() {
    const scripts = document.querySelectorAll('script[src]');
    const loadedScripts = new Set();

    scripts.forEach(script => {
        const src = script.src;

        if (loadedScripts.has(src)) {
            script.remove();
            console.log(`Script dupliqué supprimé: ${src}`);
        } else {
            loadedScripts.add(src);
        }
    });
}

// Version améliorée pour debug
window.debugScripts = function() {
    console.group('Scripts actuellement chargés');
    const scripts = Array.from(document.querySelectorAll('script[src]'));
    
    if (scripts.length === 0) {
        console.log('Aucun script chargé');
    } else {
        scripts.forEach((s, i) => {
            console.log(`${i + 1}. ${s.src} ${s.hasAttribute('data-turbo-eval') ? '(eval)' : ''}`);
        });
    }
    
    console.groupEnd();
};