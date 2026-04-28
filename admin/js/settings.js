(function () {
	'use strict';

	function copyValue(input, button) {
		if (!input || !button) return;
		var copied = button.getAttribute('data-copied') || 'Copied!';
		var label = button.getAttribute('data-label') || 'Copy';
		var doneFn = function () {
			button.textContent = copied;
			setTimeout(function () { button.textContent = label; }, 2000);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(input.value).then(doneFn, function () {
				input.select();
				try { document.execCommand('copy'); } catch (e) {}
				doneFn();
			});
		} else {
			input.select();
			try { document.execCommand('copy'); } catch (e) {}
			doneFn();
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		var copyButtons = document.querySelectorAll('[data-sb-copy]');
		copyButtons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				var targetId = btn.getAttribute('data-sb-copy');
				var input = document.getElementById(targetId);
				copyValue(input, btn);
			});
		});

		var confirmForms = document.querySelectorAll('[data-sb-confirm]');
		confirmForms.forEach(function (form) {
			form.addEventListener('submit', function (e) {
				var msg = form.getAttribute('data-sb-confirm');
				if (msg && !window.confirm(msg)) {
					e.preventDefault();
				}
			});
		});
	});
})();
