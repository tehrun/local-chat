(function () {
    const modalSelectors = ['#chat-switcher', '#member-picker', '#group-members-modal', '#image-lightbox'];
    const focusableSelector = [
        'a[href]',
        'button:not([disabled])',
        'textarea:not([disabled])',
        'input:not([disabled])',
        'select:not([disabled])',
        '[tabindex]:not([tabindex="-1"])'
    ].join(',');

    function visibleModalElements() {
        return modalSelectors
            .map((selector) => document.querySelector(selector))
            .filter((element) => element && !element.hidden && element.getAttribute('aria-hidden') !== 'true');
    }

    function syncModalState() {
        const openModals = visibleModalElements();
        document.body.classList.toggle('modal-open', openModals.length > 0);
        const activeModal = openModals[openModals.length - 1];
        if (activeModal) {
            document.body.dataset.activeModal = activeModal.id || 'modal';
        } else {
            delete document.body.dataset.activeModal;
        }
    }

    function trapTab(event) {
        if (event.key !== 'Tab') {
            return;
        }

        const openModals = visibleModalElements();
        if (openModals.length === 0) {
            return;
        }

        const activeModal = openModals[openModals.length - 1];
        const focusable = Array.from(activeModal.querySelectorAll(focusableSelector))
            .filter((element) => !element.hasAttribute('hidden') && element.offsetParent !== null);

        if (focusable.length === 0) {
            event.preventDefault();
            activeModal.focus?.();
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        const active = document.activeElement;

        if (event.shiftKey && active === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
    }

    const observer = new MutationObserver(syncModalState);
    observer.observe(document.body, {
        subtree: true,
        attributes: true,
        attributeFilter: ['hidden', 'aria-hidden', 'class']
    });

    document.addEventListener('keydown', trapTab);
    window.addEventListener('load', syncModalState, { once: true });
    syncModalState();
})();
