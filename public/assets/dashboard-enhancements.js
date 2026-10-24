(()=>{
  const panel=document.querySelector('#dashboard-view');
  if(!panel||document.body.dataset.role!=='student')return;
  const observer=new MutationObserver(()=>{
    if(!panel.querySelector('.summary-card'))return;
    observer.disconnect();
    const get=async action=>{
      const response=await fetch('/api.php?action='+encodeURIComponent(action),{credentials:'same-origin',headers:{Accept:'application/json'}});
      const data=await response.json();
      if(!response.ok)throw Error(data.error||'Request failed');
      return data;
    };
    get('dashboard').then(data=>{
      const profile=data.profile||{};
      const fields=['first_name','last_name','matric_no','phone','faculty','department','course','academic_level'];
      const answers=['sleep_time','study_time','cleanliness','noise_tolerance','social_style','guest_frequency'];
      const filled=fields.filter(key=>String(profile[key]??'').trim()!=='').length;
      const answered=answers.filter(key=>profile[key]!==null&&profile[key]!==undefined&&profile[key]!=='').length;
      const card=[...panel.querySelectorAll('.summary-card')].find(item=>item.getAttribute('href')==='/dashboard.php?view=profile');
      if(card)card.querySelector('h2').textContent=`${filled} of 8 profile fields; ${answered} of 6 answers`;
    }).catch(()=>{});
    get('matches').then(data=>{
      const card=[...panel.querySelectorAll('.summary-card')].find(item=>item.getAttribute('href')==='/dashboard.php?view=matches');
      if(card)card.querySelector('h2').textContent=data.matches.length?`${data.matches.length} recommendations shown`:(data.message||'No recommendations currently available');
    }).catch(()=>{});
    get('beds').then(data=>{
      const card=[...panel.querySelectorAll('.summary-card')].find(item=>item.getAttribute('href')==='/dashboard.php?view=beds');
      if(card)card.querySelector('h2').textContent=`${data.beds.length} beds currently available`;
    }).catch(()=>{});
  });
  observer.observe(panel,{childList:true});
})();
