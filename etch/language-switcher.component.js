(function () {
	var roots = document.querySelectorAll('[data-lb-etch-language-switcher]');

	roots.forEach(function (root) {
		if (root.dataset.lbEtchSwitcherReady === 'true') return;

		var trigger = root.querySelector('[data-lb-language-trigger]');
		var currentLabel = root.querySelector('[data-lb-language-current]');
		var menu = root.querySelector('[data-lb-language-menu]');
		var source = root.querySelector('.lb-language-switcher');
		if (!trigger || !currentLabel || !menu || !source) return;

		var links = Array.prototype.slice.call(source.querySelectorAll('a[href]'));
		if (links.length < 2) {
			source.remove();
			root.hidden = true;
			return;
		}

		menu.replaceChildren();
		var activeLink = links.find(function (link) {
			return link.getAttribute('aria-current') === 'page';
		}) || links[0];

		links.forEach(function (link) {
			var item = document.createElement('a');
			item.href = link.href;
			item.textContent = link.textContent.trim();
			item.setAttribute('role', 'menuitem');
			item.tabIndex = -1;
			var language = link.getAttribute('lang');
			if (language) item.lang = language;
			if (link.getAttribute('aria-current') === 'page') {
				item.setAttribute('aria-current', 'page');
			}
			menu.appendChild(item);
		});

		// The labels screen readers hear, in the page's own language (Italian on Italian pages, English elsewhere).
		var italian = (document.documentElement.lang || '').toLowerCase().indexOf('it') === 0;
		currentLabel.textContent = activeLink.textContent.trim();
		trigger.setAttribute('aria-label', (italian ? 'Lingua: ' : 'Language: ') + currentLabel.textContent);
		menu.setAttribute('aria-label', italian ? 'Lingue disponibili' : 'Available languages');
		menu.hidden = true;
		root.hidden = false;

		function getItems() {
			return Array.prototype.slice.call(menu.querySelectorAll('[role="menuitem"]'));
		}

		function setOpen(open, focusFirst) {
			menu.hidden = !open;
			trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
			root.dataset.lbOpen = open ? 'true' : 'false';
			if (open && focusFirst) {
				var items = getItems();
				if (items.length) items[focusFirst === 'last' ? items.length - 1 : 0].focus();
			}
		}

		trigger.addEventListener('click', function () {
			setOpen(menu.hidden, false);
		});

		trigger.addEventListener('keydown', function (event) {
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				event.preventDefault();
				setOpen(true, event.key === 'ArrowUp' ? 'last' : 'first');
			} else if (event.key === 'Escape' && !menu.hidden) {
				event.preventDefault();
				setOpen(false, false);
			}
		});

		menu.addEventListener('keydown', function (event) {
			var items = getItems();
			var index = items.indexOf(document.activeElement);
			if (!items.length) return;

			if (event.key === 'Escape') {
				event.preventDefault();
				setOpen(false, false);
				trigger.focus();
			} else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				event.preventDefault();
				var direction = event.key === 'ArrowDown' ? 1 : -1;
				items[(index + direction + items.length) % items.length].focus();
			} else if (event.key === 'Home' || event.key === 'End') {
				event.preventDefault();
				items[event.key === 'Home' ? 0 : items.length - 1].focus();
			} else if (event.key === 'Tab') {
				setOpen(false, false);
			}
		});

		document.addEventListener('pointerdown', function (event) {
			if (!root.contains(event.target)) setOpen(false, false);
		});

		source.remove();
		root.dataset.lbEtchSwitcherReady = 'true';
	});
})();
