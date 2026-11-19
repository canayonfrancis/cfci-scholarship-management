<script>
    // Browser UI deterrent only; access to records is enforced on the server.
    document.addEventListener('contextmenu', (event) => {
        event.preventDefault();
    }, { capture: true });

    document.addEventListener('keydown', (event) => {
        const key = event.key.toLowerCase();
        const developerShortcut = ['i', 'j', 'c', 'k'].includes(key)
            && ((event.ctrlKey && event.shiftKey) || (event.metaKey && event.altKey));

        if (key === 'f12' || developerShortcut) {
            event.preventDefault();
        }
    }, { capture: true });
</script>
