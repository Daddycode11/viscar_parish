(function(){
  /* ─── State ─── */
  var S = {
    step: 1,
    parish_id: 0, parish_name: '',
    service_id: 0, service_name: '', service_fee: 0, service_limit: 0,
    schedule: '', service_notes: '', notes_reviewed: false, services: [], services_request: 0,
    fields: [], requirements: [], loaded_service_id: 0, loading_service_id: 0,
    app_id: 0, qr_code: '', qr_url: ''
  };

  /* ─── Step navigation ─── */
  window.getBookingSelection=()=>({service_id:S.service_id,parish_id:S.parish_id});
  window.updateServiceAmount=value=>{S.service_fee=Number(value);};
  window.goStep = function(n) {
    document.getElementById('requirementNotesStep').style.display = 'none';
    /* Validation before advancing */
    if (n > S.step || n > 1) {
      if (S.step === 1 && n > 1) {
        if (!document.getElementById('selParish').value) { showToast('Please select a parish', 'error'); return; }
        var parishChanged = S.parish_id !== parseInt(document.getElementById('selParish').value);
        S.parish_id = parseInt(document.getElementById('selParish').value);
        S.parish_name = document.getElementById('selParish').selectedOptions[0].text;
        if (n === 2 && (parishChanged || !S.services.length)) loadServices();
      }
      if (S.step === 2 && n > 2) {
        if (!S.service_id) { showToast('Please select a service', 'error'); return; }
        if (S.service_notes.trim() && !S.notes_reviewed) {
          document.getElementById('step2').style.display = 'none';
          document.getElementById('requirementServiceName').textContent = S.service_name;
          document.getElementById('requirementNotesText').textContent = S.service_notes;
          document.getElementById('requirementNotesStep').style.display = '';
          document.getElementById('requirementNotesTitle').setAttribute('tabindex', '-1');
          document.getElementById('requirementNotesTitle').focus();
          return;
        }
      }
      if (S.step === 3 && n > 3) {
        var sv = document.getElementById('selSchedule').value;
        if (document.getElementById('btnStep3').disabled) { showToast('Choose an available date.','error'); return; }
        if (!sv) { showToast('Please select a date and time', 'error'); return; }
        S.schedule = sv;
      }
      if (S.step === 4 && n > 4) {
        if (!validateDynamicForm()) return;
      }
      if (S.step === 5 && n > 5) {
        if (!validateRequirements()) return;
      }
    }

    /* Going back to step 2, reload services if parish changed */
    if (n === 2 && S.step > 2) {
      var newP = parseInt(document.getElementById('selParish').value);
      if (newP !== S.parish_id) { S.parish_id = newP; S.parish_name = document.getElementById('selParish').selectedOptions[0].text; loadServices(); }
    }

    if (n === 4) loadFields();
    if (n === 6) {buildReview();loadManualMethods();document.getElementById('bookingPayment').style.display=(S.service_fee>0||S.amount_mode==='user_defined')?'':'none';}

    S.step = n;
    for (var i = 1; i <= 7; i++) {
      document.getElementById('step' + i).style.display = (i === n) ? '' : 'none';
    }
    updateStepBar(n);
    window.scrollTo({top: 0, behavior: 'smooth'});
  };

  document.addEventListener('app:back', function (event) {
    if (document.getElementById('requirementNotesStep').style.display !== 'none') {
      event.preventDefault(); goStep(2);
    } else if (S.step > 1 && S.step < 7) {
      event.preventDefault(); goStep(S.step - 1);
    }
  });

  window.continueAfterRequirements = function() { S.notes_reviewed = true; goStep(3); };

  function updateStepBar(current) {
    for (var i = 1; i <= 7; i++) {
      var dot = document.getElementById('stepDot' + i);
      if (!dot) continue;
      if (i < current) {
        dot.style.background = 'var(--green)'; dot.style.color = 'var(--white)'; dot.style.borderColor = 'var(--green)';
        dot.innerHTML = '[icon:check]';
      } else if (i === current) {
        dot.style.background = 'var(--navy)'; dot.style.color = 'var(--white)'; dot.style.borderColor = 'var(--navy)';
        dot.textContent = i;
      } else {
        dot.style.background = 'var(--white)'; dot.style.color = 'var(--ink-30)'; dot.style.borderColor = 'var(--ink-10)';
        dot.textContent = i;
      }
    }
  }

  /* ─── Load services (Step 2) ─── */
  function loadServices() {
    var box = document.getElementById('servicesList');
    box.innerHTML = '<div class="empty-state"><div class="empty-icon">[icon:clock]</div><p>Loading services...</p></div>';
    S.service_id = 0;
    S.service_notes = ''; S.notes_reviewed = false; S.services = [];
    var requestId = ++S.services_request;
    document.getElementById('btnStep2').disabled = true;

    fetch('apply_service.php?ajax=services&parish_id=' + S.parish_id)
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (requestId !== S.services_request) return;
        S.services = d.services || [];
        if (!d.ok || !S.services.length) {
          box.innerHTML = '<div class="empty-state"><div class="empty-icon">[icon:file]</div><p>No active services found for this parish.</p></div>';
          return;
        }
        var html = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(260px,100%),1fr));gap:14px">';
        d.services.forEach(function(s){
          html += '<div class="svc-card" data-id="'+s.id+'" data-name="'+esc(s.name)+'" data-mode="'+esc(s.amount_mode || "fixed")+'" data-fee="'+s.fee+'" data-limit="'+s.max_daily_limit+'" onclick="pickService(this)">';
          html += '<h4>' + esc(s.name) + '</h4>';
          html += '<p>' + esc(s.description || 'No description') + '</p>';
          html += '<div class="svc-meta">';
          html += '<span><span class="pill pill-gold">₱ ' + parseFloat(s.fee).toFixed(2) + '</span></span>';
          if (parseInt(s.max_daily_limit) > 0) html += '<span><span class="pill pill-navy">Max ' + s.max_daily_limit + '/day</span></span>';
          html += '</div></div>';
        });
        html += '</div>';
        box.innerHTML = html;
      })
      .catch(function(){ if (requestId !== S.services_request) return; box.innerHTML = '<div class="notice notice-wine">Failed to load services. Please try again.</div>'; });
  }

  window.pickService = function(el) {
    document.querySelectorAll('.svc-card').forEach(function(c){ c.classList.remove('selected'); });
    el.classList.add('selected');
    var selected = S.services.find(function(service) { return Number(service.id) === Number(el.dataset.id); });
    if (!selected) return;
    if (S.service_id !== Number(selected.id)) S.loaded_service_id = 0;
    S.service_notes = selected.requirements_note || '';
    S.notes_reviewed = false;
    S.service_id   = parseInt(el.dataset.id);
    S.service_name  = el.dataset.name;
    S.service_fee   = parseFloat(el.dataset.fee);
    S.amount_mode = el.dataset.mode || 'fixed';
    S.service_limit = parseInt(el.dataset.limit);
    document.getElementById('btnStep2').disabled = false;
  };

  /* ─── Schedule conflict check (Step 3) ─── */
  document.getElementById('selSchedule').addEventListener('change', function(){
    var val = this.value;
    if (!val) return;
    var dateOnly = val.split('T')[0];
    var warn = document.getElementById('scheduleWarning');
    warn.style.display = 'none';

    var fd = new FormData();
    fd.append('parish_id', S.parish_id);
    fd.append('service_id', S.service_id);
    fd.append('amount', S.service_fee.toFixed(2));
    fd.append('date', dateOnly);

    fetch('apply_service.php?ajax=check_schedule', {method:'POST', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(d){
        document.getElementById('btnStep3').disabled = !d.ok || d.full;
        if (d.ok && d.full) {
          warn.innerHTML = '<div class="notice notice-amber">[icon:alert] This date has reached maximum bookings (' + d.count + '/' + d.limit + '). Please choose another date.</div>';
          warn.style.display = '';
        } else if (d.ok && d.limit > 0) {
          warn.innerHTML = '<div class="notice notice-green">[icon:check] ' + d.count + ' of ' + d.limit + ' slots booked for this date.</div>';
          warn.style.display = '';
        }
      });
  });

  // Set min datetime to now
  (function(){
    var now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    document.getElementById('selSchedule').min = now.toISOString().slice(0,16);
  })();

  /* ─── Load fields + requirements (Step 4/5) ─── */
  function loadFields() {
    if (S.loaded_service_id === S.service_id || S.loading_service_id === S.service_id) return;
    var serviceId = S.service_id;
    S.loading_service_id = serviceId;
    document.getElementById('btnStep4').disabled = true;
    var formBox = document.getElementById('dynamicForm');
    var reqBox  = document.getElementById('requirementsList');
    formBox.innerHTML = '';
    reqBox.innerHTML = '';

    fetch('apply_service.php?ajax=fields&service_id=' + S.service_id)
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (serviceId !== S.service_id) return;
        if (!d.ok) throw new Error('Unable to load service form');
        S.loaded_service_id = serviceId;
        S.fields = d.fields || [];
        S.requirements = d.requirements || [];
        S.fields.filter(f=>f.field_type==='file').forEach(f=>S.requirements.push({id:'field_'+f.id,document_name:f.field_label,is_required:f.is_required,description:''}));

        /* Build dynamic form fields (exclude type=file, those go to step 5) */
        var formFields = S.fields.filter(function(f){ return f.field_type !== 'file'; });
        if (formFields.length === 0) {
          document.getElementById('noFields').style.display = '';
          formBox.style.display = 'none';
        } else {
          document.getElementById('noFields').style.display = 'none';
          formBox.style.display = '';
          formFields.forEach(function(f){
            var req = parseInt(f.is_required) ? ' *' : '';
            var reqAttr = parseInt(f.is_required) ? ' required' : '';
            var html = '<div class="form-group"><label for="field_'+f.id+'">' + esc(f.field_label) + req + '</label>';

            switch(f.field_type) {
              case 'text':
                html += '<input type="text" id="field_'+f.id+'" name="field_'+f.id+'" data-label="'+esc(f.field_label)+'"'+reqAttr+'>';
                break;
              case 'number':
                html += '<input type="number" id="field_'+f.id+'" name="field_'+f.id+'" data-label="'+esc(f.field_label)+'"'+reqAttr+'>';
                break;
              case 'date':
                html += '<input type="date" id="field_'+f.id+'" name="field_'+f.id+'" data-label="'+esc(f.field_label)+'"'+reqAttr+'>';
                break;
              case 'email':
                html += '<input type="email" id="field_'+f.id+'" name="field_'+f.id+'" data-label="'+esc(f.field_label)+'"'+reqAttr+'>';
                break;
              case 'phone':
                html += '<input type="tel" id="field_'+f.id+'" name="field_'+f.id+'" data-label="'+esc(f.field_label)+'"'+reqAttr+'>';
                break;
              case 'select':
                html += '<select id="field_'+f.id+'" name="field_'+f.id+'" data-label="'+esc(f.field_label)+'"'+reqAttr+'><option value="">— Select —</option>';
                (f.field_options || '').split(',').forEach(function(o){
                  o = o.trim();
                  if (o) html += '<option value="'+esc(o)+'">'+esc(o)+'</option>';
                });
                html += '</select>';
                break;
              case 'textarea':
                html += '<textarea id="field_'+f.id+'" name="field_'+f.id+'" data-label="'+esc(f.field_label)+'" rows="3"'+reqAttr+'></textarea>';
                break;
              default:
                html += '<input type="text" id="field_'+f.id+'" name="field_'+f.id+'" data-label="'+esc(f.field_label)+'"'+reqAttr+'>';
            }
            html += '</div>';
            formBox.insertAdjacentHTML('beforeend', html);
          });
        }

        /* Build requirement uploads (Step 5) */
        if (S.requirements.length === 0) {
          document.getElementById('noReqs').style.display = '';
          reqBox.style.display = 'none';
        } else {
          document.getElementById('noReqs').style.display = 'none';
          reqBox.style.display = '';
          S.requirements.forEach(function(r){
            var req = parseInt(r.is_required) ? ' *' : '';
            var reqAttr = parseInt(r.is_required) ? ' required' : '';
            var html = '<div class="file-input-wrap">';
            html += '<label for="req_'+r.id+'">'+esc(r.document_name)+req+'</label>';
            if (r.description) html += '<small>'+esc(r.description)+'</small>';
            html += '<input type="file" id="req_'+r.id+'" name="req_'+r.id+'" multiple data-multiple-attachments accept=".pdf,.jpg,.jpeg,.png"'+reqAttr+'>';
            html += '</div>';
            reqBox.insertAdjacentHTML('beforeend', html);
          });
        }
      }).catch(function () { showToast('Unable to load the form. Go back and try again.', 'error'); })
        .finally(function () {
          if (S.loading_service_id === serviceId) S.loading_service_id = 0;
          document.getElementById('btnStep4').disabled = S.loaded_service_id !== S.service_id;
        });
  }

  /* ─── Validate dynamic form ─── */
  function validateDynamicForm() {
    var valid = true;
    var formFields = S.fields.filter(function(f){ return f.field_type !== 'file'; });
    formFields.forEach(function(f){
      if (parseInt(f.is_required)) {
        var el = document.getElementById('field_' + f.id);
        if (el && !el.value.trim()) {
          el.style.borderColor = 'var(--wine)';
          valid = false;
        } else if (el) {
          el.style.borderColor = '';
        }
      }
    });
    if (!valid) showToast('Please fill in all required fields', 'error');
    return valid;
  }

  /* ─── Validate requirements ─── */
  function validateRequirements() {
    var valid = true;
    document.querySelectorAll('input[data-multiple-attachments]').forEach(input=>{if(!input.reportValidity())valid=false;});
    S.requirements.forEach(function(r){
      if (parseInt(r.is_required)) {
        var el = document.getElementById('req_' + r.id);
        if (el && el.files.length === 0) {
          el.closest('.file-input-wrap').style.borderColor = 'var(--wine)';
          valid = false;
        } else if (el) {
          el.closest('.file-input-wrap').style.borderColor = '';
        }
      }
    });
    if (!valid) showToast('Please upload all required documents', 'error');
    return valid;
  }

  /* ─── Build review (Step 6) ─── */
  function updatePaymentFields(){
    const digital=document.querySelector('[name=booking_payment_method]:checked')?.value==='gcash';
    for(const id of ['bookingPaymentReference','bookingPaymentProof']){const input=document.getElementById(id);input.closest('label').hidden=!digital;input.disabled=!digital;input.required=digital;}
    if(!digital)document.querySelectorAll('#manualPaymentMethods > div').forEach(el=>el.hidden=true);
  }
  document.getElementById('bookingPayment').addEventListener('change',updatePaymentFields);
  async function loadManualMethods(){
    const container=document.getElementById('manualPaymentMethods');container.replaceChildren();updatePaymentFields();
    const parish=S.parish_id;
    try{const response=await fetch('apply_service.php?ajax=payment_methods&parish_id='+parish);const data=await response.json();if(parish!==S.parish_id)return;
      for(const method of data.methods||[]){const label=document.createElement('label'),radio=document.createElement('input');radio.type='radio';radio.name='booking_payment_method';radio.value='gcash';radio.dataset.methodId=method.id;label.append(radio,document.createTextNode(method.name));const details=document.createElement('div');details.hidden=true;const instructions=document.createElement('p');instructions.textContent=method.instructions;const img=document.createElement('img');img.src='../public/payment_file.php?method='+method.id;img.alt=method.name+' payment QR';img.style.maxWidth='220px';details.append(instructions,img);container.append(label,details);radio.addEventListener('change',()=>{container.querySelectorAll('div').forEach(el=>el.hidden=true);details.hidden=!radio.checked;});}
    }catch(_){container.textContent='Unable to load digital-bank methods. Please retry.';}
  }
  function buildReview() {
    var html = '';
    html += '<div class="review-row"><span class="review-label">Parish</span><span class="review-value">' + esc(S.parish_name) + '</span></div>';
    html += '<div class="review-row"><span class="review-label">Service</span><span class="review-value">' + esc(S.service_name) + '</span></div>';
    if (S.amount_mode === 'user_defined') {
      html += '<label>Applicable amount <input id="userAmount" type="number" min="0" step="0.01" value="'+S.service_fee.toFixed(2)+'" onchange="updateServiceAmount(this.value)" required></label>';
    }
    html += '<div class="review-row"><span class="review-label">Fee</span><span class="review-value">₱ ' + S.service_fee.toFixed(2) + '</span></div>';

    var sched = document.getElementById('selSchedule').value;
    if (sched) {
      var dt = new Date(sched);
      html += '<div class="review-row"><span class="review-label">Schedule</span><span class="review-value">' + dt.toLocaleString('en-PH', {dateStyle:'long', timeStyle:'short',hour12:true}) + '</span></div>';
    }

    /* Form fields */
    var formFields = S.fields.filter(function(f){ return f.field_type !== 'file'; });
    formFields.forEach(function(f){
      var el = document.getElementById('field_' + f.id);
      if (el && el.value) {
        html += '<div class="review-row"><span class="review-label">' + esc(f.field_label) + '</span><span class="review-value">' + esc(el.value) + '</span></div>';
      }
    });

    /* Uploaded files */
    S.requirements.forEach(function(r){
      var el = document.getElementById('req_' + r.id);
      if (el && el.files.length > 0) {
        html += '<div class="review-row"><span class="review-label">' + esc(r.document_name) + '</span><span class="review-value"><span class="pill pill-green">' + Array.from(el.files).map(file=>esc(file.name)).join('<br>') + '</span></span></div>';
      }
    });

    document.getElementById('reviewSummary').innerHTML = html;
  }

  /* ─── Submit application ─── */
  window.submitApplication = function() {
    if(S.app_id)return;
    const method=document.querySelector('[name=booking_payment_method]:checked')?.value||'';
    const reference=document.getElementById('bookingPaymentReference').value.trim();
    if(S.service_fee>0&&!method){showToast('Choose a payment method before submitting.','error');return;}
    if(S.service_fee>0&&method==='gcash'&&!/^[A-Za-z0-9-]{6,100}$/.test(reference)){showToast('Enter a valid digital payment reference.','error');return;}

    var amountInput = document.getElementById('userAmount');
    if (amountInput && (!/^\d{1,8}(\.\d{1,2})?$/.test(amountInput.value) || !amountInput.reportValidity())) {
      amountInput.setCustomValidity('Enter an amount with at most two decimal places.');
      amountInput.reportValidity();
      amountInput.oninput = function(){this.setCustomValidity('');};
      return;
    }
    if (amountInput) S.service_fee = Number(amountInput.value);
    var btn = document.getElementById('btnSubmit');
    btn.disabled = true;
    btn.textContent = 'Submitting...';
    setLoading(true);

    /* Gather form data */
    var formDataObj = {};
    var formFields = S.fields.filter(function(f){ return f.field_type !== 'file'; });
    formFields.forEach(function(f){
      var el = document.getElementById('field_' + f.id);
      if (el) formDataObj[f.field_name || ('field_' + f.id)] = el.value;
    });

    var fd = new FormData();
    fd.append('parish_id', S.parish_id);
    fd.append('service_id', S.service_id);
    fd.append('amount', S.service_fee.toFixed(2));
    fd.append('schedule', S.schedule);
    fd.append('form_data', JSON.stringify(formDataObj));
    fd.append('payment_method',method);fd.append('reference_number',reference);fd.append('manual_method_id',document.querySelector('[name=booking_payment_method]:checked')?.dataset.methodId||'');const proof=document.getElementById('bookingPaymentProof')?.files[0];if(proof)fd.append('payment_proof',proof);

    fd.append('attachment_count', S.requirements.reduce((n,r)=>n+(document.getElementById('req_'+r.id)?.files.length||0),0));
    /* Attach files */
    S.requirements.forEach(function(r){
      var el = document.getElementById('req_' + r.id);
      if (el && el.files.length > 0) {
        Array.from(el.files).forEach(file=>fd.append((String(r.id).startsWith('field_') ? r.id : 'req_' + r.id)+'[]', file));
      }
    });

    fetch('apply_service.php?ajax=submit', {method:'POST', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(d){
        setLoading(false);
        if (!d.ok) {
          showToast(d.msg || 'Submission failed', 'error');
          btn.disabled = false;
          btn.innerHTML = '[icon:check] Submit Application';
          return;
        }
        S.app_id  = d.app_id;
        S.qr_code = d.qr_code;
        S.qr_url  = d.qr_url;
        S.service_fee = d.service_fee || S.service_fee;

        buildStep7(d);
        goStep(7);
        showToast('Application submitted successfully!', 'success');
      })
      .catch(function(){
        setLoading(false);
        showToast('Network error. Please try again.', 'error');
        btn.disabled = false;
        btn.innerHTML = '[icon:check] Submit Application';
      });
  };

  /* ─── Build Step 7 (confirmation + payment) ─── */
  function buildStep7(data) {
    var html = '';

    /* Success message + QR */
    html += '<div class="notice notice-green" style="margin-bottom:20px">[icon:check] <strong>Application submitted successfully!</strong> Your reference code is <strong>' + esc(data.qr_code) + '</strong>.</div>';
    html += '<div class="qr-box">';
    html += '<img src="' + esc(data.qr_url) + '" alt="QR Code" width="200" height="200">';
    html += '<p style="font-size:.8rem;color:var(--ink-60)">Present this QR code at the parish office</p>';
    html += '</div>';

    /* Payment is recorded atomically with the application. */
    html += '<p>'+(S.service_fee>0?'Payment selection saved for Bookkeeper verification.':'No payment is required for this service.')+'</p><a href="dashboard.php" class="btn-sm btn-navy">Go to Dashboard</a>';
    document.getElementById('step7Body').innerHTML = html;return;
  }

  function esc(s) {
    if (!s) return '';
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  let dirty=false;
  document.querySelector('.page-content')?.addEventListener('input',()=>{dirty=true;});
  document.addEventListener('click',event=>{if(event.target.closest('.svc-card'))dirty=true;const link=event.target.closest('a[href]');if(link&&dirty&&!S.app_id&&!link.getAttribute('href').startsWith('#')){if(confirm('Discard this unfinished application?'))dirty=false;else event.preventDefault();}});
  window.addEventListener('beforeunload',event=>{if(dirty&&!S.app_id){event.preventDefault();event.returnValue='';}});
  /* Selected File objects stay in the original, hidden DOM inputs between steps.
     No temporary upload or server draft exists before final submission. */
  /* Init step bar */
  updateStepBar(1);
})();
