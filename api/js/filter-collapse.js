// Filter collapse/expand functionality - DEBUGGING VERSION
console.log('[Filter Collapse] Script loaded');

document.addEventListener('DOMContentLoaded', function () {
    console.log('[Filter Collapse] DOM loaded, initializing...');

    const filterHeaders = document.querySelectorAll('.rates-card h3');
    console.log('[Filter Collapse] Found headers:', filterHeaders.length);

    filterHeaders.forEach(function (header) {
        console.log('[Filter Collapse] Processing header:', header.textContent);

        // Skip if already has click handler
        if (header.dataset.collapsible) {
            console.log('[Filter Collapse] Header already has handler, skipping');
            return;
        }
        header.dataset.collapsible = 'true';

        header.addEventListener('click', function (e) {
            console.log('[Filter Collapse] Header clicked!', this.textContent);

            // Find filter-grid - it might be inside a form
            let filterGrid = this.nextElementSibling;

            // If next sibling is a form, look inside it
            if (filterGrid && filterGrid.tagName === 'FORM') {
                console.log('[Filter Collapse] Next element is a FORM, looking inside...');
                filterGrid = filterGrid.querySelector('.filter-grid');
            }

            if (!filterGrid || !filterGrid.classList.contains('filter-grid')) {
                console.log('[Filter Collapse] No filter-grid found!', filterGrid);
                return;
            }

            console.log('[Filter Collapse] Found filter-grid, toggling...');

            // Toggle collapsed class
            this.classList.toggle('collapsed');
            filterGrid.classList.toggle('collapsed');

            console.log('[Filter Collapse] After toggle - header collapsed:', this.classList.contains('collapsed'));
            console.log('[Filter Collapse] After toggle - grid collapsed:', filterGrid.classList.contains('collapsed'));

            // Also toggle the button container if it exists
            const form = filterGrid.closest('form');
            if (form) {
                const buttonContainer = form.querySelector('.form-group:has(.btn), div:has(.btn)');
                if (buttonContainer) {
                    buttonContainer.classList.toggle('collapsed');
                    console.log('[Filter Collapse] Toggled button container');
                }
            }

            // Save state to localStorage
            const pageKey = window.location.pathname.split('/').pop();
            const isCollapsed = filterGrid.classList.contains('collapsed');
            localStorage.setItem('filter-collapsed-' + pageKey, isCollapsed);
            console.log('[Filter Collapse] Saved state:', isCollapsed);
        });

        // Restore state from localStorage
        const pageKey = window.location.pathname.split('/').pop();
        const wasCollapsed = localStorage.getItem('filter-collapsed-' + pageKey) === 'true';
        console.log('[Filter Collapse] Restoring state for', pageKey, ':', wasCollapsed);

        if (wasCollapsed) {
            let filterGrid = header.nextElementSibling;
            if (filterGrid && filterGrid.tagName === 'FORM') {
                filterGrid = filterGrid.querySelector('.filter-grid');
            }

            if (filterGrid && filterGrid.classList.contains('filter-grid')) {
                header.classList.add('collapsed');
                filterGrid.classList.add('collapsed');
                console.log('[Filter Collapse] Restored collapsed state');
            }
        }
    });
});
