// js/fahrzeuge.js
(function(){
  document.addEventListener('DOMContentLoaded', function(){

    // Enter auf "Seite"-Feld springt direkt
    const pagingInput = document.querySelector('.paging-input input.current-page');
    if (pagingInput) {
      pagingInput.addEventListener('keydown', function(e){
        if (e.key === 'Enter') {
          e.preventDefault();
          const page = Math.max(1, parseInt(pagingInput.value || '1', 10));
          const url = new URL(window.location.href);
          url.searchParams.set('paged', String(page));
          window.location.assign(url.toString());
        }
      });
    }

    // Sortierung: Server-Links in den THs
    document.querySelectorAll('#fahrzeuge-table thead th a.sort-link')
      .forEach(a => a.addEventListener('click', function(){ /* default nav */ }));
  });
})();
