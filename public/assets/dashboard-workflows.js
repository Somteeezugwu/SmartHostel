(()=>{
  'use strict';
  const role=document.body.dataset.role;
  const view=document.body.dataset.view;
  const reducedMotion=window.matchMedia('(prefers-reduced-motion: reduce)');
  const escapeHtml=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const closeWithMotion=(dialog,value='')=>{
    if(!dialog?.open)return;
    if(reducedMotion.matches){dialog.close(value);return;}
    dialog.classList.add('dialog-leave');
    window.setTimeout(()=>{dialog.classList.remove('dialog-leave');dialog.close(value);},180);
  };
  document.querySelectorAll('dialog').forEach(dialog=>{
    dialog.addEventListener('cancel',event=>{event.preventDefault();closeWithMotion(dialog,'cancel');});
    dialog.querySelector('form[method="dialog"]')?.addEventListener('submit',event=>{
      event.preventDefault();
      closeWithMotion(dialog,event.submitter?.value||'');
    });
  });
  const notificationPopover=document.querySelector('#notification-popover');
  if(notificationPopover){
    let closing=false,finalHide=false;
    new MutationObserver(()=>{
      if(finalHide){finalHide=false;return;}
      if(notificationPopover.hidden&&!closing&&!reducedMotion.matches){
        closing=true;
        notificationPopover.hidden=false;
        notificationPopover.classList.add('notification-leave');
        window.setTimeout(()=>{
          notificationPopover.classList.remove('notification-leave');
          finalHide=true;
          notificationPopover.hidden=true;
          closing=false;
        },180);
      }else if(!notificationPopover.hidden){
        notificationPopover.classList.remove('notification-leave');
      }
    }).observe(notificationPopover,{attributes:true,attributeFilter:['hidden']});
  }
  const addToastExit=toast=>{
    if(toast.dataset.exitMotion==='ready')return;
    toast.dataset.exitMotion='ready';
    const remove=toast.remove.bind(toast);
    let exiting=false;
    toast.remove=()=>{
      if(exiting)return;
      if(reducedMotion.matches){remove();return;}
      exiting=true;
      toast.classList.add('toast-leave');
      window.setTimeout(remove,180);
    };
  };
  const toastObserver=new MutationObserver(records=>records.forEach(record=>record.addedNodes.forEach(node=>{
    if(!(node instanceof Element))return;
    if(node.matches('.toast'))addToastExit(node);
    node.querySelectorAll('.toast').forEach(addToastExit);
  })));
  toastObserver.observe(document.body,{childList:true,subtree:true});
  document.querySelectorAll('.toast').forEach(addToastExit);
  let csrf='';
  const session=async()=>{
    if(csrf)return csrf;
    const response=await fetch('/api.php?action=session',{credentials:'same-origin',headers:{Accept:'application/json'}});
    const data=await response.json();
    if(!response.ok)throw Error(data.error||'Your session has expired.');
    csrf=data.csrf;
    return csrf;
  };
  const api=async(action,payload)=>{
    const method=payload?'POST':'GET';
    const headers={Accept:'application/json'};
    if(payload){headers['Content-Type']='application/json';headers['X-CSRF-Token']=await session();}
    const response=await fetch('/api.php?action='+encodeURIComponent(action),{method,credentials:'same-origin',headers,...(payload?{body:JSON.stringify(payload)}:{})});
    const data=await response.json();
    if(!response.ok)throw Error(data.error||'The request failed. Please try again.');
    return data;
  };
  const announce=(message,isError=false)=>{
    const notice=document.querySelector('#notice');
    if(!notice)return;
    notice.className=isError?'error-message':'success-message';
    notice.textContent=message;
    notice.setAttribute('role','status');
    notice.setAttribute('aria-live','polite');
  };
  const renderStudentRequests=async()=>{
    const container=document.querySelector('#room-switch-requests');
    if(!container)return;
    try{
      const data=await api('room-switches');
      const requests=data.requests||[];
      container.innerHTML=requests.length?`<div class="switch-request-list">${requests.map(request=>{
        const partner=request.direction==='outgoing'?`${request.target_first_name} ${request.target_last_name}`:`${request.first_name} ${request.last_name}`;
        return `<article class="switch-request-row"><div><b>${escapeHtml(partner)}</b><p>${escapeHtml(request.from_hostel)} · Room ${escapeHtml(request.from_room)} → ${escapeHtml(request.to_hostel)} · Room ${escapeHtml(request.to_room)} · ${escapeHtml(request.to_bed)}</p><span class="pill">${escapeHtml(request.status)} · ${escapeHtml(request.direction)}</span></div>${request.status==='pending'&&request.direction==='outgoing'?`<button class="button secondary cancel-switch" data-id="${Number(request.id)}" type="button">Cancel request</button>`:''}</article>`;
      }).join('')}</div>`:'<div class="empty"><p>No room-switch requests yet.</p></div>';
      container.querySelectorAll('.cancel-switch').forEach(button=>button.addEventListener('click',async()=>{
        button.disabled=true;
        try{
          await api('room-switch-cancel',{request_id:Number(button.dataset.id)});
          announce('Room-switch request cancelled.');
          await renderStudentRequests();
        }catch(error){announce(error.message,true);button.disabled=false;}
      }));
    }catch(error){container.innerHTML=`<p class="error-message">${escapeHtml(error.message)}</p>`;}
  };
  if(role==='student'&&view==='matches'){
    const allocationSummary=document.querySelector('#matches-current');
    let matchRecords=[];
    const cardKey=(name,score,faculty,level)=>JSON.stringify([name,score,faculty,level]);
    const decorateNoBeds=()=>{
      if(!matchRecords.length)return;
      const matches=new Map(matchRecords.map(match=>[
        cardKey(`${match.first_name} ${match.last_name||''}`.trim(),`${match.score}% compatible`,`${match.faculty||'Faculty not provided'}${match.department?` · ${match.department}`:''}`,`Academic level: ${match.academic_level||'Not provided'}`),
        match
      ]));
      document.querySelectorAll('#matches .match-card').forEach(card=>{
        if(card.querySelector('.room-vacancy-note'))return;
        const paragraphs=[...card.querySelectorAll('p')];
        const key=cardKey(card.querySelector('h3')?.textContent.trim()||'',paragraphs[0]?.textContent.trim()||'',paragraphs[1]?.textContent.trim()||'',paragraphs[2]?.textContent.trim()||'');
        const match=matches.get(key);
        const details=card.querySelector('.match-details');
        if(!match?.allocated||match.room_available||!details)return;
        const note=document.createElement('p');
        note.className='room-vacancy-note';
        note.textContent='No available beds in this room.';
        details.after(note);
      });
    };
    const dashboardView=document.querySelector('#dashboard-view');
    const matchDomObserver=new MutationObserver(decorateNoBeds);
    if(dashboardView)matchDomObserver.observe(dashboardView,{childList:true,subtree:true});
    api('matches').then(data=>{
      matchRecords=data.matches||[];
      decorateNoBeds();
      if(data.student_allocation&&allocationSummary){
        const allocation=data.student_allocation;
        allocationSummary.textContent=`Current allocation: ${allocation.hostel} · Room ${allocation.room_number} · ${allocation.bed_label}. Approved room-switch review is required for any move.`;
      }
    }).catch(()=>{});
    const form=document.querySelector('#room-switch-dialog-form');
    const dialog=document.querySelector('#switch-dialog');
    const cancel=document.querySelector('#switch-dialog-cancel');
    const closeDialog=()=>closeWithMotion(dialog,'cancel');
    cancel?.addEventListener('click',closeDialog);
    dialog?.addEventListener('click',event=>{if(event.target===dialog)closeDialog();});
    form?.addEventListener('submit',async event=>{
      event.preventDefault();
      if(!form.reportValidity())return;
      const submit=form.querySelector('button[type="submit"]');
      const feedback=document.querySelector('#switch-form-feedback');
      submit.disabled=true;
      feedback.textContent='Submitting request…';
      feedback.className='form-feedback is-loading';
      try{
        const data=await api('room-switch-request',{
          target_student_id:Number(form.dataset.target),
          bed_space_id:Number(form.querySelector('[name="bed_space_id"]').value),
          reason:form.querySelector('[name="reason"]').value.trim()
        });
        closeDialog();
        announce(data.message||'Room-switch request submitted for administrator review.');
        await renderStudentRequests();
      }catch(error){
        feedback.textContent=error.message;
        feedback.className='form-feedback error-message';
      }finally{submit.disabled=false;}
    });
    renderStudentRequests();
  }
  if((role==='admin'||role==='super_admin')&&view==='room_switches'){
    const panel=document.querySelector('#dashboard-view');
    if(!panel)return;
    const renderAdminRequests=async()=>{
      panel.innerHTML='<section class="card"><h2>Room-switch requests</h2><div class="loading-indicator" role="status">Loading requests…</div></section>';
      try{
        const data=await api('admin-room-switches');
        const requests=data.requests||[];
        panel.innerHTML=`<section class="card"><h2>Room-switch requests</h2>${requests.length?`<div class="switch-request-list">${requests.map(request=>`<article class="switch-request-row"><div><b>${escapeHtml(request.first_name)} ${escapeHtml(request.last_name)} (${escapeHtml(request.matric_no)})</b><p>${escapeHtml(request.from_hostel)} · Room ${escapeHtml(request.from_room)} · ${escapeHtml(request.from_bed)} → ${escapeHtml(request.to_hostel)} · Room ${escapeHtml(request.to_room)} · ${escapeHtml(request.to_bed)}</p><span class="pill">${escapeHtml(request.status)}</span></div>${request.status==='pending'?`<div class="switch-admin-actions"><button class="button review-switch" data-id="${Number(request.id)}" data-decision="approved" type="button">Approve move</button><button class="button secondary review-switch" data-id="${Number(request.id)}" data-decision="rejected" type="button">Reject</button></div>`:''}</article>`).join('')}</div>`:'<div class="empty"><p>No room-switch requests are waiting for review.</p></div>'}</section>`;
        panel.querySelectorAll('.review-switch').forEach(button=>button.addEventListener('click',async()=>{
          button.disabled=true;
          try{
            const result=await api('review-room-switch',{request_id:Number(button.dataset.id),decision:button.dataset.decision});
            announce(result.message||`Room switch ${button.dataset.decision}.`);
            await renderAdminRequests();
          }catch(error){announce(error.message,true);button.disabled=false;}
        }));
      }catch(error){panel.innerHTML=`<section class="card"><h2>Room-switch requests</h2><p class="error-message">${escapeHtml(error.message)}</p></section>`;}
    };
    const observer=new MutationObserver(()=>{
      if(!panel.children.length)return;
      observer.disconnect();
      renderAdminRequests();
    });
    observer.observe(panel,{childList:true});
  }
})();