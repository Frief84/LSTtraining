(function () {
  'use strict';

  function initRegionFilter(form) {
    var land = form.querySelector('select[name="land"]');
    var bundesland = form.querySelector('select[name="bundesland"]');
    var box = form.querySelector('.filter-box--region');
    var status = form.querySelector('.region-filter-status');
    if (!land || !bundesland) return;

    var map = {};
    try {
      map = JSON.parse(bundesland.getAttribute('data-map') || '{}');
    } catch (_) {
      map = {};
    }

    function fillBundeslaender(selected) {
      var country = land.value || '';
      var options = [new Option('— Bundesland wählen —', '')];
      if (country) {
        options.push(new Option('Ohne Bundesland', '__none__'));
        (map[country] || []).forEach(function (name) {
          options.push(new Option(name, name));
        });
      }
      bundesland.replaceChildren.apply(bundesland, options);
      bundesland.disabled = !country;
      bundesland.value = selected || '';
    }

    function updateStatus() {
      var country = land.value || '';
      var state = bundesland.value || '';
      if (box) box.classList.toggle('is-active', !!state);
      if (!status) return;
      if (!country) {
        status.textContent = 'Alle Länder werden angezeigt.';
      } else if (!state) {
        status.textContent = 'Nächster Schritt: Bundesland für ' + country + ' auswählen.';
      } else {
        var label = bundesland.options[bundesland.selectedIndex]?.text || state;
        status.textContent = 'Regionsfilter aktiv: ' + label + ' (' + country + ').';
      }
    }

    land.addEventListener('change', function () {
      fillBundeslaender('');
      updateStatus();
      form.requestSubmit();
    });
    bundesland.addEventListener('change', function () {
      updateStatus();
      form.requestSubmit();
    });

    fillBundeslaender(bundesland.value || '');
    updateStatus();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-lst-region-filter-form]').forEach(initRegionFilter);
  });
})();
