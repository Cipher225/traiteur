<div id="sendModal" style="display:none;position:fixed;inset:0;z-index:200;place-items:center;padding:20px;background:rgba(3,5,10,.6);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px)">
  <div class="glass-strong" style="width:min(420px,100%);padding:28px;border-radius:24px">
    <h2 style="font-family:var(--font-display);font-size:19px;margin-bottom:6px">Envoyer <span id="send-doc"></span></h2>
    <p style="color:var(--ink-faint);font-size:13.5px;margin-bottom:20px">En local, l'envoi passe par WhatsApp ou votre logiciel e-mail. Téléchargez d'abord le PDF, puis joignez-le à votre message.</p>
    <div style="display:flex;flex-direction:column;gap:10px">
      <a id="send-pdf" class="btn btn-glass" target="_blank">📄 1. Télécharger le PDF</a>
      <a id="send-wa" class="btn btn-gold" target="_blank">💬 2a. Ouvrir WhatsApp</a>
      <a id="send-mail" class="btn btn-glass" target="_blank">✉️ 2b. Ouvrir l'e-mail</a>
    </div>
    <button class="btn btn-glass btn-sm" onclick="document.getElementById('sendModal').style.display='none'" style="width:100%;margin-top:16px">Fermer</button>
  </div>
</div>
<script>
function envoyer(o){
  const m = document.getElementById('sendModal');
  document.getElementById('send-doc').textContent = o.doc;
  document.getElementById('send-pdf').href = o.url;
  const msg = encodeURIComponent('Bonjour, veuillez trouver ' + o.doc + ' de Groupe Helisce. Merci.');
  const wa = document.getElementById('send-wa');
  wa.href = o.tel ? ('https://wa.me/' + o.tel + '?text=' + msg) : ('https://wa.me/?text=' + msg);
  document.getElementById('send-mail').href = 'mailto:?subject=' + encodeURIComponent('Groupe Helisce — ' + o.doc) + '&body=' + msg;
  m.style.display = 'grid';
}
</script>
