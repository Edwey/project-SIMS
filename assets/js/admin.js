/**
 * Admin Panel JavaScript
 * Handles modal auto-opening and other admin functionality
 */

document.addEventListener('DOMContentLoaded', function() {
    console.log('Admin JS loaded');

    // Auto-open credentials modal if it exists (for admissions accept action)
    const credentialsModal = document.getElementById('credentialsModal');
    if (credentialsModal) {
        if (window.bootstrap && bootstrap.Modal) {
            const modal = new bootstrap.Modal(credentialsModal);
            modal.show();
        }
    }

    // Auto-open edit modal if data-edit-modal attribute is present on body
    const body = document.body;
    const editModalId = body.getAttribute('data-edit-modal');

    if (editModalId) {
        console.log('Edit mode detected, opening modal:', editModalId);
        const modalEl = document.getElementById(editModalId);

        if (modalEl) {
            if (window.bootstrap && bootstrap.Modal) {
                console.log('Modal element found, opening...');
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
                console.log('Modal show() called');
            } else {
                console.warn('Bootstrap modal not available. Ensure bootstrap.bundle.min.js is loaded.');
            }
        } else {
            console.error('Modal element not found:', editModalId);
        }
    }

    const removeParam = body.getAttribute('data-remove-param');
    if (removeParam) {
        const url = new URL(window.location.href);
        if (url.searchParams.has(removeParam)) {
            url.searchParams.delete(removeParam);
            window.history.replaceState({}, '', url);
        }
    }

    const dropdownToggles = Array.from(document.querySelectorAll('[data-bs-toggle="dropdown"]'));
    if (dropdownToggles.length > 0) {
        if (window.bootstrap && bootstrap.Dropdown) {
            dropdownToggles.forEach(toggle => {
                bootstrap.Dropdown.getOrCreateInstance(toggle);
            });
        } else {
            console.warn('Bootstrap dropdown not available. Applying basic fallback.');

            const closeAllDropdowns = () => {
                dropdownToggles.forEach(toggle => {
                    toggle.setAttribute('aria-expanded', 'false');
                    toggle.classList.remove('show');
                    const parent = toggle.closest('.dropdown');
                    if (parent) {
                        parent.classList.remove('show');
                    }
                    const menu = toggle.nextElementSibling;
                    if (menu && menu.classList.contains('dropdown-menu')) {
                        menu.classList.remove('show');
                    }
                });
            };

            dropdownToggles.forEach(toggle => {
                const menu = toggle.nextElementSibling;
                if (!menu || !menu.classList.contains('dropdown-menu')) {
                    return;
                }

                toggle.addEventListener('click', event => {
                    event.preventDefault();
                    event.stopPropagation();

                    const isOpen = toggle.getAttribute('aria-expanded') === 'true';
                    closeAllDropdowns();

                    if (!isOpen) {
                        toggle.setAttribute('aria-expanded', 'true');
                        toggle.classList.add('show');
                        const parent = toggle.closest('.dropdown');
                        if (parent) {
                            parent.classList.add('show');
                        }
                        menu.classList.add('show');
                    }
                });

                menu.addEventListener('click', event => {
                    event.stopPropagation();
                });
            });

            document.addEventListener('click', closeAllDropdowns);
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape') {
                    closeAllDropdowns();
                }
            });
        }
    }
});
