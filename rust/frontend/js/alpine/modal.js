import Alpine from '@alpinejs/csp';

/** Keep native dialog focus and dismissal behavior in sync with Alpine state. */
Alpine.directive('modal', (element, { expression }, { evaluateLater, effect, cleanup }) => {
    const evaluateOpen = evaluateLater(expression);
    let open = false;
    const handleKeydown = event => {
        if (event.key === 'Escape') event.stopPropagation();
    };
    element.addEventListener('keydown', handleKeydown);

    effect(() => evaluateOpen(value => {
        open = Boolean(value);
        Alpine.nextTick(() => {
            if (!element.isConnected) return;
            if (open && !element.open) element.showModal();
            if (!open && element.open) element.close();
        });
    }));

    cleanup(() => {
        element.removeEventListener('keydown', handleKeydown);
        if (element.open) element.close();
    });
});
