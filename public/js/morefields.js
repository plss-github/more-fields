/* More Fields: avaliação ao vivo das regras de visibilidade + editor de condições.
 * (C) 2026 Matheus Schmidt - GPLv2+ (ver LICENSE) */
(function () {
    'use strict';

    /* ------------------------------------------------------------------ *
     * Runtime: espelha src/RuleEngine.php (o servidor continua sendo a autoridade).
     * ------------------------------------------------------------------ */

    const CORE_INPUTS = {itilcategory: 'itilcategories_id', status: 'status', type: 'type'};

    function readRow(row) {
        const select = row.querySelector('select');
        if (select) {
            return Array.from(select.selectedOptions).map(o => o.value);
        }
        const input = row.querySelector('input:not([type=hidden]), textarea');
        return input ? [input.value] : [];
    }

    function current(cond, cfg, form) {
        const ctx = cfg.ctx || {};
        if (cond.c === 'field') {
            const row = document.querySelector('.mf-field[data-mf-def="' + cond.fid + '"]');
            return row ? readRow(row) : ((ctx.field || {})[cond.fid] || []);
        }
        if (CORE_INPUTS[cond.c]) {
            const el = cfg.mode === 'dom' && form ? form.querySelector('[name="' + CORE_INPUTS[cond.c] + '"]') : null;
            return [el ? el.value : (ctx[cond.c] || '')];
        }
        return [ctx[cond.c] === undefined ? '' : ctx[cond.c]];
    }

    function evalCond(cond, values) {
        values = values.map(String).filter(v => v !== '');
        const hit = values.filter(v => cond.vals.includes(v)).length > 0;
        switch (cond.op) {
            case 'empty': return values.length === 0;
            case 'not_empty': return values.length > 0;
            case 'not_in': return !hit;
            default: return hit;
        }
    }

    function matches(rule, cfg, form) {
        if (rule.never) { return false; }
        if (!rule.conds.length) { return true; }
        for (const cond of rule.conds) {
            const ok = evalCond(cond, current(cond, cfg, form));
            if (rule.match === 'OR' && ok) { return true; }
            if (rule.match !== 'OR' && !ok) { return false; }
        }
        return rule.match !== 'OR';
    }

    function state(baseRequired, rules, cfg, form) {
        let hasShow = false, show = false, hide = false, required = baseRequired, readonly = false;
        (rules || []).forEach(r => {
            const hit = matches(r.compiled || r, cfg, form);
            if (r.action === 'show') { hasShow = true; show = show || hit; }
            if (r.action === 'hide') { hide = hide || hit; }
            if (r.action === 'required') { required = required || hit; }
            if (r.action === 'readonly') { readonly = readonly || hit; }
        });
        return {visible: (hasShow ? show : true) && !hide, required, readonly};
    }

    /* Campos do formulário principal: entram na mesma grade dos campos nativos
     * (alinhamento idêntico). Se não houver grade nativa, ficam onde estão. */
    function relocate(container) {
        if (container.dataset.mfMoved || container.dataset.mfMode !== 'dom') { return; }
        const form = container.closest('form');
        const native = form && form.querySelector('.form-field:not(.mf-field)');
        const grid = native && native.parentElement;
        if (!grid || !grid.classList.contains('row') || container.contains(grid)) { return; }
        container.dataset.mfMoved = '1';
        container.querySelectorAll('.mf-field').forEach(row => grid.appendChild(row));
    }

    function fieldRows(container, form) {
        const cid = container.dataset.mfContainer;
        return Array.from((form || document).querySelectorAll('.mf-field[data-mf-cid="' + cid + '"]'));
    }

    function evaluate(container) {
        let cfg;
        try { cfg = JSON.parse(container.dataset.mfConfig); } catch (e) { return; }
        const form = container.closest('form');
        container.dataset.mfMode = cfg.mode;
        relocate(container);

        const cvisible = state(false, cfg.container, cfg, form).visible;
        container.style.display = cvisible ? '' : 'none';

        fieldRows(container, form).forEach(row => {
            const f = (cfg.fields || {})[row.dataset.mfDef];
            if (!f) { return; }
            const st = state(f.required, f.rules, cfg, form);
            row.style.display = cvisible && st.visible ? '' : 'none';
            if (st.readonly) { row.dataset.mfReadonly = '1'; } else { delete row.dataset.mfReadonly; }
            const star = row.querySelector('.mf-star');
            if (star) { star.style.display = st.required ? '' : 'none'; }
        });
    }

    let scheduled = false;
    function evaluateAll() {
        if (scheduled) { return; }
        scheduled = true;
        setTimeout(() => {
            scheduled = false;
            document.querySelectorAll('.mf-container[data-mf-config]').forEach(evaluate);
        }, 30);
    }

    /* ------------------------------------------------------------------ *
     * Editor de condições da regra
     * ------------------------------------------------------------------ */

    function initConditionEditor(root) {
        if (root.dataset.mfReady) { return; }
        root.dataset.mfReady = '1';

        const data = JSON.parse(root.dataset.options);
        const store = document.getElementById('mf-conditions-json');
        let conds = [];
        try { conds = JSON.parse(store.value) || []; } catch (e) { conds = []; }

        const OPS = {in: 'é', not_in: 'não é', empty: 'está vazio', not_empty: 'está preenchido'};

        // select2 só dispara 'change' pelo jQuery; jQuery também recebe os eventos nativos.
        const onChange = (node, fn) => window.jQuery ? window.jQuery(node).on('change', fn) : node.addEventListener('change', fn);
        const enhance = () => {
            if (!window.jQuery || !window.jQuery.fn.select2) { return; }
            window.jQuery(root).find('select').each(function () {
                const multi = this.multiple;
                window.jQuery(this).select2({
                    width: multi ? '100%' : (this.dataset.mfWidth || '14rem'),
                    closeOnSelect: !multi,
                    placeholder: multi ? 'Selecione os valores' : undefined,
                });
            });
        };

        const el = (tag, attrs, children) => {
            const n = document.createElement(tag);
            Object.entries(attrs || {}).forEach(([k, v]) => n[k] = v);
            (children || []).forEach(c => n.appendChild(c));
            return n;
        };
        const option = (value, label, selected) => el('option', {value: value, textContent: label, selected: !!selected});

        function keyOf(c) { return c.c === 'field' ? 'field:' + c.fid : c.c; }

        function optionsFor(c) {
            if (c.c === 'field') {
                const f = data.fields.find(x => x.id === Number(c.fid));
                return f ? f.options : null;
            }
            return (data.criteria[c.c] || {}).options || null;
        }

        function save() {
            store.value = JSON.stringify(conds);
        }

        function render() {
            root.innerHTML = '';
            conds.forEach((c, idx) => root.appendChild(row(c, idx)));
            const add = el('button', {type: 'button', className: 'btn btn-outline-secondary btn-sm', textContent: '+ Adicionar condição'});
            add.addEventListener('click', () => {
                conds.push({c: 'itilcategory', fid: null, op: 'in', vals: [], sons: true});
                save(); render();
            });
            root.appendChild(add);
            enhance();
            save();
        }

        function row(c, idx) {
            const crit = el('select', {className: 'form-select'});
            crit.dataset.mfWidth = '16rem';
            Object.entries(data.criteria).forEach(([k, v]) => crit.appendChild(option(k, v.label, keyOf(c) === k)));
            if (data.fields.length) {
                const grp = el('optgroup', {label: 'Campos extras'});
                data.fields.forEach(f => grp.appendChild(option('field:' + f.id, f.label, keyOf(c) === 'field:' + f.id)));
                crit.appendChild(grp);
            }
            onChange(crit, () => {
                const v = crit.value;
                if (v.startsWith('field:')) { c.c = 'field'; c.fid = Number(v.slice(6)); } else { c.c = v; c.fid = null; }
                c.vals = []; c.sons = !!(data.criteria[c.c] || {}).sons;
                save(); render();
            });

            const op = el('select', {className: 'form-select'});
            op.dataset.mfWidth = '12rem';
            Object.entries(OPS).forEach(([k, v]) => op.appendChild(option(k, v, c.op === k)));
            onChange(op, () => { c.op = op.value; save(); render(); });

            const del = el('button', {type: 'button', className: 'btn btn-icon btn-outline-danger btn-sm ms-auto', title: 'Remover condição'});
            del.innerHTML = '<i class="ti ti-trash"></i>';
            del.addEventListener('click', () => { conds.splice(idx, 1); save(); render(); });

            const top = el('div', {className: 'd-flex gap-2 align-items-center flex-wrap'}, [crit, op, del]);
            const box = el('div', {className: 'mf-cond'}, [top]);

            if (c.op === 'in' || c.op === 'not_in') {
                const opts = optionsFor(c);
                const line = el('div', {className: 'mt-2'});
                if (opts) {
                    const sel = el('select', {className: 'form-select', multiple: true});
                    Object.entries(opts).forEach(([id, label]) => sel.appendChild(option(id, label, (c.vals || []).map(String).includes(String(id)))));
                    onChange(sel, () => { c.vals = Array.from(sel.selectedOptions).map(o => o.value); save(); });
                    line.appendChild(sel);
                } else {
                    const txt = el('input', {type: 'text', className: 'form-control', placeholder: 'Valor', value: (c.vals || [])[0] || ''});
                    txt.addEventListener('input', () => { c.vals = txt.value === '' ? [] : [txt.value]; save(); });
                    line.appendChild(txt);
                }
                if ((data.criteria[c.c] || {}).sons) {
                    const id = 'mf_sons_' + idx;
                    const cb = el('input', {type: 'checkbox', className: 'form-check-input', id: id, checked: !!c.sons});
                    cb.addEventListener('change', () => { c.sons = cb.checked; save(); });
                    line.appendChild(el('div', {className: 'form-check mt-2'}, [cb, el('label', {className: 'form-check-label', htmlFor: id, textContent: 'incluir subitens'})]));
                }
                box.appendChild(line);
            }

            return box;
        }

        render();
    }

    /* ------------------------------------------------------------------ */

    /* No cadastro de campos do bloco, "Nome da aba" só vale para local = Aba. */
    function syncPlacement(select) {
        const form = select.closest('form');
        const box = form && form.querySelector('[data-mf-tablabel]');
        if (box) { box.style.display = select.value === 'tab' ? '' : 'none'; }
    }

    /* "Salvar tudo" (aba Campos do bloco): marca as linhas alteradas, mostra quantas no botão e
     * avisa antes de sair da página com alterações não salvas. */
    let mfDirty = 0;
    let mfSaving = false;
    const mfValue = el => el.type === 'checkbox' ? String(el.checked)
        : (el.multiple ? Array.from(el.selectedOptions).map(o => o.value).sort().join(',') : el.value);

    function trackDirty() {
        const controls = document.querySelectorAll('[form="mf_cf_all"][name^="rows["]');
        if (!controls.length) { mfDirty = 0; return; }
        const rows = new Set();
        controls.forEach(el => {
            if (el.dataset.mfInit === undefined) { el.dataset.mfInit = mfValue(el); }
            if (el.dataset.mfInit !== mfValue(el)) { rows.add(el.closest('tr')); }
        });
        document.querySelectorAll('.mf-table tbody tr').forEach(tr => tr.classList.toggle('table-warning', rows.has(tr)));
        document.querySelectorAll('[data-mf-save-count]').forEach(badge => {
            // Só escreve quando muda: alterar o texto dispara o MutationObserver (que chama boot() de novo).
            if (badge.textContent !== String(rows.size)) { badge.textContent = String(rows.size); }
            const display = rows.size ? '' : 'none';
            if (badge.style.display !== display) { badge.style.display = display; }
        });
        mfDirty = rows.size;
    }

    function boot() {
        trackDirty();
        document.querySelectorAll('form select[name=placement]').forEach(syncPlacement);
        document.querySelectorAll('#mf-conditions').forEach(initConditionEditor);
        evaluateAll();
    }

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
    }

    ready(() => {
        boot();
        new MutationObserver(boot).observe(document.body, {childList: true, subtree: true});
        if (window.jQuery) {
            window.jQuery(document).on('change', 'select[name=placement]', e => syncPlacement(e.target));
            window.jQuery(document).on('change input', '[form="mf_cf_all"]', trackDirty);
            window.jQuery(document).on('change', 'form select, form input, form textarea', evaluateAll);
        }
        document.addEventListener('input', evaluateAll);
        document.addEventListener('submit', () => { mfSaving = true; }, true);
        window.addEventListener('beforeunload', e => {
            if (mfDirty > 0 && !mfSaving) { e.preventDefault(); e.returnValue = ''; }
        });
    });
})();
