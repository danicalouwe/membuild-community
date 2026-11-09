/* The "Show" word on password fields of the community login pages. Words come from data attributes. */
document.querySelectorAll('.show').forEach(function (button) {
	button.addEventListener('click', function () {
		var input = button.parentNode.querySelector('input');
		var show = input.type === 'password';
		input.type = show ? 'text' : 'password';
		button.textContent = show ? button.dataset.hide : button.dataset.show;
		button.setAttribute('aria-pressed', show ? 'true' : 'false');
		input.focus();
	});
});

/* Two-step login setup: the app's QR code, drawn here by Two Factor's own QR maker (never sent anywhere). */
document.querySelectorAll('[data-qr]').forEach(function (box) {
	if (!window.qrcode) {
		return;
	}
	var qr = window.qrcode(0, 'M');
	if (window.qrcode.stringToBytesFuncs && window.qrcode.stringToBytesFuncs['UTF-8']) {
		window.qrcode.stringToBytes = window.qrcode.stringToBytesFuncs['UTF-8'];
	}
	qr.addData(box.dataset.qr);
	qr.make();
	box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
});
