/* Uses aggregate counts only; booking submission still locks and checks capacity. */
(() => {
 const schedule=document.getElementById('selSchedule');
 if(!schedule)return;
 const box=document.createElement('div');box.className='availability-calendar';
 const month=document.createElement('input');month.type='month';month.setAttribute('aria-label','Availability month');
 const today=new Date();const localDate=new Date(today.getTime()-today.getTimezoneOffset()*60000).toISOString().slice(0,10);
 month.value=localDate.slice(0,7);month.min=month.value;
 const grid=document.createElement('div');grid.className='availability-grid';grid.setAttribute('aria-label','Available dates');
 const status=document.createElement('p');status.setAttribute('role','status');
 const legend=document.createElement('div');legend.className='availability-legend';legend.innerHTML='<span><i class=available></i>Available</span><span><i class=full></i>Full</span><span><i class=past></i>Past / unavailable</span>';const times=document.createElement('select');times.setAttribute('aria-label','Available time');times.hidden=true;box.append(month,legend,status,grid,times);schedule.before(box);
 let serial=0;
 window.refreshAvailability=async()=>{
  const S=window.getBookingSelection();if(!S.service_id)return;
  const request=++serial;status.textContent='Loading availability…';grid.replaceChildren();
  try {
   const query=new URLSearchParams({ajax:'availability',service_id:S.service_id,parish_id:S.parish_id,month:month.value});
   const result=await fetch('apply_service.php?'+query);const data=await result.json();if(request!==serial)return;
   if(!data.ok)throw Error(data.message||'Unable to load availability.');
   const fixed=data.schedule_mode==='fixed';schedule.hidden=fixed;times.hidden=true;status.textContent='Select a green date, then choose a time.';
   for(const day of data.days){const button=document.createElement('button');button.type='button';button.textContent=String(Number(day.date.slice(-2)));button.disabled=day.full||day.past||day.unavailable;button.className=(day.past||day.unavailable)?'past':day.full?'full':'available';button.dataset.date=day.date;button.setAttribute('aria-label',day.date+(day.unavailable?' unavailable':day.full?' full':day.past?' past':' available'));button.onclick=()=>{if(fixed){times.replaceChildren(new Option('Choose an available time',''));for(const slot of day.slots){const option=new Option(slot.time+(slot.available?'':' ? unavailable'),slot.time);option.disabled=!slot.available;times.add(option);}times.hidden=false;schedule.value='';times.onchange=()=>{schedule.value=times.value?day.date+'T'+times.value:'';schedule.dispatchEvent(new Event('change'));};}else{schedule.value=day.date+'T09:00';}schedule.dispatchEvent(new Event('change'));};grid.append(button);}
   markSelected();
  }catch(error){if(request===serial)status.textContent=error.message;}
 };
 function markSelected(){for(const button of grid.children){const selected=button.dataset.date===schedule.value.slice(0,10);button.classList.toggle('selected',selected);button.setAttribute('aria-pressed',String(selected));}}
 month.onchange=window.refreshAvailability;schedule.addEventListener('change',markSelected);
 document.addEventListener('click',event=>{if(event.target.closest('.svc-card'))window.refreshAvailability();});
})();
