<?php
require __DIR__ . '/inc.php';
$cid = (int)$CLIENT['id'];

// Soumission de la commande
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $plats = $_POST['plat_id'] ?? [];
    $qtes  = $_POST['qte'] ?? [];
    $noms  = $_POST['nom_plat'] ?? [];
    $items = [];
    foreach ($plats as $i => $pid) {
        $q = max(1, (int)($qtes[$i] ?? 1));
        $items[] = ['plat_id'=>(int)$pid ?: null, 'nom'=>mb_substr(trim($noms[$i] ?? ''),0,200), 'qte'=>$q];
    }
    if (!$items) { flash('Votre panier est vide.', 'error'); header('Location: commander.php'); exit; }

    $numero = next_numero($pdo, 'commandes_client', $settings['prefixe_commande'] ?? 'CMD');
    $pdo->prepare("INSERT INTO commandes_client (numero, client_id, date_evenement, nb_invites, lieu, notes, statut) VALUES (?,?,?,?,?,?, 'nouvelle')")
        ->execute([
            $numero, $cid,
            ($_POST['date_evenement'] ?? '') ?: null,
            max(0, (int)($_POST['nb_invites'] ?? 0)),
            mb_substr(trim($_POST['lieu'] ?? ''), 0, 200),
            mb_substr(trim($_POST['notes'] ?? ''), 0, 1000),
        ]);
    $oid = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO commandes_client_lignes (commande_id, plat_id, designation, quantite) VALUES (?,?,?,?)");
    foreach ($items as $it) if ($it['nom']!=='') $ins->execute([$oid, $it['plat_id'], $it['nom'], $it['qte']]);

    flash('🎉 Votre commande '.$numero.' a été envoyée ! Nous préparons votre devis et vous suivez son évolution ci-dessous.');
    header('Location: mes-commandes.php'); exit;
}

// Menu (plats actifs groupés par catégorie)
$cats = $pdo->query("SELECT * FROM categories WHERE actif=1 ORDER BY ordre, id")->fetchAll();
$platsParCat = [];
$st = $pdo->query("SELECT * FROM plats WHERE actif=1 ORDER BY categorie_id, ordre, id");
foreach ($st as $p) $platsParCat[$p['categorie_id']][] = $p;

client_header('Commander', 'commander', $settings, $CLIENT);
?>
<div class="panel glass tone-blue">
  <h2>🛒 Composez votre commande</h2>
  <p style="color:var(--ink-dim);font-size:14px;margin:0">Parcourez nos menus, dépliez une catégorie et ajoutez les plats souhaités avec les quantités. Envoyez votre commande : notre équipe vous prépare un <strong>devis personnalisé</strong> que vous retrouverez dans « Mes commandes ».</p>
</div>

<div class="order-layout">
  <div class="order-menu">
    <div class="panel glass">
      <h2>📋 Nos menus</h2>
      <?php $first = true; foreach ($cats as $c): $items = $platsParCat[$c['id']] ?? []; ?>
      <details class="menu-cat" <?= $first?'open':'' ?>>
        <summary>
          <?= e($c['icone'] ?? '🍽️') ?> <?= e($c['nom']) ?>
          <span class="cat-count"><?= count($items) ?></span>
          <span class="chev">▸</span>
        </summary>
        <div class="menu-list">
          <?php foreach ($items as $p): ?>
          <div class="menu-item" data-id="<?= $p['id'] ?>" data-nom="<?= e($p['nom']) ?>">
            <div class="mi-body">
              <strong><?= e($p['nom']) ?></strong>
              <?php if (!empty($p['description'])): ?><p><?= e(mb_substr($p['description'],0,90)) ?></p><?php endif; ?>
            </div>
            <div class="dish-add">
              <button type="button" class="qbtn" onclick="chg(<?= $p['id'] ?>,-1)">−</button>
              <span class="qval" id="q<?= $p['id'] ?>">0</span>
              <button type="button" class="qbtn" onclick="chg(<?= $p['id'] ?>,1)">+</button>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (!$items): ?><div style="padding:14px 8px;color:var(--ink-faint);font-size:13px">Plats bientôt disponibles dans cette catégorie.</div><?php endif; ?>
        </div>
      </details>
      <?php $first = false; endforeach; ?>
      <?php if (!$cats): ?>
      <div style="text-align:center;color:var(--ink-faint);padding:20px">Les menus ne sont pas encore disponibles. Revenez bientôt !</div>
      <?php endif; ?>
    </div>
  </div>

  <aside class="order-cart">
    <div class="panel glass cart-sticky">
      <h2>🧺 Mon panier <span class="badge badge-gold" id="cartCount">0</span></h2>
      <div id="cartItems" class="cart-items"><p class="cart-empty" style="color:var(--ink-faint);font-size:13.5px">Votre panier est vide. Ajoutez des plats depuis la carte.</p></div>
      <form method="post" id="orderForm">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <div id="hiddenItems"></div>
        <h3 class="form-section">📅 Détails de l'événement</h3>
        <div class="field"><label>Date de l'événement</label><input class="input" type="date" name="date_evenement"></div>
        <div class="field"><label>Nombre de participants</label><input class="input" type="number" name="nb_invites" min="0" placeholder="ex : 150"></div>
        <div class="field"><label>Lieu</label><input class="input" name="lieu" placeholder="ex : Cocody, Abidjan"></div>
        <div class="field"><label>Précisions</label><textarea class="input" name="notes" style="min-height:60px" placeholder="Allergies, préférences, horaires…"></textarea></div>
        <button class="btn btn-gold" type="submit" id="orderBtn" style="width:100%;margin-top:12px" disabled>Envoyer ma commande</button>
        <p style="color:var(--ink-faint);font-size:12px;text-align:center;margin-top:8px">Aucun prix ici : vous recevrez un devis personnalisé.</p>
      </form>
    </div>
  </aside>
</div>

<script>
const cart = {}; const names = {};
document.querySelectorAll('.menu-item').forEach(d => names[d.dataset.id] = d.dataset.nom);
function chg(id, delta){
  cart[id] = Math.max(0, (cart[id]||0) + delta);
  if (cart[id]===0) delete cart[id];
  document.getElementById('q'+id).textContent = cart[id]||0;
  render();
}
function render(){
  const box = document.getElementById('cartItems');
  const hid = document.getElementById('hiddenItems');
  const ids = Object.keys(cart);
  document.getElementById('cartCount').textContent = ids.reduce((s,i)=>s+cart[i],0);
  document.getElementById('orderBtn').disabled = ids.length===0;
  if (!ids.length){ box.innerHTML = '<p class="cart-empty" style="color:var(--ink-faint);font-size:13.5px">Votre panier est vide. Ajoutez des plats depuis la carte.</p>'; hid.innerHTML=''; return; }
  box.innerHTML = ids.map(i => `<div class="cart-row"><span class="cart-q">${cart[i]}×</span><span class="cart-n">${names[i]}</span><button type="button" class="cart-x" onclick="chg(${i},-${cart[i]})">✕</button></div>`).join('');
  hid.innerHTML = ids.map(i => `<input type="hidden" name="plat_id[]" value="${i}"><input type="hidden" name="qte[]" value="${cart[i]}"><input type="hidden" name="nom_plat[]" value="${names[i].replace(/"/g,'&quot;')}">`).join('');
}
</script>
<?php client_footer(); ?>
