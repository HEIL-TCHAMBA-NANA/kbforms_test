// assets/js/form-builder.js — Builder inline KBForms
// Dépend de: api.js, auth.js, utils.js, SortableJS (CDN)

window.KBF = window.KBF || {};

// ── Liste des pays avec indicatifs téléphoniques ─────────────────────────────
KBF.PHONE_COUNTRIES = [
  { code: 'CM', name: 'Cameroun',          dial: '+237', flag: '🇨🇲', pattern: /^6[5-9]\d{7}$/ },
  { code: 'CI', name: "Côte d'Ivoire",     dial: '+225', flag: '🇨🇮', pattern: /^[0-9]{10}$/ },
  { code: 'SN', name: 'Sénégal',           dial: '+221', flag: '🇸🇳', pattern: /^[37][0-9]{8}$/ },
  { code: 'ML', name: 'Mali',              dial: '+223', flag: '🇲🇱', pattern: /^[267][0-9]{7}$/ },
  { code: 'BF', name: 'Burkina Faso',      dial: '+226', flag: '🇧🇫', pattern: /^[267][0-9]{7}$/ },
  { code: 'GN', name: 'Guinée',            dial: '+224', flag: '🇬🇳', pattern: /^[67][0-9]{8}$/ },
  { code: 'BJ', name: 'Bénin',             dial: '+229', flag: '🇧🇯', pattern: /^[69][0-9]{7}$/ },
  { code: 'TG', name: 'Togo',              dial: '+228', flag: '🇹🇬', pattern: /^[97][0-9]{7}$/ },
  { code: 'NE', name: 'Niger',             dial: '+227', flag: '🇳🇪', pattern: /^[79][0-9]{7}$/ },
  { code: 'CD', name: 'RD Congo',          dial: '+243', flag: '🇨🇩', pattern: /^8[0-9]{8}$/ },
  { code: 'GA', name: 'Gabon',             dial: '+241', flag: '🇬🇦', pattern: /^0[67][0-9]{6}$/ },
  { code: 'CG', name: 'Congo-Brazzaville', dial: '+242', flag: '🇨🇬', pattern: /^[06][0-9]{7}$/ },
  { code: 'NG', name: 'Nigéria',           dial: '+234', flag: '🇳🇬', pattern: /^[789][01]\d{8}$/ },
  { code: 'GH', name: 'Ghana',             dial: '+233', flag: '🇬🇭', pattern: /^[235][0-9]{8}$/ },
  { code: 'MA', name: 'Maroc',             dial: '+212', flag: '🇲🇦', pattern: /^[67][0-9]{8}$/ },
  { code: 'DZ', name: 'Algérie',           dial: '+213', flag: '🇩🇿', pattern: /^[5-7][0-9]{8}$/ },
  { code: 'TN', name: 'Tunisie',           dial: '+216', flag: '🇹🇳', pattern: /^[2-9][0-9]{7}$/ },
  { code: 'FR', name: 'France',            dial: '+33',  flag: '🇫🇷', pattern: /^[67][0-9]{8}$/ },
  { code: 'BE', name: 'Belgique',          dial: '+32',  flag: '🇧🇪', pattern: /^4[5-9][0-9]{7}$/ },
  { code: 'CH', name: 'Suisse',            dial: '+41',  flag: '🇨🇭', pattern: /^7[5-9][0-9]{7}$/ },
  { code: 'US', name: 'États-Unis',        dial: '+1',   flag: '🇺🇸', pattern: /^[2-9][0-9]{9}$/ },
  { code: 'GB', name: 'Royaume-Uni',       dial: '+44',  flag: '🇬🇧', pattern: /^7[0-9]{9}$/ },
  { code: 'DE', name: 'Allemagne',         dial: '+49',  flag: '🇩🇪', pattern: /^1[5-7][0-9]{9,10}$/ },
  { code: 'ES', name: 'Espagne',           dial: '+34',  flag: '🇪🇸', pattern: /^[67][0-9]{8}$/ },
  { code: 'IT', name: 'Italie',            dial: '+39',  flag: '🇮🇹', pattern: /^3[0-9]{9}$/ },
  { code: 'PT', name: 'Portugal',          dial: '+351', flag: '🇵🇹', pattern: /^9[1-6][0-9]{7}$/ },
  { code: 'BR', name: 'Brésil',            dial: '+55',  flag: '🇧🇷', pattern: /^[689][0-9]{10}$/ },
  { code: 'CN', name: 'Chine',             dial: '+86',  flag: '🇨🇳', pattern: /^1[3-9][0-9]{9}$/ },
  { code: 'IN', name: 'Inde',              dial: '+91',  flag: '🇮🇳', pattern: /^[6-9][0-9]{9}$/ },
];

const BB_TYPES = [
  { type: 'short_text',   label: 'Texte court' },
  { type: 'long_text',    label: 'Paragraphe' },
  { type: 'email',        label: 'Email' },
  { type: 'phone',        label: 'Téléphone' },
  { type: 'radio',        label: 'Choix unique' },
  { type: 'checkbox',     label: 'Cases à cocher' },
  { type: 'dropdown',     label: 'Liste déroulante' },
  { type: 'date',         label: 'Date' },
  { type: 'time',         label: 'Heure' },
  { type: 'linear_scale', label: 'Échelle linéaire' },
  { type: 'grid',         label: 'Grille' },
  { type: 'signature',    label: 'Signature' },
  { type: 'audio',        label: 'Audio' },
  { type: 'video',        label: 'Vidéo' },
  { type: 'calculated',   label: 'Champ calculé' },
];
const BB_CHOICE = ['radio', 'checkbox', 'dropdown'];

KBF.builder = {
  formId: null,
  sections: [],
  questions: [],
  choiceLists: [],         // B7 — listes de choix en cascade du formulaire
  openAdv: new Set(),      // ids des champs dont le pli "Avancé" est ouvert
  _timers: {},

  // ── Init ────────────────────────────────────────────────────────────────
  async init(formId) {
    this.formId = formId;
    const root = document.getElementById('bb-canvas');
    if (root) root.innerHTML = '<div class="kb-skeleton" style="height:180px;border-radius:22px;margin-bottom:22px"></div><div class="kb-skeleton" style="height:180px;border-radius:22px"></div>';
    await this.loadAll();
    if (this.sections.length === 0) {
      await KBF_API.post(`/forms/${this.formId}/sections`, { title: 'Section sans titre', position: 0 });
      await this.loadAll();
    }
    this.render();
  },

  async loadAll() {
    // Séquentiel (et non Promise.all) : plus robuste si des requêtes tierces
    // (CDN, polices) saturent les créneaux de connexion du navigateur.
    const qRes = await KBF_API.get(`/forms/${this.formId}/questions`);
    const sRes = await KBF_API.get(`/forms/${this.formId}/sections`);
    this.questions = (qRes.ok && Array.isArray(qRes.data)) ? qRes.data : (qRes.data?.questions || []);
    let secs = (sRes.ok && Array.isArray(sRes.data)) ? sRes.data : (sRes.data?.sections || []);
    // getSectionsWithQuestions renvoie parfois les questions imbriquées — on ne garde que la méta
    this.sections = secs
      .map(s => ({ id: s.id, title: s.title || '', description: s.description || '', position: Number(s.position ?? 0) }))
      .sort((a, b) => a.position - b.position);
    await this.reloadChoiceLists();
    await this.reloadRepeatGroups();
  },

  async reloadChoiceLists() {
    const r = await KBF_API.get(`/forms/${this.formId}/choice-lists`);
    this.choiceLists = (r.ok && Array.isArray(r.data?.choice_lists)) ? r.data.choice_lists : [];
  },

  async reloadRepeatGroups() {
    const r = await KBF_API.get(`/forms/${this.formId}/repeat-groups`);
    this.repeatGroups = (r.ok && Array.isArray(r.data?.repeat_groups)) ? r.data.repeat_groups : [];
  },

  q(id)  { return this.questions.find(x => Number(x.id) === Number(id)); },
  sec(id){ return this.sections.find(x => Number(x.id) === Number(id)); },
  fieldsOf(idx) {
    return this.questions
      .filter(x => Number(x.section_index) === Number(idx))
      .sort((a, b) => (a.position || 0) - (b.position || 0));
  },

  // ── Rendu ──────────────────────────────────────────────────────────────
  render() {
    const root = document.getElementById('bb-canvas');
    root.innerHTML =
      this.sections.map((s, i) => this.sectionHtml(s, i)).join('') +
      `<button class="bb-add-section" onclick="KBF.builder.addSection()">${kbIcon('plus')} Ajouter une section</button>`;
    this.mountSortables();
  },

  sectionHtml(s, i) {
    return `
      <section class="bb-section" data-sid="${s.id}" data-sidx="${i}">
        <div class="bb-section-head">
          <div class="flex-1 min-w-0">
            <input class="bb-section-title" value="${escapeHtml(s.title)}" placeholder="Titre de la section"
              onchange="KBF.builder.saveSection(${s.id},'title',this.value)">
            <input class="bb-section-sub" value="${escapeHtml(s.description)}" placeholder="Sous-titre (optionnel)"
              onchange="KBF.builder.saveSection(${s.id},'description',this.value)">
          </div>
          <div class="bb-section-actions">
            <button class="bb-icon" title="Dupliquer la section" onclick="KBF.builder.duplicateSection(${s.id})">${kbIcon('copy')}</button>
            <button class="bb-icon bb-icon-danger" title="Supprimer la section" onclick="KBF.builder.deleteSection(${s.id})">${kbIcon('trash')}</button>
          </div>
        </div>
        ${this.repeatGroupsStrip(i)}
        <div class="bb-fields" data-sidx="${i}">
          ${this.fieldsOf(i).map(q => this.fieldHtml(q)).join('')}
        </div>
        <button class="bb-add" onclick="KBF.builder.addField(${i})">${kbIcon('plus')} Ajouter un champ</button>
      </section>`;
  },

  groupsOfSection(i) {
    return (this.repeatGroups || []).filter(g => Number(g.section_index) === Number(i));
  },

  repeatGroupsStrip(i) {
    const groups = this.groupsOfSection(i);
    const chips = groups.map(g => `
      <span class="bb-adv-row" style="display:inline-flex;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;padding:4px 8px;margin:0 6px 6px 0">
        <button class="bb-adv-add" style="padding:0" onclick="KBF.builder.editRepeatGroup(${g.id})">${kbIcon('refresh','w-3 h-3')} ${escapeHtml(g.label)} · ${g.min_repeat}–${g.max_repeat ?? '∞'} · ${(g.question_ids || []).length} champ(s)</button>
        <button class="bb-icon bb-icon-danger" style="width:20px;height:20px;margin-left:6px" onclick="KBF.builder.deleteRepeatGroup(${g.id})">${kbIcon('x','w-3 h-3')}</button>
      </span>`).join('');
    return `
      <div style="padding:4px 2px 2px">
        ${chips}
        <button class="bb-adv-add" onclick="KBF.builder.newRepeatGroup(${i})">${kbIcon('plus','w-3 h-3')} Groupe répétable</button>
      </div>`;
  },

  fieldHtml(q) {
    const adv = this.openAdv.has(Number(q.id));
    return `
      <div class="bb-field" data-id="${q.id}">
        <div class="bb-field-top">
          <span class="bb-drag" title="Glisser pour réordonner">⠿</span>
          <input class="bb-field-label" value="${escapeHtml(q.label || '')}" placeholder="Libellé du champ"
            oninput="KBF.builder.edit(${q.id},'label',this.value)">
          <select class="bb-field-type" onchange="KBF.builder.changeType(${q.id}, this.value)">
            ${BB_TYPES.map(t => `<option value="${t.type}" ${t.type === q.type ? 'selected' : ''}>${t.label}</option>`).join('')}
          </select>
          <button class="bb-icon" title="Dupliquer" onclick="KBF.builder.duplicateField(${q.id})">${kbIcon('copy')}</button>
          <button class="bb-icon bb-icon-danger" title="Supprimer" onclick="KBF.builder.deleteField(${q.id})">${kbIcon('trash')}</button>
        </div>
        <div class="bb-field-preview">${this.preview(q)}</div>
        <input class="bb-field-help" value="${escapeHtml(q.help_text || '')}" placeholder="Texte d'aide (optionnel)"
          oninput="KBF.builder.edit(${q.id},'help_text',this.value)">
        <div class="bb-field-bottom">
          <label class="bb-switch">
            <input type="checkbox" ${q.required ? 'checked' : ''} onchange="KBF.builder.edit(${q.id},'required',this.checked)">
            <span class="bb-switch-track"></span> Obligatoire
          </label>
          ${['signature','audio','video'].includes(q.type) ? '' : `
          <label class="bb-switch" title="Ajoute un bouton « Prendre une photo » sous ce champ, dans l'app mobile de collecte">
            <input type="checkbox" ${q.allow_photo ? 'checked' : ''} onchange="KBF.builder.edit(${q.id},'allow_photo',this.checked)">
            <span class="bb-switch-track"></span> Photo autorisée
          </label>`}
          ${this.groupsOfSection(q.section_index ?? 0).length ? `
            <select class="bb-adv-input" style="width:auto;max-width:220px" onchange="KBF.builder.setRepeatGroup(${q.id}, this.value)">
              <option value="">— Hors groupe répétable —</option>
              ${this.groupsOfSection(q.section_index ?? 0).map(g => `<option value="${g.id}" ${Number(q.repeat_group_id) === Number(g.id) ? 'selected' : ''}>Dans « ${escapeHtml(g.label)} »</option>`).join('')}
            </select>` : ''}
          <button class="bb-advanced-btn" onclick="KBF.builder.toggleAdvanced(${q.id}, this)">
            ${kbIcon('gear')} Avancé ${kbIcon(adv ? 'chevron-up' : 'chevron-down')}
          </button>
        </div>
        <div class="bb-advanced ${adv ? '' : 'hidden'}">${this.advanced(q)}</div>
      </div>`;
  },

  preview(q) {
    switch (q.type) {
      case 'short_text': return `<div class="bb-prev-input">Réponse courte</div>`;
      case 'long_text':  return `<div class="bb-prev-input bb-prev-area">Réponse longue…</div>`;
      case 'email':      return `<div class="bb-prev-input">exemple@domaine.com</div>`;
      case 'phone':      return `<div class="bb-prev-input">+237 6XX XXX XXX</div>`;
      case 'date':       return `<div class="bb-prev-input">jj / mm / aaaa ${kbIcon('calendar', 'w-4 h-4')}</div>`;
      case 'time':       return `<div class="bb-prev-input">-- : -- ${kbIcon('clock', 'w-4 h-4')}</div>`;
      case 'dropdown':   return `<div class="bb-prev-input">— Choisir — ${kbIcon('chevron-down', 'w-4 h-4')}</div>` + this.cascadeHint(q);
      case 'radio':
        return (this.cascadeHint(q) || (q.options || []).map(o => `<div class="bb-prev-opt"><span class="bb-prev-radio"></span>${escapeHtml(o)}</div>`).join('') || `<div class="bb-adv-empty">Aucune option</div>`);
      case 'checkbox':
        return (q.options || []).map(o => `<div class="bb-prev-opt"><span class="bb-prev-check"></span>${escapeHtml(o)}</div>`).join('') || `<div class="bb-adv-empty">Aucune option</div>`;
      case 'linear_scale': {
        const mn = q.scale_min ?? 1, mx = q.scale_max ?? 5, st = q.scale_step ?? 1;
        let out = '';
        for (let n = mn; n <= mx; n += (st || 1)) out += `<span>${n}</span>`;
        return `<div class="bb-prev-scale">${out}</div>`;
      }
      case 'grid': {
        const rows = q.grid_rows || [], cols = q.grid_columns || [];
        return `<table class="bb-prev-grid"><thead><tr><th></th>${cols.map(c => `<th>${escapeHtml(c)}</th>`).join('')}</tr></thead>
          <tbody>${rows.map(r => `<tr><td>${escapeHtml(r)}</td>${cols.map(() => `<td>○</td>`).join('')}</tr>`).join('')}</tbody></table>`;
      }
      case 'signature':
        return `<div class="bb-prev-input bb-prev-area" style="display:flex;align-items:center;justify-content:center;gap:6px;color:#94a3b8">${kbIcon('pencil', 'w-4 h-4')} Zone de signature</div>`;
      case 'audio':
        return `<div class="bb-prev-input" style="display:flex;align-items:center;gap:6px;color:#94a3b8">${kbIcon('mail', 'w-4 h-4')} Enregistrement audio${q.media_max_duration_s ? ` (max ${q.media_max_duration_s}s)` : ''}</div>`;
      case 'video':
        return `<div class="bb-prev-input bb-prev-area" style="display:flex;align-items:center;justify-content:center;gap:6px;color:#94a3b8">${kbIcon('monitor', 'w-4 h-4')} Capture vidéo${q.media_max_duration_s ? ` (max ${q.media_max_duration_s}s)` : ''}</div>`;
      case 'calculated':
        return `<div class="bb-prev-input" style="display:flex;align-items:center;gap:6px;color:#94a3b8;font-family:ui-monospace,monospace">${kbIcon('chart-bar', 'w-4 h-4')} ${q.calculated_expression ? escapeHtml(q.calculated_expression) : '= (définir la formule)'}</div>`;
      default: return `<div class="bb-prev-input">Réponse</div>`;
    }
  },

  advanced(q) {
    if (BB_CHOICE.includes(q.type)) {
      const cascade = (q.type === 'dropdown' || q.type === 'radio') ? this.cascadeAdvanced(q) : '';
      if (cascade && q.cascade_list_id) {
        // Liste en cascade active → les options viennent de la liste, pas de saisie manuelle.
        return cascade;
      }
      const opts = Array.isArray(q.options) ? q.options : [];
      return `${cascade}
        <span class="bb-adv-label">Options</span>
        ${opts.map((o, i) => `
          <div class="bb-adv-row">
            <input class="bb-adv-input" value="${escapeHtml(o)}" oninput="KBF.builder.editOption(${q.id},${i},this.value)">
            <button class="bb-icon bb-icon-danger" onclick="KBF.builder.removeOption(${q.id},${i})">${kbIcon('x')}</button>
          </div>`).join('')}
        <button class="bb-adv-add" onclick="KBF.builder.addOption(${q.id})">+ Ajouter une option</button>`;
    }
    if (q.type === 'grid') {
      const rows = q.grid_rows || [], cols = q.grid_columns || [];
      return `
        <span class="bb-adv-label">Lignes</span>
        ${rows.map((r, i) => `<div class="bb-adv-row"><input class="bb-adv-input" value="${escapeHtml(r)}" oninput="KBF.builder.editGrid(${q.id},'grid_rows',${i},this.value)"><button class="bb-icon bb-icon-danger" onclick="KBF.builder.removeGrid(${q.id},'grid_rows',${i})">${kbIcon('x')}</button></div>`).join('')}
        <button class="bb-adv-add" onclick="KBF.builder.addGrid(${q.id},'grid_rows')">+ Ajouter une ligne</button>
        <span class="bb-adv-label" style="margin-top:14px">Colonnes</span>
        ${cols.map((c, i) => `<div class="bb-adv-row"><input class="bb-adv-input" value="${escapeHtml(c)}" oninput="KBF.builder.editGrid(${q.id},'grid_columns',${i},this.value)"><button class="bb-icon bb-icon-danger" onclick="KBF.builder.removeGrid(${q.id},'grid_columns',${i})">${kbIcon('x')}</button></div>`).join('')}
        <button class="bb-adv-add" onclick="KBF.builder.addGrid(${q.id},'grid_columns')">+ Ajouter une colonne</button>`;
    }
    if (q.type === 'linear_scale') {
      return `
        <div class="bb-adv-row">
          <div><span class="bb-adv-label">Min</span><input class="bb-adv-input" type="number" value="${q.scale_min ?? 1}" onchange="KBF.builder.editNum(${q.id},'scale_min',this.value)"></div>
          <div><span class="bb-adv-label">Max</span><input class="bb-adv-input" type="number" value="${q.scale_max ?? 5}" onchange="KBF.builder.editNum(${q.id},'scale_max',this.value)"></div>
          <div><span class="bb-adv-label">Pas</span><input class="bb-adv-input" type="number" value="${q.scale_step ?? 1}" onchange="KBF.builder.editNum(${q.id},'scale_step',this.value)"></div>
        </div>`;
    }
    if (q.type === 'phone') {
      const dc = q.phone_default_country || '';
      return `
        <span class="bb-adv-label">Pays par défaut</span>
        <select class="bb-adv-input" onchange="KBF.builder.edit(${q.id},'phone_default_country',this.value)">
          <option value="">— Aucun (laissé au répondant) —</option>
          ${KBF.PHONE_COUNTRIES.map(c => `<option value="${c.code}" ${dc === c.code ? 'selected' : ''}>${c.flag} ${c.name} (${c.dial})</option>`).join('')}
        </select>`;
    }
    if (q.type === 'audio' || q.type === 'video') {
      return `
        <span class="bb-adv-label">Durée maximale conseillée (secondes)</span>
        <div class="bb-adv-row">
          <input class="bb-adv-input" type="number" min="1" placeholder="ex. 60 — vide = pas de limite"
            value="${q.media_max_duration_s ?? ''}"
            onchange="KBF.builder.editMediaDuration(${q.id},this.value)">
        </div>
        <p class="bb-adv-empty">Le fichier reste plafonné à 8 Mo côté serveur.</p>`;
    }
    if (q.type === 'calculated') {
      const refs = this.questions.filter(x => Number(x.id) !== Number(q.id)
        && ['short_text','linear_scale','date','dropdown','radio','calculated'].includes(x.type));
      return `
        <span class="bb-adv-label">Formule</span>
        <div class="bb-adv-row">
          <input class="bb-adv-input" value="${escapeHtml(q.calculated_expression || '')}"
            placeholder="{q12} + {q13}   ·   age({q4})   ·   round({q7} * 0.2)"
            style="font-family:ui-monospace,monospace"
            onchange="KBF.builder.setCalcExpr(${q.id}, this.value)">
        </div>
        <p class="bb-adv-empty">Fonctions : age({q}), round(x), abs(x). Opérateurs + - * / ( ). Champs référençables :</p>
        ${refs.length ? refs.map(x => `<button class="bb-adv-add" onclick="KBF.builder.insertRef(${q.id}, ${x.id})">{q${x.id}} — ${escapeHtml((x.label || 'sans titre').slice(0, 40))}</button>`).join('') : '<p class="bb-adv-empty">Ajoutez d\'abord d\'autres champs.</p>'}`;
    }
    return `<p class="bb-adv-empty">Aucune option avancée pour ce type de champ.</p>`;
  },

  // ── B7 : listes de choix en cascade ────────────────────────────────────
  cascadeHint(q) {
    if (!q.cascade_list_id) return '';
    const l = (this.choiceLists || []).find(x => Number(x.id) === Number(q.cascade_list_id));
    const p = q.cascade_parent_question_id
      ? this.questions.find(x => Number(x.id) === Number(q.cascade_parent_question_id)) : null;
    return `<div class="bb-adv-empty">↳ options depuis « ${escapeHtml(l ? l.name : '?')} »${p ? `, filtrées par « ${escapeHtml(p.label || 'champ')} »` : ' (niveau racine)'}</div>`;
  },

  cascadeAdvanced(q) {
    const lists = this.choiceLists || [];
    const linked = lists.find(l => Number(l.id) === Number(q.cascade_list_id));
    const parentCandidates = this.questions.filter(x =>
      Number(x.id) !== Number(q.id)
      && (x.type === 'dropdown' || x.type === 'radio')
      && Number(x.cascade_list_id) === Number(q.cascade_list_id));

    return `
      <span class="bb-adv-label">Liste en cascade</span>
      <div class="bb-adv-row">
        <select class="bb-adv-input" onchange="KBF.builder.setCascade(${q.id},'cascade_list_id',this.value)">
          <option value="">— Aucune (options manuelles) —</option>
          ${lists.map(l => `<option value="${l.id}" ${Number(q.cascade_list_id) === Number(l.id) ? 'selected' : ''}>${escapeHtml(l.name)} (${(l.items || []).length})</option>`).join('')}
        </select>
        <button class="bb-icon" title="Nouvelle liste" onclick="KBF.builder.newChoiceList()">${kbIcon('plus')}</button>
        ${linked ? `<button class="bb-icon bb-icon-danger" title="Supprimer la liste" onclick="KBF.builder.deleteChoiceList(${linked.id})">${kbIcon('trash')}</button>` : ''}
      </div>
      ${q.cascade_list_id ? `
        <span class="bb-adv-label" style="margin-top:12px">Filtrée par la réponse à</span>
        <select class="bb-adv-input" onchange="KBF.builder.setCascade(${q.id},'cascade_parent_question_id',this.value)">
          <option value="">— Niveau racine (aucun parent) —</option>
          ${parentCandidates.map(x => `<option value="${x.id}" ${Number(q.cascade_parent_question_id) === Number(x.id) ? 'selected' : ''}>${escapeHtml(x.label || 'Champ sans titre')}</option>`).join('')}
        </select>
        <span class="bb-adv-label" style="margin-top:12px">Importer le contenu (remplace tout)</span>
        <textarea class="bb-adv-input" id="cl-import-${q.id}" rows="3"
          placeholder="Une entrée par ligne. Niveaux séparés par ; ou tabulation.&#10;Nord;Garoua;Pitoa&#10;Centre;Yaoundé;Nlongkak"></textarea>
        <button class="bb-adv-add" onclick="KBF.builder.importChoiceList(${q.cascade_list_id}, document.getElementById('cl-import-${q.id}').value)">Importer dans « ${escapeHtml((linked || {}).name || '')} »</button>
        ${linked ? `<p class="bb-adv-empty">${this.cascadeListSummary(linked)}</p>` : ''}
      ` : ''}
      <div style="height:6px"></div>`;
  },

  cascadeListSummary(list) {
    const items = list.items || [];
    if (!items.length) return 'Liste vide — importez son contenu.';
    const roots = items.filter(i => i.parent_item_id == null).length;
    let depth = 1;
    const byParent = {};
    items.forEach(i => { (byParent[i.parent_item_id ?? 0] = byParent[i.parent_item_id ?? 0] || []).push(i); });
    const walk = (pid, d) => { (byParent[pid] || []).forEach(i => { depth = Math.max(depth, d); walk(i.id, d + 1); }); };
    walk(0, 1);
    return `${items.length} entrée(s), ${roots} racine(s), ${depth} niveau(x).`;
  },

  async setCascade(qid, field, rawValue) {
    const q = this.q(qid); if (!q) return;
    q[field] = rawValue ? Number(rawValue) : null;
    if (field === 'cascade_list_id') {
      q.cascade_parent_question_id = null;           // la parenté ne vaut que pour une liste donnée
      if (q.cascade_list_id) q.options = [];         // plus d'options manuelles
    }
    await this.put(q);
    this.render();
  },

  async newChoiceList() {
    const name = (prompt('Nom de la nouvelle liste (ex. Localités)') || '').trim();
    if (!name) return;
    const { ok, data } = await KBF_API.post(`/forms/${this.formId}/choice-lists`, { name });
    if (!ok || !data?.success) { showToast(data?.error || 'Création impossible', 'error'); return; }
    await this.reloadChoiceLists();
    this.render();
  },

  async deleteChoiceList(listId) {
    if (!confirm('Supprimer cette liste ? Les champs qui l\'utilisent repasseront en options manuelles.')) return;
    await KBF_API.delete(`/choice-lists/${listId}`);
    this.questions.forEach(q => {
      if (Number(q.cascade_list_id) === Number(listId)) {
        q.cascade_list_id = null; q.cascade_parent_question_id = null;
      }
    });
    await this.reloadChoiceLists();
    this.render();
  },

  async importChoiceList(listId, text) {
    const rows = (text || '').split(/\r?\n/)
      .map(line => line.split(/[;\t]/).map(c => c.trim()).filter(Boolean))
      .filter(r => r.length);
    if (!rows.length) { showToast('Rien à importer — collez des lignes d\'abord.', 'warning'); return; }
    const { ok, data } = await KBF_API.post(`/choice-lists/${listId}/import`, { rows });
    if (!ok || !data?.success) { showToast(data?.error || 'Import échoué', 'error'); return; }
    showToast(`${data.items_created} entrée(s) importée(s)`);
    await this.reloadChoiceLists();
    this.render();
  },

  // ── B8 : groupes de questions répétables ───────────────────────────────
  async newRepeatGroup(sectionIndex) {
    const label = (prompt('Nom du bloc répétable (ex. Membres du ménage)') || '').trim();
    if (!label) return;
    const { ok, data } = await KBF_API.post(`/forms/${this.formId}/repeat-groups`, { label, section_index: sectionIndex, min_repeat: 0 });
    if (!ok || !data?.success) { showToast(data?.error || 'Création impossible', 'error'); return; }
    await this.reloadRepeatGroups();
    this.render();
  },

  async editRepeatGroup(gid) {
    const g = (this.repeatGroups || []).find(x => Number(x.id) === Number(gid));
    if (!g) return;
    const label = (prompt('Nom du bloc', g.label) || '').trim();
    if (!label) return;
    const min = parseInt(prompt('Occurrences minimum (0 = optionnel)', g.min_repeat) || '0', 10) || 0;
    const maxRaw = prompt('Occurrences maximum (vide = pas de limite)', g.max_repeat ?? '');
    const max = (maxRaw === '' || maxRaw == null) ? null : (parseInt(maxRaw, 10) || null);
    const { ok, data } = await KBF_API.put(`/repeat-groups/${gid}`, { label, min_repeat: min, max_repeat: max });
    if (!ok || !data?.success) { showToast(data?.error || 'Mise à jour impossible', 'error'); return; }
    await this.reloadRepeatGroups();
    this.render();
  },

  async deleteRepeatGroup(gid) {
    if (!confirm('Supprimer ce bloc ? Les champs qu\'il contient redeviennent des champs normaux.')) return;
    await KBF_API.delete(`/repeat-groups/${gid}`);
    this.questions.forEach(q => { if (Number(q.repeat_group_id) === Number(gid)) q.repeat_group_id = null; });
    await this.reloadRepeatGroups();
    this.render();
  },

  async setRepeatGroup(qid, gid) {
    const q = this.q(qid); if (!q) return;
    q.repeat_group_id = gid ? Number(gid) : null;
    await this.put(q);
    await this.reloadRepeatGroups();   // le compteur de membres change
    this.render();
  },

  // ── Édition champ ──────────────────────────────────────────────────────
  edit(id, key, val) {
    const q = this.q(id); if (!q) return;
    q[key] = (key === 'required') ? !!val : val;
    // le libellé change → mettre à jour l'aperçu radio/checkbox ? non, seul le label bouge
    this.schedule(id);
  },

  editOption(id, i, val) { const q = this.q(id); if (!q) return; q.options[i] = val; this.schedule(id); this.refreshPreview(id); },
  addOption(id) {
    const q = this.q(id); if (!q) return;
    q.options = [...(q.options || []), `Option ${(q.options || []).length + 1}`];
    this.schedule(id); this.render();
  },
  removeOption(id, i) {
    const q = this.q(id); if (!q) return;
    q.options.splice(i, 1); this.schedule(id); this.render();
  },
  editGrid(id, key, i, val) { const q = this.q(id); if (!q) return; q[key][i] = val; this.schedule(id); this.refreshPreview(id); },
  addGrid(id, key) {
    const q = this.q(id); if (!q) return;
    const arr = q[key] || [];
    const label = key === 'grid_rows' ? `Ligne ${arr.length + 1}` : `Colonne ${String.fromCharCode(65 + arr.length)}`;
    q[key] = [...arr, label]; this.schedule(id); this.render();
  },
  removeGrid(id, key, i) { const q = this.q(id); if (!q) return; q[key].splice(i, 1); this.schedule(id); this.render(); },
  editNum(id, key, val) { const q = this.q(id); if (!q) return; q[key] = Number(val); this.schedule(id); this.refreshPreview(id); },
  editMediaDuration(id, val) {
    const q = this.q(id); if (!q) return;
    const n = parseInt(val, 10);
    q.media_max_duration_s = (Number.isFinite(n) && n > 0) ? n : null;
    this.schedule(id); this.refreshPreview(id);
  },
  setCalcExpr(id, val) {
    const q = this.q(id); if (!q) return;
    q.calculated_expression = val;
    this.schedule(id); this.refreshPreview(id);
  },
  insertRef(id, refId) {
    const q = this.q(id); if (!q) return;
    q.calculated_expression = ((q.calculated_expression || '') + (q.calculated_expression ? ' + ' : '') + `{q${refId}}`).trim();
    this.schedule(id); this.render();
  },

  refreshPreview(id) {
    const card = document.querySelector(`.bb-field[data-id="${id}"] .bb-field-preview`);
    if (card) card.innerHTML = this.preview(this.q(id));
  },

  toggleAdvanced(id, btn) {
    const key = Number(id);
    const card = btn.closest('.bb-field');
    const panel = card.querySelector('.bb-advanced');
    const open = panel.classList.toggle('hidden') === false;
    if (open) this.openAdv.add(key); else this.openAdv.delete(key);
    btn.innerHTML = `${kbIcon('gear')} Avancé ${kbIcon(open ? 'chevron-up' : 'chevron-down')}`;
  },

  async changeType(id, newType) {
    const q = this.q(id); if (!q) return;
    q.type = newType;
    if (BB_CHOICE.includes(newType) && (!q.options || !q.options.length)) q.options = ['Option 1', 'Option 2'];
    if (newType === 'linear_scale') { q.scale_min = q.scale_min ?? 1; q.scale_max = q.scale_max ?? 5; q.scale_step = q.scale_step ?? 1; }
    if (newType === 'grid') {
      if (!q.grid_rows || !q.grid_rows.length) q.grid_rows = ['Ligne 1', 'Ligne 2'];
      if (!q.grid_columns || !q.grid_columns.length) q.grid_columns = ['Colonne A', 'Colonne B'];
    }
    if (!BB_CHOICE.includes(newType)) q.options = [];
    if (newType !== 'dropdown' && newType !== 'radio') {
      q.cascade_list_id = null;
      q.cascade_parent_question_id = null;
    }
    if (newType !== 'audio' && newType !== 'video') q.media_max_duration_s = null;
    if (newType !== 'calculated') q.calculated_expression = null;
    if (['signature','audio','video'].includes(newType)) q.allow_photo = false; // M5 : capture média dédiée
    await this.put(q);
    this.render();
  },

  schedule(id) {
    clearTimeout(this._timers[id]);
    this._timers[id] = setTimeout(() => { const q = this.q(id); if (q) this.put(q); }, 650);
  },

  async put(q) {
    if (typeof setSaveStatus === 'function') setSaveStatus(null, 'Sauvegarde…');
    const payload = { ...q, label: (q.label && q.label.trim()) ? q.label : 'Champ sans titre' };
    const { ok } = await KBF_API.put(`/questions/${q.id}`, payload);
    if (typeof setSaveStatus === 'function') setSaveStatus(ok);
  },

  // ── Ajout / duplication / suppression de champ ─────────────────────────
  async addField(sidx) {
    const position = this.fieldsOf(sidx).length;
    const body = { form_id: this.formId, type: 'short_text', label: 'Champ sans titre', required: false, position, section_index: sidx };
    const { ok, data } = await KBF_API.post('/questions', body);
    if (!ok) { showToast('Erreur lors de l\'ajout', 'error'); return; }
    this.questions.push({ ...body, id: data.question_id, help_text: '', options: [] });
    this.render();
    const el = document.querySelector(`.bb-field[data-id="${data.question_id}"] .bb-field-label`);
    if (el) el.focus();
  },

  async duplicateField(id) {
    const s = this.q(id); if (!s) return;
    const sidx = Number(s.section_index);
    const body = {
      form_id: this.formId, type: s.type, label: (s.label || 'Champ') + ' (copie)',
      required: !!s.required, position: this.fieldsOf(sidx).length, section_index: sidx,
      help_text: s.help_text || '', options: s.options || [],
      grid_rows: s.grid_rows, grid_columns: s.grid_columns,
      scale_min: s.scale_min, scale_max: s.scale_max, scale_step: s.scale_step,
      phone_default_country: s.phone_default_country,
    };
    const { ok, data } = await KBF_API.post('/questions', body);
    if (!ok) { showToast('Erreur', 'error'); return; }
    this.questions.push({ ...body, id: data.question_id });
    this.render();
  },

  async deleteField(id) {
    if (!confirm('Supprimer ce champ ?')) return;
    const { ok } = await KBF_API.delete(`/questions/${id}`);
    if (!ok) { showToast('Erreur', 'error'); return; }
    this.questions = this.questions.filter(q => Number(q.id) !== Number(id));
    this.openAdv.delete(Number(id));
    this.render();
  },

  // ── Sections ──────────────────────────────────────────────────────────
  async saveSection(id, key, val) {
    const s = this.sec(id); if (!s) return;
    s[key] = val;
    if (typeof setSaveStatus === 'function') setSaveStatus(null, 'Sauvegarde…');
    const { ok } = await KBF_API.put(`/sections/${id}`, { [key]: val });
    if (typeof setSaveStatus === 'function') setSaveStatus(ok);
  },

  async addSection() {
    const position = this.sections.length;
    const { ok, data } = await KBF_API.post(`/forms/${this.formId}/sections`, { title: 'Nouvelle section', position });
    if (!ok) { showToast('Erreur', 'error'); return; }
    this.sections.push({ id: data.section_id, title: 'Nouvelle section', description: '', position });
    this.render();
  },

  async duplicateSection(id) {
    const src = this.sec(id); if (!src) return;
    const srcIdx = this.sections.indexOf(src);
    const newIdx = this.sections.length;
    const { ok, data } = await KBF_API.post(`/forms/${this.formId}/sections`, {
      title: (src.title || 'Section') + ' (copie)', position: newIdx,
    });
    if (!ok) { showToast('Erreur', 'error'); return; }
    if (src.description) await KBF_API.put(`/sections/${data.section_id}`, { description: src.description });
    this.sections.push({ id: data.section_id, title: (src.title || 'Section') + ' (copie)', description: src.description || '', position: newIdx });
    // copier les champs
    const fields = this.fieldsOf(srcIdx);
    for (const f of fields) {
      const body = {
        form_id: this.formId, type: f.type, label: f.label, required: !!f.required,
        position: f.position, section_index: newIdx, help_text: f.help_text || '', options: f.options || [],
        grid_rows: f.grid_rows, grid_columns: f.grid_columns,
        scale_min: f.scale_min, scale_max: f.scale_max, scale_step: f.scale_step,
        phone_default_country: f.phone_default_country,
      };
      const r = await KBF_API.post('/questions', body);
      if (r.ok) this.questions.push({ ...body, id: r.data.question_id });
    }
    this.render();
  },

  async deleteSection(id) {
    if (this.sections.length <= 1) { showToast('Le formulaire doit garder au moins une section', 'error'); return; }
    const s = this.sec(id); if (!s) return;
    const idx = this.sections.indexOf(s);
    const fields = this.fieldsOf(idx);
    if (!confirm(`Supprimer la section « ${s.title || 'sans titre'} »${fields.length ? ` et ses ${fields.length} champ(s)` : ''} ?`)) return;

    for (const f of fields) await KBF_API.delete(`/questions/${f.id}`);
    await KBF_API.delete(`/sections/${id}`);

    this.questions = this.questions.filter(q => Number(q.section_index) !== idx);
    this.sections.splice(idx, 1);

    // décaler les section_index des sections suivantes
    const shifted = [];
    this.questions.forEach(q => {
      if (Number(q.section_index) > idx) { q.section_index = Number(q.section_index) - 1; shifted.push({ id: q.id, position: q.position, section_index: q.section_index }); }
    });
    if (shifted.length) await KBF_API.put('/questions/reorder', { items: shifted });

    this.render();
  },

  // ── Drag & drop ───────────────────────────────────────────────────────
  mountSortables() {
    if (!window.Sortable) return;
    document.querySelectorAll('.bb-fields').forEach(list => {
      Sortable.create(list, {
        group: 'bb-fields',
        handle: '.bb-drag',
        animation: 150,
        ghostClass: 'sortable-ghost',
        dragClass: 'sortable-drag',
        onEnd: () => this.persistOrder(),
      });
    });
  },

  async persistOrder() {
    const items = [];
    document.querySelectorAll('.bb-fields').forEach(list => {
      const sidx = Number(list.dataset.sidx);
      Array.from(list.querySelectorAll('.bb-field')).forEach((el, pos) => {
        const id = Number(el.dataset.id);
        const q = this.q(id);
        if (q) { q.position = pos; q.section_index = sidx; }
        items.push({ id, position: pos, section_index: sidx });
      });
    });
    if (typeof setSaveStatus === 'function') setSaveStatus(null, 'Sauvegarde…');
    const { ok } = await KBF_API.put('/questions/reorder', { items });
    if (typeof setSaveStatus === 'function') setSaveStatus(ok);
  },
};
