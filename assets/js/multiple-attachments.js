(() => {
 'use strict';
 function enhance(root) {
  root.querySelectorAll('input[data-multiple-attachments]').forEach(input=>{
   if(input.dataset.attachmentsReady)return;
   input.dataset.attachmentsReady='1';input.multiple=true;
   const list=document.createElement('ul');list.style.cssText='padding-left:20px;overflow-wrap:anywhere';list.setAttribute('aria-live','polite');input.after(list);
   function render(){
    list.replaceChildren();
    Array.from(input.files).forEach((file,index)=>{
     const item=document.createElement('li');item.append(document.createTextNode(file.name+' '));
     const remove=document.createElement('button');remove.type='button';remove.textContent='Remove';remove.className='btn-sm btn-outline';remove.style.minHeight='44px';remove.setAttribute('aria-label','Remove '+file.name);
     remove.addEventListener('click',e=>{e.preventDefault();const transfer=new DataTransfer();Array.from(input.files).forEach((f,i)=>{if(i!==index)transfer.items.add(f)});input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}));});
     item.append(remove);list.append(item);
    });
    input.setCustomValidity(input.files.length>20?'Select at most 20 files.':Array.from(input.files).some(f=>f.size>5242880)?'Each file must be at most 5 MB.':'');
   }
   input.addEventListener('change',render);render();
   input.form?.addEventListener('submit',()=>{
    let count=input.form.querySelector('input[name="attachment_count"]');if(!count){count=document.createElement('input');count.type='hidden';count.name='attachment_count';input.form.append(count)}
    count.value=Array.from(input.form.querySelectorAll('input[data-multiple-attachments]')).reduce((n,e)=>n+e.files.length,0);
   });
  });
 }
 enhance(document);
 new MutationObserver(()=>enhance(document)).observe(document.body,{childList:true,subtree:true});
})();
