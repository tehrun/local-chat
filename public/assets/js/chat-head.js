    if (window.history && typeof window.history.replaceState === 'function') {
        window.history.replaceState({}, document.title, './');
    }
