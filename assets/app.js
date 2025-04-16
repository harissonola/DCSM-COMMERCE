import './bootstrap.js';
import './styles/app.css';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');

// Fonction pour supprimer uniquement les anciens scripts JS
function removeOldScripts() {
    document.querySelectorAll('script[src]').forEach(script => script.remove());
}

// Avant de rendre la nouvelle page, on supprime les anciens scripts
document.addEventListener('turbo:before-render', function () {
    
    removeOldScripts();
});