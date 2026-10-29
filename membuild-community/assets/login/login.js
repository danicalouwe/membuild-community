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
