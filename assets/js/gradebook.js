(function(){
  'use strict';

  function ready(fn){ if(document.readyState!=='loading'){ fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }

  ready(function(){
    // Keyboard navigation for mass grading inputs
    var massForm = document.querySelector('form[action=""], form[method="POST"]');
    var massGradeSection = document.getElementById('mass-grade');
    if (massGradeSection){
      var inputs = massGradeSection.querySelectorAll('input[name="score[]"]');
      inputs.forEach(function(input, idx){
        input.addEventListener('keydown', function(e){
          var key = e.key;
          if (key === 'Enter' || key === 'ArrowDown'){
            e.preventDefault();
            var next = inputs[idx+1];
            if (next){ next.focus(); next.select && next.select(); }
          } else if (key === 'ArrowUp'){
            e.preventDefault();
            var prev = inputs[idx-1];
            if (prev){ prev.focus(); prev.select && prev.select(); }
          } else if ((e.ctrlKey || e.metaKey) && key.toLowerCase() === 'enter'){
            e.preventDefault();
            // Submit nearest form (mass grading form)
            var form = input.closest('form');
            if (form){ form.submit(); }
          }
        });
      });
    }

    // Scroll position preservation for POST submits
    document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(function(f){
      f.addEventListener('submit', function(){
        try { sessionStorage.setItem('gbScroll', String(window.scrollY)); } catch(_) {}
      });
    });

    // Restore scroll position if no hash
    if (!location.hash){
      try {
        var y = sessionStorage.getItem('gbScroll');
        if (y !== null){ window.scrollTo({ top: parseInt(y,10) || 0, behavior: 'instant' in window ? 'instant' : 'auto' }); }
      } catch(_){ }
    }

    // If there is a hash, scroll and focus into the enrollment block
    if (location.hash){
      var id = location.hash.slice(1);
      var el = document.getElementById(id);
      if (el){
        // Scroll into view considering sticky header (CSS takes care of visual)
        el.scrollIntoView({ behavior: 'auto', block: 'start' });
        // Try focus first input inside the enrollment block
        var focusTarget = el.querySelector('input[type="text"], input[type="number"], select, textarea, button');
        if (focusTarget){ try { focusTarget.focus(); } catch(_){ } }
      }
    }
  });
})();
