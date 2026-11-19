/**
 * MB Community lists in wp-admin: the blue bar for ticked rows, the ··· menus,
 * and the small pop-up that asks before anything is deleted or banned.
 * @package membuild-community
 */
(function () {
	var t = window.mbcomLists || {};
	var root = document.querySelector('.mbcom-admin');
	if (!root) return;

	function each(sel, el, fn) { Array.prototype.forEach.call((el || document).querySelectorAll(sel), fn); }

	/* ---------- The pop-up ---------- */

	function ask(c, go) {
		var last = document.activeElement;
		var ov = document.createElement('div');
		ov.className = 'mbwa-overlay mbcom-overlay';
		ov.innerHTML = '<div class="mbwa-dialog" role="dialog" aria-modal="true" aria-labelledby="mbcom-ask-title">' +
			'<div class="mbwa-dialog-head"><h2 class="mbwa-dialog-title" id="mbcom-ask-title"></h2>' +
			'<button type="button" class="mbcom-close" data-no><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg></button></div>' +
			'<div class="mbwa-dialog-form"><p class="mbcom-ask-text"></p></div>' +
			'<div class="mbwa-dialog-foot"><button type="button" class="mbcom-text-btn" data-no></button></div></div>';
		ov.querySelector('.mbwa-dialog-title').textContent = c.title;
		ov.querySelector('.mbcom-ask-text').textContent = c.text;
		ov.querySelector('.mbcom-close').setAttribute('aria-label', t.close || 'Close');
		ov.querySelector('.mbcom-text-btn').textContent = t.cancel || 'Cancel';
		var foot = ov.querySelector('.mbwa-dialog-foot');
		if (c.alt && c.altHref) {
			var alt = document.createElement('a');
			alt.className = 'mbwa-btn';
			alt.href = c.altHref;
			alt.textContent = c.alt;
			foot.appendChild(alt);
		}
		var yes = document.createElement('button');
		yes.type = 'button';
		yes.className = 'mbwa-btn mbwa-btn-primary mbcom-danger';
		yes.textContent = c.go;
		foot.appendChild(yes);

		function close() {
			ov.remove();
			document.removeEventListener('keydown', keys, true);
			if (last && last.focus) last.focus();
		}
		function keys(e) {
			if (e.key === 'Escape') { e.preventDefault(); close(); return; }
			if (e.key !== 'Tab') return;
			var f = ov.querySelectorAll('button, a[href]');
			var first = f[0], end = f[f.length - 1];
			if (e.shiftKey && document.activeElement === first) { e.preventDefault(); end.focus(); }
			else if (!e.shiftKey && document.activeElement === end) { e.preventDefault(); first.focus(); }
		}
		each('[data-no]', ov, function (b) { b.addEventListener('click', close); });
		ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
		yes.addEventListener('click', function () {
			yes.classList.add('is-busy');
			go();
		});
		document.addEventListener('keydown', keys, true);
		root.appendChild(ov);
		ov.querySelector('.mbcom-text-btn').focus();
	}

	/* ---------- Ticked rows and the blue bar ---------- */

	each('.mbcom-bulk', null, function (form) {
		var bar = form.querySelector('.mbcom-selbar');
		var all = form.querySelector('.mbcom-tick-all');
		var boxes = Array.prototype.slice.call(form.querySelectorAll('.mbcom-tick'));
		if (!bar) return;
		var ticked = function () { return boxes.filter(function (b) { return b.checked; }); };

		function update() {
			var on = ticked();
			var kinds = on.map(function (b) { return b.getAttribute('data-kind'); });
			bar.hidden = !on.length;
			bar.querySelector('[data-mbcom-count]').textContent = (on.length === 1 ? (t.one || '1 selected') : (t.many || '{n} selected')).replace('{n}', on.length);
			each('[data-for]', bar, function (el) {
				var f = el.getAttribute('data-for');
				el.hidden = !(f === '*' ? on.length : f.split(',').some(function (k) { return kinds.indexOf(k) > -1; }));
			});
			if (all) {
				all.checked = !!on.length && on.length === boxes.length;
				all.indeterminate = !!on.length && on.length < boxes.length;
			}
			boxes.forEach(function (b) { b.closest('.mbwa-trow').classList.toggle('is-ticked', b.checked); });
		}
		var bulkField = document.createElement('input');
		bulkField.type = 'hidden';
		bulkField.name = 'bulk';
		function send(value) {
			bulkField.value = value;
			if (!bulkField.parentNode) form.appendChild(bulkField);
			form.submit();
		}
		// Back from a sent action: the page may come back from the browser's cache with the old choice in it.
		window.addEventListener('pageshow', function (e) {
			if (!e.persisted) return;
			if (bulkField.parentNode) bulkField.parentNode.removeChild(bulkField);
			each('.mbcom-overlay', null, function (o) { o.remove(); });
			each('select[data-mbcom-submit]', bar, function (s) { s.selectedIndex = 0; });
		});

		boxes.forEach(function (b) { b.addEventListener('change', update); });
		if (all) all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); update(); });
		bar.querySelector('[data-mbcom-clear]').addEventListener('click', function () { boxes.forEach(function (b) { b.checked = false; }); update(); if (all) all.focus(); });
		each('button[data-confirm]', bar, function (btn) {
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				var c = JSON.parse(btn.getAttribute('data-confirm'));
				var n = ticked().length;
				ask({ title: (n === 1 ? c.title : c.titles).replace('{n}', n), text: n === 1 ? c.text : c.texts, go: c.go }, function () { send(btn.value); });
			});
		});
		each('select[data-mbcom-submit]', bar, function (s) {
			s.addEventListener('change', function () { if (s.value) form.submit(); });
		});
		update();
	});

	/* ---------- The toolbar's dropdown (posts or reels) ---------- */

	each('.mbcom-toolbar select[data-mbcom-submit]', null, function (s) {
		s.addEventListener('change', function () { s.form.submit(); });
	});

	/* ---------- ··· menus ---------- */

	document.addEventListener('click', function (e) {
		each('.mbcom-more[open]', null, function (d) { if (!d.contains(e.target)) d.open = false; });
	});
	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') return;
		each('.mbcom-more[open]', null, function (d) { d.open = false; d.querySelector('summary').focus(); });
	});
	each('.mbcom-more', null, function (d) {
		d.addEventListener('toggle', function () {
			if (!d.open) return;
			each('.mbcom-more[open]', null, function (o) { if (o !== d) o.open = false; });
			var first = d.querySelector('.mbcom-menu a');
			if (first) first.focus();
		});
	});
	each('.mbcom-menu a[data-confirm]', null, function (a) {
		a.addEventListener('click', function (e) {
			e.preventDefault();
			var d = a.closest('details');
			if (d) d.open = false;
			ask(JSON.parse(a.getAttribute('data-confirm')), function () { window.location.href = a.href; });
		});
	});
})();

/* ---------- Help: the message form ---------- */
(function () {
	var t = window.mbcomLists || {};
	var form = document.querySelector('.mbcom-help-form');
	if (!form) return;

	// Loom and screenshots for a problem or an idea; the share box for kind words.
	function kind() { var r = form.querySelector('input[name="kind"]:checked'); return r ? r.value : 'bug'; }
	function show() {
		var k = kind();
		Array.prototype.forEach.call(form.querySelectorAll('.mbcom-show'), function (el) {
			el.hidden = el.getAttribute('data-kinds').split(' ').indexOf(k) < 0;
		});
		Array.prototype.forEach.call(form.querySelectorAll('.mbcom-kind'), function (l) {
			l.classList.toggle('is-on', l.querySelector('input').checked);
		});
	}
	form.addEventListener('change', function (e) { if (e.target.name === 'kind') show(); });
	show();

	// A Loom link is checked when the field is left.
	var loom = form.querySelector('#mbcom-help-loom');
	var loomErr = form.querySelector('#mbcom-help-loom-err');
	function loomOk() {
		var v = loom.value.trim();
		if (!v) return true;
		v = v.replace(/^(https?:\/\/)?/i, 'https://');
		var ok = /^https:\/\/(www\.)?loom\.com\/share\/[A-Za-z0-9]+([?\/#].*)?$/.test(v);
		if (ok) loom.value = v;
		return ok;
	}
	function loomCheck() {
		var ok = loomOk();
		loomErr.hidden = ok;
		if (ok) { loom.removeAttribute('aria-invalid'); loom.removeAttribute('aria-describedby'); }
		else { loom.setAttribute('aria-invalid', 'true'); loom.setAttribute('aria-describedby', 'mbcom-help-loom-err'); }
		return ok;
	}
	if (loom) loom.addEventListener('blur', loomCheck);

	// Screenshots: picked ones show as small previews with a remove button; up to 3.
	var input = form.querySelector('input[name="shots[]"]');
	var list = form.querySelector('.mbcom-shots');
	var add = form.querySelector('.mbcom-add-shot');
	var err = form.querySelector('#mbcom-shots-err');
	var picked = [];
	var maxShot = parseInt(form.getAttribute('data-max-shot'), 10) || 5 * 1024 * 1024;
	var maxPost = parseInt(form.getAttribute('data-max-post'), 10) || 0;
	var urls = [];
	var canSet = typeof DataTransfer === 'function';
	function say(msg) { err.textContent = msg || ''; err.hidden = !msg; }
	function draw() {
		urls.forEach(function (u) { URL.revokeObjectURL(u); });
		urls = [];
		list.innerHTML = '';
		picked.forEach(function (f, i) {
			var box = document.createElement('span');
			box.className = 'mbcom-shot';
			var img = document.createElement('img');
			img.alt = '';
			img.src = URL.createObjectURL(f);
			urls.push(img.src);
			var x = document.createElement('button');
			x.type = 'button';
			x.className = 'mbcom-shot-x';
			x.setAttribute('aria-label', (t.remove || 'Remove {name}').replace('{name}', f.name));
			x.innerHTML = '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg>';
			x.addEventListener('click', function () { picked.splice(i, 1); sync(); draw(); say(''); input.focus(); });
			box.appendChild(img);
			box.appendChild(x);
			list.appendChild(box);
		});
		add.hidden = picked.length >= 3;
	}
	function sync() {
		if (!canSet) return;
		var dt = new DataTransfer();
		picked.forEach(function (f) { dt.items.add(f); });
		input.files = dt.files;
	}
	if (input) input.addEventListener('change', function () {
		var msg = '';
		var fresh = Array.prototype.slice.call(input.files);
		if (!canSet) { picked = fresh.slice(0, 3); draw(); return; }
		fresh.forEach(function (f) {
			if (['image/png', 'image/jpeg', 'image/webp'].indexOf(f.type) < 0) { msg = (t.shotType || '').replace('{name}', f.name); return; }
			var sum = picked.reduce(function (n, p) { return n + p.size; }, 0);
			if (f.size > maxShot || (maxPost && sum + f.size > maxPost - 512 * 1024)) { msg = (t.shotBig || '').replace('{name}', f.name); return; }
			if (picked.length >= 3) { msg = t.shotMax || ''; return; }
			picked.push(f);
		});
		sync();
		draw();
		say(msg);
	});

	form.addEventListener('submit', function (e) {
		if (loom && !kind().match(/nice/) && !loomCheck()) { e.preventDefault(); loom.focus(); return; }
		var btn = form.querySelector('.mbcom-help-foot .mbwa-btn-primary');
		if (btn) btn.classList.add('is-busy');
	});
})();
