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
      button.textContent=dark?'Light mode':'Dark mode';
      button.setAttribute('aria-pressed',String(dark));
      button.setAttribute('aria-label',dark?'Switch to light theme':'Switch to dark theme');
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
