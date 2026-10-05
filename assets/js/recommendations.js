(() => {
 const viewport=window.visualViewport;
 if(viewport){let timer;const adjust=()=>{document.documentElement.style.setProperty('--visible-height',viewport.height+'px');clearTimeout(timer);timer=setTimeout(()=>{const field=document.activeElement;if(field?.matches('input,textarea,select'))field.scrollIntoView({block:'center',behavior:'smooth'});},120);};viewport.addEventListener('resize',adjust);document.addEventListener('focusin',adjust);}
 for(const input of document.querySelectorAll('input[type="search"],input[placeholder*="Search"],input[placeholder*="search"]')){
  if(input.oninput||input.onkeyup)continue;
  const form=input.form;if(!form||(form.getAttribute('method')||'get').toLowerCase()!=='get')continue;
  let timer;input.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>{if(!input.validity.valid)return;const pos=input.selectionStart;sessionStorage.setItem('live-search',JSON.stringify({path:location.pathname,name:input.name,pos}));form.requestSubmit();},650);});
 }
 try{const saved=JSON.parse(sessionStorage.getItem('live-search'));if(saved?.path===location.pathname){const input=Array.from(document.querySelectorAll('input')).find(el=>el.name===saved.name);input?.focus();input?.setSelectionRange(saved.pos,saved.pos);sessionStorage.removeItem('live-search');}}catch(_){}
 // Never discard edited forms or interrupt active users during an idle refresh.
 let dirty=false,lastActivity=Date.now();document.addEventListener('input',()=>dirty=true);document.addEventListener('pointerdown',()=>lastActivity=Date.now());
 setInterval(()=>{if(!/\/(applications|payments|receipts|requests|announcements|records)\.php$/.test(location.pathname)||document.hidden||dirty||Date.now()-lastActivity<30000||document.querySelector('dialog[open],.modal-overlay.show,.modal-overlay.active,.modal-wrap.show,.modal-wrap.active')||document.activeElement?.matches('input,textarea,select'))return;location.reload();},60000);
})();
