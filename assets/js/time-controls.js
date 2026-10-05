/* Explicit AM/PM controls independent of browser/OS regional settings. */
(() => {
 const parse=(value,allowStorage=false)=>{
  const text=String(value||'').trim();let m=text.match(/^(0?[1-9]|1[0-2]):([0-5]\d)\s*(AM|PM)$/i);
  if(m)return String(Number(m[1])%12+(m[3].toUpperCase()==='PM'?12:0)).padStart(2,'0')+':'+m[2];
  if(allowStorage&&/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(text))return text;
  return null;
 };
 const format=value=>{const m=String(value||'').match(/^(\d{2}):([0-5]\d)(?::[0-5]\d)?$/);if(!m||Number(m[1])>23)return value||'';return (Number(m[1])%12||12)+':'+m[2]+(Number(m[1])<12?' AM':' PM');};
 const datetime=value=>String(value||'').replace(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:[0-5]\d)(?::[0-5]\d)?$/,(_,date,time)=>date+' '+format(time));
 window.ViscarTime={parse,format,datetime};
 function enhance(input){
  if(input.dataset.timeEnhanced)return;input.dataset.timeEnhanced='1';
  const isDate=input.type==='datetime-local',required=input.required;
  const title=input.getAttribute('aria-label')||document.querySelector('label[for="'+input.id+'"]')?.textContent.trim()||input.closest('label')?.textContent.trim()||'Schedule';
  const wrap=document.createElement('span');wrap.className='time-control';wrap.dataset.timeFor=input.id||input.name;wrap.setAttribute('role','group');wrap.setAttribute('aria-label',title+' (12-hour time)');
  const date=isDate?document.createElement('input'):null;
  if(date){date.type='date';date.setAttribute('aria-label',title+' date');wrap.append(date);}
  const hour=document.createElement('select'),minute=document.createElement('select'),period=document.createElement('select');
  for(const [control,label] of [[hour,'Hour'],[minute,'Minute'],[period,'AM/PM']]){control.setAttribute('aria-label',title+' '+label);control.add(new Option(label,''));control.required=required;wrap.append(control);}
  for(let h=1;h<=12;h++)hour.add(new Option(String(h),String(h)));
  for(let m=0;m<60;m++){const value=String(m).padStart(2,'0');minute.add(new Option(value,value));}
  period.add(new Option('AM','AM'));period.add(new Option('PM','PM'));
  if(date)date.required=required;
  input.classList.add('time-storage');input.required=false;input.tabIndex=-1;input.setAttribute('aria-hidden','true');input.after(wrap);
  const descriptor=Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value');
  function sync(){
   const value=descriptor.get.call(input),time=isDate?value.slice(11,16):value.slice(0,5);
   if(date){date.value=value.slice(0,10);date.min=input.min.slice(0,10);date.max=input.max.slice(0,10);}
   const m=time.match(/^(\d{2}):(\d{2})$/);hour.value=m?String(Number(m[1])%12||12):'';minute.value=m?m[2]:'';period.value=m?(Number(m[1])<12?'AM':'PM'):'';
   wrap.hidden=input.hidden;for(const control of [date,hour,minute,period].filter(Boolean)){control.disabled=input.disabled||input.hidden;control.required=required&&!input.hidden;}
  }
  // Existing modal editors assign .value directly; reflect those assignments immediately.
  Object.defineProperty(input,'value',{configurable:true,get(){return descriptor.get.call(this);},set(value){descriptor.set.call(this,value);sync();}});
  function write(){
   const time=parse(hour.value+':'+minute.value+' '+period.value);
   descriptor.set.call(input,time&&(!date||date.value)?(date?date.value+'T':'')+time:'');
   input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));
  }
  for(const control of [date,hour,minute,period].filter(Boolean))control.addEventListener('change',write);
  input.addEventListener('change',()=>{if(input.value)sync();});
  new MutationObserver(sync).observe(input,{attributes:true,attributeFilter:['hidden','disabled','min','max']});sync();
  input.form?.addEventListener('reset',()=>setTimeout(sync,0));
 }
 function scan(){document.querySelectorAll('input[type="time"],input[type="datetime-local"]').forEach(enhance);}
 scan();new MutationObserver(records=>{if(records.some(record=>record.addedNodes.length))scan();}).observe(document.body,{childList:true,subtree:true});
 document.addEventListener('reset',()=>setTimeout(()=>document.querySelectorAll('[data-time-enhanced]').forEach(input=>input.dispatchEvent(new Event('change'))),0));
})();
