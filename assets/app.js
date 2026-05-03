import 'bootstrap';
import 'bootstrap/dist/css/bootstrap.min.css';
import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.bootstrap5.css';
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';
import { Russian } from 'flatpickr/dist/l10n/ru.js';
import './styles/app.css';

const initSearchableSelects = () => {
    document.querySelectorAll('select.js-searchable-select').forEach((select) => {
        if (select.tomselect) {
            return;
        }

        const placeholderOption = select.querySelector('option[value=""]');
        const placeholder = select.dataset.searchPlaceholder || placeholderOption?.textContent?.trim() || 'Начните вводить для поиска';

        new TomSelect(select, {
            allowEmptyOption: true,
            create: false,
            maxItems: select.multiple ? null : 1,
            maxOptions: 1000,
            searchField: ['text'],
            sortField: [{ field: '$order' }],
            placeholder,
            plugins: {
                clear_button: {
                    title: 'Очистить',
                },
            },
            render: {
                no_results: () => '<div class="no-results">Ничего не найдено</div>',
            },
        });
    });
};

const initDatePickers = () => {
    document.querySelectorAll('input.js-date-picker').forEach((input) => {
        if (input._flatpickr) {
            return;
        }

        flatpickr(input, {
            allowInput: true,
            dateFormat: 'd.m.Y',
            disableMobile: true,
            locale: Russian,
        });
    });
};

const initAdminSidebarScrollMemory = () => {
    const sidebar = document.querySelector('[data-admin-sidebar-scroll]');

    if (!sidebar || sidebar.dataset.scrollMemoryInitialized === '1') {
        return;
    }

    sidebar.dataset.scrollMemoryInitialized = '1';

    const storageKey = 'admin.sidebar.scrollTop';
    const readScrollPosition = () => {
        try {
            return Number.parseInt(window.sessionStorage.getItem(storageKey) || '0', 10);
        } catch {
            return 0;
        }
    };
    const saveScrollPosition = () => {
        try {
            window.sessionStorage.setItem(storageKey, String(sidebar.scrollTop));
        } catch {
            // Storage can be unavailable in hardened browser modes; navigation should still work.
        }
    };
    const restoreValue = readScrollPosition();

    if (Number.isFinite(restoreValue) && restoreValue > 0) {
        sidebar.scrollTop = restoreValue;
    }

    let pendingAnimationFrame = null;
    sidebar.addEventListener('scroll', () => {
        if (pendingAnimationFrame !== null) {
            return;
        }

        pendingAnimationFrame = window.requestAnimationFrame(() => {
            pendingAnimationFrame = null;
            saveScrollPosition();
        });
    }, { passive: true });

    sidebar.querySelectorAll('a[href]').forEach((link) => {
        link.addEventListener('click', saveScrollPosition);
    });

    window.addEventListener('beforeunload', saveScrollPosition);
};

document.addEventListener('DOMContentLoaded', initSearchableSelects);
document.addEventListener('DOMContentLoaded', initDatePickers);
document.addEventListener('DOMContentLoaded', initAdminSidebarScrollMemory);
document.addEventListener('turbo:load', initSearchableSelects);
document.addEventListener('turbo:load', initDatePickers);
document.addEventListener('turbo:load', initAdminSidebarScrollMemory);
