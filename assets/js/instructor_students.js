'use strict';
(function(){
  function ready(fn){ if(document.readyState!=='loading'){ fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }
  ready(function(){
    const meta = document.querySelector('meta[name="app-base-url"]');
    const BASE_URL = meta ? meta.getAttribute('content') : '';

    const input = document.getElementById('qa_search');
    const list = document.getElementById('qa_results');
    const hiddenId = document.getElementById('qa_student_id');

    const debounce = (fn, d=220)=>{let t;return (...a)=>{clearTimeout(t);t=setTimeout(()=>fn.apply(null,a),d)}};
    function hide(){ if(list){ list.classList.add('d-none'); list.innerHTML=''; } }
    function show(){ if(list){ list.classList.remove('d-none'); } }
    function render(items){
      if(!list) return;
      list.innerHTML='';
      if (!items || items.length===0){
        const li = document.createElement('div');
        li.className = 'list-group-item text-muted';
        li.textContent = 'No matches';
        list.appendChild(li);
        show();
        return;
      }
      items.forEach(it=>{
        const a = document.createElement('a');
        a.href = '#';
        a.className = 'list-group-item list-group-item-action';
        a.textContent = `${it.student_id} · ${it.name} · ${it.email}`;
        a.addEventListener('click', (e)=>{
          e.preventDefault();
          if (input) input.value = `${it.name} (${it.student_id})`;
          if (hiddenId) hiddenId.value = it.id;
          hide();
        });
        list.appendChild(a);
      });
      show();
    }
    const search = debounce(async ()=>{
      if (hiddenId) hiddenId.value = '';
      const q = (input?.value || '').trim();
      if (q.length < 2){ hide(); return; }
      try{
        const r = await fetch((BASE_URL||'') + '/instructor/ajax/student_search.php?q=' + encodeURIComponent(q), {credentials:'same-origin'});
        if (!r.ok){ hide(); return; }
        const data = await r.json();
        render(data.results || []);
      }catch(e){ hide(); }
    }, 250);

    input?.addEventListener('input', search);
    input?.addEventListener('blur', ()=> setTimeout(hide, 120));
    input?.addEventListener('focus', ()=>{ if(list && list.children.length>0) show(); });

    document.getElementById('quickAddForm')?.addEventListener('submit', (e)=>{
      if (!hiddenId?.value){
        e.preventDefault();
        const warn = document.createElement('div');
        warn.className = 'alert alert-warning mt-2';
        warn.textContent = 'Please select a student from the suggestions. If you cannot find the student, contact the admin to create or update the student record.';
        document.getElementById('quickAddForm').appendChild(warn);
        setTimeout(()=>{ warn.remove(); }, 4000);
      }
    });
  });
})();
