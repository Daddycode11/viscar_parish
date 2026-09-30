<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

/**
 * Parishioner FAQ — View frequently asked questions
 * Features: search, category tabs, accordion, active-only display
 */

$page_id    = 'faq';
$page_title = 'FAQ';
$page_sub   = 'Information';
require_once __DIR__ . '/includes/layout.php';

// ── LOAD FAQs ─────────────────────────────────
$user_parish = (int)($user['parish_id'] ?? 0);

// Resolve which parish the user is browsing.
// Priority: explicit ?parish_id= override -> assigned parish -> 'general' (NULL only).
if (isset($_GET['parish_id']) && $_GET['parish_id'] !== '') {
    $selected_parish = $_GET['parish_id'] === 'general' ? 'general' : (int)$_GET['parish_id'];
} elseif ($user_parish) {
    $selected_parish = $user_parish;
} else {
    $selected_parish = 'general';
}

if ($selected_parish === 'general') {
    // General FAQs only (parish_id IS NULL)
    $stmt = $conn->prepare("SELECT * FROM faqs WHERE status='active' ORDER BY sort_order ASC, created_at DESC");
} else {
    // Selected parish + general FAQs
    $stmt = $conn->prepare("SELECT * FROM faqs WHERE status='active' AND (parish_id = ? OR parish_id IS NULL) ORDER BY sort_order ASC, created_at DESC");
    $stmt->bind_param('i', $selected_parish);
}
$stmt->execute();
$faqs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Parishes for the selector
$parishes_for_filter = [];
$pr = $conn->query("SELECT id, name FROM parishes WHERE status='active' ORDER BY name");
if ($pr) {
    while ($row = $pr->fetch_assoc()) $parishes_for_filter[] = $row;
}

// Collect distinct categories
$cat_set = [];
foreach ($faqs as $f) {
    $cat_set[$f['category']] = true;
}
$categories = array_keys($cat_set);
sort($categories);
?>


<style>
.faq-item { border: 1px solid var(--ink-10); border-radius: var(--r); margin-bottom: 10px; overflow: hidden; }
.faq-question { padding: 16px 20px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; font-weight: 500; transition: background var(--ease); }
.faq-question:hover { background: rgba(201,168,76,.04); }
.faq-answer { padding: 0 20px; max-height: 0; overflow: hidden; transition: max-height .3s ease, padding .3s ease; }
.faq-item.open .faq-answer { max-height: none; overflow-wrap:anywhere; padding: 0 20px 16px; }
.faq-item.open .faq-question { background: var(--gold-dim); }
.faq-arrow { transition: transform .3s ease; }
.faq-item.open .faq-arrow { transform: rotate(180deg); }
.cat-pill { display:inline-block; padding:6px 16px; font-size:.8rem; border-radius:20px; cursor:pointer; border:1.5px solid var(--ink-10); background:#FAFAF8; color:var(--ink-60); transition:all var(--ease); margin:0 4px 8px 0; }
.cat-pill:hover { border-color:var(--gold); color:var(--gold); }
.cat-pill.active { background:var(--navy); color:var(--white); border-color:var(--navy); }
</style>

<!-- PAGE HEADER -->
<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Information</div>
    <h1 class="sec-title">Frequently Asked Questions</h1>
    <p class="sec-sub">Find answers to common questions about parish services and processes.</p>
  </div>
</div>

<!-- PARISH SELECTOR + SEARCH BAR -->
<div class="card" style="margin-bottom:18px">
  <div class="card-body" style="padding:14px 22px;display:flex;gap:12px;flex-wrap:wrap;align-items:center">
    <form method="GET" action="faq.php" style="display:flex;align-items:center;gap:10px;min-width:240px">
      <label for="parishSel" style="font-size:.72rem;font-weight:500;letter-spacing:.07em;text-transform:uppercase;color:var(--ink-60);white-space:nowrap;margin:0">Showing FAQs for</label>
      <select id="parishSel" name="parish_id" onchange="this.form.submit()"
        style="font-size:.83rem;padding:8px 12px;border:1.5px solid var(--ink-10);border-radius:8px;background:#FAFAF8;outline:none;cursor:pointer;flex:1;min-width:160px">
        <option value="general" <?php echo $selected_parish === 'general' ? 'selected' : ''; ?>>General (all parishes)</option>
        <?php foreach ($parishes_for_filter as $p):
          $is_assigned = ((int)$p['id'] === $user_parish);
          $is_selected = ($selected_parish !== 'general' && (int)$selected_parish === (int)$p['id']);
        ?>
        <option value="<?php echo (int)$p['id']; ?>" <?php echo $is_selected ? 'selected' : ''; ?>>
          <?php echo htmlspecialchars($p['name']); echo $is_assigned ? '  (your parish)' : ''; ?>
        </option>
        <?php endforeach; ?>
      </select>
    </form>

    <div style="display:flex;align-items:center;gap:8px;background:#F8F6F2;border:1.5px solid var(--ink-10);border-radius:8px;padding:9px 14px;flex:1;min-width:200px">
      <span style="color:var(--ink-30)">[icon:search]</span>
      <input type="text" id="faqSearch" placeholder="Search questions..." oninput="filterFaqs()"
        style="border:none;outline:none;background:none;font-family:var(--fb);font-size:.85rem;color:var(--ink);width:100%">
    </div>
  </div>
</div>

<!-- CATEGORY TABS -->
<?php if (count($categories) > 1): ?>
<div style="margin-bottom:18px;display:flex;flex-wrap:wrap;align-items:center;gap:0">
  <span class="cat-pill active" data-cat="all" onclick="filterByCategory('all', this)">All</span>
  <?php foreach ($categories as $cat): ?>
  <span class="cat-pill" data-cat="<?php echo htmlspecialchars($cat); ?>" onclick="filterByCategory('<?php echo htmlspecialchars(addslashes($cat)); ?>', this)"><?php echo htmlspecialchars($cat); ?></span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- FAQ LIST -->
<div class="card">
  <div class="card-head">
    <h3>Questions &amp; Answers</h3>
    <span class="card-tag" id="faqCount"><?php echo count($faqs); ?> items</span>
  </div>
  <div class="card-body" id="faqList">
    <?php if (empty($faqs)): ?>
    <div class="empty-state" id="emptyState">
      <div class="empty-icon">[icon:help]</div>
      <p>No FAQs available at this time. Please check back later.</p>
    </div>
    <?php else: ?>
      <?php foreach ($faqs as $faq): ?>
      <div class="faq-item" data-category="<?php echo htmlspecialchars($faq['category']); ?>" data-question="<?php echo htmlspecialchars(strtolower($faq['question'])); ?>">
        <div class="faq-question" onclick="this.closest('.faq-item').classList.toggle('open')">
          <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:0">
            <span style="flex:1;min-width:0"><?php echo htmlspecialchars($faq['question']); ?></span>
            <span class="pill pill-amber" style="flex-shrink:0"><?php echo htmlspecialchars($faq['category']); ?></span>
          </div>
          <span class="faq-arrow" style="font-size:.7rem;color:var(--ink-30);margin-left:10px">▼</span>
        </div>
        <div class="faq-answer">
          <div style="padding-top:12px;border-top:1px solid var(--ink-10);font-size:.85rem;line-height:1.7;color:var(--ink-60);white-space:pre-wrap"><?php echo htmlspecialchars($faq['answer']); ?></div>
        </div>
      </div>
      <?php endforeach; ?>

      <!-- Dynamic empty state (hidden by default) -->
      <div class="empty-state" id="noResults" style="display:none">
        <div class="empty-icon">[icon:search]</div>
        <p>No FAQs match your search. Try different keywords.</p>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
let currentCat = 'all';

function filterFaqs() {
    const query = document.getElementById('faqSearch').value.toLowerCase().trim();
    const items = document.querySelectorAll('.faq-item');
    let visible = 0;

    items.forEach(item => {
        const q = item.getAttribute('data-question') || '';
        const cat = item.getAttribute('data-category') || '';
        const matchSearch = !query || q.includes(query);
        const matchCat = currentCat === 'all' || cat === currentCat;
        const show = matchSearch && matchCat;
        item.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    const noResults = document.getElementById('noResults');
    if (noResults) noResults.style.display = visible === 0 ? '' : 'none';

    const countEl = document.getElementById('faqCount');
    if (countEl) countEl.textContent = visible + ' item' + (visible !== 1 ? 's' : '');
}

function filterByCategory(cat, el) {
    currentCat = cat;
    document.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active'));
    if (el) el.classList.add('active');
    filterFaqs();
}
</script>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
