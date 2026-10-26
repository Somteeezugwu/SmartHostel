(()=>{
  const storageKey='smarthostel-theme';
  const root=document.documentElement;
  let theme='light';
  try { theme=localStorage.getItem(storageKey)==='dark'?'dark':'light'; } catch (_) {}
  root.dataset.theme=theme;
  const init=()=>{
    const button=document.querySelector('[data-theme-toggle]');
    if(!button)return;
    const render=()=>{
      const dark=root.dataset.theme==='dark';
      button.innerHTML='<svg class="theme-icon theme-sun" aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"></path></svg><svg class="theme-icon theme-moon" aria-hidden="true" viewBox="0 0 24 24"><path d="M20.5 15.5A8.5 8.5 0 0 1 8.5 3.5 8.5 8.5 0 1 0 20.5 15.5Z"></path></svg><span class="sr-only">Toggle color theme</span>';
      button.setAttribute('aria-pressed',String(dark));
      button.setAttribute('aria-label',dark?'Switch to light theme':'Switch to dark theme');
      button.title=dark?'Switch to light theme':'Switch to dark theme';
      const meta=document.querySelector('meta[name="theme-color"]');
      if(meta)meta.content=dark?'#111A28':'#17243A';
    };
    button.addEventListener('click',()=>{
      root.dataset.theme=root.dataset.theme==='dark'?'light':'dark';
      try { localStorage.setItem(storageKey,root.dataset.theme); } catch (_) {}
      render();
    });
    render();
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
