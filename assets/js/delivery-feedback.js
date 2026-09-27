// Keep delivery warnings visible even when a workflow refreshes the page.
(function () {
  function display(message, verificationUrl) {
    var old = document.getElementById('deliveryFeedback');
    if (old) old.remove();
    var box = document.createElement('div');
    box.id = 'deliveryFeedback'; box.className = 'delivery-feedback'; box.setAttribute('role', 'status');
    var close = document.createElement('button'); close.type = 'button'; close.textContent = '×'; close.setAttribute('aria-label', 'Dismiss');
    close.onclick = function () { box.remove(); sessionStorage.removeItem('deliveryFeedback'); };
    box.append(close, document.createTextNode(message));
    if (verificationUrl) {
      var link = document.createElement('a'); link.href = verificationUrl;
      link.target = '_blank'; link.rel = 'noopener'; link.textContent = ' Verify password, then retry this action.'; box.append(link);
    }
    document.body.append(box);
  }
  var saved = sessionStorage.getItem('deliveryFeedback');
  if (saved) { sessionStorage.removeItem('deliveryFeedback'); display(saved); }
  var original = window.fetch;
  window.fetch = function () {
    return original.apply(this, arguments).then(function (response) {
      if ((response.headers.get('content-type') || '').includes('application/json')) {
        response.clone().json().then(function (data) {
          var message = data.notification_warning || data.notification_message;
          if (message) { sessionStorage.setItem('deliveryFeedback', message); display(message); }
          if (data.verification_url) display(data.message, data.verification_url);
        }).catch(function () {});
      }
      return response;
    });
  };
})();
