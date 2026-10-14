/* Show / hide password buttons on the community login pages. Labels come from data attributes. */
document.querySelectorAll('.eye').forEach(function (button) {
	button.addEventListener('click', function () {
		var input = button.parentNode.querySelector('input');
		var show = input.type === 'password';
		input.type = show ? 'text' : 'password';
		button.setAttribute('aria-pressed', show ? 'true' : 'false');
		button.setAttribute('aria-label', show ? button.dataset.hide : button.dataset.show);
		input.focus();
	});
});
