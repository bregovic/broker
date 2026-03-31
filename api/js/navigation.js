/**
 * Navigation JavaScript for Broker Module
 * Handles responsive menu and other navigation interactions
 */

document.addEventListener('DOMContentLoaded', function() {
    // Get navigation elements
    const navContainer = document.querySelector('.nav-container');
    const navMenu = document.querySelector('.nav-menu');
    const logo = document.querySelector('.logo');
    
    // Create and setup mobile menu
    function setupMobileMenu() {
        let toggleBtn = document.querySelector('.nav-toggle');
        
        if (window.innerWidth <= 1023) {
            if (!toggleBtn) {
                // Create hamburger button
                toggleBtn = document.createElement('button');
                toggleBtn.className = 'nav-toggle';
                toggleBtn.innerHTML = '☰';
                toggleBtn.setAttribute('aria-label', 'Toggle navigation');
                
                // Insert button after logo
                if (logo) {
                    logo.insertAdjacentElement('afterend', toggleBtn);
                }
                
                // Add click handler
                toggleBtn.addEventListener('click', function() {
                    navMenu.classList.toggle('active');
                    this.innerHTML = navMenu.classList.contains('active') ? '✕' : '☰';
                    this.setAttribute('aria-expanded', navMenu.classList.contains('active'));
                });
            }
            toggleBtn.style.display = 'block';
        } else {
            // Hide button on larger screens
            if (toggleBtn) {
                toggleBtn.style.display = 'none';
                navMenu.classList.remove('active');
            }
        }
    }
    
    // Setup on load
    setupMobileMenu();
    
    // Handle window resize
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(setupMobileMenu, 250);
    });
    
    // Close menu when clicking on link (mobile only)
    if (window.innerWidth <= 1023) {
        const navLinks = document.querySelectorAll('.nav-link');
        navLinks.forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 1023) {
                    navMenu.classList.remove('active');
                    const toggleBtn = document.querySelector('.nav-toggle');
                    if (toggleBtn) {
                        toggleBtn.innerHTML = '☰';
                        toggleBtn.setAttribute('aria-expanded', 'false');
                    }
                }
            });
        });
    }
    
    // Add smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
    
    // Handle active page highlighting
    const currentPath = window.location.pathname;
    const navItems = document.querySelectorAll('.nav-link');
    
    navItems.forEach(item => {
        const href = item.getAttribute('href');
        if (href && currentPath.includes(href.replace('.php', ''))) {
            item.classList.add('active');
        }
    });
});