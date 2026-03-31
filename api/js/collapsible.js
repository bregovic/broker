/**
 * Collapsible Sections Logic
 * Allows elements with class .collapsible-header to toggle the visibility of the next element.
 * Persists state in localStorage.
 */

document.addEventListener('DOMContentLoaded', function () {
    const headers = document.querySelectorAll('.collapsible-header');

    headers.forEach((header, index) => {
        // Unique key for localStorage based on page and index
        const page = window.location.pathname.split('/').pop().split('?')[0].replace('.php', '') || 'index';
        // Try to find a unique identifier or fallback to index
        const id = header.id || header.dataset.id || index;
        const key = `collapse_${page}_${id}`;

        const content = header.nextElementSibling;
        if (!content) return;

        // Ensure proper cursor and style
        header.style.cursor = 'pointer';
        header.style.userSelect = 'none';

        // Restore state
        const storedState = localStorage.getItem(key);
        if (storedState === 'true') {
            header.classList.add('collapsed');
            content.classList.add('collapsed');
        } else if (storedState === 'false') {
            header.classList.remove('collapsed');
            content.classList.remove('collapsed');
        }
        // If null, leave default

        header.addEventListener('click', function () {
            this.classList.toggle('collapsed');
            content.classList.toggle('collapsed');

            // Save state
            const isCollapsed = content.classList.contains('collapsed');
            localStorage.setItem(key, isCollapsed);
        });
    });
});
